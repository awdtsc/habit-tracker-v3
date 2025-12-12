// resources/js/composables/useTodayFilters.js
import { computed } from 'vue'

export function useTodayFilters(habits, progress, activeSlot) {

  const safeList = (list) => Array.isArray(list) ? list : []

  const isDone = (h) => h?.log?.status === 'done'

  // ---------- Actionable ----------
  const actionable = computed(() => {
    const list = safeList(habits.value)
    const slot = Number(activeSlot.value)

    return list.filter(h => {
      const s = Number(h.time_slot ?? 0)

      // anytime
      if (s === 0) return !isDone(h)

      // slot match
      return !isDone(h) && s === slot
    })
  })

  // ---------- NextSlot ----------
  const nextSlot = computed(() => {
    const list = safeList(habits.value)
    const slot = Number(activeSlot.value)

    return list.filter(h => {
      const s = Number(h.time_slot ?? 0)
      return !isDone(h) && s > slot
    })
  })

  // ---------- Done ----------
  const doneHabits = computed(() => {
    const list = safeList(habits.value)
    return list.filter(h => isDone(h))
  })

  // ---------- Progress ----------
  const progressPct = computed(() => {
    if (!progress.value?.planned_count) return 0
    return Math.round(
      (progress.value.done_count / progress.value.planned_count) * 100
    )
  })

  return {
    actionable,
    nextSlot,
    doneHabits,
    progressPct,
  }
}