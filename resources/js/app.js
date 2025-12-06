// resources/js/app.js

import './bootstrap';
import './axios';   // ← axios 初期化はこれで十分
import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

// ------------------------------------------------------------
// ★ 追加の CSRF 初期化は不要
// interceptors で自動実行されるため何も書かなくて OK
// ------------------------------------------------------------

// Vue アプリ起動
createApp(App)
  .use(router)
  .mount('#app');