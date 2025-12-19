<!-- resources/js/Pages/Weekly.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <WeekTab />
    </main>
  </div>
</template>

<script setup>
defineOptions({ name: "WeeklyPage" });

import AppHeader from "@/components/layout/AppHeader.vue";
import WeekTab from "@/components/tabs/WeekTab.vue";
</script>