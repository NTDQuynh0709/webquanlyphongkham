<?php
/**
 * booking.php (ONLINE - Bệnh nhân đặt lịch)
 * UI/UX theo style xanh->tím giống index.php
 *
 * LOGIC ĐÃ LÀM GIỐNG create_appointment.php:
 * 1) SLOT = 20 phút, cho phép CHẠM MÉP ( [start, start+20') không overlap )
 * 2) Thông báo lịch trước / lịch sau nếu bị trùng + gợi ý giờ gần nhất nếu có thể
 * 3) Nếu không thể chèn giữa 2 lịch => gợi ý giờ gần nhất (quét theo 5 phút)
 * 4) Chọn khoa => lọc bác sĩ theo khoa (doctors.department_id) (AJAX)
 * 5) Giờ làm việc: 08:00–12:00 và 13:30–17:00
 * 6) Lần đặt lịch cuối: phải trước 10 phút trước khi tan ca (<= 11:50, <= 16:50)
 *    Đồng thời đảm bảo lịch không vượt quá giờ tan ca theo duration 20' (thực tế <= 11:40, <= 16:40)
 * 7) Không thể đặt quá khứ
 * 8) Check bác sĩ thuộc khoa (chống hack POST)
 * 9) Giới hạn số lịch/ngày/bác sĩ (MAX_APPT_PER_DOCTOR_PER_DAY)
 *
 * DB dùng:
 *  - patients(full_name, phone, date_of_birth, gender, address, medical_history, is_deleted)
 *  - appointments(patient_id, department_id, doctor_id, appointment_date, symptoms, status)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

/* ================= DB CONFIG ================= */
$DB_HOST = "localhost";
$DB_NAME = "phongkhamsql";
$DB_USER = "root";
$DB_PASS = "";

/* Nút về trang chủ */
$HOME_URL = "trangchu.php";

