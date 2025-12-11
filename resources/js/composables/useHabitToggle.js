// resources/js/composables/useHabitToggle.js
//------------------------------------------------------------
import api from '@/axios';

export function useHabitToggle(today) {

  async function toggle(habit, payload = {}) {

    if (!habit.log) {
      habit.log = {
        id: null,
        status: 'none',
        rating: null,
        checked_at: null,
      };
    }

    const prevLog = JSON.parse(JSON.stringify(habit.log));

    // 楽観更新
    if (payload.status !== undefined) {
      habit.log.status = payload.status;
    }
    if (payload.rating !== undefined) {
      habit.log.rating = payload.rating;
    }
    habit.log.checked_at = new Date().toISOString();

    const postData = {
      date: today.value,
      status: payload.status ?? habit.log.status,
      rating: payload.rating ?? habit.log.rating,
    };

    try {
      const { data } = await api.post(`/habits/${habit.id}/toggle`, postData);

      if (data?.log) {
        // 確実に reactivity を発火させる書き方
        habit.log = {
          id: data.log.id,
          status: data.log.status,
          rating: data.log.rating,
          checked_at: data.log.checked_at,
        };
      }

      // ★ ここ重要：結果を返す
      return { log: habit.log };

    } catch (e) {
      habit.log = prevLog;
      throw e;
    }
  }

  return { toggle };
}
