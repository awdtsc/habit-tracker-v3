// resources/js/utils/todayProgress.js
//------------------------------------------------------------
// TodayTab 用ユーティリティ
// - computeTabProgress: “今見てるタブ” の達成率を habits から計算（純関数）
// - computeOptimisticProgress: トグル直後の達成率を楽観更新（純関数）
//
// ★方針（重要）
// - SIMPLE: status が真実（status === "done" で完了）
// - SELF  : rating が真実（rating === 4 で完了）
//   → status は表示互換として残っていても、progress の真実には使わない
//------------------------------------------------------------

export function scopeToSlot(scope) {
    switch (scope) {
        case "morning":
            return 1;
        case "day":
            return 2;
        case "evening":
            return 3;
        case "night":
            return 4;
        default:
            return 0;
    }
}

/**
 * ★done判定の真実
 * - simple: status === "done"
 * - self  : rating === 4
 */
export function isHabitDone(habit) {
    const type = habit?.evaluation_type;
    const log = habit?.log ?? null;

    if (type === "self") {
        return Number(log?.rating ?? 0) === 4;
    }

    return (log?.status ?? "none") === "done";
}

export function calcPercent(done, total) {
    if (!total) return 0;
    return Math.round((done / total) * 100);
}

/**
 * “今見てるタブ” の progress を habits から計算（UI表示用）
 * - scope === "all" : anytime(0) 含める
 * - それ以外      : その slot のみ（anytime除外）
 *
 * @param {Array} habits
 * @param {string} scope "morning|day|evening|night|all"
 * @returns {{scope:string, done:number, total:number, percent:number}}
 */
export function computeTabProgress(habits, scope) {
    const list = Array.isArray(habits) ? habits : [];

    const targets =
        scope === "all"
            ? list
            : list.filter(
                  (h) => Number(h?.time_slot ?? 0) === scopeToSlot(scope)
              );

    const total = targets.length;
    const done = targets.filter((h) => isHabitDone(h)).length;

    return {
        scope,
        done,
        total,
        percent: calcPercent(done, total),
    };
}

/**
 * この habit が “このscopeの達成率計算” に含まれるか
 * - all: 全て含む（anytime含む）
 * - 通常: そのスロットのみ（anytime除外）
 */
export function isInProgressScope(habit, scope) {
    const slot = Number(habit?.time_slot ?? 0);

    if (scope === "all") return true;
    if (slot === 0) return false;

    return slot === scopeToSlot(scope);
}

/**
 * payload から「押下後に done になるか」を推定
 * - simple: status で決まる
 * - self  : rating===4 が真実
 *
 * ★重要
 * self で rating が来てない場合、status が来ても progress の真実は動かさない。
 * （＝“ratingが真実”を壊さないため、beforeDone を返す）
 */
export function predictAfterDone(habit, payload) {
    const beforeDone = isHabitDone(habit);
    const type = habit?.evaluation_type;

    // SELF：rating が来たときだけ真実が動く
    if (type === "self") {
        if (
            payload &&
            Object.prototype.hasOwnProperty.call(payload, "rating")
        ) {
            return Number(payload.rating) === 4;
        }
        return beforeDone;
    }

    // SIMPLE：status が来たらそれが真実
    if (payload && Object.prototype.hasOwnProperty.call(payload, "status")) {
        return payload.status === "done";
    }

    return beforeDone;
}

/**
 * progress を楽観更新する（変化なしなら null）
 *
 * @param {object} prevProgress {scope, done, total, percent}
 * @param {object} habit
 * @param {object} payload
 * @param {string} scope "morning|day|evening|night|all"
 * @returns {object|null}
 */
export function computeOptimisticProgress(prevProgress, habit, payload, scope) {
    if (!prevProgress) return null;

    if (!isInProgressScope(habit, scope)) return null;

    const beforeDone = isHabitDone(habit);
    const afterDone = predictAfterDone(habit, payload);

    if (beforeDone === afterDone) return null;

    const prevDone = Number(prevProgress.done ?? 0);
    const total = Number(prevProgress.total ?? 0);

    const nextDone = Math.max(0, prevDone + (afterDone ? 1 : -1));

    return {
        ...prevProgress,
        scope: prevProgress.scope ?? scope,
        done: nextDone,
        total,
        percent: calcPercent(nextDone, total),
    };
}
