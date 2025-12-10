// resources/js/composables/useTodayFilters.js
//------------------------------------------------------------
// Today の仕分け（v3 仕様）
//   - habit.log.status を使用
//   - time_slot: 1=朝, 2=昼, 3=夕, 4=夜, 0/NULL=いつでも
//   - progress は計算しない（API側の責務）
//------------------------------------------------------------

import { computed } from 'vue'

export function useTodayFilters(habits) {

  // --- 共通: 完了判定 ---
  const isDone = (h) => h.log?.status === 'done'

  // --- 朝（1） ---
  const morningHabits = computed(() =>
    habits.value.filter(h => Number(h.time_slot) === 1 && !isDone(h))
  )

  // --- 昼（2） ---
  const noonHabits = computed(() =>
    habits.value.filter(h => Number(h.time_slot) === 2 && !isDone(h))
  )

  // --- 夕方（3） ---
  const eveningHabits = computed(() =>
    habits.value.filter(h => Number(h.time_slot) === 3 && !isDone(h))
  )

  // --- 夜（4） ---
  const nightHabits = computed(() =>
    habits.value.filter(h => Number(h.time_slot) === 4 && !isDone(h))
  )

  // --- いつでも（0 or null） ---
  const anytimeHabits = computed(() =>
    habits.value.filter(h =>
      (!h.time_slot || Number(h.time_slot) === 0) && !isDone(h)
    )
  )

  // --- 完了 ---
  const doneHabits = computed(() =>
    habits.value.filter(h => isDone(h))
  )

  return {
    morningHabits,
    noonHabits,
    eveningHabits,
    nightHabits,
    anytimeHabits,
    doneHabits,
  }
}