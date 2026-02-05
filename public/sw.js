/* public/sw.js */

/**
 * Push受信SW（運用寄り）
 * - 通知表示（notification.data は最小限のホワイトリストだけ保持）
 * - 通知クリック:
 *    - 既存タブがあれば focus
 *      - postMessage({type:'REMINDER_CLICK', payload}) を送る
 *      - ★既に /today を開いているタブなら navigate しない（URL二段階化防止）
 *      - /today 以外の画面なら保険で /today?from=push&task_id=... へ navigate（可能なら）
 *      - ★navigate が失敗した場合は openWindow で救済（到達保証を上げる）
 *    - タブが無ければ /today?from=push&task_id=... を openWindow
 *
 * 重要:
 * - 「通知クリックで必ず /today に寄せる」ため、payload.url / data.url はナビゲーションに使わない
 *   （/today?week=... などで週UIが残る事故を防ぐ）
 */

self.addEventListener("install", () => {
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(self.clients.claim());
});

function pickTaskId(obj) {
    return obj?.task_id ?? obj?.taskId ?? obj?.task?.id ?? obj?.task ?? null;
}

function buildFallbackPath(taskId) {
    return taskId != null
        ? `/today?from=push&task_id=${encodeURIComponent(String(taskId))}`
        : `/today?from=push`;
}

/**
 * Same-origin only URL resolver.
 * - Accepts absolute or relative URLs but forces same-origin
 * - Falls back to fallbackPath (same-origin) if invalid or cross-origin
 */
function toSameOriginUrl(origin, urlOrPath, fallbackPath) {
    const fallback = new URL(fallbackPath || "/today?from=push", origin);

    try {
        // NOTE: new URL handles:
        // - absolute: https://...
        // - relative: /today...
        // - scheme-relative: //evil.com/... (=> cross-origin)
        const resolved = new URL(urlOrPath || fallback.href, origin);

        if (resolved.origin !== fallback.origin) return fallback.href;
        return resolved.href;
    } catch (e) {
        return fallback.href;
    }
}

function normalizePayload(input) {
    if (input && typeof input === "object" && !Array.isArray(input))
        return input;
    return {};
}

/**
 * notification.data を最小限にホワイトリスト化
 * - url は「常に /today?from=push...」の openUrl を格納（payload.url は格納しない）
 */
function sanitizeNotificationData(payload, openUrl, taskId) {
    return {
        title: payload.title ?? "Habit Tracker",
        body: payload.body ?? "",
        task_id: taskId,
        habit_time_id: payload.habit_time_id ?? null,
        date: payload.date ?? null,
        evaluation_type: payload.evaluation_type ?? null,
        url: openUrl,
    };
}

function isTodayPageUrl(origin, clientUrl) {
    try {
        const u = new URL(clientUrl);
        if (!u.href.startsWith(origin)) return false;
        return u.pathname === "/today";
    } catch (e) {
        return false;
    }
}

/**
 * Push: json() を試して、失敗したら text() fallback（★必ず await）
 * - Promise 混入を防ぎ、options.body を常に string に寄せる
 */
self.addEventListener("push", (event) => {
    event.waitUntil(
        (async () => {
            let payload = {};

            try {
                payload = event.data ? event.data.json() : {};
            } catch (e) {
                let textBody = "";
                try {
                    textBody = event.data ? await event.data.text() : "";
                } catch (_) {
                    textBody = "";
                }
                payload = {
                    title: "Habit Tracker",
                    body: textBody,
                };
            }

            payload = normalizePayload(payload);

            const title = payload.title || "Habit Tracker";
            const body =
                typeof payload.body === "string"
                    ? payload.body
                    : String(payload.body ?? "");

            const taskId = pickTaskId(payload);
            const origin = self.location.origin;
            const fallbackPath = buildFallbackPath(taskId);

            // ★重要: 通知クリックの遷移先は常に fallbackPath（/today?from=push...）に固定
            // payload.url はナビゲーションには使わない（/today?week=... で週UIが残る事故を防ぐ）
            const openUrl = toSameOriginUrl(origin, fallbackPath, fallbackPath);

            const options = {
                body,
                // ★通知データは必要最小限に限定（トークン混入・任意URL混入を防止）
                data: sanitizeNotificationData(payload, openUrl, taskId),
                // tag を付けたいならここ（通知が積まれすぎるのが嫌なら）
                // tag: taskId != null ? `reminder-${taskId}` : "reminder",
            };

            await self.registration.showNotification(title, options);
        })(),
    );
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const data =
                event.notification?.data &&
                typeof event.notification.data === "object"
                    ? event.notification.data
                    : {};

            const origin = self.location.origin;
            const taskId = pickTaskId(data);
            const fallbackPath = buildFallbackPath(taskId);

            // ★重要: data.url も信用しない。常に /today?from=push... に固定
            const openUrl = toSameOriginUrl(origin, fallbackPath, fallbackPath);

            const clientList = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            // 同一originを優先
            const sameOrigin = clientList.find(
                (c) => typeof c.url === "string" && c.url.startsWith(origin),
            );
            const target = sameOrigin || clientList[0];

            if (!target) {
                // タブがなければ openWindow
                await self.clients.openWindow(openUrl);
                return;
            }

            // 1) まずフォーカス
            try {
                await target.focus();
            } catch (_) {}

            // 2) postMessage（最速でモーダルを開ける）
            try {
                target.postMessage({
                    type: "REMINDER_CLICK",
                    payload: data,
                });
            } catch (_) {}

            // 3) すでに /today のタブなら navigate しない（URL二段階化防止）
            if (isTodayPageUrl(origin, target.url)) return;

            // 4) navigate できるなら /today に寄せる（postMessage取り逃し保険）
            //    ★失敗したら openWindow で救済して到達保証を上げる
            let navigated = false;
            try {
                await target.navigate(openUrl);
                navigated = true;
            } catch (_) {
                navigated = false;
            }

            // 5) navigate 後にもう一回 postMessage（確度上げ）
            try {
                target.postMessage({
                    type: "REMINDER_CLICK",
                    payload: data,
                });
            } catch (_) {}

            // ★救済: navigate が失敗した場合は openWindow で /today コンテキストを必ず作る
            if (!navigated) {
                try {
                    await self.clients.openWindow(openUrl);
                } catch (_) {}
            }
        })(),
    );
});
