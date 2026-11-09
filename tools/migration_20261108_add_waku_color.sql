-- ============================================================================
-- 生成日時: 2026-11-08 15:06 JST
-- ファイル名: tools/migration_20261108_add_waku_color.sql
-- 概要: 基本時間枠マスタ (m2_waku) に背景色指定カラム (waku_color) を追加
-- ============================================================================

BEGIN;

ALTER TABLE m2_waku
    ADD COLUMN IF NOT EXISTS waku_color VARCHAR(20) NOT NULL DEFAULT '';

COMMENT ON COLUMN m2_waku.waku_color IS '基本時間枠の背景色（HEXカラーコード 例: #dbeafe、未設定時は空文字）';

COMMIT;

