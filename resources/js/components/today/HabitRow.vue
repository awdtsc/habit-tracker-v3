<!-- resources/js/components/today/HabitRow.vue -->
<template>
    <div
        class="flex items-center justify-between gap-4 py-3"
        :class="isDone ? 'opacity-70' : ''"
    >
        <!-- 左側：タイトル + ゲージ -->
        <div class="min-w-0 flex-1">
            <div
                class="font-medium truncate"
                :class="isDone ? 'line-through' : ''"
            >
                {{ habit.title }}
            </div>

            <div class="text-xs text-gray-500 mt-0.5">
                {{ goalText }}
            </div>

            <!-- SIMPLE（完了ゲージ） -->
            <div
                v-if="!isSelf"
                class="mt-2 h-2 w-40 rounded-full bg-gray-200 overflow-hidden"
            >
                <div
                    class="h-full transition-all"
                    :class="isDone ? 'bg-blue-500' : 'bg-blue-300'"
                    :style="{ width: isDone ? '100%' : '0%' }"
                ></div>
            </div>
        </div>

        <!-- 右側：評価ボタン → 状態ラベル → 完了ボタン -->
        <div class="flex items-center gap-3 shrink-0">
            <!-- SELF のときだけ評価ボタン -->
            <div v-if="isSelf" class="flex gap-2 mr-2">
                <button
                    v-for="n in [0, 1, 2, 3, 4]"
                    :key="n"
                    @click="onSelectRating(n)"
                    class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-medium transition-all"
                    :class="ratingButtonClass(n)"
                >
                    {{ n }}
                </button>
            </div>

            <!-- 状態ラベル（固定幅で揺れない） -->
            <span
                class="px-2 py-0.5 rounded-full text-xs w-16 text-center"
                :class="statusBadgeClass"
            >
                {{ statusLabel }}
            </span>

            <!-- 完了ボタン -->
            <button
                class="px-3 py-1 text-sm rounded border hover:bg-gray-50"
                @click="toggleStatus"
            >
                {{ isDone ? "未完了" : "完了" }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { shallowRef, computed, watch } from "vue";

const props = defineProps({
    habit: { type: Object, required: true },
    log: { type: Object, required: true },
    today: { type: String, required: true },
});

const emit = defineEmits(["update"]);

/* ----------------------------------------------------------
   localLog = shallowRef（ちらつき防止の最重要ポイント）
---------------------------------------------------------- */
const localLog = shallowRef({ ...props.log });

watch(
    () => props.log,
    (v) => {
        localLog.value = { ...v };
    }
);

/* ----------------------------------------------------------
   計算系
---------------------------------------------------------- */
const isSelf = computed(() => props.habit.evaluation_type === "self");
const rating = computed(() => localLog.value.rating ?? 0);
const isDone = computed(() => localLog.value.status === "done");

const goalText = computed(() => "1回");

/* ----------------------------------------------------------
   状態ラベル
---------------------------------------------------------- */
const statusLabel = computed(() => {
    if (rating.value === 4) return "完了";
    if (rating.value >= 1 && rating.value <= 3) return "進行中";
    return "未完了";
});

const statusBadgeClass = computed(() => {
    if (rating.value === 4) return "bg-blue-100 text-blue-700";
    if (rating.value >= 1 && rating.value <= 3)
        return "bg-yellow-100 text-yellow-700";
    return "bg-blue-100 text-blue-700";
});

/* ----------------------------------------------------------
   完了ボタン
---------------------------------------------------------- */
function toggleStatus() {
    const newStatus = isDone.value ? "none" : "done";
    const newRating = isDone.value ? 0 : 4;

    // ちらつき防止：Optimistic Update
    localLog.value = {
        ...localLog.value,
        status: newStatus,
        rating: newRating,
    };

    emit("update", {
        habit: props.habit,
        payload: {
            date: props.today,
            status: newStatus,
            rating: newRating,
        },
    });
}

/* ----------------------------------------------------------
   rating ボタン
---------------------------------------------------------- */
function onSelectRating(n) {
    const newStatus = n === 4 ? "done" : "none";

    // ちらつき防止：localLog を即更新
    localLog.value = {
        ...localLog.value,
        rating: n,
        status: newStatus,
    };

    emit("update", {
        habit: props.habit,
        payload: {
            date: props.today,
            status: newStatus,
            rating: n,
        },
    });
}

/* ----------------------------------------------------------
   rating ボタンの色
---------------------------------------------------------- */
function ratingButtonClass(n) {
    if (n <= rating.value) {
        return "bg-blue-500 text-white";
    }
    return "bg-gray-200 text-gray-500";
}
</script>

<style scoped>
button {
    transition: 0.15s all ease;
}
</style>
