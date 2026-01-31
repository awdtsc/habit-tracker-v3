/* public/sw.js */

/**
 * Push受信SW（運用寄り）
 * - 通知表示（payloadを落とさず notification.data に保持）
 * - 通知クリック:
 *    - 既存タブがあれば focus
 *      - postMessage({type:'REMINDER_CLICK', payload}) を送る
 *      - 取りこぼし保険で /today?from=push&task_id=... へ navigate（可能なら）
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

function toAbsoluteUrl(origin, urlOrPath) {
    try {
        // すでに絶対URLならそのまま
        if (typeof urlOrPath === "string" && urlOrPath.startsWith("http"))
            return urlOrPath;
        // 相対なら origin で解決
        return new URL(urlOrPath || "/today?from=push", origin).href;
    } catch (e) {
        return origin + "/today?from=push";
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

    const title = payload.title || "Habit Tracker";
    const body = payload.body || "";

    const taskId = pickTaskId(payload);

    const origin = self.location.origin;
    const fallbackPath = buildFallbackPath(taskId);

    // url がpayloadにあるならそれを優先、無ければ fallbackPath
    const url =
        typeof payload.url === "string" && payload.url.length > 0
            ? payload.url
            : fallbackPath;

    const options = {
        body,
        // ★ここが肝：payload を丸ごと data に入れる（urlも含める）
        data: { ...payload, url },
        // tag を付けたいならここ（通知が積まれすぎるのが嫌なら）
        // tag: taskId != null ? `reminder-${taskId}` : "reminder",
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    const data = event.notification?.data || {};
    const origin = self.location.origin;

    const taskId = pickTaskId(data);

    const fallbackPath = buildFallbackPath(taskId);
    const url =
        typeof data.url === "string" && data.url.length > 0
            ? data.url
            : fallbackPath;

    const openUrl = toAbsoluteUrl(origin, url);

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

                // 2) まず postMessage（最速でモーダルを開ける）
                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                // 3) 取りこぼし保険：navigate できるなら /today?from=push... へ
                //    - postMessage を受け損ねても App.vue の openFromQueryIfNeeded が拾う
                //    - 既に /today にいるなら邪魔しない（クエリだけ付けてもOK）
                try {
                    if (typeof target.navigate === "function") {
                        // 既に today でも、from=pushでクエリ起動できるので openUrl に寄せる
                        await target.navigate(openUrl);
                    }
                } catch (e) {}

                // 4) navigate 後にもう一回 postMessage（さらに確度上げる）
                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                return;
            }

            // タブがなければ開く
            await self.clients.openWindow(openUrl);
        })(),
    );
});
