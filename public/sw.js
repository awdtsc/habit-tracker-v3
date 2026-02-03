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

                // 2) postMessage（最速でモーダルを開ける）
                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                // ★ここが修正点：
                // 既に /today を開いているなら URL をいじらない（SPA側のreplaceと競合して二段階になる）
                const alreadyToday =
                    typeof target.url === "string"
                        ? isTodayPageUrl(origin, target.url)
                        : false;
                if (alreadyToday) {
                    return;
                }

                // 3) /today以外を見ている場合だけ、取りこぼし保険で navigate
                //    （postMessageを取り逃しても、/today?from=push が拾える）
                try {
                    if (typeof target.navigate === "function") {
                        await target.navigate(openUrl);
                    }
                } catch (e) {}

                // 4) navigate 後にもう一回 postMessage（確度上げ）
                try {
                    target.postMessage({
                        type: "REMINDER_CLICK",
                        payload: data,
                    });
                } catch (e) {}

                return;
            }

            // タブがなければ開く（URLで拾えるように openUrl のまま）
            await self.clients.openWindow(openUrl);
        })(),
    );
});
