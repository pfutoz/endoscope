-- ==============================================================================
-- マイグレーションスクリプト: 時間枠日別・期間別オーバーライドテーブル新設
-- 実行日: 2026-10-22
-- 対象テーブル: m2_waku_override
-- 目的:
--   2026-10-21確定の将来アーキテクチャに基づき、基本時間枠（m2_waku）に
--   影響を与えることなく、特定日・期間の枠名変更・枠数増減・休止（🚫）を
--   安全に実現するためのオーバーライド差分テーブルを新設する。
-- ==============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS m2_waku_override (
    override_id SERIAL PRIMARY KEY,
    department_code VARCHAR(10) NOT NULL,                    -- 診療科 ('GASTRO' / 'PROCT')
    base_waku_id INTEGER REFERENCES m2_waku(id) ON DELETE SET NULL, -- 紐付く基本枠ID（新規臨時枠ならNULL）
    override_type VARCHAR(10) NOT NULL,                      -- 'DATE' (特定日) または 'PERIOD' (期間)
    target_date DATE,                                        -- 特定日の場合 (例: '2026-11-05')
    start_date DATE,                                         -- 期間指定の開始日 (例: '2027-01-01')
    end_date DATE,                                           -- 期間指定の終了日 (例: '2027-03-31')
    display_waku_name VARCHAR(50) NOT NULL,                  -- 上書き表示枠名 (例: 'TCF16:30(2027新設)')
    group_code VARCHAR(10) NOT NULL,                         -- 検査グループ ('GIF', 'TCF', 'ECHO')
    time_start TIME,                                         -- 枠開始時刻
    time_end TIME,                                           -- 枠終了時刻
    display_order INTEGER,                                   -- 表示順
    is_suspended BOOLEAN DEFAULT FALSE,                      -- TRUEの場合、その日・期間はこの枠を休止（🚫ブロック表示）
    reason VARCHAR(100),                                     -- 変更理由 (例: '2027年体制移行', '院内研修')
    is_active BOOLEAN DEFAULT TRUE,                          -- オーバーライド設定自体の有効/無効
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- インデックス作成（高速検索・インメモリマージ用）
CREATE INDEX IF NOT EXISTS idx_waku_override_date 
    ON m2_waku_override(department_code, target_date) 
    WHERE override_type = 'DATE' AND is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_waku_override_period 
    ON m2_waku_override(department_code, start_date, end_date) 
    WHERE override_type = 'PERIOD' AND is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_waku_override_base 
    ON m2_waku_override(base_waku_id);

COMMIT;
