// resources/js/utils/dateIso.js
//------------------------------------------------------------
// ISO日付ユーティリティ（YYYY-MM-DD）
//------------------------------------------------------------
export function toISODate(dateObj) {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, "0");
    const d = String(dateObj.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
}

export function addDaysISO(iso, deltaDays) {
    const dt = new Date(`${iso}T00:00:00`);
    dt.setDate(dt.getDate() + deltaDays);
    return toISODate(dt);
}

export function getWeekStartISO(baseDate = new Date()) {
    const now = new Date(baseDate);
    const day = now.getDay(); // 0=Sun..6=Sat
    const diffToMon = (day + 6) % 7;
    now.setHours(0, 0, 0, 0);
    now.setDate(now.getDate() - diffToMon);
    return toISODate(now);
}

export function formatMMDD(iso) {
    const [, m, d] = iso.split("-");
    return `${m}/${d}`;
}
