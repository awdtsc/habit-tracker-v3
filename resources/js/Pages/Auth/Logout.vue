<!-- resources/js/Pages/Auth/Logout.vue -->
<template>
  <div class="min-h-screen flex items-center justify-center text-gray-600 text-lg">
    ログアウトしています…
  </div>
</template>

<script setup>
import api, { clearAuthToken } from "@/axios";
import { useRouter } from "vue-router";
import { clearUserCache } from "@/state/authUserCache";

const router = useRouter();

logout();

async function logout() {
  try {
    // token は interceptor が付与する
    await api.post("/auth/logout");
    console.info("[logout] OK");
  } catch (e) {
    console.error("[logout error]", e);
    // 失敗してもローカルでは確実に消す
  }

  clearAuthToken();
  clearUserCache();

  router.replace("/login");
}
</script>
