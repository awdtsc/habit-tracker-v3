<!-- resources/js/components/layout/AppHeader.vue -->
<template>
  <header class="px-4 md:px-6 py-4 border-b bg-white">
    <div class="max-w-5xl mx-auto flex items-center justify-between">
      <div class="font-semibold text-gray-900">
        Habit Tracker
      </div>

      <nav class="flex items-center gap-2">
        <RouterLink
          to="/today"
          class="px-3 py-1.5 rounded-lg text-sm border"
          :class="isToday ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'"
        >
          今日
        </RouterLink>

        <RouterLink
          to="/week"
          class="px-3 py-1.5 rounded-lg text-sm border"
          :class="isWeek ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'"
        >
          週
        </RouterLink>
      </nav>
    </div>
  </header>
</template>

<script setup>
import { computed } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();

const isToday = computed(() => route.path.startsWith("/today"));
const isWeek = computed(() => route.path.startsWith("/week"));
</script>