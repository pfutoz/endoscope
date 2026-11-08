const fs = require('fs');

// furiwake_daymemo.php の HTML ソースをチェック
const html = fs.readFileSync('furiwake_daymemo.php', 'utf8');

console.log("=== furiwake_daymemo.php チェック ===");
console.log("1. link onokai_flatpickr.css:", html.includes('onokai_flatpickr.css'));
console.log("2. script onokai_flatpickr.js:", html.includes('onokai_flatpickr.js'));
console.log("3. OnokaiDatePicker.bindControl:", html.includes('OnokaiDatePicker.bindControl'));
console.log("4. newDate input:", html.includes('id="newDate"'));

// 他の画面のチェック
const modalHtml = fs.readFileSync('modal_edit.php', 'utf8');
console.log("\n=== modal_edit.php チェック ===");
console.log("1. link onokai_flatpickr.css:", modalHtml.includes('onokai_flatpickr.css'));
console.log("2. script onokai_flatpickr.js:", modalHtml.includes('onokai_flatpickr.js'));
console.log("3. OnokaiDatePicker.bindControl:", modalHtml.includes('OnokaiDatePicker.bindControl'));
console.log("4. onokai-date-picker class in exam_date:", modalHtml.includes('id="exam_date" class="form-control onokai-date-picker"'));

const searchHtml = fs.readFileSync('slot_search.php', 'utf8');
console.log("\n=== slot_search.php チェック ===");
console.log("1. OnokaiDatePicker.bindControl:", searchHtml.includes('OnokaiDatePicker.bindControl'));
console.log("2. onokai-date-picker class:", searchHtml.includes('class="date-input onokai-date-picker'));

const masterHtml = fs.readFileSync('slot_master_maintenance.php', 'utf8');
console.log("\n=== slot_master_maintenance.php チェック ===");
console.log("1. OnokaiDatePicker.bindControl:", masterHtml.includes('OnokaiDatePicker.bindControl'));
console.log("2. onokai-date-picker class:", masterHtml.includes('class="input-date onokai-date-picker'));
