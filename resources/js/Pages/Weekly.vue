<!-- resources/js/Pages/Weekly.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <div v-if="!ready" class="p-4 md:p-6 text-sm text-gray-500">
        読み込み中…
      </div>
      <WeekTab v-else />
    </main>
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import WeekTab from "@/components/tabs/WeekTab.vue";

defineOptions({ name: "WeeklyPage" });

const ready = ref(false);

onMounted(() => {
  requestAnimationFrame(() => {
    ready.value = true;
  });
});
</script>
