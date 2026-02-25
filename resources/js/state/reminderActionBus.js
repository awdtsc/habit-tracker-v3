// resources/js/state/reminderActionBus.js
const listeners = new Set();

/**
 * @param {(evt: {type:'done'|'cancel'|'snooze', task_id:number|string|null, payload:any}) => void} fn
 */
export function onReminderAction(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/** @param {{type:string, task_id:any, payload:any}} evt */
export function emitReminderAction(evt) {
    for (const fn of listeners) {
        try {
            fn(evt);
        } catch (_) {}
    }
}
