<!-- resources/js/Pages/Auth/Logout.vue -->
<template>
  <div class="min-h-screen flex items-center justify-center text-gray-600 text-lg">
    ログアウトしています…
  </div>
</template>

<script setup>
import api from '@/axios'
import { useRouter } from 'vue-router'

const router = useRouter()

logout()

async function logout() {
  try {
    await api.post('/logout')
    console.info('[logout] OK')
  } catch (e) {
    console.error('[logout error]', e)
  }

  // 即座に /login へ遷移（ここはお好み）
  router.push('/login')
}
</script>