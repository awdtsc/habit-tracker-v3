<!-- resources/js/Pages/Auth/Register.vue -->
<template>
  <div class="min-h-screen bg-[#f7f7f7] flex flex-col items-center justify-center px-4">

    <h1 class="text-3xl font-bold text-[#2b6cb0] mb-10">
      ハビットトラッカー
    </h1>

    <div class="w-full max-w-md bg-[#e8ecf1] rounded-2xl shadow-md p-10 flex flex-col items-center">

      <h2 class="text-xl font-bold text-gray-700 mb-8">ユーザー登録</h2>

      <!-- 名前 -->
      <div class="w-full mb-6">
        <label class="block text-sm text-gray-700 mb-1">名前</label>
        <input
          v-model="form.name"
          type="text"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- メール -->
      <div class="w-full mb-6">
        <label class="block text-sm text-gray-700 mb-1">メールアドレス</label>
        <input
          v-model="form.email"
          type="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="w-full mb-6">
        <label class="block text-sm text-gray-700 mb-1">パスワード</label>
        <input
          v-model="form.password"
          type="password"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード確認 -->
      <div class="w-full mb-8">
        <label class="block text-sm text-gray-700 mb-1">パスワード（確認）</label>
        <input
          v-model="form.password_confirmation"
          type="password"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- 登録ボタン -->
      <button
        @click="submit"
        class="w-full py-3 bg-[#2b6cb0] text-white font-bold rounded-full hover:bg-[#1e4e8c] transition mb-6"
      >
        登録する
      </button>

      <!-- エラーメッセージ -->
      <p v-if="error" class="w-full text-center text-red-600 text-sm mb-4">
        {{ error }}
      </p>

      <!-- 戻る -->
      <button @click="goLogin" class="text-sm text-[#2b6cb0] hover:underline">
        ログインに戻る
      </button>

    </div>

  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@/axios'
import { useRouter } from 'vue-router'

const router = useRouter()

const error = ref('')

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

async function submit() {
  error.value = ''

  try {
    await axios.post(
      '/register',
      form.value,
      { withCredentials: true }
    )

    console.info('[register] registered OK')

    // Breeze 同様に自動ログイン → SPA today へ
    router.push('/today')

  } catch (e) {
    if (e.response?.status === 422) {
      error.value = '入力内容を確認してください'
      console.warn('[register] validation error', e.response.data)
    } else {
      error.value = '登録に失敗しました'
      console.error('[register] failed:', e)
    }
  }
}

function goLogin() {
  router.push('/login')
}
</script>