<!-- resources/js/Pages/Today.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <div v-if="!ready" class="p-4 md:p-6 text-sm text-gray-500">
        読み込み中…
      </div>
      <TodayTab v-else />
    </main>
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import TodayTab from "@/components/tabs/TodayTab.vue";
import { prefetchCurrentWeek } from "@/state/weekCache";

defineOptions({ name: "TodayPage" });

const ready = ref(false);

onMounted(() => {
  // 先に1回ペイントさせる（＝URL切替直後の白画面体感を減らす）
  requestAnimationFrame(() => {
    ready.value = true;
  });

  // prefetchは“描画の後”へ（競合させない）
  requestAnimationFrame(() => {
    if ("requestIdleCallback" in window) {
      window.requestIdleCallback(() => prefetchCurrentWeek().catch(() => {}), { timeout: 900 });
    } else {
      setTimeout(() => prefetchCurrentWeek().catch(() => {}), 350);
    }
  });
});
</script>
