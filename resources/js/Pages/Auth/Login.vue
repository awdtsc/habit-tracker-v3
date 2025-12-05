<template>
  <div class="min-h-screen bg-[#f7f7f7] flex flex-col items-center justify-center px-4">

    <h1 class="text-3xl font-bold text-[#2b6cb0] mb-10">
      ハビットトラッカー
    </h1>

    <div
      class="w-full max-w-md bg-[#e8ecf1] rounded-2xl shadow-md p-10 flex flex-col items-center"
    >
      <h2 class="text-xl font-bold text-gray-700 mb-8">ログイン</h2>

      <!-- メールアドレス -->
      <div class="w-full mb-6">
        <label class="block text-sm text-gray-700 mb-1">メールアドレス</label>
        <input
          v-model="email"
          type="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="w-full mb-8">
        <label class="block text-sm text-gray-700 mb-1">パスワード</label>
        <input
          v-model="password"
          type="password"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- ボタン -->
      <button
        @click="submit"
        class="w-full py-3 bg-[#f7931a] text-white font-bold rounded-full hover:bg-[#e7840f] transition mb-6"
      >
        次へ
      </button>

      <!-- 下部リンク -->
      <button @click="goRegister" class="text-sm text-[#f7931a] hover:underline">
        ユーザー登録
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@/axios'
import { useRouter } from 'vue-router'

const email = ref('')
const password = ref('')
const router = useRouter()

async function submit() {
  try {
    // ★1: axios に CSRF 初期化は全て任せる（手動呼び出し禁止）
    const res = await axios.post('/api/login', {
      email: email.value,
      password: password.value,
    })

    console.info('[login] login OK:', res.data)

    // ★2: SPA の唯一の「信頼できる認証状態」を同期 → /api/user
    const userRes = await axios.get('/api/user')
    console.info('[login] fetched user:', userRes.data)

    // ★3: トップへ遷移
    router.push('/')
  } catch (e) {
    if (e.response?.status === 401) {
      console.warn('[login] invalid credentials')
    } else if (e.response?.status === 422) {
      console.warn('[login] validation error')
    }
    console.error(e)
  }
}

function goRegister() {
  router.push('/register')
}
</script>