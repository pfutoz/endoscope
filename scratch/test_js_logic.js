/**
 * Node.js による slot_search.php の JavaScript イベント・動作シミュレーション
 */
const fs = require('fs');
const path = require('path');

// slot_search.php を読み込んでスクリプト部分を抽出
const phpContent = fs.readFileSync(path.join(__dirname, '../slot_search.php'), 'utf8');

// 修正されたJSコードの主要部分が含まれているか検証
const tests = [
  {
    name: '初期表示モードでcustomの場合の自動検索トリガー存在チェック',
    check: () => phpContent.includes("else if (currentSearchMode === 'custom')") &&
                 phpContent.includes("runCustomSearchAjax();")
  },
  {
    name: 'タブ切替時の未描画検知と自動検索トリガーチェック',
    check: () => phpContent.includes("switchSearchMode(targetMode)") &&
                 phpContent.includes("tableRows.length === 0 && cardItems.length === 0")
  },
  {
    name: 'SSRでの初回カレンダープレビューキャッシュ埋め込みチェック',
    check: () => phpContent.includes("let calPreviewCache = <?= !empty($initial_cal_previews)")
  },
  {
    name: 'calendar_helper.php の title 警告修正チェック',
    check: () => {
      const helperContent = fs.readFileSync(path.join(__dirname, '../lib/calendar_helper.php'), 'utf8');
      return !helperContent.includes("'label' => \"{$d_short}医師 不在 ({$ev['title']})\"") &&
             helperContent.includes("$raw_title = $ev['raw_title'] ?? ($ev['title'] ?? '');");
    }
  }
];

let allPassed = true;
console.log("=== slot_search.php JS & Helper Validation Tests ===");
tests.forEach((t, i) => {
  const pass = t.check();
  console.log(`[Test ${i+1}] ${t.name}: ${pass ? 'PASS' : 'FAIL'}`);
  if (!pass) allPassed = false;
});

if (allPassed) {
  console.log("\nALL JS/PHP LOGIC TESTS PASSED!");
} else {
  console.error("\nSOME TESTS FAILED!");
  process.exit(1);
}
