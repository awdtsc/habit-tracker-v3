// resources/js/app.js

import './bootstrap';
import './axios';
import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

import { ensureCsrfCookie } from '@/axios';

// ------------------------------------------------------------
// ⭐ 最重要：Sanctum の CSRF Cookie を最初に 1 度だけ取得する
// ------------------------------------------------------------
await ensureCsrfCookie();

// ------------------------------------------------------------
// Vue アプリ起動
// ------------------------------------------------------------
createApp(App)
  .use(router)
  .mount('#app');