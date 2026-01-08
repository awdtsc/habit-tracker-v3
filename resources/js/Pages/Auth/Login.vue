<!-- resources/js/Pages/Auth/Login.vue -->
<template>
  <div class="min-h-screen bg-[#f7f9fc] flex items-center justify-center px-6">
    <div class="w-full max-w-md bg-white shadow-xl rounded-2xl p-8">
      <h1 class="text-2xl font-bold text-gray-800 mb-6">ログイン</h1>

      <!-- メールアドレス -->
      <div class="mb-4">
        <label for="login-email" class="block text-sm text-gray-700 mb-1">
          メールアドレス
        </label>
        <input
          id="login-email"
          name="email"
          v-model.trim="email"
          type="email"
          autocomplete="email"
          inputmode="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="mb-6">
        <label for="login-password" class="block text-sm text-gray-700 mb-1">
          パスワード
        </label>
        <input
          id="login-password"
          name="password"
          v-model.trim="password"
          type="password"
          autocomplete="current-password"
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
import api, { setAuthToken } from "@/axios";
import { useRouter } from "vue-router";
import { clearUserCache, getUser } from "@/state/authUserCache";

const email = ref("");
const password = ref("");
const router = useRouter();

/**
 * M1: Open-redirect 対策
 * - redirect query は「SPA内部パス（/ で始まる）」のみ許可
 * - //evil.com や http(s)://... は拒否して既定へフォールバック
 */
function safeRedirect(raw, fallback = "/today") {
  if (typeof raw !== "string" || raw.length === 0) return fallback;

  let v = raw;
  try {
    v = decodeURIComponent(raw);
  } catch {
    // decode失敗はそのまま扱う（ただし下のチェックで弾かれる）
    v = raw;
  }

  // 1) 内部パスのみ
  if (!v.startsWith("/")) return fallback;

  // 2) スキーム相対URL（//evil.com）を拒否
  if (v.startsWith("//")) return fallback;

  // 3) 改行などの混入を拒否
  if (v.includes("\n") || v.includes("\r")) return fallback;

  return v;
}

async function submit() {
  if (!email.value || !password.value) {
    alert("メールアドレスとパスワードを入力してください");
    return;
  }

  try {
    console.log("[login] start");

    const res = await api.post("/auth/login", {
      email: email.value,
      password: password.value,
    });

    const token = res?.data?.token ?? null;
    if (!token) {
      alert("ログイン応答に token がありません（API実装を確認してください）");
      return;
    }

    setAuthToken(token);

    clearUserCache();
    const me = await getUser({ force: true });
    if (!me) {
      alert("ログイン直後の認証確認に失敗しました。もう一度お試しください。");
      return;
    }

    console.info("[login] success");

    const rawRedirect = router.currentRoute.value.query.redirect;
    const redirect = safeRedirect(rawRedirect, "/today");
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
