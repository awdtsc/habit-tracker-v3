/* public/sw.js */

/**
 * Push受信SW（運用寄り / “アプリ内は遷移しない” 方針）
 * - 通知表示（notification.data は最小限のホワイトリストだけ保持 + 文字列上限で安全弁）
 * - 通知クリック:
 *    - ★既存タブがあれば「/today限定にせず」そのタブを focus して postMessage（REMINDER_CLICK）
 *      - つまり week / settings 等に居ても “勝手に /today へ飛ばさない”
 *      - 二重発火・URL汚染を避けるため、タブがある場合は navigate/openWindow をしない
 *    - タブが無ければ /today?from=push&task_id=... を openWindow（到達保証）
 *
 * 重要:
 * - payload.url / data.url はナビゲーションに使わない（常に /today?from=push... を起点にする）
 * - payload の title/body が巨大でも落ちないよう、notification.data の文字列は上限で切る
 */

self.addEventListener("install", () => {
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(self.clients.claim());
});

// ===== size guard (運用安全弁) =====
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
        url: openUrl, // ★保持はするが、クリック時ナビには使わない
    };
}

function isSameOriginWindowClient(origin, client) {
    return (
        client &&
        typeof client.url === "string" &&
        client.url.startsWith(origin)
    );
}

/**
 * “アプリタブ” 判定（安全側）
 * - same-origin の window client だけ対象
 * - /login, /register は “アプリタブ” とみなさない（勝手にモーダル出すのが微妙なので）
 * - それ以外は「アプリが動いているタブ」として扱う
 */
function isAppClient(origin, clientUrl) {
    try {
        const u = new URL(clientUrl);
        if (!u.href.startsWith(origin)) return false;

        const p = u.pathname || "/";
        if (p === "/login" || p === "/register") return false;

        // SPA配下は基本OK（/today /week /settings/... など）
        return true;
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

            // ★重要: クリック遷移用の openUrl は常に /today?from=push... を起点に固定
            const openUrl = toSameOriginUrl(origin, fallbackPath, fallbackPath);

            const options = {
                body,
                data: sanitizeNotificationData(
                    { ...payload, title, body },
                    openUrl,
                    taskId,
                ),
                // tag を付けたいならここ
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

            // クライアント探索
            const clientList = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            const sameOriginClients = clientList.filter((c) =>
                isSameOriginWindowClient(origin, c),
            );

            // ★最優先: “アプリが開いてるタブ” があれば、そこを使う（/today限定にしない）
            const appClient = sameOriginClients.find((c) =>
                isAppClient(origin, c.url),
            );

            if (appClient) {
                try {
                    await appClient.focus();
                } catch (_) {}

                // ★タブがある場合は “遷移しない”。モーダルを開くだけ。
                try {
                    appClient.postMessage({
                        type: "REMINDER_CLICK",
                        source: "notificationclick",
                        payload: data,
                    });
                } catch (_) {}

                return;
            }

            // ★タブが無い場合のみ openWindow（到達保証）
            try {
                await self.clients.openWindow(openUrl);
            } catch (_) {}
        })(),
    );
});
