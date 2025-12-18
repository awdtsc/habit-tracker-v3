// resources/js/composables/useHabitToggle.js
//------------------------------------------------------------
import api from "@/axios";

export function useHabitToggle() {
    // habit.id ごとに最新リクエスト番号を管理
    const requestVersion = new Map();

    async function toggle(habit, payload = {}) {
        // ----------------------------------
        // version 採番（race condition 防止）
        // ----------------------------------
        const version = (requestVersion.get(habit.id) ?? 0) + 1;
        requestVersion.set(habit.id, version);

        // ----------------------------------
        // log 初期化
        // ----------------------------------
        if (!habit.log) {
            habit.log = {
                id: null,
                habit_id: habit.id,
                time_slot: habit.time_slot ?? 0,
                status: "none",
                rating: null,
                checked_at: null,
            };
        }

        // rollback 用に完全コピー
        const prevHabit = JSON.parse(JSON.stringify(habit));

        // ----------------------------------
        // ★ 楽観的更新（UI 即時反映）
        // ----------------------------------
        if (payload.status !== undefined) {
            habit.log.status = payload.status;
        }

        if (payload.rating !== undefined) {
            habit.log.rating = payload.rating;
        }

        habit.log.checked_at = new Date().toISOString();

        // ----------------------------------
        // API 送信データ
        // ----------------------------------
        const postData = {
            status: payload.status ?? habit.log.status,
            rating: payload.rating ?? habit.log.rating,
            scope: payload.scope,
        };

        try {
            const { data } = await api.post(
                `/habits/${habit.id}/toggle`,
                postData
            );

            // ----------------------------------
            // ★ 最新リクエストのみ反映
            // ----------------------------------
            if (requestVersion.get(habit.id) !== version) {
                return null;
            }

            if (data?.habit) {
                // habit 本体を上書き
                Object.assign(habit, data.habit);

                // log は必ず backend 真実で再構築
                habit.log = { ...data.habit.log };
            }

            // 呼び出し元（useTodayLoader）へ返す
            return {
                habit,
                log: habit.log,
                progress: data.progress ?? null,
            };
        } catch (e) {
            // ----------------------------------
            // ★ 最新リクエストのみ rollback
            // ----------------------------------
            if (requestVersion.get(habit.id) === version) {
                Object.assign(habit, prevHabit);
            }
            throw e;
        }
    }

    return { toggle };
}
