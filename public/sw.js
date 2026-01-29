/* public/sw.js */

/**
 * Push受信SW（運用寄り）
 * - 通知表示（payloadを落とさず notification.data に保持）
 * - 通知クリック:
 *    - 既存タブがあれば focus + postMessage({type:'REMINDER_CLICK', payload})
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

self.addEventListener("push", (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        // JSONで来ない場合は最低限
        payload = {
            title: "Habit Tracker",
            body: event.data ? event.data.text() : "",
        };
    }

    const title = payload.title || "Habit Tracker";
    const body = payload.body || "";

    // task_id の取り出し（サーバ実装の揺れに耐える）
    const taskId =
        payload.task_id ??
        payload.taskId ??
        payload.task?.id ??
        payload.task ??
        null;

    // url がpayloadにあるならそれを優先、無ければ /today へ
    // さらに task_id があれば /today?from=push&task_id=... を作る（postMessage取りこぼし対策）
    const origin = self.location.origin;
    const fallbackPath =
        taskId != null
            ? `/today?from=push&task_id=${encodeURIComponent(String(taskId))}`
            : `/today?from=push`;

    const url =
        typeof payload.url === "string" && payload.url.length > 0
            ? payload.url
            : fallbackPath;

    const options = {
        body,
        // ★ここが肝：payload を丸ごと data に入れる（urlも含める）
        data: { ...payload, url },
        // 必要なら（通知が大量に積まれるのが嫌なら）tagを付ける
        // tag: taskId != null ? `reminder-${taskId}` : "reminder",
        // renotify: false,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    const data = event.notification?.data || {};
    const origin = self.location.origin;

    const taskId =
        data.task_id ?? data.taskId ?? data.task?.id ?? data.task ?? null;

    const url =
        typeof data.url === "string" && data.url.length > 0
            ? data.url
            : taskId != null
              ? `/today?from=push&task_id=${encodeURIComponent(String(taskId))}`
              : `/today?from=push`;

    event.waitUntil(
        (async () => {
            const clientList = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            // 既存タブがあればそこへ送る（同一originを優先）
            const sameOrigin = clientList.find(
                (c) => typeof c.url === "string" && c.url.startsWith(origin),
            );
            const target = sameOrigin || clientList[0];

            if (target) {
                try {
                    await target.focus();
                } catch (e) {}

                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                // postMessage が落ちても URL 側で拾えるように、必要なら遷移もさせたい場合は下を有効化
                // （通常は不要。SPA側が message を拾えば開く）
                // try { target.navigate(url); } catch (e) {}
                return;
            }

            // タブがなければ開く（相対URLを同一originで開く）
            const openUrl = url.startsWith("http") ? url : origin + url;
            await self.clients.openWindow(openUrl);
        })(),
    );
});
