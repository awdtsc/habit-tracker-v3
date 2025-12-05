// resources/js/stores/auth.js
import axios from '@/axios'
import { ref } from 'vue'

export const useAuthStore = () => {
  const user = ref(null)

  async function login(email, password) {
    await axios.get('/sanctum/csrf-cookie')
    const res = await axios.post('/login', {
      email,
      password,
    })
    user.value = res.data.user ?? null
  }

  return { user, login }
}