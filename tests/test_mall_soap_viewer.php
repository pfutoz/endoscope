<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\test_mall_soap_viewer.php
 * ファイル名: test_mall_soap_viewer.php
 * 概要: MALL3電子カルテ SOAP明細 ＆ メモ ＆ 添付画像 ＆ ファイリング画像 統合ビューア
 * 改修: 2026-09-02
 *   - T_ImportPicture の保存ルートを \\{IP}\ImageMall3\ImportPicture\ に設定
 *   - PicturePath のカンマ区切り、?以降のメタデータ除去、PDF対応を実装
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

$patient_no   = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : '';
$target_date  = isset($_GET['date']) ? trim($_GET['date']) : '';
$karte_source = isset($_GET['source']) ? trim($_GET['source']) : 'new';
$do_search    = isset($_GET['do_search']) && (string)$_GET['do_search'] === '1';

// 画像表示フラグ（デフォルト: OFF）
$show_images  = isset($_GET['show_images']) && (string)$_GET['show_images'] === '1';

// 赤丸表示フラグ（画像表示がONの場合のみ有効）
$show_dots_req = isset($_GET['show_dots']) && (string)$_GET['show_dots'] === '1';
$show_dots     = $show_images && $show_dots_req;

if (!in_array($karte_source, ['new', 'ono'], true)) {
    $karte_source = 'new';
}

if ($karte_source === 'ono') {
    $source_label = 'おのカルテ（旧）';
    $source_color = '#ea580c';
    $server_ip    = '192.168.1.52';
    $db_name      = 'his00';
    $db_uid       = 'sa';
    $db_pwd       = 'Onokomon-hp';
    $net_user     = 'Administrator';
    $net_pass     = 'Onokomon-hp';
} else {
    $source_label = '新カルテ（現行）';
    $source_color = '#0284c7';
    $server_ip    = '192.168.1.77';
    $db_name      = 'his00';
    $db_uid       = 'sa';
    $db_pwd       = 'Kochi-cl';
    $net_user     = 'Administrator';
    $net_pass     = 'Kochi-cl';
}

// 画像保存ルート
$image_dir         = "\\\\{$server_ip}\\ImageMall3\\Image";
$import_picture_dir = "\\\\{$server_ip}\\ImageMall3\\ImportPicture";

$db_error         = '';
$patient          = null;
$history_dates    = [];
$date_image_map   = [];
$current_date     = '';
$soap_records     = [];
$found_images     = [];
$filing_images    = [];
$karte_ids        = [];
$extracted_ono_id = null;
$mall_memos       = [
    1 => ['name' => '当日メモ', 'note' => '', 'date' => '', 'update' => ''],
    2 => ['name' => 'カルテメモ', 'note' => '', 'date' => '', 'update' => ''],
    3 => ['name' => '患者メモ（連絡メモ）', 'note' => '', 'date' => '', 'update' => '']
];

function extractOnoPatientNo(string $karte_memo): ?string {
    if ($karte_memo === '') return null;
    $lines = preg_split('/\r\n|\r|\n/', trim($karte_memo));
    $first_line = $lines[0] ?? '';
    $normalized = mb_convert_kana($first_line, 'asKV');
    if (preg_match('/(?:おの|ono|オノ)[^\d]{0,6}[:：=\-\s]*([0-9]{4,5})/iu', $normalized, $matches)) {
        return $matches[1];
    }
    return null;
}

