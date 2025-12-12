// resources/js/composables/useHabitToggle.js
//------------------------------------------------------------
import api from '@/axios';

export function useHabitToggle(today) {

  async function toggle(habit, payload = {}) {

    // log がなければ初期化
    if (!habit.log) {
      habit.log = {
        id: null,
        habit_id: habit.id,
        date: today.value,
        time_slot: habit.time_slot ?? 0,
        status: 'none',
        rating: null,
        checked_at: null,
      };
    }

    const prevHabit = JSON.parse(JSON.stringify(habit));

    // ---------- 楽観更新 ----------
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
      const { data } = await api.post(
        `/habits/${habit.id}/toggle`,
        postData
      );

      // ★ 新仕様：data.habit.log が正
      if (data?.habit) {
        // habit のフィールドを UI の habit に反映
        Object.assign(habit, data.habit);

        // log だけ確実に反映
        habit.log = { ...data.habit.log };
      }

      return { habit, log: habit.log };

    } catch (e) {
      // ロールバック
      Object.assign(habit, prevHabit);
      throw e;
    }
  }

  return { toggle };
}