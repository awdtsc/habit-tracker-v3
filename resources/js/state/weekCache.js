// resources/js/state/weekCache.js
import api from "@/axios";

const cache = new Map(); // key: week_start
let inflight = null; // “現在週”の先読みは1本で十分

function cloneWeek(obj) {
    if (!obj) return obj;

    if (typeof structuredClone === "function") {
        try {
            return structuredClone(obj);
        } catch {
            // fallthrough
        }
    }

    try {
        return JSON.parse(JSON.stringify(obj));
    } catch {
        return obj;
    }
}

export function getCachedWeek(weekStart) {
    const v = cache.get(weekStart) || null;
    // ★外に参照を漏らさない
    return v ? cloneWeek(v) : null;
}

export function setCachedWeek(weekData) {
    if (weekData?.week_start) {
        // ★保存時も clone（外部のミューテーションが cache に影響しない）
        cache.set(weekData.week_start, cloneWeek(weekData));
    }
}

// 今日画面の裏で叩く用（現在週）
export async function prefetchCurrentWeek() {
    if (inflight) return inflight;

    inflight = api
        .get("/week")
        .then((res) => {
            setCachedWeek(res.data);
            // ★呼び出し側にも参照を漏らさない
            return cloneWeek(res.data);
        })
        .catch(() => null)
        .finally(() => {
            inflight = null;
        });

    return inflight;
}
