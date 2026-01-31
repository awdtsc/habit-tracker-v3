<!-- resources/js/Pages/Today.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <div v-if="!ready" class="p-4 md:p-6 text-sm text-gray-500">
        読み込み中…
      </div>

      <TodayTab v-else :refresh-key="refreshKey" />
    </main>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import TodayTab from "@/components/tabs/TodayTab.vue";
import { prefetchCurrentWeek } from "@/state/weekCache";

defineOptions({ name: "TodayPage" });

const ready = ref(false);
const refreshKey = ref(0);

function bumpRefresh() {
  refreshKey.value++;
}

const handler = (e) => {
  const d = e?.detail;
  if (!d) return;
  bumpRefresh();
};

onMounted(() => {
  // ★rAFが止まっても必ず表示されるように fallback を入れる
  let painted = false;

  requestAnimationFrame(() => {
    painted = true;
    ready.value = true;
  });

  setTimeout(() => {
    if (!painted) ready.value = true;
  }, 80);

  // prefetchは“描画の後”へ（競合させない）
  requestAnimationFrame(() => {
    if ("requestIdleCallback" in window) {
      window.requestIdleCallback(() => prefetchCurrentWeek().catch(() => {}), { timeout: 900 });
    } else {
      setTimeout(() => prefetchCurrentWeek().catch(() => {}), 350);
    }
  });

  window.addEventListener("reminder-action", handler);
});

onUnmounted(() => {
  window.removeEventListener("reminder-action", handler);
});
</script>