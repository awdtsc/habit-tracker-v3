<!-- resources/js/Pages/Auth/Register.vue -->
<template>
  <div class="min-h-screen bg-[#f7f7f7] flex flex-col items-center justify-center px-4">
    <h1 class="text-3xl font-bold text-[#2b6cb0] mb-10">
      ハビットトラッカー
    </h1>

    <div class="w-full max-w-md bg-[#e8ecf1] rounded-2xl shadow-md p-10 flex flex-col items-center">
      <h2 class="text-xl font-bold text-gray-700 mb-8">ユーザー登録</h2>

      <!-- 名前 -->
      <div class="w-full mb-6">
        <label for="register-name" class="block text-sm text-gray-700 mb-1">名前</label>
        <input
          id="register-name"
          name="name"
          v-model.trim="name"
          type="text"
          autocomplete="name"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- メール -->
      <div class="w-full mb-6">
        <label for="register-email" class="block text-sm text-gray-700 mb-1">メールアドレス</label>
        <input
          id="register-email"
          name="email"
          v-model.trim="email"
          type="email"
          autocomplete="email"
          inputmode="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="w-full mb-8">
        <label for="register-password" class="block text-sm text-gray-700 mb-1">パスワード</label>
        <input
          id="register-password"
          name="password"
          v-model.trim="password"
          type="password"
          autocomplete="new-password"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- 登録ボタン -->
      <button
        @click="submit"
        class="w-full py-3 bg-[#2b6cb0] text-white font-bold rounded-full hover:bg-[#1e4e8c] transition mb-6"
      >
        登録する
      </button>

      <button @click="goLogin" class="text-sm text-[#2b6cb0] hover:underline">
        ログインに戻る
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import api, { setAuthToken } from "@/axios";
import { useRouter } from "vue-router";
import { clearUserCache, getUser } from "@/state/authUserCache";

const name = ref("");
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
    v = raw;
  }

  if (!v.startsWith("/")) return fallback;
  if (v.startsWith("//")) return fallback;
  if (v.includes("\n") || v.includes("\r")) return fallback;

  return v;
}

async function submit() {
  if (!name.value || !email.value || !password.value) {
    alert("全ての項目を入力してください");
    return;
  }

  try {
    console.log("[register] start");

    const res = await api.post("/auth/register", {
      name: name.value,
      email: email.value,
      password: password.value,
    });

    const token = res?.data?.token ?? null;
    if (token) {
      setAuthToken(token);
      clearUserCache();
      await getUser({ force: true });

      const rawRedirect = router.currentRoute.value.query.redirect;
      const redirect = safeRedirect(rawRedirect, "/today");
      await router.replace(redirect);
      return;
    }

    alert("登録しました。続けてログインしてください。");
    router.push("/login");
  } catch (e) {
    console.error("[register error]", e);

    if (e.response?.status === 422) {
      alert("入力内容に誤りがあります");
    } else {
      alert("登録に失敗しました");
    }
  }
}

function goLogin() {
  router.push("/login");
}
</script>
