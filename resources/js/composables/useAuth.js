import { ref } from 'vue'
import axios from '@/axios'

const user = ref(null)
const loading = ref(true)

export function useAuth() {

  async function fetchUser() {
    try {
      const res = await axios.get('/api/user')
      user.value = res.data
    } catch {
      user.value = null
    } finally {
      loading.value = false
    }
  }

  async function login(form) {
    await axios.get('/sanctum/csrf-cookie')
    await axios.post('/login', form)
    await fetchUser()
  }

  async function logout() {
    await axios.post('/logout')
    user.value = null
  }

  return {
    user,
    loading,
    fetchUser,
    login,
    logout,
  }
}
