/* public/sw.js */

/**
 * 最小のPush受信SW
 * - 通知表示
 * - 通知クリックでURLを開く
 */

self.addEventListener("install", (event) => {
    // すぐ有効化したいなら
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener("push", (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: "Habit Tracker", body: event.data?.text?.() ?? "" };
    }

    const title = data.title || "Habit Tracker";
    const options = {
        body: data.body || "",
        data: { url: data.url || "/" },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const url = event.notification.data?.url || "/";

    event.waitUntil(
        clients
            .matchAll({ type: "window", includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    // すでに開いてるタブがあればフォーカス
                    if ("focus" in client) return client.focus();
                }
                // なければ新規で開く
                return clients.openWindow(url);
            })
    );
});