try {
  $conn = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  die("Lỗi kết nối DB: " . $e->getMessage());
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function j($data, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================= CONFIG (giống create_appointment.php) ================= */
const SERVICE_MIN = 20; // mỗi lịch chiếm 20 phút
const STEP_MIN    = 5;  // quét gợi ý theo 5 phút
const MAX_APPT_PER_DOCTOR_PER_DAY = 20; // giống offline
const LAST_BOOKING_BEFORE_SHIFT_END_MIN = 10; // đặt cuối trước tan ca ít nhất 10 phút

/* ================= TIME HELPERS ================= */
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
function overlaps(DateTime $aStart, DateTime $aEnd, DateTime $bStart, DateTime $bEnd): bool {
  // [start, end) -> chạm mép OK
  return ($aStart < $bEnd) && ($bStart < $aEnd);
}

/* ================= WORKING HOURS =================
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
 * Check lịch start có nằm trong giờ làm không + không vượt tan ca theo SERVICE_MIN
 * + ràng buộc đặt cuối trước tan ca 10 phút
 */
function validate_working_hours(DateTime $start): array {
  $dateYmd = $start->format('Y-m-d');
  $sessions = get_work_sessions($dateYmd);
  $end = add_minutes($start, SERVICE_MIN);

  foreach ($sessions as [$s, $e]) {
    if ($start >= $s && $start < $e) {
      // đặt cuối trước tan ca 10 phút
      $latestByRule = add_minutes($e, -LAST_BOOKING_BEFORE_SHIFT_END_MIN);
      if ($start > $latestByRule) {
        return [false, "Giờ đặt lịch phải trước giờ tan ca ít nhất ".LAST_BOOKING_BEFORE_SHIFT_END_MIN." phút (ca này tan lúc ".$e->format('H:i').")."];
      }
      // không vượt tan ca theo duration
      if ($end > $e) {
        return [false, "Lịch khám ".SERVICE_MIN."' không được vượt giờ tan ca (ca này tan lúc ".$e->format('H:i').")."];
      }
      return [true, "OK"];
    }
  }
  return [false, "Bệnh viện chỉ làm việc 08:00–12:00 và 13:30–17:00."];
}

/* ================= DB FETCH ================= */
function fetchDepartments(PDO $conn): array {
  $st = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
  return $st->fetchAll() ?: [];
}
function getDoctor(PDO $conn, int $doctorId): ?array {
  $st = $conn->prepare("
    SELECT doctor_id, full_name, department_id, is_active, is_deleted
    FROM doctors
    WHERE doctor_id=?
    LIMIT 1
  ");
  $st->execute([$doctorId]);
  $row = $st->fetch();
  return $row ?: null;
}
function fetchDoctorsByDepartment(PDO $conn, int $departmentId): array {
  $st = $conn->prepare("
    SELECT doctor_id, full_name
    FROM doctors
    WHERE is_active=1 AND is_deleted=0 AND department_id=?
    ORDER BY full_name ASC
  ");
  $st->execute([$departmentId]);
  return $st->fetchAll() ?: [];
}

/** Lấy lịch trong ngày của bác sĩ (để gợi ý giờ) */
function fetchDoctorDayAppointments(PDO $conn, int $doctorId, string $dateYmd): array {
  $st = $conn->prepare("
    SELECT appointment_id, appointment_date
    FROM appointments
    WHERE doctor_id=?
      AND DATE(appointment_date)=?
      AND status <> 'cancelled'
    ORDER BY appointment_date ASC
  ");
  $st->execute([$doctorId, $dateYmd]);
  return $st->fetchAll() ?: [];
}
function intervals_from_appts(array $appts): array {
  $blocks = [];
  foreach ($appts as $a) {
    $s = new DateTime($a['appointment_date']);
    $e = add_minutes($s, SERVICE_MIN);
    $blocks[] = [$s, $e];
  }
  return $blocks;
}
function is_overlapping(DateTime $start, DateTime $end, array $blocks): bool {
  foreach ($blocks as [$bs, $be]) {
    if ($start < $be && $end > $bs) return true; // chạm mép OK
  }
  return false;
}
/** tìm lịch trước/sau gần nhất */
function find_prev_next(PDO $conn, int $doctorId, DateTime $start): array {
  $dt = $start->format('Y-m-d H:i:s');

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
  $prevStr = $stPrev->fetchColumn();

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
  $nextStr = $stNext->fetchColumn();

  return [$prevStr ?: null, $nextStr ?: null];
}

/** Gợi ý giờ gần nhất (quét theo STEP_MIN trong giờ làm, không overlap) */
function suggest_nearest_slot(PDO $conn, int $doctorId, string $dateYmd, DateTime $wanted): ?DateTime {
  $appts  = fetchDoctorDayAppointments($conn, $doctorId, $dateYmd);
  $blocks = intervals_from_appts($appts);
  $sessions = get_work_sessions($dateYmd);

  $now = new DateTime('now');
  $best = null;
  $bestAbs = null;

  foreach ($sessions as [$s, $e]) {
    $cursor = round_up_to_step(clone $s, STEP_MIN);

    while (add_minutes($cursor, SERVICE_MIN) <= $e) {
      // không quá khứ
      if ($cursor < $now) { $cursor = add_minutes($cursor, STEP_MIN); continue; }

      // rule đặt cuối trước tan ca 10 phút
      $latestByRule = add_minutes($e, -LAST_BOOKING_BEFORE_SHIFT_END_MIN);
      if ($cursor > $latestByRule) break;

      // check giờ làm + duration
      [$okWork, ] = validate_working_hours($cursor);
      if (!$okWork) { $cursor = add_minutes($cursor, STEP_MIN); continue; }

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

/**
 * Validate rules giống offline:
 * - không quá khứ
 * - max/ngày
 * - đúng giờ làm (+ rule 10')
 * - doctor thuộc khoa
 * - không overlap
 * - trả về thông báo có gợi ý
 */
function validateRulesOnline(PDO $conn, int $doctorId, int $departmentId, DateTime $start): array {
  if ($doctorId <= 0) return [false, 'Vui lòng chọn bác sĩ'];
  if ($departmentId <= 0) return [false, 'Vui lòng chọn khoa'];

  $doc = getDoctor($conn, $doctorId);
  if (!$doc || (int)$doc['is_active'] !== 1 || (int)$doc['is_deleted'] !== 0) {
    return [false, 'Bác sĩ không hợp lệ hoặc đã bị vô hiệu hóa'];
  }
  if ((int)$doc['department_id'] !== (int)$departmentId) {
    return [false, 'Bác sĩ không thuộc khoa đã chọn'];
  }

  $now = new DateTime('now');
  if ($start < $now) return [false, 'Không thể đăng ký lịch ở quá khứ'];

  // working hours
  [$okWork, $msgWork] = validate_working_hours($start);
  if (!$okWork) {
    $suggest = suggest_nearest_slot($conn, $doctorId, $start->format('Y-m-d'), $start);
    if ($suggest) $msgWork .= " Gợi ý gần nhất: ".$suggest->format('H:i d/m/Y').".";
    return [false, $msgWork];
  }

  // max per day
  $dateYmd = $start->format('Y-m-d');
  $st = $conn->prepare("
    SELECT COUNT(*)
    FROM appointments
    WHERE doctor_id=?
      AND DATE(appointment_date)=?
      AND status <> 'cancelled'
  ");
  $st->execute([$doctorId, $dateYmd]);
  if ((int)$st->fetchColumn() >= MAX_APPT_PER_DOCTOR_PER_DAY) {
    return [false, 'Bác sĩ đã đủ '.MAX_APPT_PER_DOCTOR_PER_DAY.' lịch trong ngày này'];
  }

  // overlap check
  $appts  = fetchDoctorDayAppointments($conn, $doctorId, $dateYmd);
  $blocks = intervals_from_appts($appts);
  $end = add_minutes($start, SERVICE_MIN);

  if (is_overlapping($start, $end, $blocks)) {
    $lines = [];
    $lines[] = "Giờ này bị trùng/chồng lịch của bác sĩ.";

    [$prevStr, $nextStr] = find_prev_next($conn, $doctorId, $start);

    $cand = null;
    if ($prevStr) {
      $prevStart = new DateTime($prevStr);
      $prevEnd   = add_minutes($prevStart, SERVICE_MIN);
      if ($start < $prevEnd) {
        $lines[] = "Lịch trước: ".(new DateTime($prevStr))->format('H:i d/m/Y');
        $cand = round_up_to_step($prevEnd, STEP_MIN);
      }
    }
    if ($nextStr) {
      $nextStart = new DateTime($nextStr);
      if ($end > $nextStart) {
        $lines[] = "Lịch sau: ".(new DateTime($nextStr))->format('H:i d/m/Y');
        if ($cand === null) {
          $cand = round_down_to_step(add_minutes($nextStart, -SERVICE_MIN), STEP_MIN);
        }
      }
    }

    $suggest = null;
    if ($cand !== null) {
      [$okWork2, ] = validate_working_hours($cand);
      $candEnd = add_minutes($cand, SERVICE_MIN);
      if ($okWork2 && $cand >= $now && !is_overlapping($cand, $candEnd, $blocks)) {
        $suggest = $cand;
      }
    }
    if ($suggest === null) {
      $suggest = suggest_nearest_slot($conn, $doctorId, $dateYmd, $start);
    }

    if ($suggest) $lines[] = "Gợi ý giờ gần nhất: ".$suggest->format('H:i d/m/Y');
    else $lines[] = "Không còn khung giờ phù hợp trong ngày.";

    return [false, implode("\n", $lines)];
  }

  return [true, 'OK'];
}

/* ================= AJAX ================= */
if (isset($_GET['action']) && $_GET['action'] === 'get_doctors') {
  $department_id = (int)($_GET['department_id'] ?? 0);
  if ($department_id <= 0) j(['ok'=>true, 'doctors'=>[]]);

  try {
    $doctors = fetchDoctorsByDepartment($conn, $department_id);
    j(['ok'=>true, 'doctors'=>$doctors]);
  } catch (Throwable $e) {
    j(['ok'=>false, 'message'=>$e->getMessage()], 500);
  }
}

if (isset($_GET['action']) && $_GET['action'] === 'check_slot') {
  $doctor_id = (int)($_GET['doctor_id'] ?? 0);
  $department_id = (int)($_GET['department_id'] ?? 0);
  $dtStr = trim((string)($_GET['appointment_date'] ?? ''));

  if ($doctor_id<=0 || $department_id<=0 || $dtStr==='') {
    j(['ok'=>false, 'message'=>'Thiếu doctor_id/department_id/appointment_date'], 400);
  }

  try {
    // input từ datetime-local: YYYY-mm-ddTHH:ii
    $t = new DateTime(str_replace('T',' ', $dtStr) . ':00');

    [$ok, $msg] = validateRulesOnline($conn, $doctor_id, $department_id, $t);
    if ($ok) j(['ok'=>true, 'message'=>'Khung giờ hợp lệ.']);

    // nếu lỗi có gợi ý, cố lấy suggest theo ngày
    $suggest = suggest_nearest_slot($conn, $doctor_id, $t->format('Y-m-d'), $t);
    j([
      'ok'=>false,
      'message'=>$msg,
      'suggest'=>$suggest ? $suggest->format('Y-m-d\TH:i') : null
    ]);

  } catch (Throwable $e) {
    j(['ok'=>false, 'message'=>'Lỗi thời gian: '.$e->getMessage()], 400);
  }
}

/* ================= PAGE DATA ================= */
$departments = [];
$error_message = null;

try {
  $departments = fetchDepartments($conn);
} catch (Throwable $e) {
  $error_message = "Lỗi lấy khoa: ".$e->getMessage();
}

/* ================= SUBMIT BOOKING ================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['booking_submit']) && !isset($_SESSION['form_submitted'])) {
  $full_name = trim($_POST['full_name'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $date_of_birth = trim($_POST['date_of_birth'] ?? '');
  $gender = trim($_POST['gender'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $medical_history = trim($_POST['medical_history'] ?? '');

  $department_id = (int)($_POST['department_id'] ?? 0);
  $doctor_id = (int)($_POST['doctor_id'] ?? 0);
  $appointment_date = trim($_POST['appointment_date'] ?? ''); // datetime-local
  $symptoms = trim($_POST['symptoms'] ?? '');

  try {
    if ($full_name==='' || $phone==='' || $date_of_birth==='' || $department_id<=0 || $doctor_id<=0 || $appointment_date==='' || $symptoms==='') {
      throw new Exception("Vui lòng nhập đầy đủ thông tin bắt buộc.");
    }
    if (!in_array($gender, ['Male','Female'], true)) $gender = null;

    $start = new DateTime(str_replace('T',' ', $appointment_date) . ':00');

    // validate rules giống offline
    [$ok, $msg] = validateRulesOnline($conn, $doctor_id, $department_id, $start);
    if (!$ok) throw new Exception($msg);

    $conn->beginTransaction();

    // tìm bệnh nhân theo phone (so sánh thông tin)
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE phone=:phone AND is_deleted=0 LIMIT 1");
    $stmt->execute(['phone'=>$phone]);
    $patient_id = $stmt->fetchColumn();

    if ($patient_id !== false) {
      $patient_id = (int)$patient_id;
      $upd = $conn->prepare("
        UPDATE patients
        SET full_name=:full_name,
            date_of_birth=:dob,
            gender=:gender,
            address=:address,
            medical_history=:mh
        WHERE patient_id=:pid
      ");
      $upd->execute([
        'full_name'=>$full_name,
        'dob'=>$date_of_birth,
        'gender'=>$gender,
        'address'=>($address!=='' ? $address : null),
        'mh'=>($medical_history!=='' ? $medical_history : null),
        'pid'=>$patient_id
      ]);
    } else {
      $ins = $conn->prepare("
        INSERT INTO patients(full_name,phone,date_of_birth,gender,address,medical_history,is_deleted)
        VALUES(:full_name,:phone,:dob,:gender,:address,:mh,0)
      ");
      $ins->execute([
        'full_name'=>$full_name,
        'phone'=>$phone,
        'dob'=>$date_of_birth,
        'gender'=>$gender,
        'address'=>($address!=='' ? $address : null),
        'mh'=>($medical_history!=='' ? $medical_history : null),
      ]);
      $patient_id = (int)$conn->lastInsertId();
    }

    // insert appointment
    $insA = $conn->prepare("
      INSERT INTO appointments(patient_id,department_id,doctor_id,appointment_date,symptoms,status)
      VALUES(:pid,:dep,:doc,:dt,:sym,'pending')
    ");
    $insA->execute([
      'pid'=>$patient_id,
      'dep'=>$department_id,
      'doc'=>$doctor_id,
      'dt'=>$start->format('Y-m-d H:i:s'),
      'sym'=>$symptoms
    ]);

    $conn->commit();

    $_SESSION['form_submitted']=true;
    $_SESSION['success_message']="Đặt lịch thành công! Vui lòng đến sớm 10–15 phút để làm thủ tục.";

    header("Location: booking.php?success=1#booking");
    exit;

  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $error_message = "Lỗi: " . $e->getMessage();
  }
}

$success_message = $_SESSION['success_message'] ?? null;
if (isset($_SESSION['success_message'])) {
  unset($_SESSION['success_message']);
  unset($_SESSION['form_submitted']);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Đặt lịch khám</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">

  <style>
    :root{
      --bg: #f6f7ff;
      --card:#ffffff;
      --text:#0b1220;
      --muted:#6b7280;

      --blue: #0ea5e9;
      --blue2:#2563eb;
      --purple:#7c3aed;

      --grad: linear-gradient(135deg, var(--blue) 0%, var(--blue2) 45%, var(--purple) 100%);
      --gradSoft: linear-gradient(135deg, rgba(14,165,233,.16) 0%, rgba(37,99,235,.14) 50%, rgba(124,58,237,.14) 100%);

      --shadow: 0 14px 34px rgba(11,18,32,.10);
      --shadow2: 0 10px 22px rgba(11,18,32,.07);
      --radius: 18px;
      --line: rgba(99,102,241,.10);
    }

    *{ box-sizing: border-box; }
    body{
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--text);
      background:
        radial-gradient(900px 520px at 16% 10%, rgba(14,165,233,.15), transparent 65%),
        radial-gradient(860px 520px at 86% 12%, rgba(124,58,237,.12), transparent 66%),
        var(--bg);
    }

    .container{ max-width: 1160px; }
    a{ text-decoration:none; }

    .navwrap{
      position: sticky; top:0; z-index: 1000;
      background: rgba(255,255,255,.82);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--line);
    }
    .brand{ display:flex; align-items:center; gap:12px; }
    .brand .logo{
      width:44px;height:44px;border-radius:16px;
      background: var(--grad);
      display:grid; place-items:center;
      color:#fff;
      box-shadow: 0 18px 26px rgba(37,99,235,.20);
    }
    .brand .name{ font-weight: 900; letter-spacing:-.2px; line-height:1.05; }
    .brand .tag{ color: var(--muted); font-weight: 800; font-size: 12px; }
    .btn-main{
      background: var(--grad);
      color:#fff;
      border:none;
      padding: 12px 16px;
      border-radius: 14px;
      font-weight: 900;
      box-shadow: 0 18px 26px rgba(37,99,235,.18);
    }
    .btn-main:hover{ filter: brightness(.98); color:#fff; }
    .btn-soft{
      background: rgba(14,165,233,.10);
      border: 1px solid rgba(14,165,233,.18);
      color: var(--text);
      padding: 12px 16px;
      border-radius: 14px;
      font-weight: 900;
    }
    .btn-soft:hover{ background: rgba(14,165,233,.14); color: var(--text); }

    .page-hero{
      margin: 18px 0 14px;
      border-radius: 26px;
      border: 1px solid var(--line);
      box-shadow: var(--shadow);
      background:
        radial-gradient(1000px 380px at 15% 10%, rgba(14,165,233,.18), transparent 60%),
        radial-gradient(1000px 380px at 85% 10%, rgba(124,58,237,.16), transparent 65%),
        #fff;
      padding: 18px;
    }
    .page-hero .pill{
      display:inline-flex; align-items:center; gap:10px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(37,99,235,.16);
      font-weight: 900;
      font-size: 12px;
    }
    .page-hero h1{
      margin: 10px 0 6px;
      font-weight: 900;
      letter-spacing: -0.6px;
      font-size: clamp(28px, 2.5vw, 40px);
    }
    .page-hero p{
      margin:0;
      color: var(--muted);
      font-weight: 700;
      line-height: 1.7;
      font-size: 14px;
    }

    .cardx{
      border-radius: 22px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,.86);
      box-shadow: var(--shadow2);
    }

    .form-control, .form-select{
      background: #f8fafc;
      border: 1px solid rgba(99,102,241,.14);
      border-radius: 14px;
      padding: 12px 14px;
      font-weight: 700;
    }
    .form-control:focus, .form-select:focus{
      border-color: rgba(37,99,235,.45);
      box-shadow: 0 0 0 .2rem rgba(37,99,235,.12);
      background:#fff;
    }

    .small-hint{
      color: var(--muted);
      font-weight: 700;
      font-size: 12px;
      margin-top: 6px;
    }

    .guide{
      border-radius: 22px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,.80);
      box-shadow: var(--shadow2);
      padding: 16px;
      position: sticky;
      top: 92px;
    }
    .guide h5{
      margin:0 0 10px;
      font-weight: 900;
      letter-spacing: -.2px;
    }
    .guide .item{
      display:flex;
      gap: 10px;
      padding: 10px;
      border-radius: 16px;
      border: 1px solid rgba(99,102,241,.10);
      background:#fff;
      box-shadow: 0 10px 22px rgba(11,18,32,.05);
      margin-bottom: 10px;
      align-items:flex-start;
    }
    .guide .ico{
      width:40px;height:40px;border-radius: 16px;
      display:grid;place-items:center;
      background: var(--gradSoft);
      border: 1px solid rgba(99,102,241,.12);
      color:#1d4ed8;
      flex: 0 0 auto;
    }
    .guide .item strong{ display:block; font-weight:900; }
    .guide .item span{ display:block; color: var(--muted); font-weight:700; font-size: 13px; margin-top: 4px; line-height:1.55; }

    .toast-container{ z-index: 2000; }

    pre.msg{
      white-space: pre-wrap;
      margin: 0;
      font-family: inherit;
    }
  </style>
</head>
<body>

<!-- NAVBAR -->
<div class="navwrap">
  <nav class="navbar navbar-expand-lg py-3">
    <div class="container">
      <a class="navbar-brand brand" href="<?php echo h($HOME_URL); ?>">
        <div class="logo"><i class="fa-solid fa-hospital"></i></div>
        <div>
          <div class="name">Phòng Khám ABC</div>
          <div class="tag">Đặt lịch khám trực tuyến</div>
        </div>
      </a>

      <div class="ms-auto d-flex gap-2">
        <a class="btn btn-soft" href="<?php echo h($HOME_URL); ?>">
          <i class="fa-solid fa-house me-2"></i>Trang chủ
        </a>
        <a class="btn btn-main" href="#booking">
          <i class="fa-regular fa-calendar-days me-2"></i>Đặt lịch
        </a>
      </div>
    </div>
  </nav>
</div>

<section class="py-3">
  <div class="container">
    <div class="page-hero">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <div class="pill"><i class="fa-solid fa-shield-heart"></i> Nhanh chóng • Chính xác • Minh bạch</div>
          <h1>Đặt Lịch Khám Trực Tuyến</h1>
          <p>
            Vui lòng nhập đúng thông tin, chọn khoa – bác sĩ và thời gian phù hợp. 
          </p>
        </div>
        <div class="text-end">
          <div class="small-hint" style="margin-top:6px;">
            Giờ làm việc: <b>08:00–12:00</b> &nbsp;|&nbsp; <b>13:30–17:00</b><br>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast success -->
    <?php if (isset($_GET['success']) && $success_message): ?>
      <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="successToast" class="toast align-items-center text-white bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
          <div class="d-flex">
            <div class="toast-body">
              <?php echo h($success_message); ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
      <div class="alert alert-danger rounded-4 border-0 shadow-sm">
        <pre class="msg"><?php echo h($error_message); ?></pre>
      </div>
    <?php endif; ?>

    <div class="row g-3" id="booking">
      <!-- FORM -->
      <div class="col-lg-8">
        <div class="cardx p-3 p-md-4">
          <form method="POST" action="booking.php#booking" class="row g-3" autocomplete="off">
            <input type="hidden" name="booking_submit" value="1">

            <div class="col-12">
              <h5 class="fw-black mb-0" style="font-weight:900;">Thông tin bệnh nhân</h5>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Họ và tên *</label>
              <input class="form-control" name="full_name" required placeholder="Nguyễn Văn A">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Số điện thoại *</label>
              <input class="form-control" name="phone" required placeholder="VD: 09xxxxxxxx">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Ngày sinh *</label>
              <input class="form-control" type="date" name="date_of_birth" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Giới tính</label>
              <select class="form-select" name="gender">
                <option value="">-- Chọn --</option>
                <option value="Male">Nam</option>
                <option value="Female">Nữ</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Địa chỉ</label>
              <input class="form-control" name="address" placeholder="Số nhà, đường, quận/huyện, tỉnh/thành...">
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Tiền sử bệnh (tuỳ chọn)</label>
              <textarea class="form-control" name="medical_history" rows="3" placeholder="VD: dị ứng thuốc, bệnh nền, từng phẫu thuật..."></textarea>
              
            </div>

            <hr class="my-2" style="border-color: rgba(99,102,241,.14);">

            <div class="col-12">
              <h5 class="fw-black mb-0" style="font-weight:900;">Thông tin lịch hẹn</h5>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Khoa *</label>
              <select class="form-select" name="department_id" id="department_id" required>
                <option value="">-- Chọn khoa --</option>
                <?php foreach($departments as $d): ?>
                  <option value="<?php echo (int)$d['department_id']; ?>"><?php echo h($d['department_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Bác sĩ *</label>
              <select class="form-select" name="doctor_id" id="doctor_id" required disabled>
                <option value="">-- Chọn bác sĩ --</option>
              </select>
             
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Ngày giờ khám *</label>
              <input class="form-control" type="datetime-local" name="appointment_date" id="appointment_date" required>
              
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Trạng thái kiểm tra</label>
              <div class="p-3 rounded-4" style="border:1px solid rgba(99,102,241,.14); background:#fff;">
                <div id="slotStatus" class="fw-bold" style="font-weight:900;">Chưa kiểm tra</div>
                <div id="slotSuggest" class="small-hint mb-0"></div>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Triệu chứng / Lý do khám *</label>
              <textarea class="form-control" name="symptoms" rows="4" required placeholder="VD: đau đầu 3 ngày, ho sốt, khám định kỳ..."></textarea>
              
            </div>

            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-2">
              <a class="btn btn-soft" href="<?php echo h($HOME_URL); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Về trang chủ
              </a>
              <button class="btn btn-main" type="submit" id="submitBtn">
                <i class="fa-solid fa-paper-plane me-2"></i>Gửi đặt lịch
              </button>
            </div>

          </form>
        </div>
      </div>

      <!-- GUIDE -->
      <div class="col-lg-4">
        <div class="guide">
          <h5><i class="fa-regular fa-circle-question me-2"></i>Hướng dẫn đặt lịch</h5>

          <div class="item">
            <div class="ico"><i class="fa-solid fa-id-card-clip"></i></div>
            <div>
              <strong>Thông tin bệnh nhân</strong>
              <span>
                Nhập đúng <b>Họ tên</b> và <b>SĐT</b>. Nếu SĐT đã có trong hệ thống, hồ sơ sẽ được cập nhật và chỉ thêm lịch hẹn mới.
              </span>
            </div>
          </div>

          <div class="item">
            <div class="ico"><i class="fa-solid fa-stethoscope"></i></div>
            <div>
              <strong>Chọn khoa &amp; bác sĩ</strong>
              <span>
                Chọn <b>khoa</b> trước, sau đó chọn <b>bác sĩ</b>. Danh sách bác sĩ được lọc theo khoa để bạn chọn nhanh hơn.
              </span>
            </div>
          </div>

          <div class="item">
            <div class="ico"><i class="fa-regular fa-clock"></i></div>
            <div>
              <strong>Quy tắc thời gian</strong>
              <span>
                Bệnh viện làm việc: <b>08:00–12:00</b> và <b>13:30–17:00</b>.<br>
               
              </span>
            </div>
          </div>

          <div class="item">
            <div class="ico"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
              <strong>Nếu bị trùng lịch</strong>
              <span>
                Hệ thống sẽ thông báo <b>lịch trước / lịch sau</b> và gợi ý <b>giờ gần nhất</b> .
              </span>
            </div>
          </div>

          <div class="item mb-0">
            <div class="ico"><i class="fa-solid fa-hospital-user"></i></div>
            <div>
              <strong>Đi khám đúng hẹn</strong>
              <span>
                Bạn nên đến sớm <b>10–15 phút</b> để làm thủ tục. Mang theo giấy tờ cần thiết (CMND/CCCD, BHYT nếu có).
              </span>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</section>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
  // Toast show
  document.addEventListener('DOMContentLoaded', function() {
    const toastEl = document.getElementById('successToast');
    if (toastEl) {
      const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
      toast.show();
    }
  });

  const departmentEl = document.getElementById('department_id');
  const doctorEl = document.getElementById('doctor_id');
  const dtEl = document.getElementById('appointment_date');
  const slotStatus = document.getElementById('slotStatus');
  const slotSuggest = document.getElementById('slotSuggest');
  const submitBtn = document.getElementById('submitBtn');

  function setStatus(type, text, suggest=null){
    // type: ok | bad | idle
    slotStatus.textContent = text;
    if (type === 'ok') slotStatus.style.color = '#166534';
    else if (type === 'bad') slotStatus.style.color = '#7f1d1d';
    else slotStatus.style.color = '#0b1220';

    slotSuggest.textContent = '';
    if (suggest) {
      try{
        const d = new Date(suggest);
        // format: HH:mm dd/mm/yyyy
        const hh = String(d.getHours()).padStart(2,'0');
        const mm = String(d.getMinutes()).padStart(2,'0');
        const dd = String(d.getDate()).padStart(2,'0');
        const mo = String(d.getMonth()+1).padStart(2,'0');
        const yy = d.getFullYear();
        slotSuggest.textContent = 'Gợi ý gần nhất: ' + hh + ':' + mm + ' ' + dd + '/' + mo + '/' + yy;
      }catch(e){
        slotSuggest.textContent = 'Gợi ý gần nhất: ' + suggest;
      }
    }
  }

  async function loadDoctors(){
    const dep = departmentEl.value;
    doctorEl.innerHTML = `<option value="">-- Chọn bác sĩ --</option>`;
    doctorEl.disabled = true;

    if (!dep) return;

    try{
      const res = await fetch(`booking.php?action=get_doctors&department_id=${encodeURIComponent(dep)}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.message || 'Lỗi tải bác sĩ');

      (data.doctors || []).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.doctor_id;
        opt.textContent = d.full_name;
        doctorEl.appendChild(opt);
      });

      doctorEl.disabled = false;
    }catch(err){
      setStatus('bad', 'Không tải được danh sách bác sĩ.');
    }
  }

  let checkTimer = null;
  async function checkSlot(){
    clearTimeout(checkTimer);
    checkTimer = setTimeout(async ()=>{
      const dep = departmentEl.value;
      const doc = doctorEl.value;
      const dt  = dtEl.value;

      if (!dep || !doc || !dt) {
        setStatus('idle', 'Chưa kiểm tra');
        return;
      }

      setStatus('idle', 'Đang kiểm tra...');

      try{
        const url = `booking.php?action=check_slot&department_id=${encodeURIComponent(dep)}&doctor_id=${encodeURIComponent(doc)}&appointment_date=${encodeURIComponent(dt)}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.ok) {
          setStatus('ok', 'Khung giờ hợp lệ');
        } else {
          setStatus('bad', data.message || 'Khung giờ không hợp lệ', data.suggest || null);
        }
      }catch(err){
        setStatus('bad', 'Không kiểm tra được khung giờ.');
      }
    }, 250);
  }

  // block submit nếu slot invalid (client-side; server vẫn check)
  document.querySelector('form')?.addEventListener('submit', async function(e){
    const dep = departmentEl.value;
    const doc = doctorEl.value;
    const dt  = dtEl.value;

    if (!dep || !doc || !dt) return; // required sẽ bắt

    try{
      const url = `booking.php?action=check_slot&department_id=${encodeURIComponent(dep)}&doctor_id=${encodeURIComponent(doc)}&appointment_date=${encodeURIComponent(dt)}`;
      const res = await fetch(url);
      const data = await res.json();
      if (!data.ok) {
        e.preventDefault();
        setStatus('bad', data.message || 'Khung giờ không hợp lệ', data.suggest || null);
        dtEl.scrollIntoView({ behavior:'smooth', block:'center' });
      }
    }catch(err){
      // nếu check lỗi mạng, vẫn để server xử lý khi submit
    }
  });

  departmentEl.addEventListener('change', async ()=>{
    await loadDoctors();
    checkSlot();
  });
  doctorEl.addEventListener('change', checkSlot);
  dtEl.addEventListener('change', checkSlot);
  dtEl.addEventListener('input', checkSlot);

  // init
  loadDoctors();
</script>

</body>
</html>