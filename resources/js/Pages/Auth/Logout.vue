<template>
  <div class="p-8 text-center text-gray-700">
    ログアウトしています…
  </div>
</template>

<script setup>
import axios from '@/axios'
import { useRouter } from 'vue-router'

// router 内部の cachedUser を参照するため、router ファイルから組み込み export を使う
import { clearCachedUser } from '@/router/index'

const router = useRouter()

async function doLogout() {
  try {
    await axios.post('/api/logout')

    // SPA 内のユーザ状態をクリア
    clearCachedUser()

    router.push('/login')
  } catch (e) {
    console.error('[logout] failed:', e)
    router.push('/login')
  }
}

doLogout()
</script>