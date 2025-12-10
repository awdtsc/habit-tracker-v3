// resources/js/composables/useHabitToggle.js
//------------------------------------------------------------
// Habit の完了トグル（v3: log.status / log.rating）
//------------------------------------------------------------

import api from '@/axios'

export function useHabitToggle(today) {

  async function toggle(habit, payload = {}) {

    // log が無いなら初期化
    if (!habit.log) {
      habit.log = {
        id: null,
        status: 'none',
        rating: null,
        checked_at: null,
      }
    }

    // ★ deep copy（将来のネスト拡張にも強い）
    const prevLog = JSON.parse(JSON.stringify(habit.log))

    // --- 1) 楽観更新 ---
    let newStatus

    if (typeof payload.value === 'boolean') {
      newStatus = payload.value ? 'done' : 'none'
    } else if (payload.status) {
      newStatus = payload.status
    } else {
      newStatus = habit.log.status === 'done' ? 'none' : 'done'
    }

    habit.log.status = newStatus

    // rating の楽観更新
    if ('rating' in payload) {
      habit.log.rating = payload.rating
    }

    habit.log.checked_at = new Date().toISOString()

    try {
      // --- 2) サーバー更新 ---
      const { data } = await api.post(`/habits/${habit.id}/toggle`, {
        date: today.value,
        status: habit.log.status,
        rating: payload.rating ?? habit.log.rating ?? null,
      })

      // --- 3) サーバー値で確定同期 ---
      if (data?.habit?.log) {
        habit.log = {
          ...habit.log,   // 既存を維持
          ...data.habit.log, // サーバー値を上書き
        }
      }

    } catch (e) {
      // --- 4) エラー → 元に戻す ---
      habit.log = prevLog
      throw e
    }
  }

  return { toggle }
}