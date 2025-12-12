// resources/js/composables/useHabitToggle.js
//------------------------------------------------------------
import api from "@/axios";

export function useHabitToggle(today) {
    // ★ habit.id ごとに最新のトグル呼び出し番号を管理
    const requestVersion = new Map();

    async function toggle(habit, payload = {}) {
        // ★ 新しい呼び出し番号（version）を採番
        const version = (requestVersion.get(habit.id) ?? 0) + 1;
        requestVersion.set(habit.id, version);

        // log がなければ初期化
        if (!habit.log) {
            habit.log = {
                id: null,
                habit_id: habit.id,
                date: today.value,
                time_slot: habit.time_slot ?? 0,
                status: "none",
                rating: null,
                checked_at: null,
            };
        }

        // ロールバック用にディープコピー（安全）
        const prevHabit = JSON.parse(JSON.stringify(habit));

        // -------------------------------
        // ★ 楽観的更新（即時 UI 反映）
        // -------------------------------
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

            // ★ バージョンチェック：
            //    このレスポンスが最新のトグルなら UI に反映。古いものは無視。
            if (data?.habit) {
                if (requestVersion.get(habit.id) === version) {
                    // habit 本体を更新
                    Object.assign(habit, data.habit);

                    // log を安全に再構築
                    habit.log = { ...data.habit.log };
                }
            }

            return { habit, log: habit.log };
        } catch (e) {
            // ★ エラー時も「これが最新のリクエスト」だけロールバック
            if (requestVersion.get(habit.id) === version) {
                Object.assign(habit, prevHabit);
            }

            throw e;
        }
    }

    return { toggle };
}
