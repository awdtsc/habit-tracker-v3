// resources/js/stores/habitLogStore.js
import { reactive } from "vue";
import api from "@/axios";

/**
 * Piniaなしのシングルトンストア（HabitLogだけ）
 * - logsByKey: `${date}:${habitId}` -> logObject（このオブジェクトを各画面の habit.log に共有参照させる）
 * - attach(ownerId, date, habitObj): habitObj.log を store の logObject に差し替えて「同じ参照」にする
 * - toggle(): optimistic -> API -> 確定patch / rollback（競合は requestVersion で捨てる）
 */

const state = reactive({
    logsByKey: new Map(), // key -> logObject
});

const requestVersion = new Map(); // key -> version

// key -> Map(ownerId -> Set<habitObj>)
const attached = new Map();
// ownerId -> Set<key>
const keysByOwner = new Map();

// 変更通知（通知機能などがここにぶら下がる）
const listeners = new Set();

/* ------------------------------
  helpers
------------------------------ */
function keyOf(date, habitId) {
    return `${date}:${habitId}`;
}

function getTodayJstISO() {
    // ざっくりでOK（サーバー側もAsia/Tokyoで補正してる）
    const d = new Date();
    const jst = new Date(d.getTime() + 9 * 60 * 60 * 1000);
    return jst.toISOString().slice(0, 10);
}

function ensureLog(key, initial = null) {
    if (!state.logsByKey.has(key)) {
        state.logsByKey.set(key, {
            id: initial?.id ?? null,
            habit_id: initial?.habit_id ?? null,
            time_slot: initial?.time_slot ?? 0,
            status: initial?.status ?? "none",
            rating: Object.prototype.hasOwnProperty.call(
                initial ?? {},
                "rating"
            )
                ? initial.rating
                : null,
            checked_at: initial?.checked_at ?? null,
        });
    }
    return state.logsByKey.get(key);
}

function emit(evt) {
    for (const fn of listeners) fn(evt);
}

/* ------------------------------
  public API
------------------------------ */
function createOwner() {
    return Symbol("habitLogOwner");
}

function detachOwner(ownerId) {
    const keys = keysByOwner.get(ownerId);
    if (!keys) return;

    for (const key of keys) {
        const perKey = attached.get(key);
        if (!perKey) continue;

        perKey.delete(ownerId);
        if (perKey.size === 0) attached.delete(key);
    }

    keysByOwner.delete(ownerId);
}

function attach(ownerId, date, habitObj) {
    if (!date || !habitObj?.id) return;

    const key = keyOf(date, habitObj.id);

    // owner -> keys
    if (!keysByOwner.has(ownerId)) keysByOwner.set(ownerId, new Set());
    keysByOwner.get(ownerId).add(key);

    // key -> owner -> habit refs
    if (!attached.has(key)) attached.set(key, new Map());
    const perKey = attached.get(key);
    if (!perKey.has(ownerId)) perKey.set(ownerId, new Set());
    perKey.get(ownerId).add(habitObj);

    // log参照を共有させる（これが“同期の本体”）
    const logRef = ensureLog(key, habitObj.log ?? null);
    habitObj.log = logRef;
}

function attachHabits(ownerId, date, habits) {
    (habits ?? []).forEach((h) => attach(ownerId, date, h));
}

function attachWeek(ownerId, days) {
    (days ?? []).forEach((d) => {
        attachHabits(ownerId, d.date, d.habits ?? []);
    });
}

function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/**
 * log patch（確定/optimistic共通）
 * - storeのlogObjectを mutate する
 * - attach済みの habitObj.log は同じ参照なので勝手に同期される
 */
function patchLog(date, habitId, patch, meta = {}) {
    const key = keyOf(date, habitId);
    const log = ensureLog(key, patch);

    if ("id" in patch) log.id = patch.id;
    if ("habit_id" in patch) log.habit_id = patch.habit_id;
    if ("time_slot" in patch) log.time_slot = patch.time_slot;
    if ("status" in patch) log.status = patch.status;
    if (Object.prototype.hasOwnProperty.call(patch, "rating"))
        log.rating = patch.rating;
    if ("checked_at" in patch) log.checked_at = patch.checked_at;

    emit({ type: "log:patched", date, habitId, log, ...meta });
}

/**
 * トグル（唯一の更新窓口）
 * @param {string|null} date - 省略時は今日(JST)
 * @param {object} habitObj - {id, evaluation_type ...}
 * @param {object} payload - {status, rating}
 * @param {string} scope - backend互換（morning/day/.../all）
 */
async function toggle(date, habitObj, payload, scope = "all", meta = {}) {
    const d = date ?? getTodayJstISO();
    const habitId = habitObj.id;
    const key = keyOf(d, habitId);

    const v = (requestVersion.get(key) ?? 0) + 1;
    requestVersion.set(key, v);

    // before snapshot（rollback用）
    const before = { ...(state.logsByKey.get(key) ?? null) };

    // optimistic
    patchLog(
        d,
        habitId,
        {
            habit_id: habitId,
            status: payload.status,
            rating: Object.prototype.hasOwnProperty.call(payload, "rating")
                ? payload.rating
                : null,
            checked_at: new Date().toISOString(),
        },
        { phase: "optimistic", ...meta }
    );

    try {
        const { data } = await api.post(`/habits/${habitId}/toggle`, {
            ...payload,
            date: d,
            scope,
        });

        if (requestVersion.get(key) !== v) return data; // 古い結果は捨てる

        const log = data?.habit?.log;
        if (log) {
            patchLog(
                d,
                habitId,
                {
                    id: log.id,
                    habit_id: log.habit_id ?? habitId,
                    time_slot: log.time_slot,
                    status: log.status,
                    rating: log.rating,
                    checked_at: log.checked_at,
                },
                { phase: "confirmed", ...meta }
            );
        }

        return data;
    } catch (e) {
        if (requestVersion.get(key) !== v) return;

        // rollback
        if (before && Object.keys(before).length) {
            patchLog(d, habitId, before, { phase: "rollback", ...meta });
        } else {
            // もともと無かったキーなら “none” へ戻す
            patchLog(
                d,
                habitId,
                { status: "none", rating: null },
                { phase: "rollback", ...meta }
            );
        }

        throw e;
    }
}

export function useHabitLogStore() {
    return {
        state,
        createOwner,
        detachOwner,
        attachHabits,
        attachWeek,
        subscribe,
        patchLog,
        toggle,
    };
}
