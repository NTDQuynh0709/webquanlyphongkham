<?php
require_once __DIR__ . '/../auth.php';
require_role('admin');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crypto.php';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    header('Location: ../login.php');
    exit;
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
    try { return decrypt_text($v); }
    catch (Throwable $e) { return $v; }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function genderText($g){
    if ($g === 'Male') return 'Nam';
    if ($g === 'Female') return 'Nữ';
    return 'N/A';
}
function fmtDate($d){
    if (!$d) return 'N/A';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : 'N/A';
}
function fmtDateTime($d){
    if (!$d) return 'N/A';
    $t = strtotime($d);
    return $t ? date('d/m/Y H:i', $t) : 'N/A';
}

// Lấy thông tin admin
$admin = null;
try {
    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $admin = ['full_name' => $_SESSION['full_name'] ?? 'Admin', 'email' => $_SESSION['email'] ?? ''];
}
$admin_name = $admin['full_name'] ?? 'Admin';
$admin_initial = mb_substr($admin_name, 0, 1, 'UTF-8');

/**
 * LOAD APPOINTMENTS (kèm record + prescriptions)
 */
function loadAppointmentBundle(PDO $conn, int $patient_id): array {
    try {
        $stmt = $conn->prepare("
            SELECT a.*,
                   d.full_name AS doctor_name,
                   dp.department_name
            FROM appointments a
            LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
            LEFT JOIN departments dp ON a.department_id = dp.department_id
            WHERE a.patient_id = :pid
            ORDER BY a.appointment_date DESC, a.appointment_id DESC
        ");
        $stmt->execute(['pid' => $patient_id]);
        $appts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$appts) return ['ok' => true, 'message' => '', 'rows' => []];

        $apptIds = array_map(fn($x) => (int)$x['appointment_id'], $appts);
        $in = implode(',', array_fill(0, count($apptIds), '?'));

        $stmt = $conn->prepare("
            SELECT mr.*,
                   d.full_name AS doctor_name
            FROM medical_records mr
            LEFT JOIN doctors d ON mr.doctor_id = d.doctor_id
            WHERE mr.appointment_id IN ($in)
            ORDER BY mr.examination_date DESC, mr.record_id DESC
        ");
        $stmt->execute($apptIds);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($records as &$r) {
            $r['diagnosis'] = dec_field($r['diagnosis'] ?? null);
            $r['treatment_plan'] = dec_field($r['treatment_plan'] ?? null);
        }
        unset($r);

        $recordByAppt = [];
        $recordIds = [];
        foreach ($records as $r) {
            $aid = (int)($r['appointment_id'] ?? 0);
            if ($aid > 0 && !isset($recordByAppt[$aid])) $recordByAppt[$aid] = $r;
            $rid = (int)($r['record_id'] ?? 0);
            if ($rid > 0) $recordIds[] = $rid;
        }
        $recordIds = array_values(array_unique($recordIds));

        $rxByRecord = [];

        if ($recordIds) {
            $in2 = implode(',', array_fill(0, count($recordIds), '?'));

            $stmt = $conn->prepare("
                SELECT *
                FROM prescription_headers
                WHERE record_id IN ($in2)
                ORDER BY created_at DESC, prescription_id DESC
            ");
            $stmt->execute($recordIds);
            $headers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($headers) {
                $headerIds = array_map(fn($x) => (int)$x['prescription_id'], $headers);
                $headerIds = array_values(array_unique($headerIds));
                $in3 = implode(',', array_fill(0, count($headerIds), '?'));

                $stmt = $conn->prepare("
                    SELECT *
                    FROM prescription_items
                    WHERE prescription_id IN ($in3)
                    ORDER BY item_id ASC
                ");
                $stmt->execute($headerIds);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $itemsByHeader = [];
                foreach ($items as $it) {
                    $pid = (int)($it['prescription_id'] ?? 0);
                    if ($pid <= 0) continue;
                    if (!isset($itemsByHeader[$pid])) $itemsByHeader[$pid] = [];
                    $itemsByHeader[$pid][] = $it;
                }

                foreach ($headers as $h) {
                    $rid = (int)($h['record_id'] ?? 0);
                    $pid = (int)($h['prescription_id'] ?? 0);
                    if ($rid <= 0 || $pid <= 0) continue;

                    if (!isset($rxByRecord[$rid])) $rxByRecord[$rid] = [];
                    $rxByRecord[$rid][] = [
                        'header' => $h,
                        'items'  => $itemsByHeader[$pid] ?? []
                    ];
                }
            }
        }

        $out = [];
        foreach ($appts as $a) {
            $aid = (int)$a['appointment_id'];
            $rec = $recordByAppt[$aid] ?? null;

            $rxs = [];
            if ($rec && !empty($rec['record_id'])) {
                $rxs = $rxByRecord[(int)$rec['record_id']] ?? [];
            }

            $out[] = [
                'appointment'   => $a,
                'record'        => $rec,
                'prescriptions' => $rxs
            ];
        }

        return ['ok' => true, 'message' => '', 'rows' => $out];
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'DB Error: ' . $e->getMessage(), 'rows' => []];
    }
}

/* =========================
   AJAX HANDLER
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = (string)$_GET['action'];

        // ADD
        if ($action === 'add') {
            $full_name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            $gender = $_POST['gender'] ?? null;
            $address = $_POST['address'] ?? null;
            $medical_history = $_POST['medical_history'] ?? null;

            if ($full_name === '' || $phone === '' || $date_of_birth === '') {
                echo json_encode(['success' => false, 'message' => 'Họ tên, SĐT, Ngày sinh là bắt buộc!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE phone = :phone AND is_deleted = 0");
            $stmt->execute(['phone' => $phone]);
            if ((int)$stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'SĐT đã tồn tại!']);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO patients (full_name, phone, date_of_birth, gender, address, medical_history, created_at, updated_at, is_deleted)
                VALUES (:full_name, :phone, :dob, :gender, :address, :history, NOW(), NOW(), 0)
            ");
            $stmt->execute([
                'full_name' => $full_name,
                'phone'     => $phone,
                'dob'       => $date_of_birth,
                'gender'    => ($gender === 'Male' || $gender === 'Female') ? $gender : null,
                'address'   => $address,
                'history'   => enc_field($medical_history),
            ]);

            $patient_id = (int)$conn->lastInsertId();

            $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id=:id");
            $stmt->execute(['id' => $patient_id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($p) $p['medical_history'] = dec_field($p['medical_history'] ?? null);

            echo json_encode(['success' => true, 'message' => 'Thêm bệnh nhân thành công!', 'patient' => $p]);
            exit;
        }

        // EDIT
        if ($action === 'edit') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            $gender = $_POST['gender'] ?? null;
            $address = $_POST['address'] ?? null;
            $medical_history = $_POST['medical_history'] ?? null;

            if ($patient_id <= 0 || $full_name === '' || $phone === '' || $date_of_birth === '') {
                echo json_encode(['success' => false, 'message' => 'Thiếu dữ liệu cập nhật (ID/Họ tên/SĐT/Ngày sinh)!']);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM patients
                WHERE phone = :phone
                  AND patient_id != :id
                  AND is_deleted = 0
            ");
            $stmt->execute(['phone' => $phone, 'id' => $patient_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'SĐT đã tồn tại!']);
                exit;
            }

            $stmt = $conn->prepare("
                UPDATE patients
                SET full_name=:full_name,
                    phone=:phone,
                    date_of_birth=:dob,
                    gender=:gender,
                    address=:address,
                    medical_history=:history,
                    updated_at=NOW()
                WHERE patient_id=:id AND is_deleted=0
            ");
            $stmt->execute([
                'id'        => $patient_id,
                'full_name' => $full_name,
                'phone'     => $phone,
                'dob'       => $date_of_birth,
                'gender'    => ($gender === 'Male' || $gender === 'Female') ? $gender : null,
                'address'   => $address,
                'history'   => enc_field($medical_history),
            ]);

            $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id=:id");
            $stmt->execute(['id' => $patient_id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($p) $p['medical_history'] = dec_field($p['medical_history'] ?? null);

            echo json_encode(['success' => true, 'message' => 'Cập nhật bệnh nhân thành công!', 'patient' => $p]);
            exit;
        }

        // DETAIL
        if ($action === 'detail') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'patient_id không hợp lệ!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id=:id AND is_deleted=0");
            $stmt->execute(['id' => $patient_id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                echo json_encode(['success' => false, 'message' => 'Không tìm thấy bệnh nhân!']);
                exit;
            }

            $p['medical_history'] = dec_field($p['medical_history'] ?? null);

            $bundle = loadAppointmentBundle($conn, $patient_id);

            echo json_encode([
                'success' => true,
                'patient' => $p,
                'appointments_bundle' => $bundle
            ]);
            exit;
        }

        // UPDATE RECORD + LOG
        if ($action === 'update_record') {
            $record_id = (int)($_POST['record_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');

            if ($record_id <= 0 || $reason === '') {
                echo json_encode(['success' => false, 'message' => 'Thiếu record_id hoặc lý do chỉnh sửa!']);
                exit;
            }

            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $treatment_plan = trim($_POST['treatment_plan'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $bp = trim($_POST['blood_pressure'] ?? '');
            $hr = trim($_POST['heart_rate'] ?? '');
            $temp = trim($_POST['temperature'] ?? '');
            $height = trim($_POST['height'] ?? '');
            $weight = trim($_POST['weight'] ?? '');

            $rx_note = trim($_POST['rx_note'] ?? '');

            try {
                $conn->beginTransaction();

                $st = $conn->prepare("SELECT * FROM medical_records WHERE record_id = ? LIMIT 1");
                $st->execute([$record_id]);
                $oldRec = $st->fetch(PDO::FETCH_ASSOC);

                if (!$oldRec) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy medical_records!']);
                    exit;
                }

                $old_diag = dec_field($oldRec['diagnosis'] ?? null);
                $old_tp   = dec_field($oldRec['treatment_plan'] ?? null);

                $st = $conn->prepare("
                    SELECT *
                    FROM prescription_headers
                    WHERE record_id = ?
                    ORDER BY created_at DESC, prescription_id DESC
                    LIMIT 1
                ");
                $st->execute([$record_id]);
                $oldRx = $st->fetch(PDO::FETCH_ASSOC);

                $old_snapshot = [
                    'diagnosis' => $old_diag ?? '',
                    'treatment_plan' => $old_tp ?? '',
                    'notes' => $oldRec['notes'] ?? '',
                    'blood_pressure' => $oldRec['blood_pressure'] ?? '',
                    'heart_rate' => $oldRec['heart_rate'] ?? '',
                    'temperature' => $oldRec['temperature'] ?? '',
                    'height' => $oldRec['height'] ?? '',
                    'weight' => $oldRec['weight'] ?? '',
                    'rx_note' => $oldRx['note'] ?? '',
                ];

                $new_snapshot = [
                    'diagnosis' => $diagnosis,
                    'treatment_plan' => $treatment_plan,
                    'notes' => $notes,
                    'blood_pressure' => $bp,
                    'heart_rate' => $hr,
                    'temperature' => $temp,
                    'height' => $height,
                    'weight' => $weight,
                    'rx_note' => $rx_note,
                ];

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
                    WHERE record_id = :rid
                    LIMIT 1
                ");
                $up->execute([
                    'diag' => enc_field($diagnosis),
                    'tp' => enc_field($treatment_plan),
                    'notes' => ($notes !== '' ? $notes : null),
                    'bp' => ($bp !== '' ? $bp : null),
                    'hr' => ($hr !== '' ? $hr : null),
                    'temp' => ($temp !== '' ? $temp : null),
                    'h' => ($height !== '' ? $height : null),
                    'w' => ($weight !== '' ? $weight : null),
                    'rid' => $record_id,
                ]);

                if ($oldRx) {
                    $up2 = $conn->prepare("
                        UPDATE prescription_headers
                        SET note = :note
                        WHERE prescription_id = :pid
                        LIMIT 1
                    ");
                    $up2->execute([
                        'note' => ($rx_note !== '' ? $rx_note : null),
                        'pid'  => (int)$oldRx['prescription_id'],
                    ]);
                }

                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

                $ins = $conn->prepare("
                    INSERT INTO medical_audits
                    (actor_role, actor_id, record_id, action, reason, ip, user_agent, old_data, new_data, created_at)
                    VALUES
                    ('admin', :aid, :rid, 'UPDATE', :reason, :ip, :ua, :old, :new, NOW())
                ");
                $ins->execute([
                    'aid' => $admin_id,
                    'rid' => $record_id,
                    'reason' => $reason,
                    'ip' => $ip,
                    'ua' => $ua,
                    'old' => json_encode($old_snapshot, JSON_UNESCAPED_UNICODE),
                    'new' => json_encode($new_snapshot, JSON_UNESCAPED_UNICODE),
                ]);

                $conn->commit();

                echo json_encode(['success' => true, 'message' => 'Cập nhật kết quả khám thành công (đã lưu log)!']);
                exit;
            } catch (Throwable $e) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật: ' . $e->getMessage()]);
                exit;
            }
        }

        // GET AUDITS BY RECORD
        if ($action === 'get_audits') {
            $record_id = (int)($_POST['record_id'] ?? 0);
            if ($record_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'record_id không hợp lệ!']);
                exit;
            }

            $st = $conn->prepare("
                SELECT *
                FROM medical_audits
                WHERE record_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $st->execute([$record_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            echo json_encode(['success' => true, 'rows' => $rows]);
            exit;
        }

        // GET ALL AUDITS
        if ($action === 'get_all_audits') {
            $st = $conn->query("
                SELECT *
                FROM medical_audits
                ORDER BY created_at DESC
                LIMIT 500
            ");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success' => true, 'rows' => $rows]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Action không hợp lệ!']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
        exit;
    }
}

/* =========================
   PAGE LIST
========================= */
try {
    $stmt = $conn->query("
        SELECT patient_id, full_name, phone, date_of_birth, gender
        FROM patients
        WHERE is_deleted = 0
        ORDER BY created_at DESC
    ");
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $patients = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1.0" />
<title>Admin - Quản lý bệnh nhân</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
    --bg:#f5f7fa;
    --card:#fff;
    --text:#111827;
    --muted:#6b7280;
    --line:#eef2f7;
    --primary:#667eea;
    --primary2:#764ba2;
    --shadow:0 6px 18px rgba(17,24,39,0.06);
    --radius:16px;
    --danger:#dc2626;
    --success:#16a34a;
    --soft:#f8fafc;
    --soft2:#f3f6fb;
}
body{
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    background:var(--bg);
    color:var(--text);
}
.header{
    background:linear-gradient(135deg,var(--primary) 0%, var(--primary2) 100%);
    color:#fff;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;gap:14px;
    box-shadow:0 2px 10px rgba(0,0,0,0.10);
}
.header-right{display:flex;align-items:center;gap:12px}
.avatar{
    width:44px;height:44px;border-radius:999px;background:rgba(255,255,255,0.95);color:var(--primary);
    display:grid;place-items:center;font-weight:900;font-size:18px;
}
.meta strong{display:block;font-size:14px}
.logout{
    text-decoration:none;background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.25);
    padding:9px 12px;border-radius:12px;font-weight:900;font-size:13px;display:inline-flex;align-items:center;gap:8px;
    transition:background .2s;white-space:nowrap;
}
.logout:hover{background:rgba(255,255,255,0.26)}
.layout{max-width:1200px;margin:0 auto;padding:22px;display:grid;grid-template-columns:260px 1fr;gap:18px}
@media(max-width:980px){.layout{grid-template-columns:1fr}}
.sidebar{
    background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:14px;height:fit-content;position:sticky;top:16px;
}
@media(max-width:980px){.sidebar{position:static}}
.nav-title{font-size:12px;font-weight:900;color:var(--muted);letter-spacing:.6px;text-transform:uppercase;padding:8px 10px 12px}
.nav{list-style:none;display:grid;gap:6px}
.nav-item{
    padding:12px 12px;border-radius:14px;border:1px solid transparent;cursor:pointer;display:flex;align-items:center;gap:10px;
    transition:.2s;user-select:none;
}
.nav-item:hover{background:#f8fafc;border-color:var(--line)}
.nav-item.active{background:#f0f2ff;border-color:rgba(102,126,234,0.25);color:var(--primary);font-weight:900}
.icon{width:22px;text-align:center;font-size:18px}

.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.card-head{
    padding:16px 18px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;border-bottom:1px solid var(--line);flex-wrap:wrap
}
.card-head h2{font-size:16px;font-weight:900}
.sub{color:var(--muted);font-size:13px;margin-top:4px}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;font-weight:900;font-size:13px;
    text-decoration:none;border:1px solid transparent;cursor:pointer;white-space:nowrap;transition:.2s ease;
}
.btn-primary{
    background:linear-gradient(135deg,var(--primary),var(--primary2));
    color:#fff;border:none;box-shadow:0 10px 24px rgba(102,126,234,0.25);
}
.btn-primary:hover{transform:translateY(-1px);filter:brightness(.98)}
.btn-light{background:#f9fafb;color:#111827;border-color:#e5e7eb}
.btn-light:hover{background:#f3f4f6}

.search{
    padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;font-weight:700;font-size:13px;min-width:260px;background:#fff;
}
.search:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}

.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;table-layout:fixed}
thead th{
    padding:12px 14px;background:#f8fafc;color:#374151;font-size:12px;font-weight:900;border-bottom:1px solid var(--line);
    white-space:nowrap;text-align:left;
}
tbody td{
    padding:14px;border-bottom:1px solid #f1f5f9;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
tbody tr{cursor:pointer}
tbody tr:hover{background:#fafafa}
tbody tr.selected{background:#f0f2ff !important}
.pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid #e5e7eb;background:#fff;color:#374151}
.empty{padding:30px;text-align:center;color:var(--muted)}
.empty .ico{font-size:40px;margin-bottom:10px;opacity:.75}

/* Modal */
.modal-backdrop{
    position:fixed;inset:0;background:rgba(17,24,39,0.5);display:none;align-items:center;justify-content:center;z-index:1000;padding:18px;
    backdrop-filter:blur(4px);
}
.modal{
    width:100%;
    max-width:980px;
    background:#fff;
    border-radius:22px;
    overflow:hidden;
    box-shadow:0 24px 60px rgba(0,0,0,0.22);
    max-height:88vh;
    display:flex;
    flex-direction:column;
    animation:popIn .18s ease;
}
@keyframes popIn{
    from{opacity:0;transform:translateY(12px) scale(.985)}
    to{opacity:1;transform:translateY(0) scale(1)}
}
.modal-head{
    padding:16px 18px;border-bottom:1px solid var(--line);
    display:flex;justify-content:space-between;align-items:center;gap:10px;
    position:sticky;top:0;background:#fff;z-index:2;
}
.modal-head h3{font-size:17px;font-weight:900}
.xbtn{
    border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:8px 10px;font-weight:900;cursor:pointer;transition:.2s;
}
.xbtn:hover{background:#f8fafc}
.modal-body{padding:16px 18px;overflow:auto;}
.modal-foot{
    padding:14px 18px;border-top:1px solid var(--line);
    display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:#fff;
    position:sticky;bottom:0;z-index:2;
}

.tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.tab{
    padding:8px 10px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;font-size:13px;cursor:pointer;
}
.tab.active{border-color:rgba(102,126,234,0.35);background:#f0f2ff;color:var(--primary)}
.panel{border:1px solid var(--line);border-radius:16px;padding:14px;background:#fff}
.panel h4{font-size:13px;font-weight:900;margin-bottom:10px}
.kv{display:grid;grid-template-columns:140px 1fr;gap:10px;align-items:start}
.k{color:var(--muted);font-size:12px;font-weight:900}
.v{font-weight:800;color:#111827;white-space:pre-wrap}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:920px){.grid2{grid-template-columns:1fr}}

.notice{padding:12px;border-radius:14px;border:1px dashed #e5e7eb;background:#fafafa;color:#374151;font-weight:800;line-height:1.6}

/* ===== LỊCH SỬ KHÁM GỌN ===== */
.appt-table{width:100%;border-collapse:collapse;table-layout:auto}
.appt-table th,.appt-table td{
    padding:12px 14px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:14px;vertical-align:middle
}
.appt-table th{background:#fafafa;font-weight:1000;color:#111827}
.appt-summary{display:flex;flex-direction:column;gap:4px}
.appt-main{font-weight:900;color:#111827}
.appt-sub{color:#6b7280;font-size:12px;font-weight:800;line-height:1.5}
.appt-status{
    display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;border:1px solid #dbeafe;background:#eff6ff;color:#1d4ed8;
    font-size:12px;font-weight:900;
}
.appt-status.none{background:#f8fafc;border-color:#e5e7eb;color:#6b7280}
.btn-view{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:9px 12px;border-radius:12px;background:var(--primary);color:#fff;text-decoration:none;
    font-weight:1000;border:1px solid var(--primary);
}
.btn-view:hover{filter:brightness(.97)}
.mini-btn{padding:8px 10px;border-radius:12px;font-weight:900;font-size:12px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;}
.mini-btn:hover{background:#f9fafb}
.mini-btn.primary{background:#f0f2ff;border-color:rgba(102,126,234,0.35);color:var(--primary)}
.mini-pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid #e5e7eb;background:#fff;color:#374151}

.detail-row td{
    background:#fcfcfd;
    padding:0;
}
.appt-detail{
    padding:14px;
    border-top:1px dashed #e5e7eb;
}
.block-title{
    font-size:13px;
    font-weight:1000;
    color:#111827;
    margin-bottom:8px;
}
.kq, .rxbox{border:1px solid #eef2f7;border-radius:14px;padding:12px;background:#fff;}
.kq .meta, .rxrow .meta{color:var(--muted);font-weight:800;font-size:12px}
.kq-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
@media(max-width:720px){.kq-grid{grid-template-columns:1fr}}
.kq-chip{border:1px solid #eef2f7;border-radius:12px;padding:10px;background:#fff}
.kq-chip .t{color:var(--muted);font-size:11px;font-weight:1000;text-transform:uppercase;letter-spacing:.4px}
.kq-chip .v{margin-top:6px;font-weight:1000;color:#111827;white-space:pre-wrap;line-height:1.6}

.rxbox{margin-top:10px;background:#fff}
.rxrow{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 0;border-bottom:1px dashed #e5e7eb}
.rxrow:last-child{border-bottom:none}

/* Toast */
.toast-wrap{position:fixed;top:16px;right:16px;z-index:1100;display:grid;gap:10px}
.toast{
    min-width:260px;max-width:360px;padding:12px 14px;border-radius:14px;color:#fff;box-shadow:0 12px 30px rgba(0,0,0,0.18);
    display:flex;gap:10px;align-items:flex-start;opacity:0;transform:translateY(-6px);transition:.2s;font-weight:800;
}
.toast.show{opacity:1;transform:translateY(0)}
.toast.success{background:#16a34a}
.toast.error{background:#dc2626}
.toast .small{font-size:12px;font-weight:700;opacity:.92}

/* ===== LOG MODAL ===== */
.log-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.log-tools input{
    padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;font-weight:800;font-size:13px;min-width:260px;
}
.log-tools input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}
.log-list{margin-top:12px;display:grid;gap:10px}
.log-item{border:1px solid #eef2f7;border-radius:14px;background:#fff;padding:12px}
.log-top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.log-top .who{font-weight:1000}
.log-top .time{color:var(--muted);font-weight:900;font-size:12px}
.log-reason{margin-top:6px;color:#111827;font-weight:800}
.log-mini{margin-top:8px;color:var(--muted);font-size:12px;font-weight:800}
.log-actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}

/* ===== FORM BỆNH NHÂN ===== */
.form-shell{display:grid;gap:18px}
.form-hero{
    background:linear-gradient(135deg,#eef2ff 0%, #f5f3ff 100%);
    border:1px solid #e8edff;
    border-radius:18px;
    padding:18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
}
.form-hero-left{display:flex;align-items:center;gap:14px}
.form-hero-icon{
    width:56px;height:56px;border-radius:18px;display:grid;place-items:center;font-size:24px;background:#fff;color:var(--primary);
    box-shadow:0 10px 24px rgba(102,126,234,0.12);
}
.form-hero h4{font-size:18px;font-weight:900;color:#111827}
.form-hero p{margin-top:4px;font-size:13px;font-weight:700;color:#6b7280}
.form-badge{
    display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;background:#fff;border:1px solid #dbe4ff;
    color:var(--primary);font-weight:900;font-size:12px;
}
.form-sections{display:grid;gap:16px}
.form-card{
    border:1px solid #edf2f7;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,0.03);
}
.form-card-head{
    padding:14px 16px;border-bottom:1px solid #f1f5f9;background:#fbfcff;display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.form-card-head h5{font-size:14px;font-weight:900;color:#111827}
.form-card-head span{font-size:12px;color:#6b7280;font-weight:800}
.form-card-body{padding:16px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}}
.field{display:flex;flex-direction:column;gap:8px}
.field.full{grid-column:1 / -1}
.field label{
    font-size:13px;font-weight:900;color:#111827;display:flex;align-items:center;gap:8px;
}
.field label .req{color:#ef4444}
.field small{color:#6b7280;font-size:12px;font-weight:700;margin-top:-2px}
.input-wrap{position:relative}
.input-icon{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:15px;opacity:.72;pointer-events:none;
}
.field input,
.field select,
.field textarea{
    width:100%;border:1px solid #dfe7f1;background:#fff;color:#111827;border-radius:14px;padding:13px 14px;font-size:14px;font-weight:700;transition:.2s ease;
}
.field input.with-icon{padding-left:42px}
.field textarea{min-height:110px;resize:vertical;line-height:1.6}
.field input:hover,
.field select:hover,
.field textarea:hover{border-color:#cad5e3}
.field input:focus,
.field select:focus,
.field textarea:focus{
    outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(102,126,234,0.14);background:#fff;
}
.gender-pills{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
@media(max-width:640px){.gender-pills{grid-template-columns:1fr}}
.gender-option{position:relative}
.gender-option input{position:absolute;opacity:0;pointer-events:none}
.gender-label{
    display:flex;align-items:center;gap:10px;justify-content:center;border:1px solid #dfe7f1;background:#fff;padding:12px 14px;border-radius:14px;cursor:pointer;
    transition:.2s ease;font-weight:900;color:#374151;
}
.gender-label:hover{border-color:#cbd5e1;background:#fafcff}
.gender-option input:checked + .gender-label{
    border-color:rgba(102,126,234,.45);background:#eef2ff;color:var(--primary);box-shadow:0 8px 22px rgba(102,126,234,.12);
}
.form-note{
    padding:12px 14px;background:#f8fafc;border:1px dashed #dbe3ee;border-radius:14px;color:#475569;font-size:13px;font-weight:800;line-height:1.6;
}
.form-actions-left{
    margin-right:auto;color:#6b7280;font-size:12px;font-weight:800;
}

/* ===== FORM SỬA KẾT QUẢ KHÁM ===== */
.record-form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}
@media(max-width:820px){
    .record-form-grid{
        grid-template-columns:1fr;
    }
}
.record-section{
    border:1px solid #eef2f7;
    border-radius:16px;
    background:#fff;
    overflow:hidden;
}
.record-section-head{
    padding:12px 14px;
    background:#fafbff;
    border-bottom:1px solid #eef2f7;
    font-weight:1000;
    color:#111827;
    font-size:13px;
}
.record-section-body{
    padding:14px;
    display:grid;
    gap:12px;
}
.record-preview{
    padding:10px 12px;
    border-radius:12px;
    background:#f8fafc;
    border:1px dashed #dbe3ee;
    color:#475569;
    font-size:12px;
    font-weight:800;
    line-height:1.6;
}
</style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <h1>👥 Quản lý bệnh nhân</h1>
    </div>
    <div class="header-right">
        <div class="avatar"><?php echo h($admin_initial); ?></div>
        <div class="meta">
            <strong><?php echo h($admin_name); ?></strong>
        </div>
        <a class="logout" href="../logout.php">🚪 Đăng xuất</a>
    </div>
</div>

<div class="layout">
    <aside class="sidebar">
        <div class="nav-title">Quản trị</div>
        <ul class="nav">
            <li class="nav-item" onclick="window.location.href='dashboard.php'"><span class="icon">📊</span>Trang chủ</li>
            <li class="nav-item" onclick="window.location.href='doctors.php'"><span class="icon">👨‍⚕️</span>Bác sĩ</li>
            <li class="nav-item active" onclick="window.location.href='patients.php'"><span class="icon">👥</span>Bệnh nhân</li>
            <li class="nav-item" onclick="window.location.href='appointments.php'"><span class="icon">📅</span>Lịch khám</li>
            <li class="nav-item" onclick="window.location.href='receptionists.php'"><span class="icon">👩‍💼</span>Lễ tân</li>
        </ul>
    </aside>

    <main>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Danh sách bệnh nhân</h2>
                    <div class="sub">Tổng: <strong><?php echo count($patients); ?></strong> bệnh nhân</div>
                </div>
                <div class="toolbar">
                    <input id="searchInput" class="search" placeholder="Tìm theo mã / tên / SĐT..." />
                    <button class="btn btn-light" onclick="openAllLog()">🕒 Log</button>
                    <button class="btn btn-primary" onclick="showAddModal()">➕ Thêm bệnh nhân</button>
                </div>
            </div>

            <div class="table-wrap">
                <table id="patientsTable">
                    <thead>
                        <tr>
                            <th style="width:90px">Mã</th>
                            <th>Họ tên</th>
                            <th style="width:160px">SĐT</th>
                            <th style="width:120px">Giới tính</th>
                            <th style="width:140px">Ngày sinh</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($patients)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <div class="ico">📭</div>
                                    <div>Chưa có bệnh nhân nào</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                            <tr id="row-<?php echo (int)$p['patient_id']; ?>" onclick="openDetail(<?php echo (int)$p['patient_id']; ?>)">
                                <td><strong>#<?php echo (int)$p['patient_id']; ?></strong></td>
                                <td title="<?php echo h($p['full_name']); ?>"><?php echo h($p['full_name']); ?></td>
                                <td><?php echo h($p['phone']); ?></td>
                                <td><span class="pill"><?php echo h(genderText($p['gender'])); ?></span></td>
                                <td><?php echo h(fmtDate($p['date_of_birth'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<!-- MODAL: LOG TỔNG HỢP -->
<div class="modal-backdrop" id="allLogModal">
    <div class="modal" style="max-width:980px">
        <div class="modal-head">
            <h3>🕒 LOG tổng hợp</h3>
            <button class="xbtn" onclick="closeAllLog()">✖️ Đóng</button>
        </div>
        <div class="modal-body">
            <div class="log-tools">
                <input id="allLogFilter" placeholder="Lọc theo record_id / lý do / role#id..." />
                <button class="btn btn-light" type="button" onclick="loadAllAudits()">🔄 Tải lại</button>
            </div>
            <div id="allLogList" class="log-list">
                <div class="notice">Đang tải log...</div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeAllLog()">Đóng</button>
        </div>
    </div>
</div>

<!-- MODAL: DETAIL -->
<div class="modal-backdrop" id="detailModal">
    <div class="modal">
        <div class="modal-head">
            <h3 id="detailTitle">Hồ sơ bệnh nhân</h3>
            <button class="xbtn" onclick="closeDetail()">✖️ Đóng</button>
        </div>

        <div class="modal-body">
            <div class="tabs">
                <button class="tab active" data-tab="tab-info" onclick="switchTab(event,'tab-info')">🧑‍⚕️ Thông tin bệnh nhân</button>
                <button class="tab" data-tab="tab-appt" onclick="switchTab(event,'tab-appt')">📅 Lịch sử khám bệnh</button>
            </div>

            <div id="tab-info">
                <div class="grid2" style="grid-template-columns:1fr">
                    <div class="panel">
                        <h4>Thông tin đầy đủ</h4>
                        <div class="kv"><div class="k">Mã</div><div class="v" id="d_id">-</div></div><br/>
                        <div class="kv"><div class="k">Họ tên</div><div class="v" id="d_name">-</div></div><br/>
                        <div class="kv"><div class="k">SĐT</div><div class="v" id="d_phone">-</div></div><br/>
                        <div class="kv"><div class="k">Ngày sinh</div><div class="v" id="d_dob">-</div></div><br/>
                        <div class="kv"><div class="k">Giới tính</div><div class="v" id="d_gender">-</div></div><br/>
                        <div class="kv"><div class="k">Địa chỉ</div><div class="v" id="d_address">-</div></div><br/>
                        <div class="kv"><div class="k">Tiền sử</div><div class="v" id="d_history">-</div></div><br/>
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-light" onclick="openEditFromDetail()">✏️ Sửa</button>
                </div>
            </div>

            <div id="tab-appt" style="display:none">
                <div class="panel">
                    <h4>Lịch sử khám bệnh</h4>
                    <div id="apptWrap"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ADD/EDIT PATIENT -->
<div class="modal-backdrop" id="formModal">
    <div class="modal" style="max-width:840px">
        <div class="modal-head">
            <h3 id="formTitle">Thêm bệnh nhân</h3>
            <button class="xbtn" onclick="closeForm()">✖️ Đóng</button>
        </div>

        <form id="patientForm" class="modal-body">
            <input type="hidden" name="patient_id" id="patient_id" />

            <div class="form-shell">
                <div class="form-hero">
                    <div class="form-hero-left">
                        <div class="form-hero-icon">🩺</div>
                        <div>
                            <h4 id="formHeroTitle">Hồ sơ bệnh nhân</h4>
                            <p>Nhập đầy đủ thông tin cơ bản để tạo hoặc cập nhật hồ sơ bệnh nhân.</p>
                        </div>
                    </div>
                    <div class="form-badge">🔒 Dữ liệu được lưu an toàn</div>
                </div>

                <div class="form-sections">
                    <div class="form-card">
                        <div class="form-card-head">
                            <h5>Thông tin cơ bản</h5>
                            <span>Thông tin bắt buộc để quản lý hồ sơ</span>
                        </div>
                        <div class="form-card-body">
                            <div class="form-grid">
                                <div class="field">
                                    <label>Họ tên <span class="req">*</span></label>
                                    <div class="input-wrap">
                                        <span class="input-icon">👤</span>
                                        <input class="with-icon" type="text" name="full_name" id="full_name" placeholder="Nhập họ và tên bệnh nhân" required />
                                    </div>
                                    <small>Ví dụ: Nguyễn Văn A</small>
                                </div>

                                <div class="field">
                                    <label>Số điện thoại <span class="req">*</span></label>
                                    <div class="input-wrap">
                                        <span class="input-icon">📞</span>
                                        <input class="with-icon" type="tel" name="phone" id="phone" placeholder="Nhập số điện thoại" required />
                                    </div>
                                    <small>Dùng để tra cứu và liên hệ bệnh nhân</small>
                                </div>

                                <div class="field">
                                    <label>Ngày sinh <span class="req">*</span></label>
                                    <input type="date" name="date_of_birth" id="date_of_birth" required />
                                    <small>Giúp xác định độ tuổi và hồ sơ y tế phù hợp</small>
                                </div>

                                <div class="field">
                                    <label>Giới tính</label>
                                    <div class="gender-pills">
                                        <div class="gender-option">
                                            <input type="radio" name="gender" id="gender_empty" value="" checked>
                                            <label class="gender-label" for="gender_empty">⚪ Chưa chọn</label>
                                        </div>
                                        <div class="gender-option">
                                            <input type="radio" name="gender" id="gender_male" value="Male">
                                            <label class="gender-label" for="gender_male">👨 Nam</label>
                                        </div>
                                        <div class="gender-option">
                                            <input type="radio" name="gender" id="gender_female" value="Female">
                                            <label class="gender-label" for="gender_female">👩 Nữ</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-card">
                        <div class="form-card-head">
                            <h5>Thông tin bổ sung</h5>
                            <span>Không bắt buộc nhưng nên có</span>
                        </div>
                        <div class="form-card-body">
                            <div class="form-grid">
                                <div class="field full">
                                    <label>Địa chỉ</label>
                                    <textarea name="address" id="address" placeholder="Nhập địa chỉ hiện tại của bệnh nhân..."></textarea>
                                    <small>Có thể để trống nếu chưa có thông tin</small>
                                </div>

                                <div class="field full">
                                    <label>Tiền sử bệnh / dị ứng / bệnh nền</label>
                                    <textarea name="medical_history" id="medical_history" placeholder="Ví dụ: Dị ứng penicillin, tiền sử cao huyết áp, tiểu đường..."></textarea>
                                    <small>Thông tin này sẽ được mã hóa trước khi lưu</small>
                                </div>
                            </div>

                            <div class="form-note">
                                💡 Gợi ý: Nên nhập rõ các thông tin như bệnh nền, dị ứng thuốc hoặc tiền sử phẫu thuật để hỗ trợ bác sĩ trong các lần khám tiếp theo.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <div class="modal-foot">
            <div class="form-actions-left">Các trường có dấu <span style="color:#ef4444">*</span> là bắt buộc</div>
            <button class="btn btn-light" onclick="closeForm()">Hủy</button>
            <button class="btn btn-primary" onclick="submitPatientForm()">💾 Lưu bệnh nhân</button>
        </div>
    </div>
</div>

<!-- MODAL: EDIT RECORD -->
<div class="modal-backdrop" id="recordModal">
    <div class="modal" style="max-width:920px">
        <div class="modal-head">
            <h3>✏️ Sửa kết quả khám</h3>
            <button class="xbtn" onclick="closeRecordModal()">✖️ Đóng</button>
        </div>

        <form id="recordForm" class="modal-body">
            <input type="hidden" id="record_id_edit" name="record_id">

            <div class="record-preview" id="recordPreview">
                Đang chuẩn bị dữ liệu...
            </div>

            <div class="record-form-grid" style="margin-top:14px;">
                <div class="record-section">
                    <div class="record-section-head">📋 Nội dung khám</div>
                    <div class="record-section-body">
                        <div class="field">
                            <label>Lý do chỉnh sửa <span class="req">*</span></label>
                            <textarea id="reason_edit" name="reason" placeholder="Nhập lý do chỉnh sửa để lưu log..." required></textarea>
                        </div>

                        <div class="field">
                            <label>Chẩn đoán</label>
                            <textarea id="diagnosis_edit" name="diagnosis" placeholder="Nhập chẩn đoán..."></textarea>
                        </div>

                        <div class="field">
                            <label>Phác đồ điều trị</label>
                            <textarea id="treatment_plan_edit" name="treatment_plan" placeholder="Nhập phác đồ điều trị..."></textarea>
                        </div>

                        <div class="field">
                            <label>Ghi chú</label>
                            <textarea id="notes_edit" name="notes" placeholder="Ghi chú thêm..."></textarea>
                        </div>

                        <div class="field">
                            <label>Ghi chú đơn thuốc</label>
                            <textarea id="rx_note_edit" name="rx_note" placeholder="Ghi chú trên đơn thuốc..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="record-section">
                    <div class="record-section-head">❤️ Dấu hiệu sinh tồn</div>
                    <div class="record-section-body">
                        <div class="field">
                            <label>Huyết áp</label>
                            <input type="text" id="blood_pressure_edit" name="blood_pressure" placeholder="Ví dụ: 120/80" />
                        </div>
                        <div class="field">
                            <label>Nhịp tim</label>
                            <input type="text" id="heart_rate_edit" name="heart_rate" placeholder="Ví dụ: 80" />
                        </div>
                        <div class="field">
                            <label>Nhiệt độ</label>
                            <input type="text" id="temperature_edit" name="temperature" placeholder="Ví dụ: 36.8" />
                        </div>
                        <div class="field">
                            <label>Chiều cao</label>
                            <input type="text" id="height_edit" name="height" placeholder="Ví dụ: 170" />
                        </div>
                        <div class="field">
                            <label>Cân nặng</label>
                            <input type="text" id="weight_edit" name="weight" placeholder="Ví dụ: 65" />
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeRecordModal()">Hủy</button>
            <button class="btn btn-primary" onclick="submitRecordForm()">💾 Lưu kết quả khám</button>
        </div>
    </div>
</div>

<script>
function toast(message, type='success'){
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'success' ? 'success' : 'error');
    el.innerHTML = `<div>${type === 'success' ? '✅' : '⚠️'}</div>
                    <div>
                        <div>${escapeHtml(message)}</div>
                        <div class="small">${new Date().toLocaleString()}</div>
                    </div>`;
    wrap.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 200);
    }, 2800);
}
function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[m]));
}
function formatDate(iso){
    if (!iso) return 'N/A';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('vi-VN');
}
function formatDateTime(iso){
    if (!iso) return 'N/A';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('vi-VN');
}

document.getElementById('searchInput').addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#patientsTable tbody tr').forEach(tr => {
        const text = tr.innerText.toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
    });
});

function switchTab(e, id){
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    if (e && e.target) e.target.classList.add('active');

    ['tab-info','tab-appt'].forEach(x => {
        const el = document.getElementById(x);
        if (el) el.style.display = (x === id) ? '' : 'none';
    });
}

const detailModal = document.getElementById('detailModal');
const formModal = document.getElementById('formModal');
const recordModal = document.getElementById('recordModal');

let currentDetailId = null;
let currentDetailCache = null;

function setGenderValue(value){
    const empty = document.getElementById('gender_empty');
    const male = document.getElementById('gender_male');
    const female = document.getElementById('gender_female');

    empty.checked = false;
    male.checked = false;
    female.checked = false;

    if (value === 'Male') male.checked = true;
    else if (value === 'Female') female.checked = true;
    else empty.checked = true;
}

function showAddModal(){
    document.getElementById('formTitle').textContent = 'Thêm bệnh nhân';
    document.getElementById('formHeroTitle').textContent = 'Tạo hồ sơ bệnh nhân mới';
    document.getElementById('patientForm').reset();
    document.getElementById('patient_id').value = '';
    setGenderValue('');
    formModal.style.display = 'flex';
}
function closeForm(){ formModal.style.display = 'none'; }
function closeDetail(){ detailModal.style.display = 'none'; currentDetailId = null; currentDetailCache = null; }
function closeRecordModal(){ recordModal.style.display = 'none'; }

function openDetail(patientId){
    currentDetailId = patientId;

    document.querySelectorAll('#patientsTable tbody tr').forEach(x => x.classList.remove('selected'));
    const row = document.getElementById('row-' + patientId);
    if (row) row.classList.add('selected');

    switchTab({target: document.querySelector('.tab[data-tab="tab-info"]')}, 'tab-info');

    fetch('patients.php?action=detail', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `patient_id=${encodeURIComponent(patientId)}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            toast(data.message || 'Không lấy được chi tiết!', 'error');
            return;
        }
        currentDetailCache = data;

        const p = data.patient;
        document.getElementById('detailTitle').textContent = `Hồ sơ bệnh nhân #${p.patient_id}`;
        document.getElementById('d_id').textContent = `#${p.patient_id}`;
        document.getElementById('d_name').textContent = p.full_name || 'N/A';
        document.getElementById('d_phone').textContent = p.phone || 'N/A';
        document.getElementById('d_dob').textContent = formatDate(p.date_of_birth);
        document.getElementById('d_gender').textContent = p.gender === 'Male' ? 'Nam' : p.gender === 'Female' ? 'Nữ' : 'N/A';
        document.getElementById('d_address').textContent = p.address || 'N/A';
        document.getElementById('d_history').textContent = p.medical_history || 'N/A';

        renderAppointmentBundle(data.appointments_bundle);

        detailModal.style.display = 'flex';
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
}

/* ===== Toggle detail row ===== */
function toggleAppt(aid){
    document.querySelectorAll('.detail-row').forEach(row => {
        if (row.id !== 'appt-body-' + aid) row.style.display = 'none';
    });

    document.querySelectorAll('[data-toggle]').forEach(btn => {
        if (String(btn.getAttribute('data-toggle')) !== String(aid)) {
            btn.textContent = 'Xem chi tiết';
        }
    });

    const tr = document.getElementById('appt-body-' + aid);
    if (!tr) return;

    const isOpen = tr.style.display !== 'none';
    tr.style.display = isOpen ? 'none' : '';
    const btn = document.querySelector(`[data-toggle="${aid}"]`);
    if (btn) btn.textContent = isOpen ? 'Xem chi tiết' : 'Ẩn chi tiết';
}

/* ===== Modal sửa kết quả khám ===== */
function openRecordEditModal(payload){
    document.getElementById('recordForm').reset();

    document.getElementById('record_id_edit').value = payload.record_id || '';
    document.getElementById('reason_edit').value = '';
    document.getElementById('diagnosis_edit').value = payload.diagnosis || '';
    document.getElementById('treatment_plan_edit').value = payload.treatment_plan || '';
    document.getElementById('notes_edit').value = payload.notes || '';
    document.getElementById('blood_pressure_edit').value = payload.blood_pressure || '';
    document.getElementById('heart_rate_edit').value = payload.heart_rate || '';
    document.getElementById('temperature_edit').value = payload.temperature || '';
    document.getElementById('height_edit').value = payload.height || '';
    document.getElementById('weight_edit').value = payload.weight || '';
    document.getElementById('rx_note_edit').value = payload.rx_note || '';

    document.getElementById('recordPreview').innerHTML = `
        <b>record #${escapeHtml(payload.record_id || '')}</b>
        ${payload.exam_time ? ` • ${escapeHtml(payload.exam_time)}` : ''}
        ${payload.doctor_name ? ` • Bác sĩ: ${escapeHtml(payload.doctor_name)}` : ''}
    `;

    recordModal.style.display = 'flex';
}
function submitRecordForm(){
    document.getElementById('recordForm').requestSubmit();
}

document.getElementById('recordForm').addEventListener('submit', function(e){
    e.preventDefault();

    const reason = document.getElementById('reason_edit').value.trim();
    const recordId = document.getElementById('record_id_edit').value;

    if (!recordId) {
        toast('Không có record_id!', 'error');
        return;
    }
    if (!reason) {
        toast('Vui lòng nhập lý do chỉnh sửa!', 'error');
        document.getElementById('reason_edit').focus();
        return;
    }

    const fd = new FormData(this);

    fetch('patients.php?action=update_record', { method:'POST', body: fd })
      .then(r => r.json())
      .then(data => {
          toast(data.message || 'Done', data.success ? 'success' : 'error');
          if (!data.success) return;

          closeRecordModal();
          if (currentDetailId) openDetail(currentDetailId);
          loadAllAudits();
      })
      .catch(() => toast('Lỗi kết nối server!', 'error'));
});

function adminViewAudits(record_id){
    record_id = Number(record_id || 0);
    if (!record_id) return toast('Không có record_id!', 'error');

    const fd = new FormData();
    fd.append('record_id', record_id);

    fetch('patients.php?action=get_audits', { method:'POST', body: fd })
      .then(r => r.json())
      .then(data => {
          if (!data.success) return toast(data.message || 'Không lấy được log', 'error');
          const rows = data.rows || [];
          if (!rows.length) return alert('Chưa có log chỉnh sửa.');

          const msg = rows.map(x => {
              const t = x.created_at || '';
              const who = (x.actor_role || '') + '#' + (x.actor_id || '');
              const reason = x.reason || '';
              return `- ${t} • ${who} • ${reason}`;
          }).join('\n');

          alert('Lịch sử chỉnh sửa (record #' + record_id + '):\n' + msg);
      })
      .catch(() => toast('Lỗi kết nối server!', 'error'));
}

/* ===== render lịch sử khám gọn ===== */
function renderAppointmentBundle(pack){
    const wrap = document.getElementById('apptWrap');
    wrap.innerHTML = '';

    if (!pack || pack.ok === false) {
        wrap.innerHTML = `<div class="notice">${escapeHtml(pack?.message || 'Không lấy được dữ liệu lịch khám.')}</div>`;
        return;
    }

    const rows = pack.rows || [];
    if (!rows.length) {
        wrap.innerHTML = `<div class="notice">Chưa có lịch khám cho bệnh nhân này.</div>`;
        return;
    }

    let html = `
      <div class="table-wrap">
        <table class="appt-table">
          <thead>
            <tr>
              <th style="width:180px">Ngày khám</th>
              <th>Bác sĩ / Khoa</th>
              <th style="width:160px">Kết quả</th>
              <th style="width:150px">Thao tác</th>
            </tr>
          </thead>
          <tbody>
    `;

    rows.forEach((it) => {
        const a = it.appointment || {};
        const r = it.record || null;
        const rxs = it.prescriptions || [];

        const aid = a.appointment_id ?? '';
        const apptTime = a.appointment_date ? formatDateTime(a.appointment_date) : 'N/A';
        const status = a.status || '';
        const doctor = a.doctor_name || 'N/A';
        const dept = a.department_name || 'N/A';
        const symptoms = a.symptoms || '';

        const examRaw = r ? (r.examination_date || r.created_at || '') : '';
        const examTime = examRaw ? formatDateTime(examRaw) : '';
        const hasRecord = !!r;

        let resultStatus = `<span class="appt-status none">Chưa có</span>`;
        if (hasRecord) resultStatus = `<span class="appt-status">Đã khám</span>`;

        let recordHtml = '';
        if (!r) {
            recordHtml = `<div class="notice">Chưa có kết quả khám cho lịch này.</div>`;
        } else {
            let rxNote = '';
            if (rxs && rxs.length && rxs[0] && rxs[0].header && rxs[0].header.note) rxNote = rxs[0].header.note;

            const payload = {
                record_id: Number(r.record_id || 0),
                diagnosis: r.diagnosis || '',
                treatment_plan: r.treatment_plan || '',
                notes: r.notes || '',
                blood_pressure: r.blood_pressure || '',
                heart_rate: String(r.heart_rate ?? ''),
                temperature: String(r.temperature ?? ''),
                height: String(r.height ?? ''),
                weight: String(r.weight ?? ''),
                rx_note: rxNote || '',
                exam_time: examTime || '',
                doctor_name: r.doctor_name || doctor || ''
            };

            recordHtml = `
                <div class="kq">
                    <div class="block-title">📋 Kết quả khám</div>
                    <div class="meta">
                        ${escapeHtml(r.examination_date || r.created_at || '')}
                        ${r.doctor_name ? (' • 👨‍⚕️ ' + escapeHtml(r.doctor_name)) : ''}
                    </div>

                    <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                        <button class="mini-btn" type="button" onclick='openRecordEditModal(${JSON.stringify(payload)})'>✏️ Sửa kết quả</button>
                        <button class="mini-btn" type="button" onclick="adminViewAudits(${Number(r.record_id || 0)})">🕒 Log record</button>
                        <span class="mini-pill">record #${escapeHtml(r.record_id)}</span>
                    </div>

                    <div class="kq-grid">
                        <div class="kq-chip">
                            <div class="t">Chẩn đoán</div>
                            <div class="v">${escapeHtml(r.diagnosis || 'N/A')}</div>
                        </div>
                        <div class="kq-chip">
                            <div class="t">Phác đồ</div>
                            <div class="v">${escapeHtml(r.treatment_plan || 'N/A')}</div>
                        </div>
                        <div class="kq-chip">
                            <div class="t">Ghi chú</div>
                            <div class="v">${escapeHtml(r.notes || 'N/A')}</div>
                        </div>
                        <div class="kq-chip">
                            <div class="t">Dấu hiệu sinh tồn</div>
                            <div class="v">
                                HA: ${escapeHtml(r.blood_pressure || 'N/A')} • HR: ${escapeHtml(r.heart_rate ?? 'N/A')} • T: ${escapeHtml(r.temperature ?? 'N/A')}<br>
                                Cao: ${escapeHtml(r.height ?? 'N/A')} • Nặng: ${escapeHtml(r.weight ?? 'N/A')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        let rxHtml = '';
        if (!r) {
            rxHtml = '';
        } else if (!rxs.length) {
            rxHtml = `<div class="notice" style="margin-top:10px">Chưa có đơn thuốc cho kết quả khám này.</div>`;
        } else {
            rxs.forEach(group => {
                const head = group.header || {};
                const items = group.items || [];
                rxHtml += `<div class="rxbox">
                             <div class="block-title">🧾 Đơn thuốc ${head.created_at ? ('• ' + escapeHtml(formatDateTime(head.created_at))) : ''}</div>
                             ${head.note ? `<div class="meta" style="margin-top:6px">${escapeHtml(head.note)}</div>` : ``}
                           `;

                if (!items.length) {
                    rxHtml += `<div class="notice">Đơn thuốc này chưa có dòng thuốc.</div>`;
                } else {
                    items.forEach(item => {
                        rxHtml += `
                            <div class="rxrow">
                                <div>
                                    <div><b>${escapeHtml(item.medication_name || 'N/A')}</b></div>
                                    ${item.dosage ? `<div class="meta">${escapeHtml(item.dosage)}</div>` : ``}
                                    ${item.instructions ? `<div class="meta">${escapeHtml(item.instructions)}</div>` : ``}
                                </div>
                                <div><b>Số ngày:</b> ${escapeHtml(item.days ?? '1')}</div>
                            </div>
                        `;
                    });
                }

                rxHtml += `</div>`;
            });
        }

        html += `
          <tr>
            <td>
                <div class="appt-summary">
                    <div class="appt-main">${escapeHtml(apptTime)}</div>
                    <div class="appt-sub">Mã lịch #${escapeHtml(aid)}</div>
                </div>
            </td>
            <td>
                <div class="appt-summary">
                    <div class="appt-main">👨‍⚕️ ${escapeHtml(doctor)}</div>
                    <div class="appt-sub">🏥 ${escapeHtml(dept)} ${status ? ('• ' + escapeHtml(status)) : ''}</div>
                </div>
            </td>
            <td>${resultStatus}</td>
            <td>
              <button class="mini-btn primary" type="button" data-toggle="${escapeHtml(aid)}" onclick="toggleAppt('${escapeHtml(aid)}')">Xem chi tiết</button>
            </td>
          </tr>
          <tr id="appt-body-${escapeHtml(aid)}" class="detail-row" style="display:none;">
            <td colspan="4">
              <div class="appt-detail">
                ${symptoms ? `<div class="notice" style="margin-bottom:10px"><b>Triệu chứng:</b> ${escapeHtml(symptoms)}</div>` : ''}
                ${recordHtml}
                ${rxHtml}
              </div>
            </td>
          </tr>
        `;
    });

    html += `</tbody></table></div>`;
    wrap.innerHTML = html;
}

function openEditFromDetail(){
    if (!currentDetailCache || !currentDetailCache.patient) return toast('Chưa có dữ liệu bệnh nhân!', 'error');
    const p = currentDetailCache.patient;

    document.getElementById('formTitle').textContent = 'Sửa bệnh nhân';
    document.getElementById('formHeroTitle').textContent = 'Cập nhật hồ sơ bệnh nhân';
    document.getElementById('patient_id').value = p.patient_id || '';
    document.getElementById('full_name').value = p.full_name || '';
    document.getElementById('phone').value = p.phone || '';
    document.getElementById('date_of_birth').value = p.date_of_birth || '';
    setGenderValue(p.gender || '');
    document.getElementById('address').value = p.address || '';
    document.getElementById('medical_history').value = p.medical_history || '';

    formModal.style.display = 'flex';
}
function submitPatientForm(){ document.getElementById('patientForm').requestSubmit(); }

document.getElementById('patientForm').addEventListener('submit', function(e){
    e.preventDefault();

    const patientId = document.getElementById('patient_id').value;
    const action = patientId ? 'edit' : 'add';
    const formData = new FormData(this);

    fetch(`patients.php?action=${action}`, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        toast(data.message, data.success ? 'success' : 'error');
        if (!data.success) return;

        if (action === 'add') addRowToTable(data.patient);
        else updateRowInTable(data.patient);

        closeForm();
        if (currentDetailId && data.patient && Number(currentDetailId) === Number(data.patient.patient_id)) {
            openDetail(currentDetailId);
        }
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
});

function addRowToTable(p){
    const tbody = document.querySelector('#patientsTable tbody');
    const empty = tbody.querySelector('.empty');
    if (empty) empty.closest('tr').remove();

    const tr = document.createElement('tr');
    tr.id = 'row-' + p.patient_id;
    tr.onclick = () => openDetail(p.patient_id);

    const gender = (p.gender === 'Male') ? 'Nam' : (p.gender === 'Female') ? 'Nữ' : 'N/A';

    tr.innerHTML = `
        <td><strong>#${p.patient_id}</strong></td>
        <td title="${escapeHtml(p.full_name)}">${escapeHtml(p.full_name)}</td>
        <td>${escapeHtml(p.phone)}</td>
        <td><span class="pill">${escapeHtml(gender)}</span></td>
        <td>${formatDate(p.date_of_birth)}</td>
    `;
    tbody.prepend(tr);
}

function updateRowInTable(p){
    const tr = document.getElementById('row-' + p.patient_id);
    if (!tr) return;

    const gender = (p.gender === 'Male') ? 'Nam' : (p.gender === 'Female') ? 'Nữ' : 'N/A';
    tr.children[1].textContent = p.full_name || '';
    tr.children[1].title = p.full_name || '';
    tr.children[2].textContent = p.phone || '';
    tr.children[3].innerHTML = `<span class="pill">${escapeHtml(gender)}</span>`;
    tr.children[4].textContent = formatDate(p.date_of_birth);
}

/* =========================
   LOG tổng hợp
========================= */
const allLogModal = document.getElementById('allLogModal');
const allLogList = document.getElementById('allLogList');
const allLogFilter = document.getElementById('allLogFilter');
let ALL_AUDITS = [];

function openAllLog(){
    allLogModal.style.display = 'flex';
    if (!ALL_AUDITS.length) loadAllAudits();
}
function closeAllLog(){
    allLogModal.style.display = 'none';
}

allLogModal.addEventListener('click', (e) => {
    if (e.target === allLogModal) closeAllLog();
});
formModal.addEventListener('click', (e) => {
    if (e.target === formModal) closeForm();
});
detailModal.addEventListener('click', (e) => {
    if (e.target === detailModal) closeDetail();
});
recordModal.addEventListener('click', (e) => {
    if (e.target === recordModal) closeRecordModal();
});

function auditToText(x){
    const t = x.created_at || '';
    const who = (x.actor_role || '') + '#' + (x.actor_id || '');
    const rid = x.record_id || '';
    const reason = x.reason || '';
    return `${t} ${who} record#${rid} ${reason}`.toLowerCase();
}

function renderAllAudits(list){
    if (!list.length){
        allLogList.innerHTML = `<div class="notice">Chưa có log nào.</div>`;
        return;
    }

    allLogList.innerHTML = list.map(x => {
        const t = escapeHtml(x.created_at || '');
        const who = escapeHtml((x.actor_role || '') + '#' + (x.actor_id || ''));
        const rid = escapeHtml(String(x.record_id || ''));
        const reason = escapeHtml(x.reason || '');
        const ip = escapeHtml(x.ip || '');
        return `
          <div class="log-item">
            <div class="log-top">
              <div class="who">👤 ${who} • record #${rid}</div>
              <div class="time">🕒 ${t}</div>
            </div>
            <div class="log-reason">📝 Lý do: ${reason || '<span style="color:#6b7280">—</span>'}</div>
            <div class="log-mini">🌐 IP: ${ip || '—'}</div>
            <div class="log-actions">
              <button class="mini-btn" onclick="adminViewAudits(${Number(x.record_id||0)})">Xem log record</button>
            </div>
          </div>
        `;
    }).join('');
}

function loadAllAudits(){
    allLogList.innerHTML = `<div class="notice">Đang tải log...</div>`;
    fetch('patients.php?action=get_all_audits', { method:'POST' })
      .then(r => r.json())
      .then(data => {
          if (!data.success) {
              allLogList.innerHTML = `<div class="notice">${escapeHtml(data.message || 'Không tải được log')}</div>`;
              return;
          }
          ALL_AUDITS = data.rows || [];
          applyAllLogFilter();
      })
      .catch(() => allLogList.innerHTML = `<div class="notice">Lỗi kết nối server!</div>`);
}

function applyAllLogFilter(){
    const q = (allLogFilter.value || '').toLowerCase().trim();
    if (!q) return renderAllAudits(ALL_AUDITS);
    const filtered = ALL_AUDITS.filter(x => auditToText(x).includes(q));
    renderAllAudits(filtered);
}

allLogFilter.addEventListener('input', applyAllLogFilter);
</script>
</body>
</html>