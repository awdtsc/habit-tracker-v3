<!-- resources/js/components/today/TodaySlotView.vue -->
<template>
    <div class="space-y-10">
        <!-- ========================================================= -->
        <!-- 1. この時間帯（未完） -->
        <!-- ========================================================= -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ label }} の習慣</h2>

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
                {{ label }} の完了
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

            <!-- 未完が存在する -->
            <TodayActionableSection
                v-if="anytimePending.length > 0"
                :habits="anytimePending"
                :today="today"
                @update="onRowUpdate"
            />

            <!-- 未完は0件、完了はある＝習慣はある -->
            <p
                v-else-if="
                    anytimePending.length === 0 && anytimeDone.length > 0
                "
                class="text-sm text-gray-500 pl-1"
            >
                未完了の習慣はありません。
            </p>

            <!-- 未完0 & 完了0（anytime習慣が存在しない） → 何も出さない -->
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
        <!-- 5. 次スロット（未完） -->
        <!-- ========================================================= -->
        <section v-if="nextPending.length > 0" class="space-y-3">
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
        <!-- 6. 次スロット（完了） -->
        <!-- ========================================================= -->
        <section v-if="nextDone.length > 0" class="space-y-3">
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
   props
---------------------------------------------------------- */
const props = defineProps({
    slot: { type: String, required: true },
    today: { type: String, required: true },
    habits: { type: Array, required: true },
    progress: { type: Object, required: true },
    nowSlot: { type: Number, required: true },
});

/* ----------------------------------------------------------
   ラベル
---------------------------------------------------------- */
const slotLabelMap = {
    morning: "朝",
    noon: "昼",
    evening: "夕方",
    night: "夜",
};
const label = computed(() => slotLabelMap[props.slot]);

/* ----------------------------------------------------------
   slot → 数値
---------------------------------------------------------- */
const slotMap = { morning: 1, noon: 2, evening: 3, night: 4 };
const activeSlot = computed(() => slotMap[props.slot]);
const nextSlot = computed(() => activeSlot.value + 1);

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
    localHabits.filter(
        (h) => Number(h.time_slot) === nextSlot.value && !isDone(h)
    )
);

const nextDone = computed(() =>
    localHabits.filter(
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
