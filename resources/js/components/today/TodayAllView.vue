<!-- resources/js/components/today/TodayAllView.vue -->
<template>
    <div class="space-y-10">
        <!-- 未完了 -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">未完了</h2>

            <TodayActionableSection
                v-if="undoneSorted.length > 0"
                :habits="undoneSorted"
                :today="today"
                @update="onRowUpdate"
            />

            <p v-else class="text-sm text-gray-500 pl-1">
                未完了の習慣はありません。
            </p>
        </section>

        <!-- 完了 -->
        <section v-if="doneSorted.length > 0" class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-700">完了</h2>

            <TodayDoneSection
                :habits="doneSorted"
                :today="today"
                @update="onRowUpdate"
            />
        </section>
    </div>
</template>

<script setup>
import { computed } from "vue";

import TodayActionableSection from "@/components/today/TodayActionableSection.vue";
import TodayDoneSection from "@/components/today/TodayDoneSection.vue";

/* emits */
const emit = defineEmits(["update"]);

/* props */
const props = defineProps({
    today: { type: String, required: true },
    habits: { type: Array, required: true },
    nowSlot: { type: Number, required: true },

    // TodayService の top_pick をそのまま渡す（任意）
    topPick: { type: Object, required: false, default: null },
});

/* helpers */
const isDone = (h) => h?.log?.status === "done";
const slotOf = (h) => Number(h?.time_slot ?? 0);

function bucketForUndone(h) {
    const slot = slotOf(h);

    // anytime は常に最後
    if (slot === 0) return 3;

    // 0: 現在スロット
    if (slot === Number(props.nowSlot)) return 0;

    // 1: 過去の未完了（= actionable を信用）
    if (h?.actionable === true) return 1;

    // 2: 未来の未完了
    return 2;
}

function compareUndone(a, b) {
    const aId = Number(a?.id ?? 0);
    const bId = Number(b?.id ?? 0);

    const ab = bucketForUndone(a);
    const bb = bucketForUndone(b);
    if (ab !== bb) return ab - bb;

    // 同じグループ内だけ top_pick を先頭に寄せる（順位ルールは壊さない）
    const pickId = Number(props.topPick?.habit_id ?? 0);
    const aPick = pickId && aId === pickId ? 1 : 0;
    const bPick = pickId && bId === pickId ? 1 : 0;
    if (aPick !== bPick) return bPick - aPick;

    const aSlot = slotOf(a);
    const bSlot = slotOf(b);

    // 過去グループは “今に近い順”（例: 2→1）
    if (ab === 1 && aSlot !== bSlot) return bSlot - aSlot;

    // 未来グループは早い順（例: 3→4）
    if (ab === 2 && aSlot !== bSlot) return aSlot - bSlot;

    // anytime / current は slot→id
    if (aSlot !== bSlot) return aSlot - bSlot;
    return aId - bId;
}

/* lists */
const undoneSorted = computed(() => {
    const list = (props.habits ?? []).filter((h) => !isDone(h));
    return [...list].sort(compareUndone);
});

const doneSorted = computed(() => {
    const list = (props.habits ?? []).filter((h) => isDone(h));
    return [...list].sort((a, b) => {
        const aSlot = slotOf(a);
        const bSlot = slotOf(b);
        if (aSlot !== bSlot) return aSlot - bSlot;
        return Number(a?.id ?? 0) - Number(b?.id ?? 0);
    });
});

/* child -> parent relay */
function onRowUpdate({ habit, payload }) {
    emit("update", { habit, payload });
}
</script>
