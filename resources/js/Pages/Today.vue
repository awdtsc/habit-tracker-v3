<!-- resources/js/Pages/Today.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <TodayTab />
    </main>
  </div>
</template>

<script setup>
import { onMounted } from "vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import TodayTab from "@/components/tabs/TodayTab.vue";
import { prefetchCurrentWeek } from "@/state/weekCache";

// ★ KeepAlive include にマッチさせる
defineOptions({ name: "TodayPage" });

function idle(fn) {
  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(() => fn());
  } else {
    setTimeout(() => fn(), 200);
  }
}

onMounted(() => {
  // ★ 初回切り替えの体感を減らす：今日を開いたら週を裏で先読み
  idle(() => prefetchCurrentWeek());
});
</script>