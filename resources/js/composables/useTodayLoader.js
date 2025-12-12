// resources/js/composables/useTodayLoader.js
//------------------------------------------------------------
// Today API を一括ロードし、UI に必要な状態だけ expose する
//------------------------------------------------------------

import { ref } from 'vue'
import api from '@/axios'

export function useTodayLoader() {

  const loading = ref(true)
  const errorMessage = ref('')

  const today = ref('')
  const nowSlot = ref(null)
  const habits = ref([])

  const progress = ref({
    planned_count: 0,
    done_count: 0,
    completion_rate: 0,
  })

  const topPick = ref(null)

  // -------------------------
  // Today API のロード
  // -------------------------
  async function fetchToday() {
    loading.value = true
    errorMessage.value = ''

    try {
      const { data } = await api.get('/today')

      today.value   = data.today ?? ''
      nowSlot.value = data.now_slot ?? null
      habits.value  = data.habits ?? []

      // -------------------------
      // ★ progress（API が返す値をそのまま使う）
      // -------------------------
      if (data.progress) {
        progress.value = {
          planned_count: data.progress.planned_count ?? 0,
          done_count: data.progress.done_count ?? 0,
          completion_rate: data.progress.completion_rate ?? 0,
        }
      } else {
        // API が progress を返さないのは仕様違反なので 0 固定にする
        progress.value = {
          planned_count: 0,
          done_count: 0,
          completion_rate: 0,
        }
      }

      topPick.value = data.top_pick ?? null

    } catch (e) {
      console.error('[useTodayLoader] Today fetch failed', e)
      errorMessage.value = '今日の習慣を取得できませんでした。'
    } finally {
      loading.value = false
    }
  }

  async function refresh() {
    await fetchToday()
  }

  fetchToday()

  return {
    loading,
    errorMessage,

    today,
    nowSlot,
    habits,
    progress,
    topPick,

    refresh,
  }
}