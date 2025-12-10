// resources/js/composables/useTodayLoader.js
//------------------------------------------------------------
// Today API を一括でロードし、UI に必要な状態だけ expose する。
// TodayTab.vue を薄くするための v3 正式仕様。
//------------------------------------------------------------

import { ref } from 'vue'
import api from '@/axios'

export function useTodayLoader() {
  // -------------------------
  // 状態
  // -------------------------
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

      // -------------------------
      // TodayService::buildTodayPayload()
      // {
      //   today,
      //   now_slot,
      //   habits: [],
      //   progress: {},
      //   top_pick: {}
      // }
      // -------------------------
      today.value   = data.today ?? ''
      nowSlot.value = data.now_slot ?? null
      habits.value  = data.habits ?? []

      // progress
      if (data.progress) {
        progress.value = {
          planned_count: data.progress.planned_count ?? 0,
          done_count: data.progress.done_count ?? 0,
          completion_rate: data.progress.completion_rate ?? 0,
        }
      } else {
        // fallback（progress がない場合はローカル計算）
        const total = habits.value.length
        const done = habits.value.filter(h => h?.log?.status === 'done').length
        progress.value = {
          planned_count: total,
          done_count: done,
          completion_rate: total > 0 ? done / total : 0,
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


  // -------------------------
  // 外部から API を再取得するための refresh()
  // -------------------------
  async function refresh() {
    await fetchToday()
  }

  // -------------------------
  // 初回ロード
  // -------------------------
  fetchToday()


  // -------------------------
  // 呼び出し側へ返す
  // -------------------------
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
