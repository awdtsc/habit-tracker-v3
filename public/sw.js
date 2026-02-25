// public/sw.js
// bump: 2026-02-09

/**
 * Push受信SW（運用寄り / “アプリ内は遷移しない” 方針）
 * - 通知表示（notification.data は最小限のホワイトリストだけ保持 + 文字列上限で安全弁）
 * - 通知クリック:
 *    - 既存タブがあれば focus して postMessage（REMINDER_CLICK）
 *      - week / settings 等に居ても “勝手に /today へ飛ばさない”
 *      - タブがある場合は navigate/openWindow をしない
 *    - タブが無ければ payload.url（same-origin制約）を openWindow（deep link 許可）
 *      - 不正/欠損なら /today?from=push&task_id=... に fallback（到達保証）
 *
 * Update policy (運用安定優先):
 * - ★自動 skipWaiting しない
 * - ★clients.claim() も自動ではしない（切替の瞬間だけ operator が明示的に実行）
 *   => 更新を即時反映したい場合だけ、window 側から waiting SW に
 *      reg.waiting.postMessage({type:"SKIP_WAITING"}) を送る
 *      controllerchange 後に controllerへ postMessage({type:"CLAIM_CLIENTS"}) を送る
 */

const SW_VERSION = "2026-02-09";

// claimを「デフォルトOFF」にして、明示指示でだけONにする
const CLAIM_DEFAULT = false;

self.addEventListener("install", (event) => {
    // ★自動で skipWaiting しない（安全な段階的更新）
    event.waitUntil(Promise.resolve());
});

self.addEventListener("activate", (event) => {
    // ★自動 claim しない（B対策）
    if (CLAIM_DEFAULT) {
        event.waitUntil(self.clients.claim());
    } else {
        event.waitUntil(Promise.resolve());
    }
});

self.addEventListener("message", (event) => {
    // --- debug: version ping ---
    if (event?.data?.type === "PING_VERSION") {
        event?.source?.postMessage({
            type: "PONG_VERSION",
            version: SW_VERSION,
        });
        return;
    }

    // --- operator-controlled rollout ---
    if (event?.data?.type === "SKIP_WAITING") {
        self.skipWaiting();
        return;
    }

    // ★切替操作の「最後」にだけ claim を実行
    if (event?.data?.type === "CLAIM_CLIENTS") {
        event.waitUntil(self.clients.claim());
        return;
    }
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

/**
 * Same-origin window client 判定（startsWith ではなく origin 厳密比較）
 */
function isSameOriginWindowClient(origin, client) {
    try {
        const u = new URL(client?.url || "");
        return u.origin === origin;
    } catch (e) {
        return false;
    }
}

/**
 * “アプリタブ” 判定（安全側 / origin 厳密比較）
 */
function isAppClient(origin, clientUrl) {
    try {
        const u = new URL(clientUrl);
        if (u.origin !== origin) return false;

        const p = u.pathname || "/";
        if (p === "/login" || p === "/register") return false;
        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Push: json() を試して、失敗したら text() fallback（★必ず await）
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
                payload = { title: "Habit Tracker", body: textBody };
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

            // ★deep link 許可: payload.url を採用（same-origin制約）、ダメなら fallback
            const openUrl = toSameOriginUrl(origin, payload.url, fallbackPath);

            const options = {
                body,
                data: sanitizeNotificationData(
                    { ...payload, title, body },
                    openUrl,
                    taskId,
                ),
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

            // ★deep link 許可: data.url を採用（same-origin制約）、ダメなら fallback
            const openUrl = toSameOriginUrl(origin, data.url, fallbackPath);

            const clientList = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            const sameOriginClients = clientList.filter((c) =>
                isSameOriginWindowClient(origin, c),
            );

            const appClient = sameOriginClients.find((c) =>
                isAppClient(origin, c.url),
            );

            if (appClient) {
                try {
                    await appClient.focus();
                } catch (_) {}

                try {
                    appClient.postMessage({
                        type: "REMINDER_CLICK",
                        source: "notificationclick",
                        payload: data,
                    });
                } catch (_) {}

                return;
            }

            try {
                await self.clients.openWindow(openUrl);
            } catch (_) {}
        })(),
    );
});
