<!-- resources/js/Pages/Auth/Login.vue -->
<template>
  <div class="min-h-screen bg-[#f7f9fc] flex items-center justify-center px-6">
    <div class="w-full max-w-md bg-white shadow-xl rounded-2xl p-8">

      <h1 class="text-2xl font-bold text-gray-800 mb-6">ログイン</h1>

      <!-- メールアドレス -->
      <div class="mb-4">
        <label class="block text-sm text-gray-700 mb-1">メールアドレス</label>
        <input
          v-model.trim="email"
          type="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="mb-6">
        <label class="block text-sm text-gray-700 mb-1">パスワード</label>
        <input
          v-model.trim="password"
          type="password"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- ログインボタン -->
      <button
        @click="submit"
        class="w-full py-3 bg-[#f7931a] text-white font-bold rounded-full hover:bg-[#e7840f] transition mb-6"
      >
        次へ
      </button>

      <button @click="goRegister" class="text-sm text-[#f7931a] hover:underline">
        ユーザー登録
      </button>

    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api, { initCsrf } from '@/axios'
import { useRouter } from 'vue-router'

const email = ref('')
const password = ref('')
const router = useRouter()

async function submit() {
  if (!email.value || !password.value) {
    alert('メールアドレスとパスワードを入力してください')
    return
  }

  try {
    console.log('[login] start')

    // ------------------------------------------------------------
    // ① CSRF Cookie（相対パスで OK。環境依存を避ける）
    // ------------------------------------------------------------
    await initCsrf()

    // ------------------------------------------------------------
    // ② /api/login（axios 共通設定を利用）
    // ------------------------------------------------------------
    await api.post('/login', {
      email: email.value,
      password: password.value,
    })

    console.info('[login] success')

    // ------------------------------------------------------------
    // ③ /api/user を強制取得（session race を防ぐ）
    // ------------------------------------------------------------
    try {
      await api.get('/user')
    } catch {
      console.warn('[login] /user failed (but session may still be valid)')
    }

    // ------------------------------------------------------------
    // ④ リダイレクト処理
    // ------------------------------------------------------------
    const redirect = router.currentRoute.value.query.redirect || '/today'
    router.push(redirect)

  } catch (e) {
    console.error('[login error]', e)

    if (e.response?.status === 401) {
      alert('メールまたはパスワードが違います')
    } else {
      alert('ログイン中にエラーが発生しました')
    }
  }
}

function goRegister() {
  router.push('/register')
}
</script>