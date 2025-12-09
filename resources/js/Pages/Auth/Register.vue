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
          v-model.trim="name"
          type="text"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- メール -->
      <div class="w-full mb-6">
        <label class="block text-sm text-gray-700 mb-1">メールアドレス</label>
        <input
          v-model.trim="email"
          type="email"
          class="w-full px-4 py-3 bg-[#e6efff] rounded-xl outline-none focus:ring-2 focus:ring-blue-300 text-gray-700"
        />
      </div>

      <!-- パスワード -->
      <div class="w-full mb-8">
        <label class="block text-sm text-gray-700 mb-1">パスワード</label>
        <input
          v-model.trim="password"
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

      <button @click="goLogin" class="text-sm text-[#2b6cb0] hover:underline">
        ログインに戻る
      </button>
    </div>

  </div>
</template>

<script setup>
import { ref } from 'vue'
import api, { initCsrf } from '@/axios'
import { useRouter } from 'vue-router'

const name = ref('')
const email = ref('')
const password = ref('')

const router = useRouter()

async function submit() {
  if (!name.value || !email.value || !password.value) {
    alert('全ての項目を入力してください')
    return
  }

  try {
    console.log('[register] start')

    // ------------------------------------------------------------
    // ① CSRF Cookie
    // ------------------------------------------------------------
    await initCsrf()

    // ------------------------------------------------------------
    // ② /api/register
    // ------------------------------------------------------------
    await api.post('/register', {
      name: name.value,
      email: email.value,
      password: password.value,
    })

    console.info('[register] success')

    // ------------------------------------------------------------
    // ③ 自動ログイン直後は /api/user が 401 になる事がある
    //    → router.beforeEach の判断とずれるのを防ぐため 1 回だけ確認
    // ------------------------------------------------------------
    try {
      await api.get('/user')
    } catch {
      console.warn('[register] /user not ready yet (will be retried by router)')
    }

    // ------------------------------------------------------------
    // ④ /today へ遷移（redirect も考慮）
    // ------------------------------------------------------------
    const redirect = router.currentRoute.value.query.redirect || '/today'
    router.push(redirect)

  } catch (e) {
    console.error('[register error]', e)

    if (e.response?.status === 422) {
      alert('入力内容に誤りがあります')
    } else {
      alert('登録に失敗しました')
    }
  }
}

function goLogin() {
  router.push('/login')
}
</script>
