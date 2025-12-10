// resources/js/app.js

import './bootstrap';
import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

import { initCsrf } from './axios';  // ★ 必須

// ------------------------------------------------------------
// ★ SPA 起動前に Sanctum CSRF Cookie を取得する
// ------------------------------------------------------------
await initCsrf();
console.log('[startup] CSRF cookie initialized');

// Vue アプリ起動
createApp(App)
  .use(router)
  .mount('#app');