/* public/sw.js */

/**
 * Push受信SW（運用寄り）
 * - 通知表示（payloadを落とさず notification.data に保持）
 * - 通知クリック:
 *    - 既存タブがあれば focus
 *      - postMessage({type:'REMINDER_CLICK', payload}) を送る
 *      - ★既に /today を開いているタブなら navigate しない（URL二段階化防止）
 *      - /today 以外の画面なら保険で /today?from=push&task_id=... へ navigate（可能なら）
 *    - タブが無ければ /today?from=push&task_id=... を openWindow
 *
 * 重要: data を削らずに通す（task_id / habit_time_id / date / evaluation_type など）
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
        // - scheme-relative: //evil.com/... (=> cross-origin)  ← ★ここが落とし穴になりやすい
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

self.addEventListener("push", (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = {
            title: "Habit Tracker",
            body: event.data ? event.data.text() : "",
        };
    }

    payload = normalizePayload(payload);

    const title = payload.title || "Habit Tracker";
    const body = payload.body || "";

    const taskId = pickTaskId(payload);
    const origin = self.location.origin;
    const fallbackPath = buildFallbackPath(taskId);

    const rawUrl =
        typeof payload.url === "string" && payload.url.length > 0
            ? payload.url
            : fallbackPath;

    const openUrl = toSameOriginUrl(origin, rawUrl, fallbackPath);

    const options = {
        body,
        // ★通知データは必要最小限に限定（トークン混入を防止）
        data: sanitizeNotificationData(payload, openUrl, taskId),
        // tag を付けたいならここ（通知が積まれすぎるのが嫌なら）
        // tag: taskId != null ? `reminder-${taskId}` : "reminder",
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    const data =
        event.notification?.data && typeof event.notification.data === "object"
            ? event.notification.data
            : {};

    const origin = self.location.origin;
    const taskId = pickTaskId(data);

    const fallbackPath = buildFallbackPath(taskId);

    const rawUrl =
        typeof data.url === "string" && data.url.length > 0
            ? data.url
            : fallbackPath;

    const openUrl = toSameOriginUrl(origin, rawUrl, fallbackPath);

    event.waitUntil(
        (async () => {
            const clientList = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            // 同一originを優先
            const sameOrigin = clientList.find(
                (c) => typeof c.url === "string" && c.url.startsWith(origin),
            );
            const target = sameOrigin || clientList[0];

            if (target) {
                // 1) まずフォーカス
                try {
                    await target.focus();
                } catch (e) {}

                // 2) postMessage（最速でモーダルを開ける）
                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                // 3) すでに /today のタブなら navigate しない（URL二段階化防止）
                if (isTodayPageUrl(origin, target.url)) return;

                // 4) navigate できるなら /today に寄せる（postMessage取り逃し保険）
                try {
                    await target.navigate(openUrl);
                } catch (e) {}

                // 5) navigate 後にもう一回 postMessage（確度上げ）
                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                return;
            }

            // タブがなければ openWindow
            await self.clients.openWindow(openUrl);
        })(),
    );
});
