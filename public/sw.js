/* public/sw.js */

/**
 * Push受信SW運用寄り）
 * - 通知表示（notification.data は最小限のホワイトリストだけ保持 + 文字列上限で安全弁）
 * - 通知クリック:
 *    - 既存タブがあれば focus
 *      - ★既に /today を開いているタブがあれば、そのタブにだけ postMessage（最速UI起動）
 *      - /today タブが無ければ /today?from=push&task_id=... へ navigate（可能なら）
 *      - ★navigate が失敗した場合は openWindow で救済（到達保証を上げる）
 *      - ★openWindow 前に /today タブ再確認してタブ増殖を抑止
 *    - タブが無ければ /today?from=push&task_id=... を openWindow
 *
 * 重要:
 * - 「通知クリックで必ず /today に寄せる」ため、payload.url / data.url はナビゲーションに使わない
 *   （/today?week=... などで週UIが残る事故を防ぐ）
 * - payload の title/body 等が巨大でも落ちないように、notification.data の文字列は上限で切る（運用安全弁）
 */

self.addEventListener("install", () => {
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(self.clients.claim());
});

// ===== size guard (運用安全弁) =====
// ※好みで調整OK（titleは短め、bodyは少し長め）
const LIMITS = {
    title: 80,
    body: 500,
    date: 64,
    evaluation_type: 64,
};

function clampStr(v, maxLen) {
    const s = typeof v === "string" ? v : String(v ?? "");
    if (!maxLen || maxLen <= 0) return s;
    if (s.length <= maxLen) return s;
    return s.slice(0, maxLen);
}

function toStrOrNull(v) {
    if (v == null) return null;
    const s = String(v);
    return s ? s : null;
}

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
 * - 文字列は上限で切って、異常サイズでも落ちないようにする
 */
function sanitizeNotificationData(payload, openUrl, taskId) {
    return {
        title: clampStr(payload.title ?? "Habit Tracker", LIMITS.title),
        body: clampStr(payload.body ?? "", LIMITS.body),
        task_id: taskId,
        habit_time_id: payload.habit_time_id ?? null,
        habit_id: payload.habit_id ?? null,
        time_slot: payload.time_slot ?? null,
        date: toStrOrNull(clampStr(payload.date ?? "", LIMITS.date)),
        evaluation_type: toStrOrNull(
            clampStr(payload.evaluation_type ?? "", LIMITS.evaluation_type),
        ),
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
 * - さらに options.data の文字列も上限で切って、異常サイズでの事故を抑止
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

            const rawTitle = payload.title || "Habit Tracker";
            const title = clampStr(rawTitle, LIMITS.title);

            const rawBody =
                typeof payload.body === "string"
                    ? payload.body
                    : String(payload.body ?? "");
            const body = clampStr(rawBody, LIMITS.body);

            const taskId = pickTaskId(payload);
            const origin = self.location.origin;
            const fallbackPath = buildFallbackPath(taskId);

            // ★重要: 通知クリックの遷移先は常に fallbackPath（/today?from=push...）に固定
            // payload.url はナビゲーションには使わない（/today?week=... で週UIが残る事故を防ぐ）
            const openUrl = toSameOriginUrl(origin, fallbackPath, fallbackPath);

            const options = {
                body,
                // ★通知データは必要最小限に限定（トークン混入・任意URL混入を防止）
                data: sanitizeNotificationData(
                    { ...payload, title, body },
                    openUrl,
                    taskId,
                ),
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

            // 初回のクライアント探索
            const clientList = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            const sameOriginClients = clientList.filter(
                (c) => typeof c.url === "string" && c.url.startsWith(origin),
            );

            // ★最優先: 既に /today を開いているタブがあるなら、それだけを使う
            const todayClient = sameOriginClients.find((c) =>
                isTodayPageUrl(origin, c.url),
            );

            if (todayClient) {
                try {
                    await todayClient.focus();
                } catch (_) {}

                // ★/today がある場合のみ message（1回だけ）
                // data は notification.data（ホワイトリスト + 上限済み）なのでそのまま送って良い
                try {
                    todayClient.postMessage({
                        type: "REMINDER_CLICK",
                        source: "notificationclick",
                        payload: data,
                    });
                } catch (_) {}

                return;
            }

            // ★/today が無い場合:
            // - message は送らない（/today以外で開いても二重トリガー/未ログイン/画面不一致が起きやすい）
            // - /today?from=push... に寄せる（navigate → openWindow救済）
            const target = sameOriginClients[0] || clientList[0];

            if (target) {
                try {
                    await target.focus();
                } catch (_) {}

                let navigated = false;
                try {
                    await target.navigate(openUrl);
                    navigated = true;
                } catch (_) {
                    navigated = false;
                }

                if (navigated) return;
            }

            // navigate が失敗した場合の救済:
            // ★タブ増殖抑止のため、openWindow 前にもう一度 /today が生えたか再確認
            try {
                const refreshed = await self.clients.matchAll({
                    type: "window",
                    includeUncontrolled: true,
                });

                const refreshedSameOrigin = refreshed.filter(
                    (c) =>
                        typeof c.url === "string" && c.url.startsWith(origin),
                );

                const refreshedToday = refreshedSameOrigin.find((c) =>
                    isTodayPageUrl(origin, c.url),
                );

                if (refreshedToday) {
                    try {
                        await refreshedToday.focus();
                    } catch (_) {}
                    try {
                        refreshedToday.postMessage({
                            type: "REMINDER_CLICK",
                            source: "notificationclick",
                            payload: data,
                        });
                    } catch (_) {}
                    return;
                }
            } catch (_) {}

            try {
                await self.clients.openWindow(openUrl);
            } catch (_) {}
        })(),
    );
});
