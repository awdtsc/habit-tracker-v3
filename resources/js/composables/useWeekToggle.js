// resources/js/composables/useWeekToggle.js
import api from "@/axios";

/**
 * daysRef の想定構造：
 * daysRef.value = [
 *  { date: "2025-12-19", habits: [ {id,title,evaluation_type,log:{status,rating,...}} ... ] },
 *  ...
 * ]
 */
export function useWeekToggle(daysRef) {
    // date + habitId ごとに「最新のトグル」だけを有効にする
    const requestVersion = new Map();

    function makeKey(date, habitId) {
        return `${date}:${habitId}`;
    }

    function findHabitInDay(date, habitId) {
        const day = daysRef.value.find((d) => d.date === date);
        if (!day) return null;
        const habit = day.habits.find((h) => h.id === habitId);
        if (!habit) return null;
        return { day, habit };
    }

    function ensureLog(habit) {
        if (!habit.log) {
            habit.log = { status: "none", rating: null, checked_at: null };
        }
    }

    // ★重要：並び順を変えない（sort/filterで配列を差し替えない）
    function applyLocalPatch(date, habitId, patch) {
        const hit = findHabitInDay(date, habitId);
        if (!hit) return;
        const { habit } = hit;

        ensureLog(habit);
        if ("status" in patch) habit.log.status = patch.status;
        if ("rating" in patch) habit.log.rating = patch.rating;
        if ("checked_at" in patch) habit.log.checked_at = patch.checked_at;
    }

    /**
     * 完了/未完了トグル（週タブ想定）
     * - scope は controller 仕様で required なので週は all 固定推奨
     */
    async function toggleComplete({ date, habit }) {
        const key = makeKey(date, habit.id);
        const v = (requestVersion.get(key) ?? 0) + 1;
        requestVersion.set(key, v);

        // 現在値（rollback用）
        const before = {
            status: habit.log?.status ?? "none",
            rating: habit.log?.rating ?? null,
            checked_at: habit.log?.checked_at ?? null,
        };

        const isDone = before.status === "done";
        const nextStatus = isDone ? "none" : "done";

        // SELF の場合：トグルで完了にするなら rating=4 を送る／戻すなら rating=0 で明示的に戻す
        const payload = { date, scope: "all" };
        if (habit.evaluation_type === "self") {
            payload.rating = isDone ? 0 : 4;
            // status も一応送る（両対応）
            payload.status = isDone ? "none" : "done";
        } else {
            payload.status = nextStatus;
            payload.rating = null;
        }

        // optimistic（ここで UI 反映。★並び替えないこと）
        applyLocalPatch(date, habit.id, {
            status: payload.status ?? nextStatus,
            rating: "rating" in payload ? payload.rating : null,
            checked_at: new Date().toISOString(),
        });

        try {
            const { data } = await api.post(
                // ★修正：baseURL(/api/v1) に乗せる。/api を二重にしない
                `/habits/${habit.id}/toggle`,
                payload
            );

            // 最新以外は捨てる（競合でのチラつき防止）
            if (requestVersion.get(key) !== v) return;

            // controller は data.habit.log に返してるのでそこを見る
            const log = data?.habit?.log;

            if (log) {
                applyLocalPatch(date, habit.id, {
                    status: log.status,
                    rating: log.rating,
                    checked_at: log.checked_at,
                });
            }
        } catch (e) {
            if (requestVersion.get(key) !== v) return;

            // rollback
            applyLocalPatch(date, habit.id, before);
            throw e;
        }
    }

    return { toggleComplete };
}
