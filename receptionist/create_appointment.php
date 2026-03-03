<?php
/**
 * create_appointment.php (Lễ tân tạo/sửa lịch OFFLINE)
 */

require_once __DIR__ . '/../auth.php';
require_role('receptionist');

require_once __DIR__ . '/../db.php';

$receptionist_id = (int)($_SESSION['receptionist_id'] ?? 0);
if ($receptionist_id <= 0) {
  header('Location: ../login.php');
  exit;
}

$receptionist_name = $_SESSION['full_name'] ?? 'Lễ tân';
$today = date('Y-m-d');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ================= Helpers ================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function j($data, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function is_valid_date($d){ return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function is_valid_time_hm($t){
  if (!preg_match('/^\d{2}:\d{2}$/', (string)$t)) return false;
  [$hh,$mm] = array_map('intval', explode(':',$t));
  return ($hh>=0 && $hh<=23 && $mm>=0 && $mm<=59);
}

/**
 * Pure function – unit test 100%
 * Parse input: empty / phone / patient_id / text
 */
function classify_patient_search_input(string $q): array {
    $raw = trim($q);

    if ($raw === '') {
        return ['kind' => 'empty', 'compact' => ''];
    }

    $compact = preg_replace('/\s+/', '', $raw);

    // phone: +? 10-15 digits
    if (preg_match('/^\+?\d{10,15}$/', $compact)) {
        return ['kind' => 'phone', 'compact' => $compact];
    }

    // patient_id: chỉ digits dưới 10 số
    if (preg_match('/^\d{1,9}$/', $compact)) {
        return ['kind' => 'patient_id', 'compact' => $compact];
    }

    return ['kind' => 'text', 'compact' => $compact];
}

/** ====== CONFIG ====== */
const SERVICE_MIN = 20;
const STEP_MIN = 5;
const MAX_APPT_PER_DOCTOR_PER_DAY = 20;
const MIN_LEAD_MIN = 0;
const LAST_BOOKING_BEFORE_SHIFT_END_MIN = 10;

/** ====== TIME HELPERS ====== */
function dt_from_ymd_hm(string $dateYmd, string $timeHm): ?DateTime {
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateYmd.' '.$timeHm.':00');
  return $dt ?: null;
}
function add_minutes(DateTime $dt, int $min): DateTime {
  $c = clone $dt;
  $c->modify(($min>=0?'+':'').$min.' minutes');
  return $c;
}
function minutes_diff(DateTime $a, DateTime $b): int {
  return (int)round(($a->getTimestamp() - $b->getTimestamp()) / 60);
}
function round_up_to_step(DateTime $dt, int $stepMin = STEP_MIN): DateTime {
  $c = clone $dt;
  $total = ((int)$c->format('H'))*60 + ((int)$c->format('i'));
  $rounded = (int)(ceil($total / $stepMin) * $stepMin);
  $h = intdiv($rounded, 60);
  $mi = $rounded % 60;
  $c->setTime($h % 24, $mi, 0);
  return $c;
}
function round_down_to_step(DateTime $dt, int $stepMin = STEP_MIN): DateTime {
  $c = clone $dt;
  $total = ((int)$c->format('H'))*60 + ((int)$c->format('i'));
  $rounded = (int)(floor($total / $stepMin) * $stepMin);
  $h = intdiv($rounded, 60);
  $mi = $rounded % 60;
  $c->setTime($h % 24, $mi, 0);
  return $c;
}

/** ====== WORKING HOURS ======
 *  Sáng: 08:00-12:00
 *  Chiều: 13:30-17:00
 */
function get_work_sessions(string $dateYmd): array {
  return [
    [new DateTime("$dateYmd 08:00:00"), new DateTime("$dateYmd 12:00:00")],
    [new DateTime("$dateYmd 13:30:00"), new DateTime("$dateYmd 17:00:00")],
  ];
}

/**
 * Check lịch start có nằm trong giờ làm không
 * + đặt cuối trước tan ca 10 phút
 * + không được vượt tan ca theo duration SERVICE_MIN
 */
function validate_working_hours(DateTime $start, int $serviceMin = SERVICE_MIN): array {
  $sessions = get_work_sessions($start->format('Y-m-d'));
  $end = add_minutes($start, $serviceMin);

  foreach ($sessions as [$s, $e]) {
    if ($start >= $s && $start < $e) {
      $latestByRule = add_minutes($e, -LAST_BOOKING_BEFORE_SHIFT_END_MIN);
      if ($start > $latestByRule) {
        return [false, "Giờ đặt lịch phải trước giờ tan ca ít nhất ".LAST_BOOKING_BEFORE_SHIFT_END_MIN." phút (ca này tan lúc ".$e->format('H:i').")"];
      }

      // duration không vượt tan ca
      if ($end > $e) {
        return [false, "Lịch khám (".SERVICE_MIN." phút) không được vượt quá giờ tan ca (".$e->format('H:i').")."];
      }

      return [true, "OK"];
    }
  }

  return [false, "Bệnh viện chỉ làm việc 08:00–12:00 và 13:30–17:00."];
}

/** ====== DB FETCH ====== */
function fetchDepartments(PDO $conn): array {
  $st = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function fetchDoctors(PDO $conn): array {
  $st = $conn->query("
    SELECT doctor_id, full_name, department_id
    FROM doctors
    WHERE is_active=1 AND is_deleted=0
    ORDER BY full_name ASC
  ");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function getDoctor(PDO $conn, int $doctorId): ?array {
  $st = $conn->prepare("
    SELECT doctor_id, full_name, department_id, is_active, is_deleted
    FROM doctors
    WHERE doctor_id=?
    LIMIT 1
  ");
  $st->execute([$doctorId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function getAppt(PDO $conn, int $id): ?array {
  $st = $conn->prepare("SELECT * FROM appointments WHERE appointment_id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function getPatient(PDO $conn, int $id): ?array {
  $st = $conn->prepare("
    SELECT patient_id, full_name, phone, date_of_birth, gender, address
    FROM patients
    WHERE patient_id=?
  ");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetchDoctorDayAppointments(PDO $conn, int $doctorId, string $dateYmd, ?int $excludeApptId=null): array {
  if ($excludeApptId) {
    $st = $conn->prepare("
      SELECT appointment_id, appointment_date
      FROM appointments
      WHERE doctor_id=?
        AND DATE(appointment_date)=?
        AND status <> 'cancelled'
        AND appointment_id <> ?
      ORDER BY appointment_date ASC
    ");
    $st->execute([$doctorId, $dateYmd, $excludeApptId]);
  } else {
    $st = $conn->prepare("
      SELECT appointment_id, appointment_date
      FROM appointments
      WHERE doctor_id=?
        AND DATE(appointment_date)=?
        AND status <> 'cancelled'
      ORDER BY appointment_date ASC
    ");
    $st->execute([$doctorId, $dateYmd]);
  }
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function intervals_from_appts(array $appts, int $serviceMin = SERVICE_MIN): array {
  $blocks = [];
  foreach ($appts as $a) {
    $s = new DateTime($a['appointment_date']);
    $e = add_minutes($s, $serviceMin);
    $blocks[] = [$s, $e];
  }
  return $blocks;
}
function is_overlapping(DateTime $start, DateTime $end, array $blocks): bool {
  foreach ($blocks as [$bs, $be]) {
    // allow touching edges
    if ($start < $be && $end > $bs) return true;
  }
  return false;
}

function find_prev_next(PDO $conn, int $doctorId, DateTime $start, ?int $excludeApptId=null): array {
  $dt = $start->format('Y-m-d H:i:s');

  if ($excludeApptId) {
    $stPrev = $conn->prepare("
      SELECT appointment_date
      FROM appointments
      WHERE doctor_id=?
        AND status <> 'cancelled'
        AND appointment_id <> ?
        AND appointment_date < ?
      ORDER BY appointment_date DESC
      LIMIT 1
    ");
    $stPrev->execute([$doctorId, $excludeApptId, $dt]);
  } else {
    $stPrev = $conn->prepare("
      SELECT appointment_date
      FROM appointments
      WHERE doctor_id=?
        AND status <> 'cancelled'
        AND appointment_date < ?
      ORDER BY appointment_date DESC
      LIMIT 1
    ");
    $stPrev->execute([$doctorId, $dt]);
  }
  $prevStr = $stPrev->fetchColumn();

  if ($excludeApptId) {
    $stNext = $conn->prepare("
      SELECT appointment_date
      FROM appointments
      WHERE doctor_id=?
        AND status <> 'cancelled'
        AND appointment_id <> ?
        AND appointment_date > ?
      ORDER BY appointment_date ASC
      LIMIT 1
    ");
    $stNext->execute([$doctorId, $excludeApptId, $dt]);
  } else {
    $stNext = $conn->prepare("
      SELECT appointment_date
      FROM appointments
      WHERE doctor_id=?
        AND status <> 'cancelled'
        AND appointment_date > ?
      ORDER BY appointment_date ASC
      LIMIT 1
    ");
    $stNext->execute([$doctorId, $dt]);
  }
  $nextStr = $stNext->fetchColumn();

  return [$prevStr ?: null, $nextStr ?: null];
}

function suggest_nearest_slot(PDO $conn, int $doctorId, string $dateYmd, DateTime $wanted, ?int $excludeApptId=null): ?DateTime {
  $appts = fetchDoctorDayAppointments($conn, $doctorId, $dateYmd, $excludeApptId);
  $blocks = intervals_from_appts($appts, SERVICE_MIN);

  $sessions = get_work_sessions($dateYmd);
  $now = new DateTime('now');

  $best = null;
  $bestAbs = null;

  foreach ($sessions as [$s, $e]) {
    $cursor = round_up_to_step(clone $s, STEP_MIN);

    while (add_minutes($cursor, SERVICE_MIN) <= $e) {
      // past/lead time
      if (MIN_LEAD_MIN > 0) {
        if ($cursor < add_minutes($now, MIN_LEAD_MIN)) { $cursor = add_minutes($cursor, STEP_MIN); continue; }
      } else {
        if ($cursor < $now) { $cursor = add_minutes($cursor, STEP_MIN); continue; }
      }

      // last booking rule
      $latestByRule = add_minutes($e, -LAST_BOOKING_BEFORE_SHIFT_END_MIN);
      if ($cursor > $latestByRule) break;

      $end = add_minutes($cursor, SERVICE_MIN);
      if (!is_overlapping($cursor, $end, $blocks)) {
        $abs = abs(minutes_diff($cursor, $wanted));
        if ($bestAbs === null || $abs < $bestAbs || ($abs === $bestAbs && $cursor < $best)) {
          $best = clone $cursor;
          $bestAbs = $abs;
        }
      }

      $cursor = add_minutes($cursor, STEP_MIN);
    }
  }

  return $best;
}

function validateRulesOffline(PDO $conn, int $doctorId, int $departmentId, string $dateYmd, string $timeHm, ?int $editingId=null): array {
  if ($doctorId <= 0) return [false, 'Vui lòng chọn bác sĩ'];
  if ($departmentId <= 0) return [false, 'Vui lòng chọn khoa'];

  $doc = getDoctor($conn, $doctorId);
  if (!$doc || (int)$doc['is_active'] !== 1 || (int)$doc['is_deleted'] !== 0) {
    return [false, 'Bác sĩ không hợp lệ hoặc đã bị vô hiệu hóa'];
  }
  if ((int)$doc['department_id'] !== (int)$departmentId) {
    return [false, 'Bác sĩ không thuộc khoa đã chọn'];
  }

  $apptStart = dt_from_ymd_hm($dateYmd, $timeHm);
  if (!$apptStart) return [false, 'Ngày/giờ không hợp lệ'];

  $now = new DateTime('now');
  if (MIN_LEAD_MIN > 0) {
    if ($apptStart < add_minutes($now, MIN_LEAD_MIN)) return [false, 'Giờ khám phải sau hiện tại tối thiểu '.MIN_LEAD_MIN.' phút'];
  } else {
    if ($apptStart < $now) return [false, 'Không thể đăng ký lịch ở quá khứ'];
  }

  [$okWork, $msgWork] = validate_working_hours($apptStart, SERVICE_MIN);
  if (!$okWork) {
    $suggest = suggest_nearest_slot($conn, $doctorId, $dateYmd, $apptStart, $editingId);
    if ($suggest) $msgWork .= " Gợi ý gần nhất: ".$suggest->format('H:i');
    return [false, $msgWork];
  }

  if ($editingId) {
    $st = $conn->prepare("
      SELECT COUNT(*) FROM appointments
      WHERE doctor_id=?
        AND DATE(appointment_date)=?
        AND status <> 'cancelled'
        AND appointment_id <> ?
    ");
    $st->execute([$doctorId, $dateYmd, $editingId]);
  } else {
    $st = $conn->prepare("
      SELECT COUNT(*) FROM appointments
      WHERE doctor_id=?
        AND DATE(appointment_date)=?
        AND status <> 'cancelled'
    ");
    $st->execute([$doctorId, $dateYmd]);
  }
  if ((int)$st->fetchColumn() >= MAX_APPT_PER_DOCTOR_PER_DAY) {
    return [false, 'Bác sĩ đã đủ '.MAX_APPT_PER_DOCTOR_PER_DAY.' lịch trong ngày này'];
  }

  [$prevStr, $nextStr] = find_prev_next($conn, $doctorId, $apptStart, $editingId);

  $appts = fetchDoctorDayAppointments($conn, $doctorId, $dateYmd, $editingId);
  $blocks = intervals_from_appts($appts, SERVICE_MIN);

  $newEnd = add_minutes($apptStart, SERVICE_MIN);

  if (is_overlapping($apptStart, $newEnd, $blocks)) {
    $lines = [];
    $lines[] = "Giờ này bị trùng/chồng lịch của bác sĩ.";

    $cand = null;

    if ($prevStr) {
      $prevStart = new DateTime($prevStr);
      $prevEnd   = add_minutes($prevStart, SERVICE_MIN);
      if ($apptStart < $prevEnd) {
        $lines[] = "Lịch trước: " . substr($prevStr, 0, 16);
        $cand = round_up_to_step($prevEnd, STEP_MIN);
      }
    }

    if ($nextStr) {
      $nextStart = new DateTime($nextStr);
      if ($newEnd > $nextStart) {
        $lines[] = "Lịch sau: " . substr($nextStr, 0, 16);
        if ($cand === null) {
          $cand = round_down_to_step(add_minutes($nextStart, -SERVICE_MIN), STEP_MIN);
        }
      }
    }

    $suggest = null;

    if ($cand !== null) {
      [$okWork2, ] = validate_working_hours($cand, SERVICE_MIN);
      $candEnd = add_minutes($cand, SERVICE_MIN);
      if ($okWork2 && !is_overlapping($cand, $candEnd, $blocks)) {
        $suggest = $cand;
      }
    }

    if ($suggest === null) {
      $suggest = suggest_nearest_slot($conn, $doctorId, $dateYmd, $apptStart, $editingId);
    }

    if ($suggest) $lines[] = "Gợi ý giờ gần nhất: " . $suggest->format('H:i');
    else $lines[] = "Không còn khung giờ phù hợp trong ngày.";

    return [false, implode("\n", $lines)];
  }

  return [true, 'OK'];
}

/* ================= AJAX: search patient ================= */
function search_patient(PDO $conn, string $q): array {
  $info = classify_patient_search_input($q);

  if ($info['kind'] === 'empty') return [];

  // gõ số -> tìm theo patient_id trước (tìm nhanh, chính xác)
  if ($info['kind'] === 'patient_id') {
    $st = $conn->prepare("
      SELECT patient_id, full_name, phone, date_of_birth, gender, address
      FROM patients
      WHERE patient_id = ?
      LIMIT 1
    ");
    $st->execute([(int)$info['compact']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? [$row] : [];
  }

  // IMPORTANT: like theo compact để xử lý phone có khoảng trắng
  $qLike = '%'.$info['compact'].'%';

  if ($info['kind'] === 'phone') {
    $st = $conn->prepare("
      SELECT patient_id, full_name, phone, date_of_birth, gender, address
      FROM patients
      WHERE REPLACE(phone,' ','') LIKE REPLACE(?, ' ','')
         OR REPLACE(phone,' ','') LIKE REPLACE(?, ' ','')
         OR full_name LIKE ?
      ORDER BY full_name ASC, patient_id DESC
      LIMIT 20
    ");
    $st->execute([$info['compact'], $qLike, '%'.trim($q).'%']);
  } else {
    // text: tìm theo tên/phone dạng thường
    $st = $conn->prepare("
      SELECT patient_id, full_name, phone, date_of_birth, gender, address
      FROM patients
      WHERE full_name LIKE ? OR phone LIKE ?
      ORDER BY full_name ASC, patient_id DESC
      LIMIT 20
    ");
    $st->execute(['%'.trim($q).'%', '%'.trim($q).'%']);
  }

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ================= AJAX HANDLER (MUST BE BEFORE HTML) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) j(['ok'=>false,'message'=>'CSRF sai'],403);
  $action = (string)$_POST['action'];

  try {
    if ($action === 'search_patient') {
      $q = trim((string)($_POST['q'] ?? ''));
      $items = search_patient($conn, $q);
      j(['ok'=>true,'items'=>$items]);
    }

    j(['ok'=>false,'message'=>'Action không hợp lệ'],400);
  } catch (Exception $e) {
    j(['ok'=>false,'message'=>'Lỗi: '.$e->getMessage()],500);
  }
}

/* ================= Handle Save ================= */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0 ? getAppt($conn, $editId) : null;
if ($editId > 0 && !$editing) $editId = 0;

$errors = [];
$success = '';

$patient_id = $editing['patient_id'] ?? 0;
$doctor_id  = $editing['doctor_id'] ?? '';
$department_id = $editing['department_id'] ?? '';
$symptoms = $editing['symptoms'] ?? '';
$status = $editing['status'] ?? 'pending';

$existingDT = $editing['appointment_date'] ?? '';
$defaultDate = $existingDT ? substr($existingDT,0,10) : $today;
$defaultTime = $existingDT ? substr($existingDT,11,5) : '';

$selectedPatient = null;
if ((int)$patient_id > 0) $selectedPatient = getPatient($conn, (int)$patient_id);

$new_full_name = '';
$new_phone = '';
$new_dob = '';
$new_gender = '';
$new_address = '';
$showCreateBox = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) $errors[] = 'CSRF sai';

  $patient_id = (int)($_POST['patient_id'] ?? 0);

  $new_full_name = trim((string)($_POST['new_full_name'] ?? ''));
  $new_phone     = trim((string)($_POST['new_phone'] ?? ''));
  $new_dob       = trim((string)($_POST['new_dob'] ?? ''));
  $new_gender    = trim((string)($_POST['new_gender'] ?? ''));
  $new_address   = trim((string)($_POST['new_address'] ?? ''));

  $showCreateBox = (string)($_POST['show_create_box'] ?? '0') === '1';

  $doctor_id = (int)($_POST['doctor_id'] ?? 0);
  $department_id = (int)($_POST['department_id'] ?? 0);

  $symptoms = trim((string)($_POST['symptoms'] ?? ''));
  $status = (string)($_POST['status'] ?? 'pending');

  $dateYmd = (string)($_POST['appointment_date'] ?? $today);
  $timeHm = trim((string)($_POST['appointment_time'] ?? ''));

  if ($patient_id <= 0) {
    if ($new_full_name === '') $errors[] = 'Vui lòng nhập Họ tên bệnh nhân';
    if ($new_phone === '') $errors[] = 'Vui lòng nhập SĐT bệnh nhân';
    if ($new_dob !== '' && !is_valid_date($new_dob)) $errors[] = 'Ngày sinh không hợp lệ';
    $showCreateBox = true;
  }

  if ($doctor_id <= 0) $errors[] = 'Vui lòng chọn bác sĩ';
  if ($department_id <= 0) $errors[] = 'Vui lòng chọn khoa';
  if (!is_valid_date($dateYmd)) $errors[] = 'Ngày khám không hợp lệ';

  if ($timeHm === '') {
    $timeHm = round_up_to_step(new DateTime('now'), STEP_MIN)->format('H:i');
  }
  if (!is_valid_time_hm($timeHm)) $errors[] = 'Giờ khám không hợp lệ';

  if (!$errors && $patient_id <= 0) {
    try {
      $st = $conn->prepare("
        INSERT INTO patients (full_name, phone, date_of_birth, gender, address)
        VALUES (?, ?, ?, ?, ?)
      ");
      $st->execute([
        $new_full_name,
        $new_phone,
        ($new_dob !== '' ? $new_dob : null),
        ($new_gender !== '' ? $new_gender : null),
        ($new_address !== '' ? $new_address : null),
      ]);
      $patient_id = (int)$conn->lastInsertId();
      $selectedPatient = getPatient($conn, $patient_id);
    } catch (Exception $e) {
      $errors[] = 'Lỗi tạo bệnh nhân: ' . $e->getMessage();
    }
  }

  if (!$errors) {
    [$ok, $msg] = validateRulesOffline($conn, $doctor_id, $department_id, $dateYmd, $timeHm, $editId ?: null);
    if (!$ok) $errors[] = $msg;
  }

  if (!$errors) {
    $dt = $dateYmd . ' ' . $timeHm . ':00';
    try {
      if ($editId > 0) {
        $st = $conn->prepare("
          UPDATE appointments
          SET patient_id=?, doctor_id=?, department_id=?, appointment_date=?, symptoms=?, status=?
          WHERE appointment_id=?
        ");
        $st->execute([$patient_id, $doctor_id, $department_id, $dt, $symptoms, $status, $editId]);
        $success = "Đã cập nhật lịch hẹn #{$editId}";
      } else {
        $st = $conn->prepare("
          INSERT INTO appointments (patient_id, doctor_id, department_id, appointment_date, symptoms, status)
          VALUES (?,?,?,?,?,?)
        ");
        $st->execute([$patient_id, $doctor_id, $department_id, $dt, $symptoms, 'pending']);
        $newId = (int)$conn->lastInsertId();
        $success = "Đã tạo lịch hẹn #{$newId}";
      }
      $showCreateBox = false;
    } catch (Exception $e) {
      $errors[] = 'Lỗi lưu lịch hẹn: ' . $e->getMessage();
    }
  }

  $defaultDate = $dateYmd;
  $defaultTime = $timeHm;
  if ($patient_id > 0 && !$selectedPatient) $selectedPatient = getPatient($conn, $patient_id);
}

/* ================= Page data ================= */
$departments = fetchDepartments($conn);
$doctors = fetchDoctors($conn);

$now = new DateTime('now');
$minDate = $now->format('Y-m-d');
$minTimeToday = $now->format('H:i');

$resetUrl = $editId > 0
  ? ('create_appointment.php?edit=' . $editId)
  : 'create_appointment.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo $editId>0 ? "Sửa lịch khám" : "Tạo lịch khám"; ?></title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    *, *::before, *::after{ box-sizing:border-box; }
    html, body{ width:100%; overflow-x:hidden; }
    :root{ --pri:#1976d2; --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280; --line:#e5e7eb; }
    body{ background:var(--bg); margin:0; }
    .wrap{ max-width:1100px; margin:0 auto; padding:18px; }
    .topbar{
      background:#fff; border:1px solid var(--line); border-radius:14px;
      padding:12px 14px; font-size:13px; color:#4b5563;
      display:flex; justify-content:space-between; align-items:center;
    }
    .hero{ margin-top:12px; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); color:#fff; border-radius:16px; padding:18px; }
    .hero h1{ margin:0; font-size:22px; }
    .hero .sub{ margin-top:6px; opacity:.9; font-size:13px; font-weight:800; }
    .nav{ margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .nav a{
      text-decoration:none; background:#fff; border:1px solid var(--line);
      padding:10px 14px; border-radius:999px; font-weight:900; color:#111827;
      display:inline-flex; align-items:center; gap:8px;
    }
    .nav a.active{ background:var(--pri); color:#fff; border-color:transparent; }
    .card{ background:var(--card); border:1px solid var(--line); border-radius:16px; padding:16px; box-shadow:0 6px 20px rgba(17,24,39,.06); margin-top:14px; }
    .grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width: 860px){ .grid{ grid-template-columns:1fr; } }
    label{ display:block; font-size:13px; font-weight:800; margin:0 0 6px; color:#374151; }
    select, input, textarea{ width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px; outline:none; background:#fff; }
    textarea{ min-height:90px; resize:vertical; }
    .btn{ border:none; border-radius:12px; padding:10px 14px; font-weight:900; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
    .btn-pri{ background:var(--pri); color:#fff; }
    .btn-gray{ background:#f3f4f6; color:#111827; border:1px solid var(--line); }
    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; margin-top:12px; }
    .alert{ padding:12px 14px; border-radius:12px; font-weight:800; margin-top:10px; }
    .ok{ background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .bad{ background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; }
    .pill{ padding:8px 10px; border:1px solid var(--line); border-radius:999px; background:#fff; color:var(--muted); font-size:12px; font-weight:900; display:inline-flex; align-items:center; gap:8px;}
    .results{ margin-top:10px; border:1px solid var(--line); border-radius:12px; overflow:hidden; display:none; }
    .res-item{ display:flex; justify-content:space-between; gap:10px; padding:10px 12px; border-top:1px solid var(--line); background:#fff; }
    .res-item:first-child{ border-top:none; }
    .res-main{ font-weight:900; }
    .res-sub{ font-size:12px; color:var(--muted); font-weight:800; margin-top:2px; }
    .mini{ padding:8px 10px; border-radius:12px; font-weight:900; font-size:12px; border:1px solid var(--line); cursor:pointer; display:inline-flex; align-items:center; gap:6px; background:#fff; }
    .mini.blue{ background:#eff6ff; color:#1d4ed8; }
    .mini.green{ background:#ecfdf5; color:#166534; }
    .mini.gray{ background:#f3f4f6; color:#111827; }
    .patient-create{ margin-top:12px; border:1px dashed var(--line); border-radius:14px; padding:12px; background:#fafafa; display:none; }
    .patient-create .title{ font-weight:1000; margin-bottom:10px; display:flex; align-items:center; gap:8px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div><i class="fas fa-user"></i> <?php echo h($receptionist_name); ?></div>
    <div style="display:flex; gap:12px; align-items:center;">
      <span style="opacity:.7">Hôm nay:</span>
      <b><?php echo date('d/m/Y'); ?></b>
      <a href="../logout.php"
         onclick="return confirm('Bạn có chắc muốn đăng xuất?')"
         style="
           background:linear-gradient(135deg,#5b6ee1,#6a4bbf);
           color:#fff;
           padding:8px 14px;
           border-radius:999px;
           text-decoration:none;
           font-weight:900;
           font-size:13px;
           box-shadow:0 4px 12px rgba(90,100,200,.25);
           transition:all .2s ease;
         "
         onmouseover="this.style.opacity='.85'"
         onmouseout="this.style.opacity='1'">
         <i class="fas fa-right-from-bracket"></i> Đăng xuất
      </a>
    </div>
  </div>

  <div class="hero">
    <h1><i class="fas fa-plus"></i> <?php echo $editId>0 ? "Sửa lịch khám " : "Tạo lịch khám "; ?></h1>
    <div class="sub">
      Giờ làm việc: 08:00–12:00 &nbsp;|&nbsp; 13:30–17:00 • Đặt cuối trước tan ca <?php echo LAST_BOOKING_BEFORE_SHIFT_END_MIN; ?> phút
    </div>
  </div>

  <div class="nav">
    <a class="active" href="create_appointment.php"><i class="fas fa-plus"></i> Tạo lịch khám</a>
    <a href="appointments_list.php"><i class="fas fa-list"></i> DS lịch khám</a>
    <a href="patients_list.php"><i class="fas fa-users"></i> DS bệnh nhân</a>
  </div>

  <div class="card">
    <?php if ($success): ?>
      <div class="alert ok"><i class="fas fa-check"></i> <?php echo h($success); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert bad">
        <div><i class="fas fa-triangle-exclamation"></i> Có lỗi:</div>
        <ul style="margin:8px 0 0 18px;">
          <?php foreach($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"/>
      <input type="hidden" name="patient_id" id="patient_id" value="<?php echo (int)$patient_id; ?>">
      <input type="hidden" name="show_create_box" id="show_create_box" value="<?php echo $showCreateBox ? '1' : '0'; ?>">

      <div style="margin-bottom:12px;">
        <label>Tìm bệnh nhân (SĐT hoặc Tên)</label>
        <input id="patientSearch" type="text" placeholder="Nhập SĐT hoặc tên...">

        <div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <button type="button" class="mini green" id="btnShowCreate"><i class="fas fa-user-plus"></i> Tạo mới bệnh nhân</button>

          <button type="button" class="mini gray" id="btnClearPick" style="<?php echo $selectedPatient ? '' : 'display:none;'; ?>">
            <i class="fas fa-xmark"></i> Bỏ chọn
          </button>

          <div class="pill" id="pickedPill" style="<?php echo $selectedPatient ? '' : 'display:none;'; ?>">
            Đã chọn:
            <b id="pickedText">
              <?php if ($selectedPatient): ?>
                <?php echo '#'.(int)$selectedPatient['patient_id'].' - '.h($selectedPatient['full_name']).' - '.h($selectedPatient['phone']); ?>
              <?php endif; ?>
            </b>
          </div>
        </div>

        <div class="results" id="searchResults"></div>

        <div class="patient-create" id="createBox" style="<?php echo $showCreateBox ? 'display:block;' : 'display:none;'; ?>">
          <div class="title"><i class="fas fa-user-plus"></i> Thêm bệnh nhân mới (tự lưu khi bấm Lưu lịch hẹn)</div>

          <div class="grid">
            <div>
              <label>Họ tên</label>
              <input name="new_full_name" id="new_full_name" type="text" value="<?php echo h($new_full_name); ?>" placeholder="Nhập họ tên">
            </div>
            <div>
              <label>SĐT</label>
              <input name="new_phone" id="new_phone" type="text" value="<?php echo h($new_phone); ?>" placeholder="Nhập số điện thoại">
            </div>
            <div>
              <label>Ngày sinh</label>
              <input name="new_dob" id="new_dob" type="date" value="<?php echo h($new_dob); ?>">
            </div>
            <div>
              <label>Giới tính</label>
              <select name="new_gender" id="new_gender">
                <option value="">-- Chọn --</option>
                <option value="male"   <?php echo $new_gender==='male'?'selected':''; ?>>Nam</option>
                <option value="female" <?php echo $new_gender==='female'?'selected':''; ?>>Nữ</option>
                <option value="other"  <?php echo $new_gender==='other'?'selected':''; ?>>Khác</option>
              </select>
            </div>
            <div style="grid-column:1/-1;">
              <label>Địa chỉ</label>
              <input name="new_address" id="new_address" type="text" value="<?php echo h($new_address); ?>" placeholder="Nhập địa chỉ">
            </div>
          </div>

          <div style="margin-top:10px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
            <button type="button" class="mini gray" id="btnHideCreate"><i class="fas fa-xmark"></i> Ẩn</button>
          </div>
        </div>
      </div>

      <div class="grid">
        <div>
          <label>Khoa</label>
          <select name="department_id" id="departmentSelect" required>
            <option value="">-- Chọn khoa --</option>
            <?php foreach($departments as $d): ?>
              <option value="<?php echo (int)$d['department_id']; ?>" <?php echo ((int)$department_id === (int)$d['department_id'])?'selected':''; ?>>
                <?php echo h($d['department_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Bác sĩ</label>
          <select name="doctor_id" id="doctorSelect" required>
            <option value="">-- Chọn bác sĩ --</option>
            <?php foreach($doctors as $d): ?>
              <option value="<?php echo (int)$d['doctor_id']; ?>"
                      data-dept="<?php echo (int)$d['department_id']; ?>"
                      <?php echo ((int)$doctor_id === (int)$d['doctor_id'])?'selected':''; ?>>
                <?php echo h($d['full_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Ngày khám</label>
          <input id="apptDate" type="date" name="appointment_date"
                 value="<?php echo h($defaultDate); ?>"
                 min="<?php echo h($minDate); ?>"
                 required>
        </div>

        <div>
          <label>Giờ khám (giờ bắt đầu)</label>
          <input id="apptTime" type="time" name="appointment_time"
                 value="<?php echo h($defaultTime); ?>">
        </div>

        <div style="grid-column:1/-1;">
          <label>Triệu chứng / Ghi chú</label>
          <textarea name="symptoms" placeholder="Nhập triệu chứng..."><?php echo h($symptoms); ?></textarea>
        </div>
      </div>

      <div class="row">
        <button class="btn btn-gray" type="button" id="btnReset">
          <i class="fas fa-rotate-left"></i> Reset
        </button>

        <button class="btn btn-pri" type="submit" name="save" value="1">
          <i class="fas fa-floppy-disk"></i> Lưu
        </button>
      </div>

    </form>
  </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const RESET_URL = <?php echo json_encode($resetUrl); ?>;
const EDIT_ID = <?php echo json_encode((int)$editId); ?>;

const $ = (id)=>document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));

/* ===== AJAX ===== */
async function api(action, payload={}){
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('action', action);
  Object.entries(payload).forEach(([k,v])=>fd.append(k,v));
  const res = await fetch(location.href, { method:'POST', body: fd });
  return res.json();
}

/* ===== Patient pick ===== */
function showPicked(p){
  $('patient_id').value = p.patient_id;
  $('pickedText').textContent = '#'+p.patient_id+' - '+(p.full_name||'')+' - '+(p.phone||'');
  $('pickedPill').style.display = 'inline-flex';
  $('btnClearPick').style.display = 'inline-flex';

  $('createBox').style.display = 'none';
  $('show_create_box').value = '0';
}
function clearPicked(){
  $('patient_id').value = '0';
  $('pickedPill').style.display = 'none';
  $('btnClearPick').style.display = 'none';
  $('pickedText').textContent = '';
}
function showCreateBox(prefill=''){
  $('createBox').style.display = 'block';
  $('show_create_box').value = '1';

  const q = (prefill || $('patientSearch').value || '').trim();
  if(q){
    const phoneLike = /^[+\d][\d\s]{7,20}$/.test(q);
    if(phoneLike && !$('new_phone').value) $('new_phone').value = q.replace(/\s+/g,'');
    if(!phoneLike && !$('new_full_name').value) $('new_full_name').value = q;
  }
  setTimeout(()=> $('new_full_name').focus(), 0);
}
function hideCreateBox(){
  $('createBox').style.display = 'none';
  $('show_create_box').value = '0';
}

$('btnShowCreate').addEventListener('click', ()=> showCreateBox());
$('btnHideCreate').addEventListener('click', hideCreateBox);
$('btnClearPick').addEventListener('click', ()=> clearPicked());

/* ===== Search patient ===== */
let searchTimer = null;
async function runSearch(){
  const q = $('patientSearch').value.trim();
  const box = $('searchResults');

  if(!q){
    box.style.display = 'none';
    box.innerHTML = '';
    return;
  }

  const data = await api('search_patient', { q });
  if(!data.ok) return;

  const items = data.items || [];
  if(items.length === 0){
    box.innerHTML = `
      <div class="res-item">
        <div>
          <div class="res-main">Không tìm thấy</div>
          <div class="res-sub">Bạn có thể bấm “Tạo mới bệnh nhân”.</div>
        </div>
        <div>
          <button type="button" class="mini green" id="quickCreateBtn">
            <i class="fas fa-user-plus"></i> Tạo mới
          </button>
        </div>
      </div>
    `;
    box.style.display = 'block';
    setTimeout(()=>{
      const b = $('quickCreateBtn');
      if(b) b.addEventListener('click', ()=> showCreateBox(q));
    }, 0);
    return;
  }

  box.innerHTML = items.map(p=>{
    const safe = JSON.stringify(p).replace(/</g,'\\u003c');
    return `
      <div class="res-item">
        <div>
          <div class="res-main">${esc('#'+p.patient_id+' - '+(p.full_name||''))}</div>
          <div class="res-sub">
            SĐT: ${esc(p.phone||'')} • NS: ${esc(p.date_of_birth||'-')} • GT: ${esc(p.gender||'-')}
          </div>
        </div>
        <div>
          <button type="button" class="mini blue" onclick='window.__pick(${safe})'>
            <i class="fas fa-check"></i> Chọn
          </button>
        </div>
      </div>
    `;
  }).join('');
  box.style.display = 'block';
}

window.__pick = (p)=>{
  showPicked(p);
  $('searchResults').style.display = 'none';
  $('patientSearch').value = '';
};

$('patientSearch').addEventListener('input', ()=>{
  clearTimeout(searchTimer);
  searchTimer = setTimeout(runSearch, 220);
});

/* ===== Lọc bác sĩ theo khoa ===== */
function filterDoctors(){
  const dept = $('departmentSelect').value;
  const doctorSel = $('doctorSelect');
  const current = doctorSel.value;

  let hasCurrent = false;

  [...doctorSel.options].forEach((opt, idx)=>{
    if(idx === 0) return;
    const d = opt.getAttribute('data-dept');
    const show = dept && d === dept;
    opt.hidden = !show;
    if(show && opt.value === current) hasCurrent = true;
  });

  if(dept && !hasCurrent) doctorSel.value = '';
}
$('departmentSelect').addEventListener('change', filterDoctors);
filterDoctors();

/* ===== Min date/time UI (server vẫn check) ===== */
(function(){
  const dateEl = $('apptDate');
  const timeEl = $('apptTime');
  const minDate = <?php echo json_encode($minDate); ?>;
  const minTimeToday = <?php echo json_encode($minTimeToday); ?>;

  function updateMinTime(){
    if(dateEl.value === minDate) timeEl.min = minTimeToday;
    else timeEl.min = "00:00";
  }
  dateEl.addEventListener('change', updateMinTime);
  updateMinTime();
})();

/* ===== Reset button ===== */
$('btnReset').addEventListener('click', ()=>{
  window.location.href = RESET_URL;
});
</script>
</body>
</html>