<template>
  <div v-if="open" class="rm-backdrop" @click.self="emitClose">
    <div class="rm-modal" role="dialog" aria-modal="true">
      <div class="rm-header">
        <div class="rm-title">{{ title }}</div>
        <button class="rm-x" @click="emitClose" aria-label="Close">×</button>
      </div>

      <div class="rm-body">
        <p v-if="payload?.body" class="rm-text">{{ payload.body }}</p>

        <div class="rm-meta" v-if="payload">
          <div v-if="taskId != null"><b>task_id:</b> {{ taskId }}</div>
          <div v-if="payload.habit_time_id != null"><b>habit_time_id:</b> {{ payload.habit_time_id }}</div>
          <div v-if="payload.date"><b>date:</b> {{ payload.date }}</div>
          <div v-if="payload.evaluation_type"><b>evaluation_type:</b> {{ payload.evaluation_type }}</div>
          <div v-if="payload.url"><b>url:</b> {{ payload.url }}</div>
        </div>
      </div>

      <div class="rm-footer">
        <button class="rm-btn" @click="emitClose">閉じる</button>
        <a
          v-if="openUrl"
          class="rm-btn rm-primary"
          :href="openUrl"
        >
          Today を開く
        </a>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "Habit Reminder" },
  payload: { type: Object, default: null },
});

const emit = defineEmits(["close"]);

const emitClose = () => emit("close");

const taskId = computed(() => {
  const p = props.payload ?? {};
  return p.task_id ?? p.taskId ?? null;
});

const openUrl = computed(() => {
  const p = props.payload ?? {};
  // payload.url があれば優先、無ければ /today?from=push&task_id=... を組む
  if (typeof p.url === "string" && p.url.length > 0) return p.url;
  if (taskId.value != null) return `/today?from=push&task_id=${encodeURIComponent(String(taskId.value))}`;
  return `/today?from=push`;
});
</script>

<style scoped>
.rm-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.45);
  display:flex; align-items:center; justify-content:center;
  padding:16px; z-index:9999;
}
.rm-modal{
  width:min(520px, 100%);
  background:#fff; border-radius:12px; overflow:hidden;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.rm-header{
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 14px; border-bottom:1px solid #eee;
}
.rm-title{ font-weight:700; }
.rm-x{
  border:none; background:transparent; font-size:22px; cursor:pointer; line-height:1;
}
.rm-body{ padding:14px; }
.rm-text{ margin:0 0 10px 0; color:#333; }
.rm-meta{ font-size:12px; color:#666; display:grid; gap:6px; }
.rm-footer{
  padding:12px 14px; border-top:1px solid #eee;
  display:flex; gap:10px; justify-content:flex-end; align-items:center;
}
.rm-btn{
  padding:9px 12px; border-radius:10px; border:1px solid #ddd; background:#fff;
  cursor:pointer; text-decoration:none; color:#111; font-size:14px;
}
.rm-primary{
  border-color:#111; background:#111; color:#fff;
}
</style>
