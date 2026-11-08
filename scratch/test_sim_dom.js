const fs = require('fs');
const content = fs.readFileSync(__dirname + '/../bulk_edit_prep_room.php', 'utf8');

const jsStart = content.indexOf('<script>');
const jsEnd = content.lastIndexOf('</script>');
const jsCode = content.substring(jsStart + 8, jsEnd);

// 簡単なDOM要素モック
class MockElement {
    constructor(tag, attrs = {}) {
        this.tagName = tag.toUpperCase();
        this.attrs = attrs;
        this.style = {};
        const set = new Set();
        this.classList = {
            add: (c) => set.add(c),
            remove: (c) => set.delete(c),
            contains: (c) => set.has(c)
        };
        this.options = [];
        this.value = attrs.value || '';
        this.textContent = '';
        this.children = [];
    }
    getAttribute(name) { return this.attrs[name] !== undefined ? this.attrs[name] : null; }
    setAttribute(name, val) { this.attrs[name] = val; }
    querySelector(sel) {
        if (sel === '.room-select') return this.children.find(c => c.attrs.class === 'room-select');
        if (sel === '.room-alert-msg') return this.children.find(c => c.attrs.class === 'room-alert-msg');
        return null;
    }
}

// 予約行3行をシミュレート
// 行1: 土居 美保 (TCF), 部屋: ﾏ101
// 行2: 影山 和秀 (TCF), 部屋: 未指定
// 行3: 山田 太郎 (GIF), 部屋: 未指定

const row1 = new MockElement('tr', { 'data-sid': '1', 'data-patient-name': '土居 美保', 'data-exam-type': 'TCF' });
const sel1 = new MockElement('select', { class: 'room-select', value: 'ﾏ101' });
const msg1 = new MockElement('div', { class: 'room-alert-msg' });
row1.children = [sel1, msg1];

const row2 = new MockElement('tr', { 'data-sid': '2', 'data-patient-name': '影山 和秀', 'data-exam-type': 'TCF' });
const sel2 = new MockElement('select', { class: 'room-select', value: '' });
const msg2 = new MockElement('div', { class: 'room-alert-msg' });
row2.children = [sel2, msg2];

const row3 = new MockElement('tr', { 'data-sid': '3', 'data-patient-name': '山田 太郎', 'data-exam-type': 'GIF' });
const sel3 = new MockElement('select', { class: 'room-select', value: '' });
const msg3 = new MockElement('div', { class: 'room-alert-msg' });
row3.children = [sel3, msg3];

[sel1, sel2, sel3].forEach(sel => {
    const optNone = new MockElement('option', { value: '', 'data-raw-room': '' });
    optNone.textContent = '--未指定--';
    const optM101 = new MockElement('option', { value: 'ﾏ101', 'data-raw-room': 'ﾏ101' });
    optM101.textContent = 'ﾏ101';
    const optM102 = new MockElement('option', { value: 'ﾏ102', 'data-raw-room': 'ﾏ102' });
    optM102.textContent = 'ﾏ102';
    sel.options = [optNone, optM101, optM102];
});

const mockRows = [row1, row2, row3];
const sandbox = {
    document: {
        querySelectorAll: (sel) => sel === '.reservation-row' ? mockRows : [],
        addEventListener: () => {}
    },
    console: console
};

const vm = require('vm');
vm.createContext(sandbox);
vm.runInContext(jsCode, sandbox);

console.log("=== 1. 初期状態 (土居さんがﾏ101選択) ===");
sandbox.refreshAllRoomStates();
console.log("行1 セレクト背景色:", sel1.style.backgroundColor); // 通常色 (#16a34a)
console.log("行1 アラート表示:", msg1.style.display); // none
console.log("行2のﾏ101ラベル:", sel2.options[1].textContent); // "ﾏ101 (土居)"
console.assert(sel1.style.backgroundColor === '#16a34a', "通常色エラー");
console.assert(sel2.options[1].textContent === 'ﾏ101 (土居)', "使用中表示エラー");

console.log("\n=== 2. 影山さんもﾏ101を選択 (重複発生) ===");
sel2.value = 'ﾏ101';
sandbox.refreshAllRoomStates();
console.log("行1 セレクト背景色:", sel1.style.backgroundColor); // 重複赤 (#dc2626)
console.log("行1 アラート文字:", msg1.textContent); // "⚠️ 重複: 影山"
console.log("行2 セレクト背景色:", sel2.style.backgroundColor); // 重複赤 (#dc2626)
console.log("行2 アラート文字:", msg2.textContent); // "⚠️ 重複: 土居"
console.log("全行のﾏ101ラベル:", sel1.options[1].textContent); // "ﾏ101 (土居, 影山)"
console.assert(sel1.style.backgroundColor === '#dc2626', "重複赤色エラー");
console.assert(msg1.textContent === '⚠️ 重複: 影山', "重複相手エラー");
console.assert(sel2.options[1].textContent === 'ﾏ101 (土居, 影山)', "重複ラベルエラー");

console.log("\n=== 3. 影山さんがﾏ102に変更 (重複解消) ===");
sel2.value = 'ﾏ102';
sandbox.refreshAllRoomStates();
console.log("行1 セレクト背景色:", sel1.style.backgroundColor); // 通常色 (#16a34a)
console.log("行1 アラート表示:", msg1.style.display); // none
console.log("行2 セレクト背景色:", sel2.style.backgroundColor); // 通常色 (#16a34a)
console.log("行2 アラート表示:", msg2.style.display); // none
console.log("ﾏ101ラベル:", sel1.options[1].textContent); // "ﾏ101 (土居)"
console.log("ﾏ102ラベル:", sel1.options[2].textContent); // "ﾏ102 (影山)"
console.assert(sel1.style.backgroundColor === '#16a34a', "重複解除後色エラー");
console.assert(msg1.style.display === 'none', "アラート非表示エラー");

console.log("\n=== 4. 山田さん (GIF) がﾏ101を選択 (通常部屋割り不要注意) ===");
sel3.value = 'ﾏ101';
sandbox.refreshAllRoomStates();
console.log("行3 セレクト背景色:", sel3.style.backgroundColor); // 土居さんと重複しているため赤
console.log("行3 アラート文字:", msg3.textContent); // "⚠️ 重複: 土居 (通常部屋不要)"
console.assert(msg3.textContent.includes('通常部屋不要'), "GIF注意表示エラー");

console.log("\n🎉 全シミュレーション検証 PASSED!");
