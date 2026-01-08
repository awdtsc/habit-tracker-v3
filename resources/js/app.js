// resources/js/app.js

import "./bootstrap";
import "../css/app.css";

import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";

// ★ Bearer運用ではCSRF cookieは不要（むしろCookie汚染の原因）
// import { initCsrf } from "./axios";
// await initCsrf();

createApp(App).use(router).mount("#app");
