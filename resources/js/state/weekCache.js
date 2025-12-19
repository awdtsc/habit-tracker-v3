// resources/js/state/weekCache.js
import api from "@/axios";

const cache = new Map(); // key: week_start
let inflight = null; // “現在週”の先読みは1本で十分

export function getCachedWeek(weekStart) {
    return cache.get(weekStart) || null;
}

export function setCachedWeek(weekData) {
    if (weekData?.week_start) {
        cache.set(weekData.week_start, weekData);
    }
}

// 今日画面の裏で叩く用（現在週）
export async function prefetchCurrentWeek() {
    if (inflight) return inflight;

    inflight = api
        .get("/week")
        .then((res) => {
            setCachedWeek(res.data);
            return res.data;
        })
        .catch(() => null)
        .finally(() => {
            inflight = null;
        });

    return inflight;
}
