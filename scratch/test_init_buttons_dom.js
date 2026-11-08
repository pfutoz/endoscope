const { JSDOM } = require('jsdom');

const html = `
<!DOCTYPE html>
<html>
<body>
    <div id="doctorContainer">
        <button type="button" class="btn active-all" data-group="doctor" data-value="all">全医師</button>
        <button type="button" class="btn" data-group="doctor" data-value="哲也">哲也</button>
        <button type="button" class="btn" data-group="doctor" data-value="義智">義智</button>
        <button type="button" class="btn" data-group="doctor" data-value="慶太郎">慶太郎</button>
    </div>
    <input type="hidden" name="doctor" id="doctorInput" value="all">
</body>
</html>
`;

const dom = new JSDOM(html);
const document = dom.window.document;
let triggered = false;
function triggerConditionChange() {
    triggered = true;
}

// 修正後の initButtons ロジック
function initButtons(groupName, containerSelector) {
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    container.querySelectorAll('.btn[data-group="' + groupName + '"]').forEach(function(btn) {
        btn.onclick = function() {
            var val = this.dataset.value;
            var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
            var allBtn = container.querySelector('.btn[data-value="all"]');
            var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
            
            if (val === 'all') {
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
                triggerConditionChange();
                return;
            }
            
            if (allBtn.classList.contains('active-all')) {
                allBtn.classList.remove('active-all');
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
            } else {
                this.classList.toggle('active');
            }
            
            var selected = [];
            itemBtns.forEach(function(b) {
                if (b.classList.contains('active')) selected.push(b.dataset.value);
            });
            
            if (selected.length === 0) {
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else {
                allBtn.classList.remove('active-all');
                input.value = selected.join(',');
            }

            triggerConditionChange();
        };
    });
}

initButtons('doctor', '#doctorContainer');

const btnAll = document.querySelector('[data-value="all"]');
const btnTetsuya = document.querySelector('[data-value="哲也"]');
const btnYoshitomo = document.querySelector('[data-value="義智"]');
const btnKeitaro = document.querySelector('[data-value="慶太郎"]');
const input = document.getElementById('doctorInput');

console.log("--- 初期状態 ---");
console.log("input.value:", input.value, "all-active:", btnAll.classList.contains('active-all'));

console.log("--- 1. 哲也をクリック ---");
btnTetsuya.click();
console.log("input.value:", input.value, "all-active:", btnAll.classList.contains('active-all'), "哲也-active:", btnTetsuya.classList.contains('active'));

console.log("--- 2. 義智をクリック (複数選択) ---");
btnYoshitomo.click();
console.log("input.value:", input.value, "哲也-active:", btnTetsuya.classList.contains('active'), "義智-active:", btnYoshitomo.classList.contains('active'));

console.log("--- 3. 慶太郎をクリック (3人全員選択) ---");
btnKeitaro.click();
console.log("input.value:", input.value, "全員active:", btnTetsuya.classList.contains('active') && btnYoshitomo.classList.contains('active') && btnKeitaro.classList.contains('active'), "all-active:", btnAll.classList.contains('active-all'));

console.log("--- 4. 哲也を解除 ---");
btnTetsuya.click();
console.log("input.value:", input.value, "哲也-active:", btnTetsuya.classList.contains('active'), "義智-active:", btnYoshitomo.classList.contains('active'), "慶太郎-active:", btnKeitaro.classList.contains('active'));

console.log("--- 5. 全医師をクリック ---");
btnAll.click();
console.log("input.value:", input.value, "all-active:", btnAll.classList.contains('active-all'), "個別ボタン全部off:", !btnTetsuya.classList.contains('active') && !btnYoshitomo.classList.contains('active') && !btnKeitaro.classList.contains('active'));
