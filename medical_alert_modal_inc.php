<?php
/**
 * 生成・改修日時: 2026-10-01 21:13:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\medical_alert_modal_inc.php
 * ファイル名: medical_alert_modal_inc.php
 * 
 * 概要:
 *   医療安全アラート ランナー＆共通チップスタイル (iframeモーダル分離版)
 *   modal_edit.php と同一アーキテクチャの Native <dialog> + <iframe> による動的モーダル実行。
 *   画面本体は別ファイル「modal_medical_alert.php」として完全分離。
 * 
 * 履歴:
 *   - 2026-09-27: 新規作成 (インラインモーダル・タグ自動判定)
 *   - 2026-09-28: escapeHtml定義漏れによるスピナー停止不具合の修正
 *   - 2026-10-01: 【独立ファイル化・modal_edit同等分離】
 *       1. 700行超のインラインモーダルDOM・スキャン判定描画JSを「modal_medical_alert.php」へ完全移設
 *       2. 本ファイルを親画面用共通アラートチップCSS＋Native <dialog> & <iframe> ランナーへ超軽量化
 *       3. openMedicalAlertScanModal() の完全互換性を維持し、既存テンプレート側の呼出コード無変更でiframe動作へ昇華
 *       4. postMessage 連動 (medical_alert_close / medical_alert_saved) 実装
 */
?>
<!-- ── 画面表示用共通アラートチップ スタイル ── -->
<style>
.med-alerts-wrapper {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    flex-wrap: wrap;
    vertical-align: middle;
    margin-left: 4px;
}
.med-alert-chip {
    display: inline-flex;
    align-items: center;
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 9.5px;
    font-weight: 800;
    line-height: 1.25;
    white-space: nowrap;
    box-sizing: border-box;
}
.chip-anti { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; animation: medPulseChip 2s infinite; }
.chip-anti-verified { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
.chip-allergy { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.chip-infection { background: #fee2e2; color: #991b1b; border: 1.5px solid #dc2626; }
.chip-cold { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }

@keyframes medPulseChip {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.75; }
}

/* ── 医療安全アラート iframe モーダルランナー スタイル (modal_editと同等) ── */
#modalMedicalAlertRunnerDialog {
    position: fixed;
    inset: 0;
    margin: auto;
    width: 1060px;
    max-width: calc(100vw - 32px);
    height: 88vh;
    max-height: 880px;
    padding: 0;
    border: 1px solid rgba(148, 163, 184, 0.4);
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.1);
    background: #ffffff;
    overflow: hidden;
    z-index: 10001;
    opacity: 0;
    transform: scale(0.96) translateY(10px);
    transition: opacity 0.2s ease-out, transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), overlay 0.2s allow-discrete, display 0.2s allow-discrete;
}
#modalMedicalAlertRunnerDialog[open] {
    opacity: 1;
    transform: scale(1) translateY(0);
}
#modalMedicalAlertRunnerDialog::backdrop {
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    opacity: 0;
    transition: opacity 0.2s ease-out, overlay 0.2s allow-discrete, display 0.2s allow-discrete;
}
#modalMedicalAlertRunnerDialog[open]::backdrop {
    opacity: 1;
}
@starting-style {
    #modalMedicalAlertRunnerDialog[open] {
        opacity: 0;
        transform: scale(0.96) translateY(10px);
    }
    #modalMedicalAlertRunnerDialog[open]::backdrop {
        opacity: 0;
    }
}
.med-runner-wrapper {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
}
.med-runner-iframe {
    width: 100%;
    height: 100%;
    border: none;
    flex: 1;
    background: #f8fafc;
}
.med-runner-loading {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.92);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: #334155;
    font-weight: 700;
    font-size: 13px;
    z-index: 5;
    transition: opacity 0.2s;
    pointer-events: none;
}
.med-runner-spinner {
    width: 34px;
    height: 34px;
    border: 3.5px solid #e2e8f0;
    border-top-color: #0d9488;
    border-radius: 50%;
    animation: medRunnerSpin 0.7s linear infinite;
}
@keyframes medRunnerSpin {
    to { transform: rotate(360deg); }
}
</style>

<script>
/**
 * 医療安全アラート モーダルランナー (modal_edit.php 同等連携)
 */
