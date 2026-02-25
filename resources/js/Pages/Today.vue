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
import { onReminderAction } from "@/state/reminderActionBus";

defineOptions({ name: "TodayPage" });

const ready = ref(false);
const refreshKey = ref(0);

function bumpRefresh() {
  refreshKey.value++;
}

function pickActionType(d) {
  // ★表記ゆれ吸収（どれかに入ってればOK）
  return d?.type ?? d?.action ?? d?.kind ?? d?.event ?? null;
}

let offAction = null;

onMounted(() => {
  let painted = false;

  requestAnimationFrame(() => {
    painted = true;
    ready.value = true;
  });

  setTimeout(() => {
    if (!painted) ready.value = true;
  }, 80);

  requestAnimationFrame(() => {
    if ("requestIdleCallback" in window) {
      window.requestIdleCallback(() => prefetchCurrentWeek().catch(() => {}), { timeout: 900 });
    } else {
      setTimeout(() => prefetchCurrentWeek().catch(() => {}), 350);
    }
  });

  // ✅ 正：reminderActionBus で同期
  // 方針：done のときだけ同期（ただし type 欠落は「同期してOK」扱い）
  offAction = onReminderAction((evt) => {
    const d = evt ?? null;
    if (!d) return;

    const t = pickActionType(d);
    if (t != null && t !== "done") return;

    bumpRefresh();
  });
});

onUnmounted(() => {
  if (typeof offAction === "function") offAction();
  offAction = null;
});
</script>