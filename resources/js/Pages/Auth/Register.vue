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
          v-model="name"
          type="text"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- メール -->
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

      <!-- 登録ボタン -->
      <button
        @click="submit"
        class="w-full py-3 bg-[#2b6cb0] text-white font-bold rounded-full hover:bg-[#1e4e8c] transition mb-6"
      >
        登録する
      </button>

      <!-- 戻るリンク -->
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

const name = ref('')
const email = ref('')
const password = ref('')

const router = useRouter()

async function submit() {
  try {
    const res = await axios.post('/register', {
      name: name.value,
      email: email.value,
      password: password.value,
    })

    console.info('[register] registered OK:', res.data)

    // すでに自動ログイン済 → /today に送る
    router.push('/today')

  } catch (e) {
    if (e.response?.status === 422) {
      console.warn('[register] validation error:', e.response.data)
    } else {
      console.error('[register] failed:', e)
    }
  }
}

function goLogin() {
  router.push('/login')
}
</script>