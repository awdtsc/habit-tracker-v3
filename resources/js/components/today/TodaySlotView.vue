<!-- resources/js/components/today/TodaySlotView.vue -->
<template>
    <div class="space-y-10">
        <!-- ========================================================= -->
        <!-- 1. この時間帯（未完） -->
        <!-- ========================================================= -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ slotLabel }} の習慣</h2>

            <TodayActionableSection
                :habits="currentPending"
                :today="today"
                @update="onRowUpdate"
            />

            <p
                v-if="currentPending.length === 0"
                class="text-sm text-gray-500 pl-1"
            >
                この時間帯の未完了の習慣はありません。
            </p>
        </section>

        <!-- ========================================================= -->
        <!-- 2. この時間帯（完了） -->
        <!-- ========================================================= -->
        <section v-if="currentDone.length > 0" class="space-y-3">
            <h3 class="text-md font-semibold text-gray-700">
                {{ slotLabel }} の完了
            </h3>

            <TodayDoneSection
                :habits="currentDone"
                :today="today"
                @update="onRowUpdate"
            />
        </section>

        <!-- ========================================================= -->
        <!-- 3. Anytime（未完） -->
        <!-- ========================================================= -->
        <section class="space-y-3">
            <h3 class="text-md font-semibold text-gray-700">いつでも の習慣</h3>

            <TodayActionableSection
                v-if="anytimePending.length > 0"
                :habits="anytimePending"
                :today="today"
                @update="onRowUpdate"
            />

            <p
                v-else-if="
                    anytimePending.length === 0 && anytimeDone.length > 0
                "
                class="text-sm text-gray-500 pl-1"
            >
                未完了の習慣はありません。
            </p>
        </section>

        <!-- ========================================================= -->
        <!-- 4. Anytime（完了） -->
        <!-- ========================================================= -->
        <section v-if="anytimeDone.length > 0" class="space-y-3">
            <h3 class="text-md font-semibold text-gray-700">いつでも の完了</h3>

            <TodayDoneSection
                :habits="anytimeDone"
                :today="today"
                @update="onRowUpdate"
            />
        </section>

        <!-- ========================================================= -->
        <!-- 5. 次スロット（未完）※ 今の時間帯のみ表示 -->
        <!-- ========================================================= -->
        <section
            v-if="nextPending.length > 0 && activeSlot === nowSlot"
            class="space-y-3"
        >
            <h3 class="text-md font-semibold text-gray-700">
                次の時間帯の習慣
            </h3>

            <TodayNextSlotSection
                :habits="nextPending"
                :today="today"
                @update="onRowUpdate"
            />
        </section>

        <!-- ========================================================= -->
        <!-- 6. 次スロット（完了）※ 今の時間帯のみ表示 -->
        <!-- ========================================================= -->
        <section
            v-if="nextDone.length > 0 && activeSlot === nowSlot"
            class="space-y-3"
        >
            <h3 class="text-md font-semibold text-gray-700">
                次の時間帯の完了
            </h3>

            <TodayDoneSection
                :habits="nextDone"
                :today="today"
                @update="onRowUpdate"
            />
        </section>
    </div>
</template>

<script setup>
import { computed, reactive, watch } from "vue";

import TodayActionableSection from "@/components/today/TodayActionableSection.vue";
import TodayNextSlotSection from "@/components/today/TodayNextSlotSection.vue";
import TodayDoneSection from "@/components/today/TodayDoneSection.vue";

import { useHabitToggle } from "@/composables/useHabitToggle";

/* ----------------------------------------------------------
   utils（slot の知識はすべてここ）
---------------------------------------------------------- */
import { slotKeyToLabel, SLOT_ENUM } from "@/utils/slot";

/* ----------------------------------------------------------
   props
---------------------------------------------------------- */
const props = defineProps({
    slot: { type: String, required: true }, // 'morning' | 'day' | 'evening' | 'night'
    today: { type: String, required: true },
    habits: { type: Array, required: true },
    progress: { type: Object, required: true },
    nowSlot: { type: Number, required: true }, // enum
});

/* ----------------------------------------------------------
   ラベル
---------------------------------------------------------- */
const slotLabel = computed(() => slotKeyToLabel(props.slot));

/* ----------------------------------------------------------
   slot key → enum
---------------------------------------------------------- */
const activeSlot = computed(() => {
    switch (props.slot) {
        case "morning":
            return SLOT_ENUM.MORNING;
        case "day":
            return SLOT_ENUM.DAY;
        case "evening":
            return SLOT_ENUM.EVENING;
        case "night":
            return SLOT_ENUM.NIGHT;
        default:
            return null;
    }
});

const nextSlot = computed(() =>
    activeSlot.value != null ? activeSlot.value + 1 : null
);

/* ----------------------------------------------------------
   habits を reactive に
---------------------------------------------------------- */
const localHabits = reactive([]);

watch(
    () => props.habits,
    (newVal) => {
        localHabits.length = 0;
        newVal.forEach((h) => localHabits.push(h));
    },
    { immediate: true }
);

/* ----------------------------------------------------------
   共通判定
---------------------------------------------------------- */
const isDone = (h) => h?.log?.status === "done";

/* ----------------------------------------------------------
   セクション分類
---------------------------------------------------------- */
const currentPending = computed(() =>
    localHabits.filter(
        (h) => Number(h.time_slot) === activeSlot.value && !isDone(h)
    )
);

const currentDone = computed(() =>
    localHabits.filter(
        (h) => Number(h.time_slot) === activeSlot.value && isDone(h)
    )
);

const anytimePending = computed(() =>
    localHabits.filter((h) => Number(h.time_slot) === 0 && !isDone(h))
);

const anytimeDone = computed(() =>
    localHabits.filter((h) => Number(h.time_slot) === 0 && isDone(h))
);

const nextPending = computed(() =>
    nextSlot.value == null
        ? []
        : localHabits.filter(
              (h) => Number(h.time_slot) === nextSlot.value && !isDone(h)
          )
);

const nextDone = computed(() =>
    nextSlot.value == null
        ? []
        : localHabits.filter(
              (h) => Number(h.time_slot) === nextSlot.value && isDone(h)
          )
);

/* ----------------------------------------------------------
   Toggle
---------------------------------------------------------- */
const { toggle } = useHabitToggle(computed(() => props.today));

async function onRowUpdate({ habit, payload }) {
    const { log } = await toggle(habit, payload);
    habit.log = { ...log };
}
</script>
