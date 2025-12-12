// resources/js/utils/habitPriority.js

import { SLOT_ENUM, isSlotKey } from "@/utils/slot";

/**
 * Habit 優先度計算（v1）
 *
 * 優先順位ルール：
 * 1. 現在のスロットに一致
 * 2. 現在スロットより前（取りこぼし）
 * 3. 現在スロットより後
 * 4. いつでも（time_slot = 0）
 */

/**
 * time_slot を enum（number）に正規化
 */
function normalizeSlot(slot) {
    if (slot == null) return 0;

    // number（DB / API）
    if (typeof slot === "number") return slot;

    // string key（UI）
    if (typeof slot === "string" && isSlotKey(slot)) {
        return SLOT_ENUM[slot.toUpperCase()] ?? 0;
    }

    return 0;
}

/**
 * 優先度スコアを返す
 */
export function calcHabitPriority(habit, nowSlot) {
    const slot = normalizeSlot(habit.time_slot);

    // nowSlot 未確定時（保険）
    if (!nowSlot) {
        return slot === 0 ? 10 : 50;
    }

    // いつでも（最低）
    if (slot === 0) return 10;

    // 現在スロット
    if (slot === nowSlot) return 100;

    // 過去スロット（取りこぼし）
    if (slot < nowSlot) return 80;

    // 未来スロット
    return 40;
}

/**
 * 習慣配列を優先度順に並べ替える
 */
export function sortHabitsByPriority(habits, nowSlot) {
    return habits.slice().sort((a, b) => {
        const pa = calcHabitPriority(a, nowSlot);
        const pb = calcHabitPriority(b, nowSlot);

        if (pa !== pb) return pb - pa;

        // 安定ソート
        return a.id - b.id;
    });
}
