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
          @keydown.enter="submit"
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
          @keydown.enter="submit"
        />
      </div>

      <!-- ログインボタン（これ1つだけ） -->
      <button
        type="button"
        @click="submit"
        :disabled="busy"
        class="w-full py-3 bg-[#f7931a] text-white font-bold rounded-full hover:bg-[#e7840f] transition mb-6 disabled:opacity-50"
      >
        次へ
      </button>

      <button @click="goRegister" class="text-sm text-[#f7931a] hover:underline">
        ユーザー登録
      </button>

      <div v-if="debugMsg" class="mt-6 text-xs text-gray-600">
        {{ debugMsg }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import { cookieLogin, cookieMe } from "@/axios";
import { useRouter } from "vue-router";
import { clearUserCache, getUser } from "@/state/authUserCache";

const email = ref("");
const password = ref("");
const router = useRouter();

const busy = ref(false);
const debugMsg = ref("");

/**
 * Open-redirect 対策（内部パスのみ許可）
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

  // login後の redirect に /logout を許可しない
  if (v === "/logout" || v.startsWith("/logout/")) return fallback;

  return v;
}

async function submit() {
  if (busy.value) return;

  if (!email.value || !password.value) {
    alert("メールアドレスとパスワードを入力してください");
    return;
  }

  busy.value = true;
  debugMsg.value = "";

  try {
    console.log("[login] start (cookie-only)");

    await cookieLogin({
      email: email.value,
      password: password.value,
    });

    const me = await cookieMe();
    debugMsg.value = `Cookie login OK: ${me?.user?.email ?? "unknown"}`;

    // authUserCache を温める（ルーターガード安定化）
    try {
      clearUserCache();
      const u = await getUser({ force: true });
      if (!u) {
        alert("ログイン直後の認証確認に失敗しました。もう一度お試しください。");
        return;
      }
    } catch {
      // ignore
    }

    const rawRedirect = router.currentRoute.value.query.redirect;
    const redirect = safeRedirect(rawRedirect, "/today");
    await router.replace(redirect);
  } catch (e) {
    console.error("[login error cookie-only]", e);

    const status = e?.response?.status ?? 0;
    if (status === 401 || status === 422) {
      alert("メールまたはパスワードが違います");
      return;
    }

    alert("ログイン中にエラーが発生しました");
  } finally {
    busy.value = false;
  }
}

function goRegister() {
  router.push("/register");
}
</script>
