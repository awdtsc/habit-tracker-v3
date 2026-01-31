<!-- resources/js/Pages/Weekly.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <div v-if="!ready" class="p-4 md:p-6 text-sm text-gray-500">
        読み込み中…
      </div>
      <WeekTab v-else :refresh-key="refreshKey" />
    </main>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import WeekTab from "@/components/tabs/WeekTab.vue";

defineOptions({ name: "WeeklyPage" });

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
  let painted = false;

  requestAnimationFrame(() => {
    painted = true;
    ready.value = true;
  });

  setTimeout(() => {
    if (!painted) ready.value = true;
  }, 80);

  window.addEventListener("reminder-action", handler);
});

onUnmounted(() => {
  window.removeEventListener("reminder-action", handler);
});
</script>
