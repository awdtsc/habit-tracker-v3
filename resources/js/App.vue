<template>
  <RouterView v-slot="{ Component, route }">
    <KeepAlive :include="keepAliveNames">
      <component
        v-if="Component"
        :is="Component"
        :key="route.name"
      />
    </KeepAlive>
  </RouterView>
</template>

<script setup>
import { onMounted, watch } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();
const keepAliveNames = ["TodayPage", "WeeklyPage"];

// プリフェッチ対象（反対側のページを先読み）
const prefetchWeek = () => import("@/Pages/Weekly.vue");
const prefetchToday = () => import("@/Pages/Today.vue");

function schedulePrefetch(fn) {
  const run = () => fn().catch(() => {});
  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(run);
  } else {
    setTimeout(run, 250);
  }
}

function prefetchOpposite(name) {
  if (name === "today") schedulePrefetch(prefetchWeek);
  if (name === "week") schedulePrefetch(prefetchToday);
}

onMounted(() => {
  prefetchOpposite(route.name);
});

watch(
  () => route.name,
  (name) => {
    prefetchOpposite(name);
  }
);
</script>