if ($patient_no !== '' && $do_search) {
    try {
        $dsn = "sqlsrv:server={$server_ip};Database={$db_name};TrustServerCertificate=1;ApplicationIntent=ReadOnly;";
        $conn = new PDO($dsn, $db_uid, $db_pwd, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $conn->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

        // 1. 患者基本情報
        $stmt_p = $conn->prepare("
            SELECT 
                PatientID, PatientNo, PatientName, PatientKana, Sex, 
                CONVERT(varchar(10), BirthDay, 120) AS BirthDayStr 
            FROM M_Patient 
            WHERE PatientNo = :pno
        ");
        $stmt_p->execute([':pno' => $patient_no]);
        $patient = $stmt_p->fetch();

        if ($patient) {
            $patient_id = (int)$patient['PatientID'];

            // 2. MALL同期メモ (t_memo)
            $sql_memo = "
                SELECT 
                    Sub.DivID, Sub.Note,
                    CONVERT(varchar(10), Sub.MemoDate, 120) AS MemoDateStr,
                    CONVERT(varchar(19), Sub.LastUpdateTime, 120) AS LastUpdateTimeStr
                FROM (
                    SELECT 
                        M.DivID, M.Note, M.MemoDate, M.LastUpdateTime,
                        ROW_NUMBER() OVER (PARTITION BY M.DivID ORDER BY M.LastUpdateTime DESC) AS row_num
                    FROM t_memo M
                    WHERE M.PatientID = :pid AND M.DivID IN (1, 2, 3)
                ) Sub
                WHERE Sub.row_num = 1
                ORDER BY Sub.DivID ASC
            ";
            $stmt_memo = $conn->prepare($sql_memo);
            $stmt_memo->execute([':pid' => $patient_id]);
            while ($m_row = $stmt_memo->fetch()) {
                $div_id = (int)$m_row['DivID'];
                if (isset($mall_memos[$div_id])) {
                    $mall_memos[$div_id]['note']   = $m_row['Note'] !== null ? trim($m_row['Note']) : '';
                    $mall_memos[$div_id]['date']   = (string)($m_row['MemoDateStr'] ?? '');
                    $mall_memos[$div_id]['update'] = (string)($m_row['LastUpdateTimeStr'] ?? '');
                }
            }

            if ($karte_source === 'new') {
                $extracted_ono_id = extractOnoPatientNo($mall_memos[2]['note'] ?? '');
            }

            // 3. 受診履歴一覧取得
            $date_karte_map = [];
            if ($show_dots) {
                $stmt_dates = $conn->prepare("
                    SELECT 
                        CONVERT(varchar(10), k.ConsultDate, 120) AS ConsultDateStr,
                        k.KarteID,
                        CASE 
                            WHEN EXISTS (SELECT 1 FROM T_OpePicObj o WHERE o.KarteID = k.KarteID) THEN 1
                            WHEN EXISTS (SELECT 1 FROM T_PictureObjective po WHERE po.KarteID = k.KarteID) THEN 1
                            WHEN EXISTS (SELECT 1 FROM T_DrOrder d WHERE d.KarteID = k.KarteID AND d.PicObjOutFlag = 1) THEN 1
                            ELSE 0 
                        END AS DbHasImage
                    FROM T_Karte k
                    WHERE k.PatientID = :pid 
                    ORDER BY ConsultDateStr DESC, k.KarteID DESC
                ");
                $stmt_dates->execute([':pid' => $patient_id]);
                $raw_history = $stmt_dates->fetchAll();

                foreach ($raw_history as $row) {
                    $d = (string)$row['ConsultDateStr'];
                    $kid = (int)$row['KarteID'];
                    $db_img = (int)$row['DbHasImage'] === 1;

                    if (!isset($date_image_map[$d])) {
                        $history_dates[] = $d;
                        $date_image_map[$d] = false;
                        $date_karte_map[$d] = [];
                    }
                    $date_karte_map[$d][] = $kid;
                    if ($db_img) {
                        $date_image_map[$d] = true;
                    }
                }
            } else {
                $stmt_dates = $conn->prepare("
                    SELECT DISTINCT
                        CONVERT(varchar(10), ConsultDate, 120) AS ConsultDateStr
                    FROM T_Karte
                    WHERE PatientID = :pid 
                    ORDER BY ConsultDateStr DESC
                ");
                $stmt_dates->execute([':pid' => $patient_id]);
                $history_dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);
            }

            if (!empty($history_dates)) {
                if ($target_date === '' || !in_array($target_date, $history_dates, true)) {
                    $target_date = $history_dates[0];
                }
                $current_date = $target_date;

                if (isset($date_karte_map[$current_date])) {
                    $karte_ids = $date_karte_map[$current_date];
                } else {
                    $stmt_k = $conn->prepare("
                        SELECT KarteID 
                        FROM T_Karte 
                        WHERE PatientID = :pid 
                          AND CAST(ConsultDate AS DATE) = CAST(:cdate AS DATE)
                        ORDER BY KarteID ASC
                    ");
                    $stmt_k->execute([':pid' => $patient_id, ':cdate' => $current_date]);
                    $karte_ids = $stmt_k->fetchAll(PDO::FETCH_COLUMN);
                }

                // 4. 対象受診日のSOAP明細取得
                $sql = "
                    WITH LatestKarteS AS (
                        SELECT 
                            s.KarteSID, s.KarteID, s.KarteSNo, s.DataID, s.EditNo, s.Note, s.EditStaffID, s.EditDate,
                            ROW_NUMBER() OVER (
                                PARTITION BY s.KarteID, s.KarteSNo, s.DataID 
                                ORDER BY s.EditNo DESC
                            ) AS rn
                        FROM T_KarteS s
                        INNER JOIN T_Karte k ON s.KarteID = k.KarteID
                        WHERE k.PatientID = :pid
                          AND CAST(k.ConsultDate AS DATE) = CAST(:cdate AS DATE)
                    )
                    SELECT 
                        ls.KarteID, ls.DataID,
                        CASE ls.DataID 
                            WHEN 1 THEN 'S' 
                            WHEN 2 THEN 'O' 
                            WHEN 3 THEN 'A' 
                            WHEN 4 THEN 'P' 
                            ELSE 'その他' 
                        END AS SoapType,
                        CONVERT(varchar(max), ls.Note) AS NoteText,
                        CONVERT(varchar(19), ls.EditDate, 120) AS EditDateTime,
                        st.StaffName AS DoctorName
                    FROM LatestKarteS ls
                    INNER JOIN T_Karte k ON ls.KarteID = k.KarteID
                    LEFT JOIN M_Staff st ON ls.EditStaffID = st.StaffID
                    WHERE ls.rn = 1
                    ORDER BY ls.KarteID ASC, ls.KarteSNo ASC, ls.DataID ASC
                ";
                $stmt_soap = $conn->prepare($sql);
                $stmt_soap->execute([':pid' => $patient_id, ':cdate' => $current_date]);
                $soap_records = $stmt_soap->fetchAll();

                // 5. 画像ON時のみファイルサーバー通信
                if ($show_images) {
                    $auth_cmd = "net use \\\\{$server_ip}\\ImageMall3 {$net_pass} /USER:{$net_user} 2>&1";
                    @exec($auth_cmd, $net_output, $net_ret);

                    // A. カルテ添付画像 (シェーマ)
                    if (is_dir($image_dir)) {
                        foreach ($karte_ids as $kid) {
                            $pattern = $image_dir . "\\" . $kid . "_*.png";
                            $files = glob($pattern);
                            if (!empty($files)) {
                                foreach ($files as $file_path) {
                                    $raw_data = file_get_contents($file_path);
                                    $found_images[] = [
                                        'karte_id'  => $kid,
                                        'file_name' => basename($file_path),
                                        'file_size' => round(filesize($file_path) / 1024, 1) . ' KB',
                                        'file_time' => date("Y-m-d H:i:s", filemtime($file_path)),
                                        'src'       => 'data:image/png;base64,' . base64_encode($raw_data)
                                    ];
                                }
                            }
                        }
                    }

                    // B. ファイリング画像 (T_ImportPicture)
                    $sql_filing = "
                        SELECT 
                            ImportPictureID,
                            PicturePath,
                            CONVERT(varchar(10), PictureDate, 120) AS PictureDateStr,
                            Title,
                            ImportPictureNote
                        FROM T_ImportPicture
                        WHERE PatientID = :pid
                        ORDER BY PictureDate DESC, ImportPictureID DESC
                    ";
                    $stmt_filing = $conn->prepare($sql_filing);
                    $stmt_filing->execute([':pid' => $patient_id]);
                    $raw_filing = $stmt_filing->fetchAll();

                    foreach ($raw_filing as $f_item) {
                        $raw_paths_field = trim((string)($f_item['PicturePath'] ?? ''));
                        if ($raw_paths_field === '') continue;

                        // カンマ区切りの複数パスを分割
                        $sub_paths = explode(',', $raw_paths_field);

                        foreach ($sub_paths as $single_path_entry) {
                            $single_path_entry = trim($single_path_entry);
                            if ($single_path_entry === '') continue;

                            // ?以降の更新パラメータ/メタデータ（例: ?2026/06/13 17:22:32|98）を除去
                            if (strpos($single_path_entry, '?') !== false) {
                                $single_path_entry = explode('?', $single_path_entry)[0];
                            }

                            // ディレクトリ区切り文字を正規化
                            $clean_rel_path = str_replace('/', '\\', trim($single_path_entry));
                            $candidate_file = $import_picture_dir . '\\' . ltrim($clean_rel_path, '\\');

                            // ファイルが存在しない場合はサブフォルダを無視して直下・ファイル名でも探索
                            if (!file_exists($candidate_file)) {
                                $candidate_file = $import_picture_dir . '\\' . basename($clean_rel_path);
                            }

                            if (file_exists($candidate_file)) {
                                $raw_data = file_get_contents($candidate_file);
                                $ext = strtolower(pathinfo($candidate_file, PATHINFO_EXTENSION));
                                $is_pdf = ($ext === 'pdf');

                                $mime_type = 'image/jpeg';
                                if ($ext === 'png') $mime_type = 'image/png';
                                elseif ($ext === 'gif') $mime_type = 'image/gif';
                                elseif ($ext === 'pdf') $mime_type = 'application/pdf';

                                $base64_src = 'data:' . $mime_type . ';base64,' . base64_encode($raw_data);

                                $filing_images[] = [
                                    'id'       => (int)$f_item['ImportPictureID'],
                                    'title'    => (string)($f_item['Title'] ?? '（無題）'),
                                    'date'     => (string)($f_item['PictureDateStr'] ?? ''),
                                    'note'     => (string)($f_item['ImportPictureNote'] ?? ''),
                                    'is_pdf'   => $is_pdf,
                                    'src'      => $base64_src,
                                    'filename' => basename($candidate_file),
                                    'rel_path' => $clean_rel_path,
                                    'size'     => round(filesize($candidate_file) / 1024, 1) . ' KB'
                                ];
                            }
                        }
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $db_error = "[{$source_label}] DB接続エラー ({$server_ip}): " . $e->getMessage();
    }
}

function getSoapLabelText(string $type): string {
    $map = [
        'S' => '主訴・患者情報 (Subjective)',
        'O' => '所見・検査結果 (Objective)',
        'A' => '評価・診断 (Assessment)',
        'P' => '治療計画・処置 (Plan)'
    ];
    return $map[$type] ?? 'その他';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>カルテSOAP＆画像ビューア<?= $patient_no !== '' ? ' - ' . htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8') : '' ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 16px;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            max-width: 1120px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            padding: 20px 24px;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid <?= $source_color ?>;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-bar h2 {
            margin: 0;
            font-size: 18px;
            color: #0f172a;
        }

        .source-toggle-group {
            display: inline-flex;
            background: #e2e8f0;
            padding: 3px;
            border-radius: 6px;
            gap: 2px;
        }
        .source-toggle-btn {
            border: none;
            background: transparent;
            padding: 4px 14px;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
        }
        .source-toggle-btn:hover { color: #0f172a; }
        .source-toggle-btn.active-new {
            background: #0284c7;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .source-toggle-btn.active-ono {
            background: #ea580c;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .search-nav {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .input-text {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
            width: 130px;
        }
        .select-date {
            padding: 5px 10px;
            border: 1.5px solid <?= $source_color ?>;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
            background: #fff;
            color: <?= $source_color ?>;
        }
        .btn {
            padding: 6px 14px;
            border: none;
            border-radius: 4px;
            background: <?= $source_color ?>;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
            font-size: 12px;
        }
        .btn:hover { opacity: 0.9; }

        .image-toggle-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            background: <?= $show_images ? '#ecfdf5' : '#f1f5f9' ?>;
            border: 1.5px solid <?= $show_images ? '#10b981' : '#cbd5e1' ?>;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            color: <?= $show_images ? '#047857' : '#64748b' ?>;
            transition: all 0.15s ease;
            user-select: none;
        }
        .image-toggle-label input[type="checkbox"] {
            margin: 0;
            cursor: pointer;
            width: 14px;
            height: 14px;
            accent-color: #10b981;
        }

        .dot-toggle-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            user-select: none;
            transition: all 0.15s ease;
            <?= $show_images ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.45;' ?>
            background: <?= ($show_images && $show_dots) ? '#fef2f2' : '#f1f5f9' ?>;
            border: 1.5px solid <?= ($show_images && $show_dots) ? '#ef4444' : '#cbd5e1' ?>;
            color: <?= ($show_images && $show_dots) ? '#b91c1c' : '#64748b' ?>;
        }
        .dot-toggle-label input[type="checkbox"] {
            margin: 0;
            width: 13px;
            height: 13px;
            accent-color: #ef4444;
            <?= !$show_images ? 'cursor: not-allowed;' : 'cursor: pointer;' ?>
        }

        .btn-ono-trigger {
            background: #ea580c;
            color: #ffffff;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: none;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(234, 88, 12, 0.3);
        }
        .btn-ono-trigger:hover { background: #c2410c; }

        .btn-filing {
            background: #475569;
            color: #fff;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-filing:hover { background: #334155; }
        .btn-filing.has-items { background: #0d9488; }
        .btn-filing.has-items:hover { background: #0f766e; }

        .patient-banner {
            background: <?= $karte_source === 'ono' ? '#fff7ed' : '#eff6ff' ?>;
            border: 1px solid <?= $karte_source === 'ono' ? '#fed7aa' : '#bfdbfe' ?>;
            border-radius: 6px;
            padding: 10px 16px;
            margin-bottom: 14px;
            display: flex;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
        }

        .memos-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }
        .memo-box {
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .memo-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .memo-body {
            padding: 8px 10px;
            font-size: 12px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 85px;
            overflow-y: auto;
            flex: 1;
            color: #1e293b;
        }
        .memo-empty { color: #94a3b8; font-style: italic; }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 18px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }

        .soap-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            margin-bottom: 12px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .soap-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            font-weight: bold;
            font-size: 12px;
        }
        .soap-body {
            padding: 12px 14px;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.6;
            font-size: 13px;
        }
        .badge-soap {
            display: inline-block;
            width: 22px;
            height: 22px;
            line-height: 22px;
            text-align: center;
            border-radius: 4px;
            color: #fff;
            font-weight: 800;
            font-size: 12px;
        }
        .meta-text {
            font-size: 11px;
            font-weight: normal;
            color: #64748b;
            margin-left: auto;
        }

        .type-S .soap-header { background: #eff6ff; color: #1d4ed8; border-bottom: 1px solid #bfdbfe; }
        .type-O .soap-header { background: #f0fdf4; color: #15803d; border-bottom: 1px solid #bbf7d0; }
        .type-A .soap-header { background: #fefce8; color: #a16207; border-bottom: 1px solid #fef08a; }
        .type-P .soap-header { background: #faf5ff; color: #7e22ce; border-bottom: 1px solid #e9d5ff; }
        .type-その他 .soap-header { background: #f1f5f9; color: #475569; border-bottom: 1px solid #cbd5e1; }

        .type-S .badge-soap { background: #2563eb; }
        .type-O .badge-soap { background: #16a34a; }
        .type-A .badge-soap { background: #ca8a04; }
        .type-P .badge-soap { background: #9333ea; }
        .type-その他 .badge-soap { background: #64748b; }

        .image-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            background: #fff;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .image-card-header {
            background: #f8fafc;
            padding: 5px 8px;
            font-size: 11px;
            font-weight: bold;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            color: #475569;
        }
        .image-card-body {
            padding: 8px;
            background: #f8fafc;
            text-align: center;
        }
        .image-card-body img {
            max-width: 100%;
            max-height: 220px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .image-card-body img:hover { transform: scale(1.02); }
        .image-card-footer {
            padding: 5px 8px;
            font-size: 10px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            background: #fff;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-window {
            background: #ffffff;
            border-radius: 8px;
            max-width: 900px;
            width: 92%;
            max-height: 86vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .modal-header {
            background: #0f172a;
            color: #fff;
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: bold;
        }
        .modal-body {
            padding: 16px;
            overflow-y: auto;
            flex: 1;
            background: #f8fafc;
        }
        .filing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
        }
        .filing-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .pdf-preview-box {
            height: 180px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fef2f2;
            border-radius: 4px;
            border: 1px dashed #f87171;
            color: #b91c1c;
            text-decoration: none;
            padding: 10px;
        }
        .pdf-preview-box:hover {
            background: #fee2e2;
        }

        .alert-error {
            background: #fef2f2;
            border: 1.5px solid #dc2626;
            color: #dc2626;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: bold;
        }
        .empty-msg {
            text-align: center;
            padding: 30px;
            color: #94a3b8;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
        }
        .image-off-box {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <div class="header-title-area">
            <h2>📑 MALL電子カルテ 統合ビューア</h2>
            
            <div class="source-toggle-group">
                <a href="?patient_no=<?= urlencode($patient_no) ?>&source=new&show_images=<?= $show_images ? '1' : '0' ?>&show_dots=<?= $show_dots ? '1' : '0' ?>" 
                   class="source-toggle-btn <?= $karte_source === 'new' ? 'active-new' : '' ?>"
                   title="現行カルテ (192.168.1.77) に切り替え">
                    🔵 新カルテ
                </a>
                <a href="?patient_no=<?= urlencode($patient_no) ?>&source=ono&show_images=<?= $show_images ? '1' : '0' ?>&show_dots=<?= $show_dots ? '1' : '0' ?>" 
                   class="source-toggle-btn <?= $karte_source === 'ono' ? 'active-ono' : '' ?>"
                   title="旧カルテ (192.168.1.52) に切り替え">
                    🟠 おのカルテ
                </a>
            </div>
        </div>
        <button type="button" onclick="window.close()" class="btn" style="background:#64748b;">✕ 閉じる</button>
    </div>

    <!-- 検索 & 日付選択バー -->
    <form method="GET" action="test_mall_soap_viewer.php" class="search-nav" id="searchForm">
        <input type="hidden" name="source" value="<?= htmlspecialchars($karte_source, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="do_search" value="1">
        
        <label for="patient_no" style="font-weight:bold;">カルテ番号 (PatientNo):</label>
        <input type="text" id="patient_no" name="patient_no" value="<?= htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8') ?>" class="input-text" placeholder="例: 57405" autofocus required>
        <button type="submit" class="btn">🔍 検索</button>

        <label class="image-toggle-label" title="チェックを入れるとファイルサーバーから画像を取得します">
            <input type="checkbox" id="show_images_chk" name="show_images" value="1" <?= $show_images ? 'checked' : '' ?> onchange="handleImageToggle(this.checked)">
            🖼️ 画像表示 <?= $show_images ? 'ON' : 'OFF' ?>
        </label>

        <?php if (!empty($history_dates)): ?>
            <div style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
                <?php if ($show_images): ?>
                    <button type="button" class="btn-filing <?= !empty($filing_images) ? 'has-items' : '' ?>" onclick="openFilingModal()">
                        📁 ファイリング画像 (<?= count($filing_images) ?>)
                    </button>
                <?php endif; ?>

                <label class="dot-toggle-label" title="<?= $show_images ? '受診日リストに画像ありマーク（🔴）を表示します' : '画像表示がONの時のみ利用できます' ?>">
                    <input type="checkbox" id="show_dots_chk" name="show_dots" value="1" <?= $show_dots ? 'checked' : '' ?> <?= !$show_images ? 'disabled' : '' ?> onchange="document.getElementById('searchForm').submit();">
                    🔴 マーク
                </label>

                <label for="date" style="font-weight:bold; color:<?= $source_color ?>;">📅 受診日切替:</label>
                <select id="date" name="date" class="select-date" onchange="this.form.submit()">
                    <?php foreach ($history_dates as $d): 
                        $dot = ($show_dots && !empty($date_image_map[$d])) ? ' 🔴' : '';
                        $latest_tag = ($d === $history_dates[0]) ? ' (最新)' : '';
                    ?>
                        <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>" <?= $d === $current_date ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?><?= $dot ?><?= $latest_tag ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!empty($db_error)): ?>
        <div class="alert-error"><?= htmlspecialchars($db_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($patient && $do_search): ?>
        <div class="patient-banner">
            <div>対象カルテ: <strong style="color:<?= $source_color ?>;"><?= $source_label ?></strong> (<?= $server_ip ?>)</div>
            <div>カルテNo: <strong><?= htmlspecialchars($patient['PatientNo'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div style="font-size:16px; font-weight:bold; color:<?= $source_color ?>;"><?= htmlspecialchars($patient['PatientName'], ENT_QUOTES, 'UTF-8') ?> 様</div>
            <div>(<?= htmlspecialchars($patient['PatientKana'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</div>
            <div>生年月日: <?= htmlspecialchars($patient['BirthDayStr'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            
            <?php if (!empty($extracted_ono_id)): ?>
                <div style="margin-left: 6px;">
                    <button type="button" class="btn-ono-trigger" onclick="openOnoKarteWindow('<?= htmlspecialchars($extracted_ono_id, ENT_QUOTES, 'UTF-8') ?>')" title="別ウィンドウでおのカルテを表示">
                        🟠 おのカルテ表示 (ID: <?= htmlspecialchars($extracted_ono_id, ENT_QUOTES, 'UTF-8') ?>)
                    </button>
                </div>
            <?php endif; ?>

            <div style="margin-left:auto; font-weight:bold; color:<?= $source_color ?>;">
                受診日: <?= htmlspecialchars($current_date, ENT_QUOTES, 'UTF-8') ?>
                <span style="font-size:11px; color:#64748b; font-weight:normal;">(受診履歴: 全<?= count($history_dates) ?>回)</span>
            </div>
        </div>

        <!-- MALL同期メモ -->
        <div class="memos-container">
            <?php foreach ($mall_memos as $div_id => $m): 
                $has_note = !empty($m['note']);
            ?>
                <div class="memo-box">
                    <div class="memo-header">
                        <span>📌 <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        
                        <?php if ($div_id === 2 && !empty($extracted_ono_id)): ?>
                            <button type="button" class="btn-ono-trigger" style="padding:1px 6px; font-size:9.5px;" onclick="openOnoKarteWindow('<?= htmlspecialchars($extracted_ono_id, ENT_QUOTES, 'UTF-8') ?>')">
                                🟠 開く: <?= htmlspecialchars($extracted_ono_id, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php else: ?>
                            <span style="font-size:9.5px; color:#94a3b8;"><?= htmlspecialchars($m['update'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="memo-body <?= !$has_note ? 'memo-empty' : '' ?>">
                        <?= $has_note ? htmlspecialchars($m['note'], ENT_QUOTES, 'UTF-8') : 'なし' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="content-grid">
            <!-- 左カラム: SOAP 明細 -->
            <div>
                <div class="section-title">
                    <span>📝 SOAP 診察記録 (<?= count($soap_records) ?> 項目)</span>
                </div>

                <?php if (empty($soap_records)): ?>
                    <div class="empty-msg">[<?= $source_label ?>] この受診日のSOAP記録はありません。</div>
                <?php else: ?>
                    <?php foreach ($soap_records as $row): 
                        $stype = $row['SoapType'];
                        $note = trim((string)$row['NoteText']);
                        if ($note === '') $note = '（記載なし）';
                    ?>
                        <div class="soap-card type-<?= $stype ?>">
                            <div class="soap-header">
                                <span class="badge-soap"><?= $stype ?></span>
                                <span><?= getSoapLabelText($stype) ?></span>
                                <span class="meta-text">
                                    医師: <strong><?= htmlspecialchars((string)($row['DoctorName'] ?? '不明'), ENT_QUOTES, 'UTF-8') ?></strong>
                                    | 入力: <?= htmlspecialchars((string)($row['EditDateTime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="soap-body"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 右カラム: 添付画像 -->
            <div>
                <div class="section-title">
                    <span>🖼️ カルテ添付画像</span>
                    <span style="font-size:11px; font-weight:normal; color:#64748b;">
                        <?= $show_images ? '(' . count($found_images) . ' 枚)' : '［表示OFF］' ?>
                    </span>
                </div>

                <?php if (!$show_images): ?>
                    <div class="image-off-box">
                        <div style="font-size:24px; margin-bottom:8px;">🖼️</div>
                        <div style="font-weight:bold; margin-bottom:6px;">画像表示は現在OFFです</div>
                        <div style="font-size:11px; color:#94a3b8; margin-bottom:12px;">画像を確認する場合は、上部の「画像表示」スイッチをONにしてください。</div>
                        <button type="button" class="btn" style="padding:4px 12px; font-size:11px;" onclick="enableImages()">🖼️ 画像を表示する</button>
                    </div>
                <?php elseif (empty($found_images)): ?>
                    <div class="empty-msg">[<?= $source_label ?>] この受診日の添付画像はありません。</div>
                <?php else: ?>
                    <?php foreach ($found_images as $img): ?>
                        <div class="image-card">
                            <div class="image-card-header">
                                <span>KarteID: #<?= (int)$img['karte_id'] ?></span>
                                <span><?= htmlspecialchars($img['file_size'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="image-card-body">
                                <a href="<?= $img['src'] ?>" target="_blank" title="クリックで別タブ原寸表示">
                                    <img src="<?= $img['src'] ?>" alt="<?= htmlspecialchars($img['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                </a>
                            </div>
                            <div class="image-card-footer">
                                <div>📄 <?= htmlspecialchars($img['file_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="margin-top:2px;">更新: <?= htmlspecialchars($img['file_time'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($patient_no !== '' && !$do_search): ?>
        <div class="empty-msg">
            モードを「<strong><?= $source_label ?></strong>」に切り替えました。「<strong>🔍 検索</strong>」ボタンを押して照会してください。
        </div>
    <?php elseif ($patient_no !== '' && empty($db_error)): ?>
        <div class="empty-msg">[<?= $source_label ?>] 患者No.「<?= htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8') ?>」に一致するカルテが見つかりませんでした。</div>
    <?php else: ?>
        <div class="empty-msg">カルテ番号を入力して「検索」を押してください。</div>
    <?php endif; ?>
</div>

<!-- ファイリング画像モーダル -->
<?php if ($show_images): ?>
<div id="filingModal" class="modal-overlay" onclick="closeFilingModal(event)">
    <div class="modal-window" onclick="event.stopPropagation()">
        <div class="modal-header">
            <span>📁 ファイリング画像一覧（T_ImportPicture: 全 <?= count($filing_images) ?> 件）</span>
            <button type="button" onclick="closeFilingModal()" style="background:none; border:none; color:#fff; font-size:16px; cursor:pointer; font-weight:bold;">✕</button>
        </div>
        <div class="modal-body">
            <?php if (empty($filing_images)): ?>
                <div class="empty-msg" style="background:#fff;">ファイリングされた画像・ファイルはありません。</div>
            <?php else: ?>
                <div class="filing-grid">
                    <?php foreach ($filing_images as $f): ?>
                        <div class="filing-card">
                            <div style="padding:6px 8px; font-weight:bold; font-size:11px; background:#f1f5f9; border-bottom:1px solid #cbd5e1; display:flex; justify-content:space-between; align-items:center;">
                                <span title="<?= htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') ?>" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;">
                                    <?= htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span style="font-size:10px; color:#64748b;"><?= htmlspecialchars($f['date'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>

                            <div style="padding:8px; text-align:center; background:#fafafa; flex:1; display:flex; align-items:center; justify-content:center;">
                                <?php if ($f['is_pdf']): ?>
                                    <a href="<?= $f['src'] ?>" target="_blank" class="pdf-preview-box" title="クリックでPDFを別タブ表示">
                                        <div style="font-size:32px; margin-bottom:4px;">📄</div>
                                        <div style="font-weight:bold; font-size:12px;">PDFドキュメント</div>
                                        <div style="font-size:10px; color:#991b1b; margin-top:2px;">クリックして閲覧</div>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= $f['src'] ?>" target="_blank" title="クリックで別タブ原寸表示">
                                        <img src="<?= $f['src'] ?>" alt="<?= htmlspecialchars($f['filename'], ENT_QUOTES, 'UTF-8') ?>" style="max-width:100%; max-height:180px; border-radius:4px; border:1px solid #e2e8f0;">
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div style="padding:6px 8px; font-size:10px; color:#64748b; border-top:1px solid #cbd5e1; background:#fff;">
                                <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($f['filename'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= $f['is_pdf'] ? '📕' : '🖼️' ?> <strong><?= htmlspecialchars($f['filename'], ENT_QUOTES, 'UTF-8') ?></strong> (<?= $f['size'] ?>)
                                </div>
                                <?php if ($f['note'] !== ''): ?>
                                    <div style="margin-top:2px; color:#334155; white-space:pre-wrap; word-break:break-all;"><?= htmlspecialchars($f['note'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function handleImageToggle(isImgOn) {
    const dotsChk = document.getElementById('show_dots_chk');
    if (!isImgOn && dotsChk) {
        dotsChk.checked = false;
        dotsChk.disabled = true;
    }
    document.getElementById('searchForm').submit();
}

function enableImages() {
    const form = document.getElementById('searchForm');
    const chk = form.querySelector('input[name="show_images"]');
    if (chk) chk.checked = true;
    form.submit();
}

function openOnoKarteWindow(onoId) {
    if (!onoId) return;
    const url = 'test_mall_soap_viewer.php?patient_no=' + encodeURIComponent(onoId) + '&source=ono&do_search=1';
    window.open(url, 'ono_karte_viewer_' + encodeURIComponent(onoId), 'width=1120,height=800,scrollbars=yes,resizable=yes');
}

function openFilingModal() {
    const modal = document.getElementById('filingModal');
    if (modal) modal.style.display = 'flex';
}

function closeFilingModal(e) {
    const modal = document.getElementById('filingModal');
    if (!modal) return;
    if (!e || e.target.id === 'filingModal' || e.target.tagName === 'BUTTON') {
        modal.style.display = 'none';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFilingModal();
});
</script>

</body>
</html>