<!-- resources/js/Pages/Auth/Logout.vue -->
<template>
  <div class="min-h-screen flex items-center justify-center text-gray-600 text-lg">
    ログアウトしています…
  </div>
</template>

<script setup>
import { onMounted } from "vue";
import api, { clearAuthToken } from "@/axios";
import { useRouter } from "vue-router";
import { clearUserCache } from "@/state/authUserCache";

const router = useRouter();

onMounted(() => {
  logout();
});

async function logout() {
  try {
    // /api/v1/auth/logout（api.baseURL="/api/v1" 前提）
    await api.post("/auth/logout");
    console.info("[logout] OK");
  } catch (e) {
    // ログアウトは冪等でOK：失敗してもクライアント状態は消してログインへ
    console.warn("[logout] failed (ignore and continue)", e);
  } finally {
    clearAuthToken();
    clearUserCache();

    // redirectクエリ等は持ち越さない（事故防止）
    await router.replace({ name: "login", query: {} });
  }
}
</script>
