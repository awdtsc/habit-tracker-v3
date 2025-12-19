<template>
  <div class="border-t md:border-t-0 md:border-l first:border-l-0">
    <!-- day header -->
    <div class="px-3 py-2 bg-gray-50 border-b">
      <div class="text-xs text-gray-500">{{ day.weekday }}</div>
      <div class="text-sm font-semibold text-gray-800">{{ day.label }}</div>
    </div>

    <!-- habits -->
    <div class="p-2 space-y-2">
      <button
        v-for="h in day.habits"
        :key="h.id"
        type="button"
        class="w-full text-left rounded-xl border px-3 py-3 transition-colors"
        :class="rowClass(h)"
        @click="emitToggle(h)"
      >
        <div class="font-medium truncate">
          {{ h.title }}
        </div>
      </button>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  day: { type: Object, required: true },
});

const emit = defineEmits(["toggle"]);

function emitToggle(habit) {
  emit("toggle", { date: props.day.date, habit });
}

function isDone(h) {
  const type = h.evaluation_type;
  const status = h.log?.status ?? "none";
  const rating = h.log?.rating ?? null;

  // self: rating=4 を done 扱い（status も保険）
  if (type === "self") return rating === 4 || status === "done";

  // simple
  return status === "done";
}

function rowClass(h) {
  if (isDone(h)) return "border-green-300 bg-green-50 hover:bg-green-100";
  return "border-orange-200 bg-orange-50 hover:bg-orange-100";
}
</script>