(function() {
    const DIALOG_ID = 'modalMedicalAlertRunnerDialog';
    const IFRAME_ID = 'modalMedicalAlertRunnerFrame';

    function ensureMedAlertRunnerElement() {
        let dialog = document.getElementById(DIALOG_ID);
        if (dialog) return dialog;

        dialog = document.createElement('dialog');
        dialog.id = DIALOG_ID;
        dialog.innerHTML = `
            <div class="med-runner-wrapper">
                <div id="medAlertRunnerLoading" class="med-runner-loading">
                    <div class="med-runner-spinner"></div>
                    <div>医療安全アラート スキャン画面を読み込み中...</div>
                </div>
                <iframe id="${IFRAME_ID}" class="med-runner-iframe" src="about:blank"></iframe>
            </div>
        `;

        document.body.appendChild(dialog);

        const iframe = dialog.querySelector(`#${IFRAME_ID}`);
        const loading = dialog.querySelector('#medAlertRunnerLoading');

        if (iframe && loading) {
            iframe.addEventListener('load', function() {
                if (iframe.src && iframe.src !== 'about:blank') {
                    loading.style.opacity = '0';
                    setTimeout(() => { loading.style.display = 'none'; }, 200);
                }
            });
        }

        // ダイアログ外（暗転部）クリックで閉じる
        dialog.addEventListener('click', function(e) {
            const rect = dialog.getBoundingClientRect();
            const isInDialog = (
                rect.top <= e.clientY && e.clientY <= rect.bottom &&
                rect.left <= e.clientX && e.clientX <= rect.right
            );
            if (!isInDialog) {
                closeMedicalAlertRunner(false);
            }
        });

        // ESCキーで閉じた際の後処理
        dialog.addEventListener('cancel', function() {
            closeMedicalAlertRunner(false);
        });

        return dialog;
    }

    // 医療安全アラートモーダルを開く (modal_medical_alert.php を iframe で実行)
    window.openMedicalAlertScanModal = function(options = {}) {
        const dialog = ensureMedAlertRunnerElement();
        const iframe = document.getElementById(IFRAME_ID);
        const loading = document.getElementById('medAlertRunnerLoading');
        if (!dialog || !iframe) return;

        let url = 'modal_medical_alert.php';
        let params = [];
        if (options.date) params.push('date=' + encodeURIComponent(options.date));
        if (options.dept) params.push('dept=' + encodeURIComponent(options.dept));
        if (options.year) params.push('year=' + encodeURIComponent(options.year));
        if (options.month) params.push('month=' + encodeURIComponent(options.month));
        if (options.schedule_ids) {
            const sids = Array.isArray(options.schedule_ids) ? options.schedule_ids.join(',') : options.schedule_ids;
            params.push('schedule_ids=' + encodeURIComponent(sids));
        }

        if (params.length > 0) {
            url += '?' + params.join('&');
        }

        if (loading) {
            loading.style.display = 'flex';
            loading.style.opacity = '1';
        }

        iframe.src = url;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    };

    // 医療安全アラートモーダルを閉じる
    window.closeMedicalAlertRunner = function(needReload = false) {
        const dialog = document.getElementById(DIALOG_ID);
        const iframe = document.getElementById(IFRAME_ID);
        if (dialog) {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }
        if (iframe) {
            iframe.src = 'about:blank';
        }

        if (needReload) {
            if (typeof showAuditToast === 'function') {
                showAuditToast('✅ 医療安全アラートを反映しました', 'success');
            } else if (typeof showToast === 'function') {
                showToast('✅ 医療安全アラートを反映しました', 'success');
            }
            setTimeout(() => {
                window.location.reload();
            }, 300);
        }
    };

    // 後方互換性エイリアス
    window.closeMedicalAlertModal = function() {
        window.closeMedicalAlertRunner(false);
    };

    // postMessage 受信ハンドラ (modal_medical_alert.php からのメッセージ)
    window.addEventListener('message', function(event) {
        if (!event.data) return;
        const data = event.data;

        if (data.type === 'medical_alert_close') {
            window.closeMedicalAlertRunner(false);
        } else if (data.type === 'medical_alert_saved') {
            window.closeMedicalAlertRunner(true);
        }
    });

})();
</script>
