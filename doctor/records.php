<?php
require_once __DIR__ . '/../auth.php';
require_role('doctor');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crypto.php'; // ✅ thêm để encrypt/decrypt diagnosis/treatment_plan

$doctor_id = (int)($_SESSION['doctor_id'] ?? 0);
if ($doctor_id <= 0) {
    header('Location: ../login.php');
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function genderText(?string $gender): string {
    if ($gender === 'Male') return 'Nam';
    if ($gender === 'Female') return 'Nữ';
    return '—';
}
function calcAge(?string $dob): string {
    if (empty($dob)) return '—';
    try {
        $d = new DateTime($dob);
        $n = new DateTime();
        return (string)$d->diff($n)->y;
    } catch (Exception $e) {
        return '—';
    }
}

/* =========================
   ENCRYPT/DECRYPT HELPERS
========================= */
function enc_field(?string $v): ?string {
    if ($v === null) return null;
    $v = (string)$v;
    if ($v === '') return '';
    return encrypt_text($v);
}
function dec_field(?string $v): ?string {
    if ($v === null) return null;
    $v = (string)$v;
    if ($v === '') return '';
    try {
        return decrypt_text($v);
    } catch (Throwable $e) {
        // fallback plaintext nếu data cũ chưa mã hoá
        return $v;
    }
}

// Lấy thông tin bác sĩ
$stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ? LIMIT 1");
$stmt->execute([$doctor_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) die('Không tìm thấy bác sĩ');

/* =========================================================
   1) CHI TIẾT 1 HỒ SƠ (record_id)
========================================================= */
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $record_id = (int)$_GET['id'];
    if ($record_id <= 0) {
        header('Location: records.php');
        exit;
    }

    $stmt = $conn->prepare("
        SELECT
            mr.*,
            a.appointment_date,
            a.appointment_id,
            p.patient_id,
            p.full_name AS patient_name,
            p.phone,
            p.gender,
            p.date_of_birth,
            d.full_name AS doctor_name
        FROM medical_records mr
        JOIN appointments a ON mr.appointment_id = a.appointment_id
        JOIN patients p ON mr.patient_id = p.patient_id
        JOIN doctors d  ON mr.doctor_id = d.doctor_id
        WHERE mr.record_id = ?
          AND mr.doctor_id = ?
        LIMIT 1
    ");
    $stmt->execute([$record_id, $doctor_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        header('Location: records.php');
        exit();
    }

    // prescription header
    $stmt = $conn->prepare("SELECT * FROM prescription_headers WHERE record_id = ? LIMIT 1");
    $stmt->execute([$record_id]);
    $prescription_header = $stmt->fetch(PDO::FETCH_ASSOC);

    // prescription items
    $prescription_items = [];
    if ($prescription_header) {
        $stmt = $conn->prepare("
            SELECT *
            FROM prescription_items
            WHERE prescription_id = ?
            ORDER BY item_id ASC
        ");
        $stmt->execute([(int)$prescription_header['prescription_id']]);
        $prescription_items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ✅ decrypt khi hiển thị
    $record['diagnosis'] = dec_field($record['diagnosis'] ?? null);
    $record['treatment_plan'] = dec_field($record['treatment_plan'] ?? null);

    // =========================
    // UPDATE HANDLER (EDIT + LOG)
    // =========================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_record') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            die('Thiếu lý do chỉnh sửa!');
        }

        $new_diagnosis = trim($_POST['diagnosis'] ?? '');
        $new_treatment_plan = trim($_POST['treatment_plan'] ?? '');
        $new_notes = trim($_POST['notes'] ?? '');

        // vital
        $new_bp = trim($_POST['blood_pressure'] ?? '');
        $new_hr = trim($_POST['heart_rate'] ?? '');
        $new_temp = trim($_POST['temperature'] ?? '');
        $new_height = trim($_POST['height'] ?? '');
        $new_weight = trim($_POST['weight'] ?? '');

        // Rx note
        $new_rx_note = trim($_POST['rx_note'] ?? '');

        $old_snapshot = [
            'diagnosis' => $record['diagnosis'] ?? '',
            'treatment_plan' => $record['treatment_plan'] ?? '',
            'notes' => $record['notes'] ?? '',
            'blood_pressure' => $record['blood_pressure'] ?? '',
            'heart_rate' => $record['heart_rate'] ?? '',
            'temperature' => $record['temperature'] ?? '',
            'height' => $record['height'] ?? '',
            'weight' => $record['weight'] ?? '',
            'rx_note' => $prescription_header['note'] ?? '',
            'rx_items' => $prescription_items,
        ];

        $new_snapshot = [
            'diagnosis' => $new_diagnosis,
            'treatment_plan' => $new_treatment_plan,
            'notes' => $new_notes,
            'blood_pressure' => $new_bp,
            'heart_rate' => $new_hr,
            'temperature' => $new_temp,
            'height' => $new_height,
            'weight' => $new_weight,
            'rx_note' => $new_rx_note,
            'rx_items' => $prescription_items, // (chưa cho sửa items thì giữ nguyên)
        ];

        try {
            $conn->beginTransaction();

            // update medical_records (encrypt diagnosis/treatment_plan)
            $up = $conn->prepare("
                UPDATE medical_records
                SET diagnosis = :diag,
                    treatment_plan = :tp,
                    notes = :notes,
                    blood_pressure = :bp,
                    heart_rate = :hr,
                    temperature = :temp,
                    height = :h,
                    weight = :w,
                    updated_at = NOW()
                WHERE record_id = :rid AND doctor_id = :did
                LIMIT 1
            ");
            $up->execute([
                'diag' => enc_field($new_diagnosis),
                'tp' => enc_field($new_treatment_plan),
                'notes' => ($new_notes !== '' ? $new_notes : null),
                'bp' => ($new_bp !== '' ? $new_bp : null),
                'hr' => ($new_hr !== '' ? $new_hr : null),
                'temp' => ($new_temp !== '' ? $new_temp : null),
                'h' => ($new_height !== '' ? $new_height : null),
                'w' => ($new_weight !== '' ? $new_weight : null),
                'rid' => $record_id,
                'did' => $doctor_id,
            ]);

            // update rx note if exists
            if ($prescription_header) {
                $up2 = $conn->prepare("
                    UPDATE prescription_headers
                    SET note = :note
                    WHERE prescription_id = :pid AND record_id = :rid
                    LIMIT 1
                ");
                $up2->execute([
                    'note' => ($new_rx_note !== '' ? $new_rx_note : null),
                    'pid' => (int)$prescription_header['prescription_id'],
                    'rid' => $record_id,
                ]);
            }

            // insert audit
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $ins = $conn->prepare("
                INSERT INTO medical_audits
                (actor_role, actor_id, record_id, action, reason, ip, user_agent, old_data, new_data, created_at)
                VALUES
                ('doctor', :aid, :rid, 'UPDATE', :reason, :ip, :ua, :old, :new, NOW())
            ");
            $ins->execute([
                'aid' => $doctor_id,
                'rid' => $record_id,
                'reason' => $reason,
                'ip' => $ip,
                'ua' => $ua,
                'old' => json_encode($old_snapshot, JSON_UNESCAPED_UNICODE),
                'new' => json_encode($new_snapshot, JSON_UNESCAPED_UNICODE),
            ]);

            $conn->commit();
            header('Location: records.php?id=' . $record_id . '&updated=1');
            exit;

        } catch (Throwable $e) {
            $conn->rollBack();
            die('Lỗi cập nhật: ' . $e->getMessage());
        }
    }

    // load audits for display
    $audits = [];
    try {
        $st = $conn->prepare("
            SELECT *
            FROM medical_audits
            WHERE record_id = ?
            ORDER BY created_at DESC
        ");
        $st->execute([$record_id]);
        $audits = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $audits = [];
    }

    $patient_initial = mb_substr($record['patient_name'] ?? 'B', 0, 1, 'UTF-8');

    $exam_time_raw = $record['examination_date'] ?? $record['created_at'] ?? null;
    $exam_time_text = $exam_time_raw ? date('d/m/Y H:i', strtotime($exam_time_raw)) : '—';

    $isEdit = (isset($_GET['edit']) && $_GET['edit'] == '1');
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Chi tiết hồ sơ bệnh án</title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            :root{
                --bg:#f5f7fa;--card:#fff;--text:#111827;--muted:#6b7280;--line:#eef2f7;
                --primary:#667eea;--primary2:#764ba2;--shadow2: 0 6px 18px rgba(17,24,39,0.06);
                --radius: 16px;
            }
            body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--text)}
            .header{
                background:linear-gradient(135deg,var(--primary) 0%, var(--primary2) 100%);
                color:#fff; padding:18px 22px; display:flex;justify-content:space-between;align-items:center;gap:12px;
                box-shadow:0 2px 10px rgba(0,0,0,0.10)
            }
            .header h1{font-size:18px}
            .header p{opacity:.92;font-size:13px;margin-top:4px}
            .btn{
                display:inline-flex;align-items:center;gap:8px;
                padding:10px 12px;border-radius:12px;font-weight:900;font-size:13px;text-decoration:none;cursor:pointer;
                border:1px solid transparent; transition:filter .2s, transform .1s; white-space:nowrap;
            }
            .btn:active{transform:translateY(1px)}
            .btn-ghost{background:rgba(255,255,255,0.18);color:#fff;border-color:rgba(255,255,255,0.25)}
            .btn-ghost:hover{background:rgba(255,255,255,0.26)}
            .btn-green{background:#16a34a;color:#fff}
            .btn-green:hover{filter:brightness(.97)}

            .wrap{max-width:1100px;margin:0 auto;padding:22px;display:grid;gap:14px}
            .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow2);overflow:hidden;}
            .card-head{
                padding:16px 18px;border-bottom:1px solid var(--line);
                display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px
            }
            .title{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
            .avatar{width:42px;height:42px;border-radius:999px;background:#e0e7ff;color:#3730a3;display:grid;place-items:center;font-weight:900;}
            .title h2{font-size:16px;font-weight:900}
            .sub{color:var(--muted);font-size:13px;margin-top:2px}
            .grid{display:grid;grid-template-columns:1fr;gap:12px;padding:16px 18px;}
            .section{background:#f8fafc;border:1px solid #eef2f7;border-radius:14px;padding:14px;}
            .section h3{font-size:14px;font-weight:900;color:#111827;display:flex;align-items:center;gap:10px;margin-bottom:10px;}
            .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;}
            .info{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:12px;}
            .label{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.4px}
            .value{margin-top:4px;font-weight:800;color:#111827}
            .text{white-space:pre-wrap;line-height:1.6;background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:12px;}
            .med-list{display:grid;gap:10px}
            .med{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:12px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;}
            .med strong{font-weight:900}
            .pill{
                display:inline-block;padding:5px 10px;border-radius:999px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;
                font-size:12px;font-weight:900;white-space:nowrap;height:fit-content;
            }
            input, textarea{
                width:100%;
                padding:10px 12px;
                border:1px solid #e5e7eb;
                border-radius:12px;
                font-size:14px;
                font-weight:800;
            }
            textarea{min-height:90px;resize:vertical}
            input:focus, textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}
            details summary{cursor:pointer;font-weight:900}
            @media print{.header,.print-actions{display:none!important}body{background:#fff}.card{box-shadow:none;border:0}.section{border:1px solid #e5e7eb}}
        </style>
    </head>
    <body>
        <div class="header">
            <div>
                <h1>📄 Chi tiết hồ sơ bệnh án</h1>
                <p>Bệnh nhân: <?php echo h($record['patient_name'] ?? ''); ?></p>
            </div>
            <div class="print-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
                <a class="btn btn-ghost" href="records.php?patient_id=<?php echo (int)($record['patient_id'] ?? 0); ?>">← Lịch sử khám</a>
                <a class="btn btn-ghost" href="records.php?id=<?php echo (int)$record_id; ?>&edit=1">✏️ Sửa hồ sơ</a>
                <button class="btn btn-green" onclick="window.print()">🖨️ In hồ sơ</button>
            </div>
        </div>

        <div class="wrap">
            <div class="card">
                <div class="card-head">
                    <div class="title">
                        <div class="avatar"><?php echo h($patient_initial); ?></div>
                        <div>
                            <h2><?php echo h($record['patient_name'] ?? ''); ?></h2>
                            <div class="sub">
                                Ngày khám: <strong><?php echo h($exam_time_text); ?></strong>
                                • Bác sĩ: <strong><?php echo h($record['doctor_name'] ?? ''); ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($record['appointment_date'])): ?>
                        <span class="pill">📅 Ngày hẹn: <?php echo date('d/m/Y H:i', strtotime($record['appointment_date'])); ?></span>
                    <?php endif; ?>
                </div>

                <div class="grid">

                    <?php if ($isEdit): ?>
                    <div class="section">
                        <h3>✏️ Chỉnh sửa hồ sơ (có lưu dấu vết)</h3>
                        <form method="POST" style="display:grid;gap:10px;">
                            <input type="hidden" name="action" value="update_record">

                            <div class="label">Lý do chỉnh sửa *</div>
                            <input name="reason" required placeholder="Ví dụ: nhập sai, bổ sung thông tin..." />

                            <div class="label">Chẩn đoán</div>
                            <textarea name="diagnosis"><?php echo h($record['diagnosis'] ?? ''); ?></textarea>

                            <div class="label">Kế hoạch điều trị</div>
                            <textarea name="treatment_plan"><?php echo h($record['treatment_plan'] ?? ''); ?></textarea>

                            <div class="label">Ghi chú</div>
                            <textarea name="notes"><?php echo h($record['notes'] ?? ''); ?></textarea>

                            <div class="label">Dấu hiệu sinh tồn</div>
                            <div class="info-grid">
                                <div class="info"><div class="label">Huyết áp</div><input name="blood_pressure" value="<?php echo h($record['blood_pressure'] ?? ''); ?>"></div>
                                <div class="info"><div class="label">Nhịp tim</div><input name="heart_rate" value="<?php echo h($record['heart_rate'] ?? ''); ?>"></div>
                                <div class="info"><div class="label">Nhiệt độ</div><input name="temperature" value="<?php echo h($record['temperature'] ?? ''); ?>"></div>
                                <div class="info"><div class="label">Chiều cao</div><input name="height" value="<?php echo h($record['height'] ?? ''); ?>"></div>
                                <div class="info"><div class="label">Cân nặng</div><input name="weight" value="<?php echo h($record['weight'] ?? ''); ?>"></div>
                            </div>

                            <div class="label">Ghi chú đơn thuốc</div>
                            <textarea name="rx_note"><?php echo h($prescription_header['note'] ?? ''); ?></textarea>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <button class="btn btn-green" type="submit">💾 Lưu thay đổi</button>
                                <a class="btn btn-ghost" href="records.php?id=<?php echo (int)$record_id; ?>">↩️ Hủy</a>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="section">
                        <h3>👤 Thông tin bệnh nhân</h3>
                        <div class="info-grid">
                            <div class="info">
                                <div class="label">Số điện thoại</div>
                                <div class="value"><?php echo h($record['phone'] ?? '—'); ?></div>
                            </div>
                            <div class="info">
                                <div class="label">Giới tính</div>
                                <div class="value"><?php echo h(genderText($record['gender'] ?? null)); ?></div>
                            </div>
                            <div class="info">
                                <div class="label">Ngày sinh</div>
                                <div class="value">
                                    <?php echo !empty($record['date_of_birth']) ? h(date('d/m/Y', strtotime($record['date_of_birth']))) : '—'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h3>📊 Dấu hiệu sinh tồn</h3>
                        <div class="info-grid">
                            <div class="info"><div class="label">Huyết áp</div><div class="value"><?php echo h(!empty($record['blood_pressure']) ? $record['blood_pressure'] : 'Chưa đo'); ?></div></div>
                            <div class="info"><div class="label">Nhịp tim</div><div class="value"><?php echo !empty($record['heart_rate']) ? h($record['heart_rate'].' bpm') : 'Chưa đo'; ?></div></div>
                            <div class="info"><div class="label">Nhiệt độ</div><div class="value"><?php echo !empty($record['temperature']) ? h($record['temperature'].'°C') : 'Chưa đo'; ?></div></div>
                            <div class="info">
                                <div class="label">Chiều cao / Cân nặng</div>
                                <div class="value">
                                    <?php
                                        $height = !empty($record['height']) ? $record['height'].' cm' : 'Chưa đo';
                                        $weight = !empty($record['weight']) ? $record['weight'].' kg' : 'Chưa đo';
                                        echo h($height.' / '.$weight);
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h3>🩺 Chẩn đoán</h3>
                        <div class="text"><?php echo h($record['diagnosis'] ?? ''); ?></div>
                    </div>

                    <?php if (!empty($record['treatment_plan'])): ?>
                    <div class="section">
                        <h3>💡 Kế hoạch điều trị</h3>
                        <div class="text"><?php echo h($record['treatment_plan']); ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="section">
                        <h3>💊 Đơn thuốc</h3>

                        <?php if (!$prescription_header): ?>
                            <div class="text">Không có đơn thuốc</div>
                        <?php else: ?>

                            <?php if (!empty($prescription_header['note'])): ?>
                                <div class="text" style="margin-bottom:10px;">
                                    <strong>Ghi chú đơn:</strong><br>
                                    <?php echo h($prescription_header['note']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($prescription_items)): ?>
                                <div class="text">Không có thuốc trong đơn</div>
                            <?php else: ?>
                                <div class="med-list">
                                    <?php foreach ($prescription_items as $it): ?>
                                        <div class="med">
                                            <div>
                                                <strong><?php echo h($it['medication_name'] ?? ''); ?></strong>
                                                <div class="sub">
                                                    <?php if (!empty($it['dosage'])): ?>
                                                        Liều dùng: <strong><?php echo h($it['dosage']); ?></strong>
                                                    <?php endif; ?>
                                                    • Số ngày: <strong><?php echo (int)($it['days'] ?? 0); ?></strong>
                                                    <?php if (!empty($it['instructions'])): ?>
                                                        <br>Hướng dẫn: <?php echo h($it['instructions']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($record['notes'])): ?>
                    <div class="section">
                        <h3>📝 Ghi chú</h3>
                        <div class="text"><?php echo h($record['notes']); ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="section">
                        <h3>🕒 Lịch sử chỉnh sửa</h3>
                        <?php if (empty($audits)): ?>
                            <div class="text">Chưa có chỉnh sửa nào.</div>
                        <?php else: ?>
                            <div class="med-list">
                                <?php foreach ($audits as $a): ?>
                                    <?php
                                        $time = !empty($a['created_at']) ? date('d/m/Y H:i', strtotime($a['created_at'])) : '—';
                                        $old = json_decode($a['old_data'] ?? '{}', true) ?: [];
                                        $new = json_decode($a['new_data'] ?? '{}', true) ?: [];
                                    ?>
                                    <div class="med" style="align-items:flex-start;">
                                        <div style="min-width:240px;">
                                            <strong><?php echo h($time); ?></strong><br>
                                            <div class="sub">👤 <?php echo h(($a['actor_role'] ?? '') . ' #' . ($a['actor_id'] ?? '')); ?></div>
                                            <div class="sub">📝 Lý do: <?php echo h($a['reason'] ?? ''); ?></div>
                                            <div class="sub">🌐 IP: <?php echo h($a['ip'] ?? ''); ?></div>
                                        </div>
                                        <div style="flex:1;">
                                            <details>
                                                <summary>Xem chi tiết thay đổi</summary>
                                                <div class="text" style="margin-top:10px;">
<b>Chẩn đoán</b>
- Trước: <?php echo h($old['diagnosis'] ?? ''); ?>

- Sau: <?php echo h($new['diagnosis'] ?? ''); ?>


<b>Phác đồ</b>
- Trước: <?php echo h($old['treatment_plan'] ?? ''); ?>

- Sau: <?php echo h($new['treatment_plan'] ?? ''); ?>


<b>Ghi chú</b>
- Trước: <?php echo h($old['notes'] ?? ''); ?>

- Sau: <?php echo h($new['notes'] ?? ''); ?>
                                                </div>
                                            </details>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

/* =========================================================
   2) LỊCH SỬ KHÁM THEO BỆNH NHÂN (patient_id)
========================================================= */
if (isset($_GET['patient_id']) && is_numeric($_GET['patient_id'])) {
    $patient_id = (int)$_GET['patient_id'];
    if ($patient_id <= 0) {
        header('Location: records.php');
        exit;
    }

    $st = $conn->prepare("
        SELECT p.*
        FROM patients p
        JOIN medical_records mr ON mr.patient_id = p.patient_id
        WHERE p.patient_id = ?
          AND mr.doctor_id = ?
        LIMIT 1
    ");
    $st->execute([$patient_id, $doctor_id]);
    $patient = $st->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        header('Location: records.php');
        exit;
    }

    $st = $conn->prepare("
        SELECT
            mr.record_id,
            mr.examination_date,
            mr.created_at,
            a.appointment_date,
            a.appointment_id,
            mr.blood_pressure,
            mr.heart_rate,
            mr.temperature
        FROM medical_records mr
        JOIN appointments a ON mr.appointment_id = a.appointment_id
        WHERE mr.doctor_id = ?
          AND mr.patient_id = ?
        ORDER BY COALESCE(mr.examination_date, mr.created_at) DESC
    ");
    $st->execute([$doctor_id, $patient_id]);
    $visits = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $initial = mb_substr($patient['full_name'] ?? 'B', 0, 1, 'UTF-8');
    $ageText = calcAge($patient['date_of_birth'] ?? null);
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Lịch sử khám</title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            :root{
                --bg:#f5f7fa;--card:#fff;--text:#111827;--muted:#6b7280;--line:#eef2f7;
                --primary:#667eea;--primary2:#764ba2;--shadow2: 0 6px 18px rgba(17,24,39,0.06);
                --radius: 16px;
            }
            body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--text)}
            .header{
                background:linear-gradient(135deg,var(--primary) 0%, var(--primary2) 100%);
                color:#fff;padding:18px 22px;box-shadow:0 2px 10px rgba(0,0,0,0.10);
            }
            .header-top{max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
            .btn{
                display:inline-flex;align-items:center;gap:8px;
                padding:10px 12px;border-radius:12px;font-weight:900;font-size:13px;text-decoration:none;
                border:1px solid rgba(255,255,255,0.25);background:rgba(255,255,255,0.18);color:#fff;
                transition:background .2s, transform .1s;white-space:nowrap;
            }
            .btn:hover{background:rgba(255,255,255,0.26)}
            .btn:active{transform:translateY(1px)}

            .wrap{max-width:1200px;margin:0 auto;padding:22px;display:grid;gap:14px}
            .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow2);overflow:hidden;}
            .topinfo{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
            .avatar{width:44px;height:44px;border-radius:999px;background:#e0e7ff;color:#3730a3;display:grid;place-items:center;font-weight:900;}
            .name{font-weight:1000}
            .sub{color:var(--muted);font-weight:800;font-size:13px;margin-top:2px}
            .table{width:100%;border-collapse:collapse;}
            .table th,.table td{padding:12px 14px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:14px;}
            .table th{background:#fafafa;font-weight:1000;color:#111827}
            .muted{color:var(--muted);font-weight:800;font-size:13px}
            .pill{
                display:inline-flex;align-items:center;gap:8px;
                padding:6px 10px;border-radius:999px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;
                font-size:12px;font-weight:900;white-space:nowrap;
            }
            .btn-view{
                display:inline-flex;align-items:center;justify-content:center;gap:8px;
                padding:9px 12px;border-radius:12px;background:var(--primary);color:#fff;text-decoration:none;
                font-weight:1000;border:1px solid var(--primary);
            }
            .btn-view:hover{filter:brightness(.97)}
            .empty{padding:40px 16px;text-align:center;color:var(--muted);}
            @media (max-width: 680px){
                .table th:nth-child(3), .table td:nth-child(3){display:none;}
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="header-top">
                <div>
                    <div style="font-size:18px;font-weight:1000;">📚 Lịch sử khám</div>
                    <div style="opacity:.92;font-size:13px;margin-top:4px;">
                        Bác sĩ: <?php echo h($doctor['full_name'] ?? ''); ?>
                    </div>
                </div>
                <a class="btn" href="records.php">← Danh sách bệnh nhân</a>
            </div>
        </div>

        <div class="wrap">
            <div class="card">
                <div class="topinfo">
                    <div class="avatar"><?php echo h($initial); ?></div>
                    <div>
                        <div class="name"><?php echo h($patient['full_name'] ?? ''); ?></div>
                        <div class="sub">
                            SĐT: <b><?php echo h($patient['phone'] ?? '—'); ?></b>
                            • Giới tính: <b><?php echo h(genderText($patient['gender'] ?? null)); ?></b>
                            • Tuổi: <b><?php echo h($ageText); ?></b>
                            • Tổng số lần khám: <b><?php echo (int)count($visits); ?></b>
                        </div>
                    </div>
                </div>

                <?php if (empty($visits)): ?>
                    <div class="empty">Không có lần khám nào.</div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:240px;">Thời gian khám</th>
                                <th>Ngày hẹn</th>
                                <th>Dấu hiệu sinh tồn</th>
                                <th style="width:160px;">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visits as $v): ?>
                                <?php
                                    $t = $v['examination_date'] ?? $v['created_at'] ?? null;
                                    $tText = $t ? date('H:i d/m/Y', strtotime($t)) : '—';

                                    $apptText = !empty($v['appointment_date']) ? date('H:i d/m/Y', strtotime($v['appointment_date'])) : '—';

                                    $bp = !empty($v['blood_pressure']) ? $v['blood_pressure'] : '—';
                                    $hr = !empty($v['heart_rate']) ? ($v['heart_rate'].' bpm') : '—';
                                    $temp = !empty($v['temperature']) ? ($v['temperature'].'°C') : '—';
                                ?>
                                <tr>
                                    <td><span class="pill">🩺 <?php echo h($tText); ?></span></td>
                                    <td class="muted"><?php echo h($apptText); ?></td>
                                    <td class="muted">
                                        HA: <b><?php echo h($bp); ?></b>
                                        • HR: <b><?php echo h($hr); ?></b>
                                        • T: <b><?php echo h($temp); ?></b>
                                    </td>
                                    <td>
                                        <a class="btn-view" href="records.php?id=<?php echo (int)$v['record_id']; ?>">👁️ Xem chi tiết</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
   3) DANH SÁCH BỆNH NHÂN (mỗi bệnh nhân 1 card)
========================================================= */

$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$where  = "mr.doctor_id = ?";
$params = [$doctor_id];

if ($search !== '') {
    if (ctype_digit($search)) {
        if (strlen($search) >= 8) {
            $where .= " AND p.phone LIKE ?";
            $params[] = "%$search%";
        } else {
            $where .= " AND p.patient_id = ?";
            $params[] = (int)$search;
        }
    } else {
        $where .= " AND p.full_name LIKE ?";
        $params[] = "%$search%";
    }
}

// Đếm tổng số BỆNH NHÂN (distinct)
$count_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT mr.patient_id)
    FROM medical_records mr
    JOIN patients p ON mr.patient_id = p.patient_id
    WHERE $where
");
$count_stmt->execute($params);
$total_patients = (int)$count_stmt->fetchColumn();
$total_pages = (int)ceil(($total_patients > 0 ? $total_patients : 1) / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

// Lấy danh sách bệnh nhân
$sql = "
    SELECT
        p.patient_id,
        p.full_name AS patient_name,
        p.phone,
        p.gender,
        p.date_of_birth,
        COUNT(*) AS total_visits,
        MAX(COALESCE(mr.examination_date, mr.created_at)) AS last_exam_time
    FROM medical_records mr
    JOIN patients p ON mr.patient_id = p.patient_id
    WHERE $where
    GROUP BY p.patient_id, p.full_name, p.phone, p.gender, p.date_of_birth
    ORDER BY last_exam_time DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ bệnh án</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --bg:#f5f7fa;--card:#fff;--text:#111827;--muted:#6b7280;--line:#eef2f7;
            --primary:#667eea;--primary2:#764ba2;--shadow2: 0 6px 18px rgba(17,24,39,0.06);
            --radius: 16px;
        }
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--text)}
        .header{
            background:linear-gradient(135deg,var(--primary) 0%, var(--primary2) 100%);
            color:#fff;padding:18px 22px;box-shadow:0 2px 10px rgba(0,0,0,0.10);
        }
        .header-top{
            max-width:1200px;margin:0 auto;
            display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;
        }
        .header h2{font-size:18px}
        .header p{opacity:.92;font-size:13px;margin-top:4px}
        .btn{
            display:inline-flex;align-items:center;gap:8px;
            padding:10px 12px;border-radius:12px;
            font-weight:900;font-size:13px;text-decoration:none;cursor:pointer;
            border:1px solid rgba(255,255,255,0.25);
            background:rgba(255,255,255,0.18);
            color:#fff;white-space:nowrap;
            transition:background .2s, transform .1s;
        }
        .btn:hover{background:rgba(255,255,255,0.26)}
        .btn:active{transform:translateY(1px)}

        .wrap{max-width:1200px;margin:0 auto;padding:22px;display:grid;gap:14px}
        .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow2);overflow:hidden;}
        .toolbar{
            padding:16px 18px;display:flex;justify-content:space-between;align-items:center;gap:10px;
            border-bottom:1px solid var(--line);flex-wrap:wrap;
        }
        .toolbar h3{font-size:15px;font-weight:1000}
        .search{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
        .search input{
            width:320px;max-width:80vw;
            padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;
            font-size:14px;font-weight:800;
            transition:border .2s, box-shadow .2s;
        }
        .search input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18);}
        .search button{
            padding:10px 12px;border-radius:12px;background:var(--primary);color:#fff;border:1px solid var(--primary);
            font-weight:1000;cursor:pointer;
        }
        .search button:hover{filter:brightness(.97)}
        .search .clear{
            padding:10px 12px;border-radius:12px;background:#f9fafb;color:#111827;border:1px solid #e5e7eb;
            font-weight:1000;text-decoration:none;
        }
        .search .clear:hover{background:#f3f4f6}

        .grid{
            padding:16px 18px;
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
        }
        .item{
            border:1px solid #eef2f7;border-radius:16px;background:#fff;padding:14px;
            transition: transform .15s, box-shadow .15s;
        }
        .item:hover{transform: translateY(-2px);box-shadow: 0 10px 20px rgba(17,24,39,0.07);}
        .item-head{
            display:flex;justify-content:space-between;align-items:center;gap:10px;
            padding-bottom:10px;border-bottom:1px dashed #eef2f7;margin-bottom:10px;
        }
        .patient{display:flex;gap:10px;align-items:center;}
        .avatar{
            width:38px;height:38px;border-radius:999px;background:#e0e7ff;color:#3730a3;
            display:grid;place-items:center;font-weight:1000;
        }
        .patient-name{font-weight:1000}
        .muted{color:var(--muted);font-size:12px;font-weight:900}
        .info{
            margin:10px 0 12px;
            color:#374151;
            font-size:13px;
            line-height:1.6;
            font-weight:800;
        }
        .btn-view{
            width:100%;
            display:inline-flex;justify-content:center;align-items:center;gap:8px;
            padding:10px 12px;border-radius:12px;
            background:var(--primary);color:#fff;text-decoration:none;
            font-weight:1000;border:1px solid var(--primary);
        }
        .btn-view:hover{filter:brightness(.97)}

        .pagination{
            display:flex;justify-content:center;gap:8px;flex-wrap:wrap;
            padding:14px 16px;border-top:1px solid var(--line);background:#fff;
        }
        .page-btn{
            padding:9px 12px;border-radius:12px;
            border:1px solid #e5e7eb;background:#fff;color:#111827;
            text-decoration:none;font-weight:1000;font-size:13px;
        }
        .page-btn:hover{background:#f9fafb}
        .page-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
        .empty{padding:40px 16px;text-align:center;color:var(--muted);}
        .empty .icon{font-size:40px;margin-bottom:10px;opacity:.75}
        .empty h3{color:#111827;margin-bottom:6px;font-weight:1000}
        @media (max-width: 520px){
            .grid{grid-template-columns:1fr}
            .search input{width:100%;max-width:100%}
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <div>
                <h2>📋 Hồ sơ bệnh án</h2>
                <p>Bác sĩ: <?php echo h($doctor['full_name'] ?? ''); ?></p>
            </div>
            <a href="dashboard.php" class="btn">← Lịch khám</a>
        </div>
    </div>

    <div class="wrap">
        <div class="card">
            <div class="toolbar">
                <h3>Danh sách bệnh nhân đã khám</h3>

                <form method="GET" class="search">
                    <input type="text" name="search"
                           placeholder="Tìm theo SĐT, tên hoặc mã bệnh nhân"
                           value="<?php echo h($search); ?>">
                    <button type="submit">🔍 Tìm</button>

                    <?php if ($search !== ''): ?>
                        <a class="clear" href="records.php">✖ Xóa lọc</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($patients)): ?>
                <div class="empty">
                    <div class="icon">📁</div>
                    <h3>Chưa có bệnh nhân nào</h3>
                    <p>Danh sách sẽ xuất hiện sau khi bạn khám bệnh và lưu hồ sơ.</p>
                </div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($patients as $p): ?>
                        <?php
                            $pname = $p['patient_name'] ?? '';
                            $initial = mb_substr($pname ?: 'B', 0, 1, 'UTF-8');

                            $genderText = genderText($p['gender'] ?? null);
                            $ageText = calcAge($p['date_of_birth'] ?? null);

                            $lastTime = !empty($p['last_exam_time']) ? date('H:i d/m/Y', strtotime($p['last_exam_time'])) : '—';
                            $phone = $p['phone'] ?? '—';
                            $total = (int)($p['total_visits'] ?? 0);
                        ?>
                        <div class="item">
                            <div class="item-head">
                                <div class="patient">
                                    <div class="avatar"><?php echo h($initial); ?></div>
                                    <div>
                                        <div class="patient-name"><?php echo h($pname); ?></div>
                                        <div class="muted">Mã BN: #<?php echo (int)($p['patient_id'] ?? 0); ?></div>
                                    </div>
                                </div>
                                <div class="muted">Lần gần nhất<br><b><?php echo h($lastTime); ?></b></div>
                            </div>

                            <div class="info">
                                📞 SĐT: <b><?php echo h($phone); ?></b><br>
                                👤 Giới tính: <b><?php echo h($genderText); ?></b> • 🎂 Tuổi: <b><?php echo h($ageText); ?></b><br>
                                🧾 Số lần khám: <b><?php echo (int)$total; ?></b>
                            </div>

                            <a class="btn-view" href="records.php?patient_id=<?php echo (int)($p['patient_id'] ?? 0); ?>">
                                📚 Xem lịch sử khám
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                            $base = 'records.php?search=' . urlencode($search) . '&page=';
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);

                            if ($page > 1) echo '<a class="page-btn" href="'.$base.($page-1).'">←</a>';

                            if ($start > 1) {
                                echo '<a class="page-btn" href="'.$base.'1">1</a>';
                                if ($start > 2) echo '<span class="page-btn" style="border:0;background:transparent;">…</span>';
                            }

                            for ($i=$start; $i<=$end; $i++){
                                $active = ($i==$page) ? 'active' : '';
                                echo '<a class="page-btn '.$active.'" href="'.$base.$i.'">'.$i.'</a>';
                            }

                            if ($end < $total_pages) {
                                if ($end < $total_pages - 1) echo '<span class="page-btn" style="border:0;background:transparent;">…</span>';
                                echo '<a class="page-btn" href="'.$base.$total_pages.'">'.$total_pages.'</a>';
                            }

                            if ($page < $total_pages) echo '<a class="page-btn" href="'.$base.($page+1).'">→</a>';
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>