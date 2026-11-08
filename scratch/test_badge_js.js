// Test JS logic for patient card rendering
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function generateVisitBadgeHtml(data) {
    let lastVisitBadgeHtml = '';
    if (data.last_visit_date) {
        const deptStr = data.last_dept_name ? ` (${escapeHtml(data.last_dept_name)})` : '';
        if (data.is_today_visit) {
            lastVisitBadgeHtml = `<span class="visit-badge visit-today" title="本日電子カルテで受診実績があります">🔴 本日受診${deptStr}</span>`;
        } else {
            const dStr = escapeHtml(data.last_visit_date.replace(/-/g, '/'));
            lastVisitBadgeHtml = `<span class="visit-badge visit-past" title="最終受診日: ${dStr}${deptStr}">📅 最終: ${dStr}${deptStr}</span>`;
        }
    } else if (data.last_visit_date === null && data.patient_id) {
        lastVisitBadgeHtml = `<span class="visit-badge visit-none" title="過去の電子カルテ受診実績なし（新患）">最終: なし(新患)</span>`;
    } else {
        lastVisitBadgeHtml = `<span class="visit-badge visit-none">照会中...</span>`;
    }
    return lastVisitBadgeHtml;
}

// Test case 1: 過去の受診日がある患者 (例: 14759)
const t1 = generateVisitBadgeHtml({
    patient_id: '14759',
    last_visit_date: '2026-10-26',
    last_dept_name: '胃腸科',
    is_today_visit: false
});
console.log('Test 1 (Past visit):', t1);
if (!t1.includes('📅 最終: 2026/10/26 (胃腸科)') || !t1.includes('visit-past')) {
    throw new Error('Test 1 failed');
}

// Test case 2: 本日受診の患者
const t2 = generateVisitBadgeHtml({
    patient_id: '12345',
    last_visit_date: '2026-10-30',
    last_dept_name: '肛門科',
    is_today_visit: true
});
console.log('Test 2 (Today visit):', t2);
if (!t2.includes('🔴 本日受診 (肛門科)') || !t2.includes('visit-today')) {
    throw new Error('Test 2 failed');
}

// Test case 3: 過去受診実績なし（新患）
const t3 = generateVisitBadgeHtml({
    patient_id: '99999',
    last_visit_date: null,
    last_dept_name: null,
    is_today_visit: false
});
console.log('Test 3 (New patient):', t3);
if (!t3.includes('最終: なし(新患)') || !t3.includes('visit-none')) {
    throw new Error('Test 3 failed');
}

// Test case 4: 照会中
const t4 = generateVisitBadgeHtml({});
console.log('Test 4 (Loading):', t4);
if (!t4.includes('照会中...')) {
    throw new Error('Test 4 failed');
}

console.log('All JS badge tests passed successfully!');
