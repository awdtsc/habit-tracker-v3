// resources/js/app.js

import "./bootstrap";
import "../css/app.css";

import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";

// ★ CookieモードのときだけCSRF cookieを取得（POST/PUT/DELETEを安定化）
// - Bearerモードでは呼ばない（従来どおり）
import { initCsrf, COOKIE_AUTH_ENABLED } from "./axios";

async function boot() {
    if (COOKIE_AUTH_ENABLED) {
        try {
            await initCsrf();
            console.info("[boot] csrf cookie ready");
        } catch (e) {
            // CSRF取得失敗で即死させない（オフライン等）
            console.warn("[boot] csrf cookie failed (continue)", e);
        }
    }

    const app = createApp(App);

    app.use(router);

    // 初回ナビゲーション完了を待ってから mount（ガード/リダイレクト絡みのチラつき防止）
    try {
        await router.isReady();
    } catch (e) {
        console.warn("[boot] router.isReady() failed (continue)", e);
    }

    app.mount("#app");
}

boot();
