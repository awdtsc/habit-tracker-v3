<!-- resources/js/Pages/Auth/Login.vue -->
<template>
  <div class="min-h-screen bg-[#f7f9fc] flex items-center justify-center px-6">
    <div class="w-full max-w-md bg-white shadow-xl rounded-2xl p-8">

      <h1 class="text-2xl font-bold text-gray-800 mb-6">ログイン</h1>

      <!-- メールアドレス -->
      <div class="mb-4">
        <label class="block text-sm text-gray-700 mb-1">メールアドレス</label>
        <input
          v-model.trim="email"
          type="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="mb-6">
        <label class="block text-sm text-gray-700 mb-1">パスワード</label>
        <input
          v-model.trim="password"
          type="password"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- ログインボタン -->
      <button
        @click="submit"
        class="w-full py-3 bg-[#f7931a] text-white font-bold rounded-full hover:bg-[#e7840f] transition mb-6"
      >
        次へ
      </button>

      <button @click="goRegister" class="text-sm text-[#f7931a] hover:underline">
        ユーザー登録
      </button>

    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import api, { initCsrf } from "@/axios";
import { useRouter } from "vue-router";

import { clearUserCache, getUser, isAuthUnknown } from "@/state/authUserCache";

const email = ref("");
const password = ref("");
const router = useRouter();

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

/**
 * ログイン直後の “cookie反映レース” を吸収して
 * authUserCache 側で /api/user(200) を確定させる
 */
async function waitAuthReady() {
  // 念のため、古い 401/null キャッシュを消す
  clearUserCache();

  // 150ms→250ms→400ms→600ms くらい（最大~1.4s）で軽くリトライ
  const waits = [0, 150, 250, 400, 600];

  for (let i = 0; i < waits.length; i++) {
    if (waits[i] > 0) await sleep(waits[i]);

    const v = await getUser({ force: true });

    // ネットワーク/5xx 等は「未認証確定」じゃないので、少しだけ粘る
    if (isAuthUnknown(v)) continue;

    // 認証OK
    if (v && v.id) return true;

    // v === null は「未認証確定（401）」なので、ここでもう一回だけ粘る
    // （直後401→次200のパターンがある）
  }

  return false;
}

async function submit() {
  if (!email.value || !password.value) {
    alert("メールアドレスとパスワードを入力してください");
    return;
  }

  try {
    console.log("[login] start");

    // ① CSRF Cookie
    await initCsrf();

    // ② ログイン
    // ※ api(baseURL=/api) で /api/login を叩く設計のままでOK（あなたのバックエンド前提）
    await api.post("/login", {
      email: email.value,
      password: password.value,
    });

    console.info("[login] success");

    // ③ “authUserCache の世界”で /api/user を200確定させる（2回クリック問題の根治）
    const ok = await waitAuthReady();
    if (!ok) {
      // ここで弾くと「ログイン成功したのに戻る」になるので、
      // まずはリロード誘導が最も安全
      console.warn("[login] auth not confirmed. suggest reload.");
      alert("ログインは成功しましたが、認証確認が遅れました。ページを再読み込みしてください。");
      return;
    }

    // ④ リダイレクト処理
    const redirect = router.currentRoute.value.query.redirect || "/today";
    await router.replace(redirect);

  } catch (e) {
    console.error("[login error]", e);

    if (e.response?.status === 401) {
      alert("メールまたはパスワードが違います");
    } else {
      alert("ログイン中にエラーが発生しました");
    }
  }
}

function goRegister() {
  router.push("/register");
}
</script>
