<template>
  <div class="p-8 text-center text-gray-700">
    ログアウトしています…
  </div>
</template>

<script setup>
import axios from '@/axios'
import { useRouter } from 'vue-router'

// router 内部の auth キャッシュをクリアする公式関数
import { clearAuthState } from '@/router/index'

const router = useRouter()

async function doLogout() {
  try {
    await axios.post('/api/logout')

    // SPA のログイン状態キャッシュを完全クリア
    clearAuthState()

    router.push('/login')
  } catch (e) {
    console.error('[logout] failed:', e)
    // 万が一失敗しても login へ
    router.push('/login')
  }
}

doLogout()
</script>