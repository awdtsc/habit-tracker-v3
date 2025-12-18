<!-- resources/js/components/today/TodaySlotView.vue -->
<template>
    <div class="space-y-10">
        <!-- 1. この時間帯（未完） -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ slotLabel }} の習慣</h2>

            <TodayActionableSection
                :habits="currentPending"
                :today="today"
                @update="emitUpdate"
            />

            <p
                v-if="currentPending.length === 0"
                class="text-sm text-gray-500 pl-1"
            >
                この時間帯の未完了の習慣はありません。
            </p>
        </section>

        <!-- 2. この時間帯（完了） -->
        <section v-if="currentDone.length > 0" class="space-y-3">
            <h3 class="text-md font-semibold text-gray-700">
                {{ slotLabel }} の完了
            </h3>

            <TodayDoneSection
                :habits="currentDone"
                :today="today"
                @update="emitUpdate"
            />
        </section>

        <!-- 3. Anytime（未完・進行中含む） -->
        <section class="space-y-3">
            <h3 class="text-md font-semibold text-gray-700">いつでも の習慣</h3>

            <TodayActionableSection
                v-if="anytimePending.length > 0"
                :habits="anytimePending"
                :today="today"
                @update="emitUpdate"
            />

            <p
                v-else-if="anytimeDone.length > 0"
                class="text-sm text-gray-500 pl-1"
            >
                未完了の習慣はありません。
            </p>
        </section>

        <!-- 4. Anytime（完了） -->
        <section v-if="anytimeDone.length > 0" class="space-y-3">
            <h3 class="text-md font-semibold text-gray-700">いつでも の完了</h3>

            <TodayDoneSection
                :habits="anytimeDone"
                :today="today"
                @update="emitUpdate"
            />
        </section>

        <!-- 5. 次スロット（未完） -->
        <section
            v-if="nextPending.length > 0 && activeSlotEnum === nowSlot"
            class="space-y-3"
        >
            <h3 class="text-md font-semibold text-gray-700">
                次の時間帯の習慣
            </h3>

            <TodayNextSlotSection
                :habits="nextPending"
                :today="today"
                @update="emitUpdate"
            />
        </section>

        <!-- 6. 次スロット（完了） -->
        <section
            v-if="nextDone.length > 0 && activeSlotEnum === nowSlot"
            class="space-y-3"
        >
            <h3 class="text-md font-semibold text-gray-700">
                次の時間帯の完了
            </h3>

            <TodayDoneSection
                :habits="nextDone"
                :today="today"
                @update="emitUpdate"
            />
        </section>
    </div>
</template>

<script setup>
import { computed } from "vue";

import TodayActionableSection from "@/components/today/TodayActionableSection.vue";
import TodayNextSlotSection from "@/components/today/TodayNextSlotSection.vue";
import TodayDoneSection from "@/components/today/TodayDoneSection.vue";

import { slotKeyToLabel } from "@/utils/slot";
import { scopeToSlot, isHabitDone } from "@/utils/todayProgress";

/* emit */
const emit = defineEmits(["update"]);

/* props */
const props = defineProps({
    slot: { type: String, required: true }, // "morning|day|evening|night"
    today: { type: String, required: true },
    habits: { type: Array, required: true },
    nowSlot: { type: Number, required: true },
    nextSlot: { type: Number, required: false, default: null }, // ★ root next_slot
});

/* base */
const slotLabel = computed(() => slotKeyToLabel(props.slot));
const habits = computed(() => props.habits ?? []);

/* active slot enum */
const activeSlotEnum = computed(() => {
    const v = scopeToSlot(props.slot);
    return v === 0 ? null : v;
});

/* backend anytime flag を優先 */
function isAnytime(h) {
    if (typeof h?.anytime === "boolean") return h.anytime;
    return Number(h?.time_slot ?? 0) === 0;
}

/* セクション分類 */
const currentPending = computed(() => {
    const s = activeSlotEnum.value;
    if (s == null) return [];
    return habits.value.filter((h) => Number(h.time_slot) === s && !isHabitDone(h));
});

const currentDone = computed(() => {
    const s = activeSlotEnum.value;
    if (s == null) return [];
    return habits.value.filter((h) => Number(h.time_slot) === s && isHabitDone(h));
});

const anytimePending = computed(() =>
    habits.value.filter((h) => isAnytime(h) && !isHabitDone(h))
);

const anytimeDone = computed(() =>
    habits.value.filter((h) => isAnytime(h) && isHabitDone(h))
);

/**
 * ★ “次の時間帯” は frontend で +1 しない
 * - backend(root next_slot) をそのまま使う
 * - activeSlot が nowSlot の時だけ表示する（今の仕様維持）
 */
const nextPending = computed(() => {
    const s = props.nextSlot;
    if (!s) return [];
    return habits.value.filter((h) => Number(h.time_slot) === s && !isHabitDone(h));
});

const nextDone = computed(() => {
    const s = props.nextSlot;
    if (!s) return [];
    return habits.value.filter((h) => Number(h.time_slot) === s && isHabitDone(h));
});

/* 親に通知 */
function emitUpdate(payload) {
    emit("update", payload);
}
</script>
