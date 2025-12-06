<!-- resources/js/Pages/Auth/Logout.vue -->
<template>
  <div class="p-8 text-center text-gray-700">
    ログアウトしています…
  </div>
</template>

<script setup>
import axios from '@/axios'
import { useRouter } from 'vue-router'

const router = useRouter()

// ページ表示と同時にログアウト処理開始
logout()

async function logout() {
  try {
    await axios.post('/api/logout')   // ← 後で /logout に変更予定
    console.info('[logout] OK')
  } catch (e) {
    console.error('[logout] failed', e)
  }

  // ルーターガードが未認証を検出して自動で /login に飛ばす
  router.push('/login')
}
</script>