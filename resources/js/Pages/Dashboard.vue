<!-- resources/js/pages/Dashboard.vue -->
<script setup>
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="p-8">
    <h1 class="text-2xl font-bold mb-4">Dashboard</h1>

    <div v-if="auth.user" class="text-lg">
      ようこそ、{{ auth.user.name }} さん！
    </div>

    <div v-else class="text-gray-500">
      ロード中…
    </div>
  </div>
</template>