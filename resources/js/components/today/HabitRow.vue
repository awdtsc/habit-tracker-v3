<!-- resources/js/components/today/HabitRow.vue -->
<template>
  <div
    class="flex items-center justify-between gap-4 py-3"
    :class="isDone ? 'opacity-70' : ''"
  >
    <!-- 左側 -->
    <div class="min-w-0">
      <div class="font-medium truncate" :class="isDone ? 'line-through' : ''">
        {{ habit.title }}
      </div>

      <div class="text-xs text-gray-500 mt-0.5">
        {{ goalText }}
      </div>

      <!-- SELF（0〜4 の自己評価） -->
      <div v-if="isSelf" class="flex gap-2 mt-2">
        <button
          v-for="n in [0,1,2,3,4]"
          :key="n"
          @click="onSelectRating(n)"
          class="w-7 h-7 rounded flex items-center justify-center text-sm font-medium border"
          :class="buttonClass(n)"
        >
          {{ n }}
        </button>
      </div>

      <!-- SIMPLE（完了バー） -->
      <div v-else class="mt-2 h-2 w-40 rounded-full bg-gray-200 overflow-hidden">
        <div
          class="h-full transition-all"
          :class="isDone ? 'bg-green-500' : 'bg-blue-400'"
          :style="{ width: isDone ? '100%' : '0%' }"
        ></div>
      </div>
    </div>

    <!-- 右側（STATUS ボタン） -->
    <div class="flex items-center gap-3 shrink-0">
      <span class="px-2 py-0.5 rounded-full text-xs" :class="statusBadgeClass">
        {{ statusLabel }}
      </span>

      <!-- SELF / SIMPLE 両方で完了ボタンを出す -->
      <button
        class="px-3 py-1 text-sm rounded border hover:bg-gray-50"
        @click="toggleStatus"
      >
        {{ isDone ? '未完' : '完了' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, watch } from "vue";

const props = defineProps({
  habit: Object,
  log: Object,
  today: String,
});

const emit = defineEmits(["update"]);

// localLog を reactive に
const localLog = reactive({ ...props.log });

watch(
  () => props.log,
  (v) => Object.assign(localLog, v),
  { deep: true }
);

const isSelf = computed(() => props.habit.evaluation_type === "self");
const isDone = computed(() => localLog.status === "done");

// rating=null は 0 扱い
const rating = computed(() => localLog.rating ?? 0);

const goalText = computed(() => "1回");

const statusLabel = computed(() => (isDone.value ? "完了" : "未完了"));
const statusBadgeClass = computed(() =>
  isDone.value
    ? "bg-green-100 text-green-700"
    : "bg-blue-100 text-blue-700"
);

/* ======================================================
   STATUS ボタン（SELF / SIMPLE 共通）
   - SELF: 完了→rating 4 / 未完→rating 0
   - SIMPLE: 完了/未完のみ
====================================================== */
function toggleStatus() {
  if (isSelf.value) {
    // SELF: 完了は rating 4、未完は rating 0
    const isCurrentlyDone = isDone.value;

    emit("update", {
      date: props.today,
      status: isCurrentlyDone ? "none" : "done",
      rating: isCurrentlyDone ? 0 : 4,
    });
  } else {
    // SIMPLE
    emit("update", {
      date: props.today,
      status: isDone.value ? "none" : "done",
      rating: null,
    });
  }
}

/* ======================================================
   SELF: 各数字クリック（0〜4）
====================================================== */
function onSelectRating(n) {
  const newStatus = n === 4 ? "done" : "none";

  emit("update", {
    date: props.today,
    status: newStatus,
    rating: n,
  });
}

/* ======================================================
   ボタンの色（視覚的に自然）
====================================================== */
function buttonClass(n) {
  if (rating.value === 0) {
    return n === 0
      ? "bg-gray-300 text-gray-700 border-gray-400"
      : "bg-gray-200 text-gray-500 border-gray-300";
  }
  return n <= rating.value
    ? "bg-yellow-400 text-black border-yellow-500"
    : "bg-gray-200 text-gray-500 border-gray-300";
}
</script>

<style scoped>
button {
  transition: 0.15s all ease;
}
</style>
