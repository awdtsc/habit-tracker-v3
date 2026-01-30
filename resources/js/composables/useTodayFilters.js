// resources/js/composables/useTodayFilters.js
import { computed } from "vue";

/**
 * @param {Ref<Array>} habits
 * @param {Ref<Object>} progress
 * @param {Ref<number|string>} activeSlot  // 1..4 想定（0/all の場合も考慮）
 */
export function useTodayFilters(habits, progress, activeSlot) {
    const safeList = (list) => (Array.isArray(list) ? list : []);

    const isDone = (h) => {
        const type = h?.evaluation_type ?? "simple";
        const rating = h?.log?.rating ?? null;
        const status = h?.log?.status ?? "none";

        if (type === "self") return Number(rating ?? 0) === 4;
        return status === "done";
    };

    const slotNum = computed(() => {
        const n = Number(activeSlot.value);
        return Number.isFinite(n) ? n : 0;
    });

    // ---------- Actionable ----------
    // 今スロットで「着手可能（= 今 or 過去の未完）」 + anytime（未完）を拾う
    // backend が actionable を返している場合はそれを優先
    const actionable = computed(() => {
        const list = safeList(habits.value);
        const slot = slotNum.value;

        return list.filter((h) => {
            if (isDone(h)) return false;

            const s = Number(h?.time_slot ?? 0);

            // anytime（0）は常に表示（未完のみ）
            if (s === 0) return true;

            // backendの actionable を優先
            if (typeof h?.actionable === "boolean") {
                // 「今スロット以降」に絞りたいなら s===slot を足す、など調整可
                return h.actionable === true && s <= slot;
            }

            // fallback: 過去〜現在の未完を actionable 扱い
            return s <= slot;
        });
    });

    // ---------- NextSlot ----------
    // 未来の未完（anytime=0 は除外）
    const nextSlot = computed(() => {
        const list = safeList(habits.value);
        const slot = slotNum.value;

        return list.filter((h) => {
            if (isDone(h)) return false;
            const s = Number(h?.time_slot ?? 0);
            if (s === 0) return false;
            return s > slot;
        });
    });

    // ---------- Done ----------
    // 現在スロットの完了 + anytime 完了（「all」用途ならここを全件doneにしてもOK）
    const doneHabits = computed(() => {
        const list = safeList(habits.value);
        const slot = slotNum.value;

        return list.filter((h) => {
            if (!isDone(h)) return false;
            const s = Number(h?.time_slot ?? 0);

            // anytime done は表示
            if (s === 0) return true;

            // slot=0(all扱い)なら全部のdone
            if (slot === 0) return true;

            // それ以外は「今見ているスロットのdone」
            return s === slot;
        });
    });

    // ---------- Progress ----------
    // 新: { done, total, percent } / 旧: { done_count, planned_count } の両対応
    const progressPct = computed(() => {
        const p = progress.value ?? {};

        if (Number.isFinite(Number(p.percent))) {
            return Number(p.percent);
        }

        // 新型 fallback
        if (Number.isFinite(Number(p.total)) && Number(p.total) > 0) {
            return Math.round((Number(p.done ?? 0) / Number(p.total)) * 100);
        }

        // 旧型 fallback
        if (
            Number.isFinite(Number(p.planned_count)) &&
            Number(p.planned_count) > 0
        ) {
            return Math.round(
                (Number(p.done_count ?? 0) / Number(p.planned_count)) * 100
            );
        }

        return 0;
    });

    return {
        actionable,
        nextSlot,
        doneHabits,
        progressPct,
    };
}
