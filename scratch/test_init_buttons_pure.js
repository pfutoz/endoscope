class Element {
    constructor(tag, dataset = {}, isInput = false) {
        this.tag = tag;
        this.dataset = dataset;
        this.isInput = isInput;
        this.value = isInput ? dataset.value || '' : '';
        this.classes = new Set();
        if (dataset.cls) dataset.cls.split(' ').forEach(c => this.classes.add(c));
    }
    get classList() {
        return {
            contains: (c) => this.classes.has(c),
            add: (c) => this.classes.add(c),
            remove: (c) => this.classes.delete(c),
            toggle: (c) => {
                if (this.classes.has(c)) { this.classes.delete(c); return false; }
                else { this.classes.add(c); return true; }
            }
        };
    }
    click() {
        if (this.onclick) this.onclick();
    }
}

class Container {
    constructor() {
        this.allBtn = new Element('button', { value: 'all', cls: 'btn active-all' });
        this.docBtns = [
            new Element('button', { group: 'doctor', value: '哲也', cls: 'btn' }),
            new Element('button', { group: 'doctor', value: '義智', cls: 'btn' }),
            new Element('button', { group: 'doctor', value: '慶太郎', cls: 'btn' })
        ];
        this.input = new Element('input', { value: 'all' }, true);
    }
    querySelector(sel) {
        if (sel === '.btn[data-value="all"]') return this.allBtn;
        return null;
    }
    querySelectorAll(sel) {
        if (sel.includes(':not([data-value="all"])')) return this.docBtns;
        return [this.allBtn, ...this.docBtns];
    }
}

const container = new Container();
const input = container.input;

function initButtons(groupName, cont) {
    const allBtn = cont.querySelector('.btn[data-value="all"]');
    const itemBtns = cont.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
    
    cont.querySelectorAll('.btn[data-group="' + groupName + '"]').forEach(function(btn) {
        btn.onclick = function() {
            var val = this.dataset.value;
            
            if (val === 'all') {
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
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
        };
    });
}

initButtons('doctor', container);

console.log("--- 初期状態 ---");
console.log("input.value:", input.value, "all-active:", container.allBtn.classList.contains('active-all'));

console.log("--- 1. 哲也をクリック ---");
container.docBtns[0].click();
console.log("input.value:", input.value, "all-active:", container.allBtn.classList.contains('active-all'), "哲也-active:", container.docBtns[0].classList.contains('active'));

console.log("--- 2. 義智をクリック (複数選択) ---");
container.docBtns[1].click();
console.log("input.value:", input.value, "哲也-active:", container.docBtns[0].classList.contains('active'), "義智-active:", container.docBtns[1].classList.contains('active'));

console.log("--- 3. 慶太郎をクリック (3人全員選択) ---");
container.docBtns[2].click();
console.log("input.value:", input.value, "哲也-active:", container.docBtns[0].classList.contains('active'), "義智-active:", container.docBtns[1].classList.contains('active'), "慶太郎-active:", container.docBtns[2].classList.contains('active'), "all-active:", container.allBtn.classList.contains('active-all'));

console.log("--- 4. 哲也を解除 (2人に戻す) ---");
container.docBtns[0].click();
console.log("input.value:", input.value, "哲也-active:", container.docBtns[0].classList.contains('active'), "義智-active:", container.docBtns[1].classList.contains('active'), "慶太郎-active:", container.docBtns[2].classList.contains('active'));

console.log("--- 5. 全医師をクリック ---");
container.allBtn.click();
console.log("input.value:", input.value, "all-active:", container.allBtn.classList.contains('active-all'), "個別ボタン全部off:", !container.docBtns[0].classList.contains('active') && !container.docBtns[1].classList.contains('active') && !container.docBtns[2].classList.contains('active'));
