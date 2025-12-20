<!-- resources/js/components/today/HabitRow.vue -->
<template>
    <div
        class="flex items-center justify-between gap-4 py-3"
        :class="isDone ? 'opacity-70' : ''"
    >
        <!-- 左側：タイトル + ゲージ -->
        <div class="min-w-0 flex-1">
            <div class="font-medium truncate" :class="isDone ? 'line-through' : ''">
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
                    :class="isDone ? 'bg-green-500' : 'bg-blue-300'"
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
                    type="button"
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
                type="button"
                class="px-3 py-1 text-sm rounded border hover:bg-gray-50"
                @click="toggleStatus"
            >
                {{ isDone ? "未完了" : "完了" }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from "vue";

const props = defineProps({
    habit: { type: Object, required: true },
    log: { type: Object, required: true },
    today: { type: String, required: true },
});

const emit = defineEmits(["update"]);

/**
 * ★同期の肝
 * - 以前: shallowRef({ ...props.log }) にしていたため、storeが log を in-place で更新しても追従しなかった
 * - 今回: props.log を真実として参照しつつ、クリック直後だけ pending を上書き表示して“止血”する
 */
const pending = ref(null); // { status?, rating? } 一時上書き

const isSelf = computed(() => props.habit.evaluation_type === "self");

const viewStatus = computed(() => {
    return (pending.value?.status ?? props.log.status ?? "none");
});
const viewRating = computed(() => {
    // SELF のときだけ意味があるが、UI都合で常に 0..4 に正規化
    const r = pending.value?.rating ?? props.log.rating ?? 0;
    return typeof r === "number" ? r : 0;
});

const isDone = computed(() => {
  if (isSelf.value) return viewRating.value === 4; // ★ここだけを真実にする
  return viewStatus.value === "done";
});

const goalText = computed(() => "1回");

/**
 * store が props.log を patch したら pending を解除
 * - in-place で status/rating が変わるので、それを watch すればOK（参照差し替え不要）
 */
watch(
    () => [props.log.status, props.log.rating, props.log.checked_at, props.log.id],
    () => {
        pending.value = null;
    }
);

/* ----------------------------------------------------------
   状態ラベル
---------------------------------------------------------- */
const statusLabel = computed(() => {
    if (!isSelf.value) {
        return isDone.value ? "完了" : "未完了";
    }

    if (viewRating.value === 4) return "完了";
    if (viewRating.value >= 1) return "進行中";
    return "未完了";
});

const statusBadgeClass = computed(() => {
    if (isDone.value) return "bg-green-100 text-green-700";
    if (isSelf.value && viewRating.value >= 1) return "bg-yellow-100 text-yellow-700";
    return "bg-blue-100 text-blue-600";
});

/* ----------------------------------------------------------
   完了ボタン
---------------------------------------------------------- */
function toggleStatus() {
  if (isSelf.value) {
    const nextRating = (viewRating.value === 4) ? 0 : 4;
    const nextStatus = nextRating === 4 ? "done" : "none";

    pending.value = { rating: nextRating, status: nextStatus };

    emit("update", {
      habit: props.habit,
      payload: {
        date: props.today,
        status: nextStatus,
        rating: nextRating, // ★SELFは常に送る
      },
    });
    return;
  }

  // simple
  const nextStatus = isDone.value ? "none" : "done";
  pending.value = { status: nextStatus, rating: null };

  emit("update", {
    habit: props.habit,
    payload: { date: props.today, status: nextStatus, rating: null },
  });
}

/* ----------------------------------------------------------
   rating ボタン
---------------------------------------------------------- */
function onSelectRating(n) {
    const nextStatus = n === 4 ? "done" : "none";

    // ちらつき止血
    pending.value = { rating: n, status: nextStatus };

    emit("update", {
        habit: props.habit,
        payload: {
            date: props.today,
            status: nextStatus,
            rating: n,
        },
    });
}

/* ----------------------------------------------------------
   rating ボタンの色
---------------------------------------------------------- */
function ratingButtonClass(n) {
    // “現在のrating” を基準に塗る（pendingも反映）
    if (n <= viewRating.value) return "bg-blue-500 text-white";
    return "bg-gray-200 text-gray-500";
}
</script>

<style scoped>
button {
    transition: 0.15s all ease;
}
</style>
