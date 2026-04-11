<?php
require_once __DIR__ . '/../auth.php';
require_role('admin');

require_once __DIR__ . '/../db.php';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    header('Location: ../login.php');
    exit;
}


/* =========================
   HELPERS
========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDT($d){
    if (!$d) return 'N/A';
    $t = strtotime($d);
    return $t ? date('d/m/Y H:i', $t) : 'N/A';
}
function toDatetimeLocal($d){
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('Y-m-d\TH:i', $t) : '';
}
function statusPillHtml($status){
    $status = (string)$status;
    $map = [
        'Chờ khám'  => ['Chờ khám', 'pending'],
        'Đang khám' => ['Đang khám', 'doing'],
        'Hoàn tất'  => ['Hoàn tất', 'done'],
        'Hủy'       => ['Hủy', 'cancel'],
    ];
    $x = $map[$status] ?? [$status ?: 'N/A', 'unknown'];
    return '<span class="pill '.$x[1].'">'.h($x[0]).'</span>';
}

function is_valid_date($d){ return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function is_valid_time_hm($t){
    if (!preg_match('/^\d{2}:\d{2}$/', (string)$t)) return false;
    [$hh, $mm] = array_map('intval', explode(':', $t));
    return ($hh >= 0 && $hh <= 23 && $mm >= 0 && $mm <= 59);
}
function classify_patient_search_input(string $q): array {
    $raw = trim($q);
    if ($raw === '') return ['kind' => 'empty', 'compact' => ''];
    $compact = preg_replace('/\s+/', '', $raw);
    if (preg_match('/^\+?\d{8,15}$/', $compact)) return ['kind' => 'phone', 'compact' => $compact];
    if (preg_match('/^\d{1,9}$/', $compact)) return ['kind' => 'patient_id', 'compact' => $compact];
    return ['kind' => 'text', 'compact' => $compact];
}
const SERVICE_MIN = 20;
const STEP_MIN = 5;
const MAX_APPT_PER_DOCTOR_PER_DAY = 20;
const MIN_LEAD_MIN = 0;

function dt_from_ymd_hm(string $dateYmd, string $timeHm): ?DateTime {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateYmd . ' ' . $timeHm . ':00');
    return $dt ?: null;
}
function add_minutes(DateTime $dt, int $min): DateTime {
    $c = clone $dt;
    $c->modify(($min >= 0 ? '+' : '') . $min . ' minutes');
    return $c;
}
function minutes_diff(DateTime $a, DateTime $b): int {
    return (int)round(($a->getTimestamp() - $b->getTimestamp()) / 60);
}
function round_up_to_step(DateTime $dt, int $stepMin = STEP_MIN): DateTime {
    $c = clone $dt;
    $total = ((int)$c->format('H')) * 60 + ((int)$c->format('i'));
    $rounded = (int)(ceil($total / $stepMin) * $stepMin);
    $h = intdiv($rounded, 60);
    $mi = $rounded % 60;
    $c->setTime($h % 24, $mi, 0);
    return $c;
}
function round_down_to_step(DateTime $dt, int $stepMin = STEP_MIN): DateTime {
    $c = clone $dt;
    $total = ((int)$c->format('H')) * 60 + ((int)$c->format('i'));
    $rounded = (int)(floor($total / $stepMin) * $stepMin);
    $h = intdiv($rounded, 60);
    $mi = $rounded % 60;
    $c->setTime($h % 24, $mi, 0);
    return $c;
}
function get_work_sessions(string $dateYmd): array {
    return [
        [new DateTime("$dateYmd 08:00:00"), new DateTime("$dateYmd 12:00:00")],
        [new DateTime("$dateYmd 13:30:00"), new DateTime("$dateYmd 17:00:00")],
    ];
}
function validate_working_hours(DateTime $start, int $serviceMin = SERVICE_MIN): array {
    $sessions = get_work_sessions($start->format('Y-m-d'));
    $end = add_minutes($start, $serviceMin);

    foreach ($sessions as [$s, $e]) {
        if ($start >= $s && $start < $e) {
            if ($end > $e) {
                return [false, "Lịch khám vượt quá thời gian làm việc của ca này (kết thúc lúc " . $e->format('H:i') . ")"];
            }

            return [true, 'OK'];
        }
    }

    return [false, 'Phòng khám chỉ làm việc 08:00–12:00 và 13:30–17:00.'];
}
function dbColumnExists(PDO $conn, string $table, string $column): bool {
    try {
        $st = $conn->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function getDoctor(PDO $conn, int $doctorId): ?array {
    $hasSpecialty = dbColumnExists($conn, 'doctors', 'specialty');
    $sql = $hasSpecialty
        ? "SELECT doctor_id, full_name, department_id, status, is_deleted, specialty FROM doctors WHERE doctor_id = ? LIMIT 1"
        : "SELECT doctor_id, full_name, department_id, status, is_deleted, '' AS specialty FROM doctors WHERE doctor_id = ? LIMIT 1";
    $st = $conn->prepare($sql);
    $st->execute([$doctorId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function getPatient(PDO $conn, int $id): ?array {
    $st = $conn->prepare("SELECT patient_id, full_name, phone, date_of_birth, gender, address, is_deleted FROM patients WHERE patient_id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function fetchDoctorDayAppointments(PDO $conn, int $doctorId, string $dateYmd, ?int $excludeApptId = null): array {
    $sql = "SELECT appointment_id, appointment_date FROM appointments WHERE doctor_id = ? AND DATE(appointment_date) = ? AND status <> 'Hủy'";
    $params = [$doctorId, $dateYmd];
    if ($excludeApptId) { $sql .= " AND appointment_id <> ?"; $params[] = $excludeApptId; }
    $sql .= " ORDER BY appointment_date ASC";
    $st = $conn->prepare($sql);
    $st->execute($params);
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
    foreach ($blocks as [$bs, $be]) if ($start < $be && $end > $bs) return true;
    return false;
}
function find_prev_next(PDO $conn, int $doctorId, DateTime $start, ?int $excludeApptId = null): array {
    $dt = $start->format('Y-m-d H:i:s');
    if ($excludeApptId) {
        $stPrev = $conn->prepare("SELECT appointment_date FROM appointments WHERE doctor_id = ? AND status <> 'Hủy' AND appointment_id <> ? AND appointment_date < ? ORDER BY appointment_date DESC LIMIT 1");
        $stPrev->execute([$doctorId, $excludeApptId, $dt]);
        $stNext = $conn->prepare("SELECT appointment_date FROM appointments WHERE doctor_id = ? AND status <> 'Hủy' AND appointment_id <> ? AND appointment_date > ? ORDER BY appointment_date ASC LIMIT 1");
        $stNext->execute([$doctorId, $excludeApptId, $dt]);
    } else {
        $stPrev = $conn->prepare("SELECT appointment_date FROM appointments WHERE doctor_id = ? AND status <> 'Hủy' AND appointment_date < ? ORDER BY appointment_date DESC LIMIT 1");
        $stPrev->execute([$doctorId, $dt]);
        $stNext = $conn->prepare("SELECT appointment_date FROM appointments WHERE doctor_id = ? AND status <> 'Hủy' AND appointment_date > ? ORDER BY appointment_date ASC LIMIT 1");
        $stNext->execute([$doctorId, $dt]);
    }
    return [$stPrev->fetchColumn() ?: null, $stNext->fetchColumn() ?: null];
}
function suggest_nearest_slot(PDO $conn, int $doctorId, string $dateYmd, DateTime $wanted, ?int $excludeApptId = null): ?DateTime {
    $appts = fetchDoctorDayAppointments($conn, $doctorId, $dateYmd, $excludeApptId);
    $blocks = intervals_from_appts($appts, SERVICE_MIN);
    $sessions = get_work_sessions($dateYmd);
    $now = new DateTime('now');
    $best = null;
    $bestAbs = null;

    foreach ($sessions as [$s, $e]) {
        $cursor = round_up_to_step(clone $s, STEP_MIN);

        while (true) {
            if ($cursor < $now) {
                $cursor = add_minutes($cursor, STEP_MIN);
                continue;
            }

            $end = add_minutes($cursor, SERVICE_MIN);
            if ($end > $e) {
                break;
            }

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
function validateRulesOffline(PDO $conn, int $doctorId, int $departmentId, string $dateYmd, string $timeHm, ?int $editingId = null): array {
    if ($doctorId <= 0) return [false, 'Vui lòng chọn bác sĩ'];
    if ($departmentId <= 0) return [false, 'Vui lòng chọn khoa'];
    $doc = getDoctor($conn, $doctorId);
    if (!$doc || ((int)($doc['is_deleted'] ?? 0) !== 0)) return [false, 'Bác sĩ không hợp lệ hoặc đã bị vô hiệu hóa'];
    if ((int)($doc['department_id'] ?? 0) !== (int)$departmentId) return [false, 'Bác sĩ không thuộc khoa đã chọn'];
    $apptStart = dt_from_ymd_hm($dateYmd, $timeHm);
    if (!$apptStart) return [false, 'Ngày/giờ không hợp lệ'];
    $now = new DateTime('now');
    if ($apptStart < $now) return [false, 'Không thể đăng ký lịch ở quá khứ'];
    [$okWork, $msgWork] = validate_working_hours($apptStart, SERVICE_MIN);
    if (!$okWork) {
        $suggest = suggest_nearest_slot($conn, $doctorId, $dateYmd, $apptStart, $editingId);
        if ($suggest) $msgWork .= ' Gợi ý gần nhất: ' . $suggest->format('H:i');
        return [false, $msgWork];
    }
    $st = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND DATE(appointment_date) = ? AND status <> 'Hủy'" . ($editingId ? " AND appointment_id <> ?" : ""));
    $params = [$doctorId, $dateYmd];
    if ($editingId) $params[] = $editingId;
    $st->execute($params);
    if ((int)$st->fetchColumn() >= MAX_APPT_PER_DOCTOR_PER_DAY) return [false, 'Bác sĩ đã đủ ' . MAX_APPT_PER_DOCTOR_PER_DAY . ' lịch trong ngày này'];
    [$prevStr, $nextStr] = find_prev_next($conn, $doctorId, $apptStart, $editingId);
    $appts = fetchDoctorDayAppointments($conn, $doctorId, $dateYmd, $editingId);
    $blocks = intervals_from_appts($appts, SERVICE_MIN);
    $newEnd = add_minutes($apptStart, SERVICE_MIN);
    if (is_overlapping($apptStart, $newEnd, $blocks)) {
        $lines = ['Giờ này bị trùng/chồng lịch của bác sĩ.'];
        $cand = null;
        if ($prevStr) {
            $prevStart = new DateTime($prevStr); $prevEnd = add_minutes($prevStart, SERVICE_MIN);
            if ($apptStart < $prevEnd) { $lines[] = 'Lịch trước: ' . substr($prevStr, 0, 16); $cand = round_up_to_step($prevEnd, STEP_MIN); }
        }
        if ($nextStr) {
            $nextStart = new DateTime($nextStr);
            if ($newEnd > $nextStart) { $lines[] = 'Lịch sau: ' . substr($nextStr, 0, 16); if ($cand === null) $cand = round_down_to_step(add_minutes($nextStart, -SERVICE_MIN), STEP_MIN); }
        }
        $suggest = null;
        if ($cand !== null) {
            [$okWork2, ] = validate_working_hours($cand, SERVICE_MIN); $candEnd = add_minutes($cand, SERVICE_MIN);
            if ($okWork2 && !is_overlapping($cand, $candEnd, $blocks)) $suggest = $cand;
        }
        if ($suggest === null) $suggest = suggest_nearest_slot($conn, $doctorId, $dateYmd, $apptStart, $editingId);
        $lines[] = $suggest ? ('Gợi ý giờ gần nhất: ' . $suggest->format('H:i')) : 'Không còn khung giờ phù hợp trong ngày.';
        return [false, implode("\n", $lines)];
    }
    return [true, 'OK'];
}
function search_patient(PDO $conn, string $q): array {
    $info = classify_patient_search_input($q);
    if ($info['kind'] === 'empty') return [];
    if ($info['kind'] === 'patient_id') {
        $st = $conn->prepare("SELECT patient_id, full_name, phone, date_of_birth, gender, address FROM patients WHERE patient_id = ? AND is_deleted = 0 LIMIT 1");
        $st->execute([(int)$info['compact']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? [$row] : [];
    }
    $qLike = '%' . $info['compact'] . '%';
    if ($info['kind'] === 'phone') {
        $st = $conn->prepare("SELECT patient_id, full_name, phone, date_of_birth, gender, address FROM patients WHERE is_deleted = 0 AND (REPLACE(phone,' ','') LIKE REPLACE(?, ' ','') OR REPLACE(phone,' ','') LIKE REPLACE(?, ' ','') OR full_name LIKE ?) ORDER BY full_name ASC, patient_id DESC LIMIT 20");
        $st->execute([$info['compact'], $qLike, '%' . trim($q) . '%']);
    } else {
        $st = $conn->prepare("SELECT patient_id, full_name, phone, date_of_birth, gender, address FROM patients WHERE is_deleted = 0 AND (full_name LIKE ? OR phone LIKE ?) ORDER BY full_name ASC, patient_id DESC LIMIT 20");
        $st->execute(['%' . trim($q) . '%', '%' . trim($q) . '%']);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* =========================
   LOAD ADMIN
========================= */
$admin = null;
try {
    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $admin = null;
}

$admin_name = $admin['full_name'] ?? ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
$admin_email = $admin['email'] ?? ($_SESSION['email'] ?? '');
$admin_initial = mb_substr($admin_name, 0, 1, 'UTF-8');

/* =========================
   DETAIL LOADER
========================= */
function loadAppointmentDetail(PDO $conn, int $appointment_id): array {
    try {
        $stmt = $conn->prepare("
            SELECT a.*,
                   p.full_name       AS patient_name,
                   p.phone           AS patient_phone,
                   p.date_of_birth   AS patient_dob,
                   p.gender          AS patient_gender,
                   d.full_name       AS doctor_name,
                   dp.department_name
            FROM appointments a
            LEFT JOIN patients p    ON a.patient_id = p.patient_id
            LEFT JOIN doctors d     ON a.doctor_id = d.doctor_id
            LEFT JOIN departments dp ON a.department_id = dp.department_id
            WHERE a.appointment_id = :aid
            LIMIT 1
        ");
        $stmt->execute(['aid' => $appointment_id]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) return ['ok'=>false, 'message'=>'Không tìm thấy lịch khám!'];

        $stmt = $conn->prepare("
            SELECT mr.*,
                   d.full_name AS doctor_name
            FROM medical_records mr
            LEFT JOIN doctors d ON mr.doctor_id = d.doctor_id
            WHERE mr.appointment_id = :aid
            LIMIT 1
        ");
        $stmt->execute(['aid' => $appointment_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $rxs = [];
        if ($rec && !empty($rec['record_id'])) {
            $stmt = $conn->prepare("
                SELECT pr.*,
                       m.name AS medication_name,
                       m.unit
                FROM prescriptions pr
                LEFT JOIN medications m ON pr.medication_id = m.medication_id
                WHERE pr.record_id = :rid
                ORDER BY pr.prescription_id ASC
            ");
            $stmt->execute(['rid' => (int)$rec['record_id']]);
            $rxs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return ['ok'=>true, 'appointment'=>$a, 'record'=>$rec, 'prescriptions'=>$rxs];
    } catch (PDOException $e) {
        return ['ok'=>false, 'message'=>'DB Error: '.$e->getMessage()];
    }
}

/* =========================
   AJAX HANDLER
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = (string)$_GET['action'];

        // LIST
        if ($action === 'list') {
            $q = trim($_POST['q'] ?? '');
            $status = trim($_POST['status'] ?? '');
            $from = trim($_POST['from'] ?? '');
            $to = trim($_POST['to'] ?? '');

            $where = [];
            $params = [];

            if ($status !== '' && in_array($status, ['Chờ khám','Đang khám','Hoàn tất','Hủy'], true)) {
                $where[] = "a.status = :st";
                $params['st'] = $status;
            }
            if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $where[] = "DATE(a.appointment_date) >= :from";
                $params['from'] = $from;
            }
            if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where[] = "DATE(a.appointment_date) <= :to";
                $params['to'] = $to;
            }
            if ($q !== '') {
                $where[] = "(
                    a.appointment_id LIKE :q
                    OR p.full_name LIKE :q2
                    OR p.phone LIKE :q3
                    OR d.full_name LIKE :q4
                    OR dp.department_name LIKE :q5
                )";
                $params['q']  = "%$q%";
                $params['q2'] = "%$q%";
                $params['q3'] = "%$q%";
                $params['q4'] = "%$q%";
                $params['q5'] = "%$q%";
            }

            $sqlWhere = $where ? ("WHERE ".implode(" AND ", $where)) : "";

            $stmt = $conn->prepare("
                SELECT a.appointment_id, a.appointment_date, a.status, a.symptoms,
                       a.patient_id, a.doctor_id, a.department_id,
                       p.full_name AS patient_name, p.phone AS patient_phone,
                       d.full_name AS doctor_name,
                       dp.department_name
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
                LEFT JOIN departments dp ON a.department_id = dp.department_id
                $sqlWhere
                ORDER BY a.appointment_date DESC, a.appointment_id DESC
                LIMIT 800
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success'=>true, 'rows'=>$rows]);
            exit;
        }

        // DETAIL
        if ($action === 'detail') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            if ($appointment_id <= 0) {
                echo json_encode(['success'=>false,'message'=>'appointment_id không hợp lệ!']);
                exit;
            }
            $pack = loadAppointmentDetail($conn, $appointment_id);
            if (!$pack['ok']) {
                echo json_encode(['success'=>false,'message'=>$pack['message'] ?? 'Không lấy được chi tiết!']);
                exit;
            }
            echo json_encode(['success'=>true,'data'=>$pack]);
            exit;
        }

        // GET DOCTORS BY DEPARTMENT
        if ($action === 'get_doctors') {
            $deptId = (int)($_POST['department_id'] ?? 0);
            if ($deptId <= 0) { echo json_encode(['success'=>true,'rows'=>[]]); exit; }

            $hasSpecialty = dbColumnExists($conn, 'doctors', 'specialty');

if ($hasSpecialty) {
    $stmt = $conn->prepare("
        SELECT DISTINCT d.doctor_id, d.full_name
        FROM doctors d
        LEFT JOIN departments dp ON dp.department_id = :did
        WHERE d.is_deleted = 0
          AND (
                d.department_id = :did
                OR d.specialty = dp.department_name
                OR d.specialty LIKE CONCAT('%', dp.department_name, '%')
              )
        ORDER BY d.full_name ASC
    ");
    $stmt->execute(['did'=>$deptId]);
} else {
    $stmt = $conn->prepare("
        SELECT DISTINCT d.doctor_id, d.full_name
        FROM doctors d
        WHERE d.is_deleted = 0
          AND d.department_id = :did
        ORDER BY d.full_name ASC
    ");
    $stmt->execute(['did'=>$deptId]);
}
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success'=>true,'rows'=>$rows]);
            exit;
        }

        // SEARCH PATIENT
        if ($action === 'search_patient') {
            $q = trim((string)($_POST['q'] ?? ''));
            echo json_encode(['success'=>true, 'items'=>search_patient($conn, $q)]);
            exit;
        }

        // ADD
        if ($action === 'add') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $department_id = (int)($_POST['department_id'] ?? 0);
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $appointment_date_only = trim($_POST['appointment_date_only'] ?? '');
            $appointment_time = trim($_POST['appointment_time'] ?? '');
            $appointment_date = trim($_POST['appointment_date'] ?? '');
            $symptoms = trim($_POST['symptoms'] ?? '');
            $status = trim($_POST['status'] ?? 'Chờ khám');

            $new_full_name = trim((string)($_POST['new_full_name'] ?? ''));
            $new_phone = trim((string)($_POST['new_phone'] ?? ''));
            $new_dob = trim((string)($_POST['new_dob'] ?? ''));
            $new_gender = trim((string)($_POST['new_gender'] ?? ''));
            $new_address = trim((string)($_POST['new_address'] ?? ''));

            if (!in_array($status, ['Chờ khám','Đang khám','Hoàn tất','Hủy'], true)) $status = 'Chờ khám';

            if ($appointment_date_only === '' || $appointment_time === '') {
                if ($appointment_date !== '') {
                    $tmp = strtotime($appointment_date);
                    if ($tmp) {
                        $appointment_date_only = date('Y-m-d', $tmp);
                        $appointment_time = date('H:i', $tmp);
                    }
                }
            }

            if ($patient_id <= 0) {
                if ($new_full_name === '' || $new_phone === '' || $new_dob === '') {
                    echo json_encode(['success'=>false,'message'=>'Vui lòng chọn bệnh nhân hoặc nhập đủ thông tin bệnh nhân mới!']);
                    exit;
                }
                if (!is_valid_date($new_dob)) {
                    echo json_encode(['success'=>false,'message'=>'Ngày sinh bệnh nhân không hợp lệ!']);
                    exit;
                }
                if ($new_gender !== '' && !in_array($new_gender, ['Male','Female'], true)) $new_gender = '';
                try {
                    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE phone = ? AND is_deleted = 0 LIMIT 1");
                    $stmt->execute([$new_phone]);
                    $existingPatientId = (int)($stmt->fetchColumn() ?: 0);
                    if ($existingPatientId > 0) {
                        $patient_id = $existingPatientId;
                    } else {
                        $stmt = $conn->prepare("INSERT INTO patients (full_name, phone, date_of_birth, gender, address) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$new_full_name, $new_phone, $new_dob, ($new_gender ?: null), ($new_address ?: null)]);
                        $patient_id = (int)$conn->lastInsertId();
                    }
                } catch (Throwable $e) {
                    echo json_encode(['success'=>false,'message'=>'Không thể tạo bệnh nhân mới: '.$e->getMessage()]);
                    exit;
                }
            }

            if ($patient_id<=0 || $department_id<=0 || $doctor_id<=0 || $appointment_date_only==='' || $appointment_time==='') {
                echo json_encode(['success'=>false,'message'=>'Thiếu dữ liệu bắt buộc (Bệnh nhân/Khoa/Bác sĩ/Ngày khám/Giờ khám)!']);
                exit;
            }
            if (!is_valid_date($appointment_date_only) || !is_valid_time_hm($appointment_time)) {
                echo json_encode(['success'=>false,'message'=>'Ngày hoặc giờ khám không hợp lệ!']);
                exit;
            }
            if ($symptoms==='') {
                echo json_encode(['success'=>false,'message'=>'Vui lòng nhập triệu chứng!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE patient_id=:id AND is_deleted=0");
            $stmt->execute(['id'=>$patient_id]);
            if ((int)$stmt->fetchColumn() <= 0) {
                echo json_encode(['success'=>false,'message'=>'Bệnh nhân không hợp lệ!']);
                exit;
            }
            $stmt = $conn->prepare("SELECT COUNT(*) FROM departments WHERE department_id=:id");
            $stmt->execute(['id'=>$department_id]);
            if ((int)$stmt->fetchColumn() <= 0) {
                echo json_encode(['success'=>false,'message'=>'Khoa không hợp lệ!']);
                exit;
            }
            $stmt = $conn->prepare("SELECT COUNT(*) FROM doctors WHERE doctor_id=:id AND is_deleted=0");
            $stmt->execute(['id'=>$doctor_id]);
            if ((int)$stmt->fetchColumn() <= 0) {
                echo json_encode(['success'=>false,'message'=>'Bác sĩ không hợp lệ!']);
                exit;
            }

            [$okRule, $msgRule] = validateRulesOffline($conn, $doctor_id, $department_id, $appointment_date_only, $appointment_time, null);
            if (!$okRule) {
                echo json_encode(['success'=>false,'message'=>$msgRule]);
                exit;
            }

            $fullDateTime = $appointment_date_only . ' ' . $appointment_time . ':00';
            $stmt = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id, department_id, appointment_date, symptoms, status) VALUES (:pid, :doc, :dep, :dt, :sym, :st)");
            $stmt->execute([
                'pid'=>$patient_id,
                'doc'=>$doctor_id,
                'dep'=>$department_id,
                'dt'=>$fullDateTime,
                'sym'=>$symptoms,
                'st'=>$status
            ]);
            $newId = (int)$conn->lastInsertId();

            echo json_encode(['success'=>true,'message'=>'Thêm lịch khám thành công!','appointment_id'=>$newId]);
            exit;
        }

        // EDIT
        if ($action === 'edit') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $department_id = (int)($_POST['department_id'] ?? 0);
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $appointment_date_only = trim($_POST['appointment_date_only'] ?? '');
            $appointment_time = trim($_POST['appointment_time'] ?? '');
            $appointment_date = trim($_POST['appointment_date'] ?? '');
            $symptoms = trim($_POST['symptoms'] ?? '');
            $status = trim($_POST['status'] ?? 'Chờ khám');

            if (!in_array($status, ['Chờ khám','Đang khám','Hoàn tất','Hủy'], true)) $status = 'Chờ khám';

            if ($appointment_date_only === '' || $appointment_time === '') {
                if ($appointment_date !== '') {
                    $tmp = strtotime($appointment_date);
                    if ($tmp) {
                        $appointment_date_only = date('Y-m-d', $tmp);
                        $appointment_time = date('H:i', $tmp);
                    }
                }
            }

            if ($appointment_id<=0 || $patient_id<=0 || $department_id<=0 || $doctor_id<=0 || $appointment_date_only==='' || $appointment_time==='') {
                echo json_encode(['success'=>false,'message'=>'Thiếu dữ liệu cập nhật!']);
                exit;
            }
            if (!is_valid_date($appointment_date_only) || !is_valid_time_hm($appointment_time)) {
                echo json_encode(['success'=>false,'message'=>'Ngày hoặc giờ khám không hợp lệ!']);
                exit;
            }
            if ($symptoms==='') {
                echo json_encode(['success'=>false,'message'=>'Vui lòng nhập triệu chứng!']);
                exit;
            }

            [$okRule, $msgRule] = validateRulesOffline($conn, $doctor_id, $department_id, $appointment_date_only, $appointment_time, $appointment_id);
            if (!$okRule) {
                echo json_encode(['success'=>false,'message'=>$msgRule]);
                exit;
            }

            $fullDateTime = $appointment_date_only . ' ' . $appointment_time . ':00';
            $stmt = $conn->prepare("UPDATE appointments SET patient_id=:pid, department_id=:dep, doctor_id=:doc, appointment_date=:dt, symptoms=:sym, status=:st WHERE appointment_id=:id");
            $stmt->execute([
                'id'=>$appointment_id,
                'pid'=>$patient_id,
                'dep'=>$department_id,
                'doc'=>$doctor_id,
                'dt'=>$fullDateTime,
                'sym'=>$symptoms,
                'st'=>$status
            ]);

            echo json_encode(['success'=>true,'message'=>'Cập nhật lịch khám thành công!']);
            exit;
        }

        // CHANGE STATUS QUICK
        if ($action === 'change_status') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if ($appointment_id <= 0) {
                echo json_encode(['success'=>false,'message'=>'appointment_id không hợp lệ!']);
                exit;
            }
            if (!in_array($status, ['Chờ khám','Đang khám','Hoàn tất','Hủy'], true)) {
                echo json_encode(['success'=>false,'message'=>'Trạng thái không hợp lệ!']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE appointments SET status=:st WHERE appointment_id=:id");
            $stmt->execute(['st'=>$status,'id'=>$appointment_id]);
            echo json_encode(['success'=>true,'message'=>'Đã cập nhật trạng thái.']);
            exit;
        }

        // CANCEL APPOINTMENT (không xóa DB)
        if ($action === 'cancel') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            if ($appointment_id <= 0) {
                echo json_encode(['success'=>false,'message'=>'appointment_id không hợp lệ!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT status FROM appointments WHERE appointment_id = :id LIMIT 1");
            $stmt->execute(['id' => $appointment_id]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                echo json_encode(['success'=>false,'message'=>'Không tìm thấy lịch khám!']);
                exit;
            }

            if ($current === 'Hủy') {
                echo json_encode(['success'=>false,'message'=>'Lịch khám này đã ở trạng thái Hủy.']);
                exit;
            }

            $stmt = $conn->prepare("UPDATE appointments SET status = 'Hủy' WHERE appointment_id = :id");
            $stmt->execute(['id'=>$appointment_id]);

            echo json_encode(['success'=>true,'message'=>'Đã hủy lịch khám!']);
            exit;
        }

        echo json_encode(['success'=>false,'message'=>'Action không hợp lệ!']);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB Error: '.$e->getMessage()]);
        exit;
    }
}

/* =========================
   PAGE DATA
========================= */
try {
    $patients = $conn->query("
        SELECT patient_id, full_name, phone
        FROM patients
        WHERE is_deleted = 0
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $patients = [];
}

try {
    $departments = $conn->query("
        SELECT department_id, department_name
        FROM departments
        ORDER BY department_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $departments = [];
}

try {
    $hasSpecialty = dbColumnExists($conn, 'doctors', 'specialty');

    if ($hasSpecialty) {
        $sqlDoctors = "
            SELECT d.doctor_id, d.full_name, d.department_id, d.specialty, d.status, d.is_deleted,
                   dp.department_name AS doctor_department_name
            FROM doctors d
            LEFT JOIN departments dp ON d.department_id = dp.department_id
            WHERE d.is_deleted = 0
            ORDER BY d.full_name ASC
        ";
    } else {
        $sqlDoctors = "
            SELECT d.doctor_id, d.full_name, d.department_id, '' AS specialty, d.status, d.is_deleted,
                   dp.department_name AS doctor_department_name
            FROM doctors d
            LEFT JOIN departments dp ON d.department_id = dp.department_id
            WHERE d.is_deleted = 0
            ORDER BY d.full_name ASC
        ";
    }

    $allDoctors = $conn->query($sqlDoctors)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $allDoctors = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1.0" />
<title>Admin - Quản lý lịch khám</title>
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
}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--text)}

.header{
    background:linear-gradient(135deg,var(--primary) 0%, var(--primary2) 100%);
    color:#fff;padding:18px 22px;
    display:flex;justify-content:space-between;align-items:center;gap:14px;
    box-shadow:0 2px 10px rgba(0,0,0,0.10);
}
.header-left h1{font-size:20px;margin-bottom:4px}
.header-left p{opacity:.92;font-size:13px}
.header-right{display:flex;align-items:center;gap:12px}
.avatar{
    width:44px;height:44px;border-radius:999px;
    background:rgba(255,255,255,0.95);
    color:var(--primary);
    display:grid;place-items:center;font-weight:900;font-size:18px;
}
.meta strong{display:block;font-size:14px}
.meta span{font-size:12px;opacity:.92}
.logout{
    text-decoration:none;
    background:rgba(255,255,255,0.18);
    color:#fff;border:1px solid rgba(255,255,255,0.25);
    padding:9px 12px;border-radius:12px;
    font-weight:900;font-size:13px;
    display:inline-flex;align-items:center;gap:8px;
    transition:background .2s;
    white-space:nowrap;
}
.logout:hover{background:rgba(255,255,255,0.26)}

.layout{
    max-width:1200px;margin:0 auto;padding:22px;
    display:grid;grid-template-columns:260px 1fr;gap:18px;
}
@media(max-width:980px){.layout{grid-template-columns:1fr}}

.sidebar{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    height:fit-content;
    position:sticky;top:16px;
}
@media(max-width:980px){.sidebar{position:static}}

.nav-title{
    font-size:12px;font-weight:900;color:var(--muted);
    letter-spacing:.6px;text-transform:uppercase;
    padding:8px 10px 12px;
}
.nav{list-style:none;display:grid;gap:6px}
.nav-item{
    padding:12px 12px;border-radius:14px;
    border:1px solid transparent;
    cursor:pointer;
    display:flex;align-items:center;gap:10px;
    transition:.2s;
    user-select:none;
}
.nav-item:hover{background:#f8fafc;border-color:var(--line)}
.nav-item.active{background:#f0f2ff;border-color:rgba(102,126,234,0.25);color:var(--primary);font-weight:900}
.icon{width:22px;text-align:center;font-size:18px}

.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
}
.card-head{
    padding:16px 18px;
    display:flex;justify-content:space-between;align-items:center;gap:12px;
    border-bottom:1px solid var(--line);
    flex-wrap:wrap;
}
.card-head h2{font-size:16px;font-weight:900}
.sub{color:var(--muted);font-size:13px;margin-top:4px}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:9px 12px;border-radius:12px;
    font-weight:900;font-size:13px;text-decoration:none;
    border:1px solid transparent;
    cursor:pointer;
    white-space:nowrap;
}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{filter:brightness(.97)}
.btn-light{background:#f9fafb;color:#111827;border-color:#e5e7eb}
.btn-light:hover{background:#f3f4f6}
.btn-danger{background:#dc2626;color:#fff;border-color:#dc2626}
.btn-danger:hover{filter:brightness(.98)}

.search{
    padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;
    font-weight:700;font-size:13px;min-width:260px;
}
.search:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}

.select, .date{
    padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;
    font-weight:800;font-size:13px;background:#fff;
}
.select:focus, .date:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}

.content-grid{
    display:grid;
    grid-template-columns: 1fr 360px;
    gap:18px;
    align-items:start;
    padding:18px;
}
@media(max-width:1050px){.content-grid{grid-template-columns:1fr}}

.panel{
    background:#fff;
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
}
.panel-head{
    padding:14px 14px;
    border-bottom:1px solid var(--line);
    display:flex;justify-content:space-between;align-items:center;gap:10px;
}
.panel-head strong{font-size:14px}

.table-wrap{width:100%;overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{
    padding:12px 14px;background:#f8fafc;color:#374151;font-size:12px;font-weight:900;
    border-bottom:1px solid var(--line);white-space:nowrap;text-align:left;
}
tbody td{
    padding:14px;border-bottom:1px solid #f1f5f9;
    vertical-align:middle;font-size:14px;
}
tbody tr:hover{background:#fafafa}
tbody tr{cursor:pointer}
tbody tr.selected{background:#f0f2ff !important}

.muted{color:var(--muted);font-size:12px;font-weight:800}
.hint{color:var(--muted);font-size:13px;line-height:1.45;font-weight:800}

.pill{
    display:inline-flex;align-items:center;gap:8px;
    padding:6px 10px;border-radius:999px;
    font-size:12px;font-weight:900;
    border:1px solid #e5e7eb;
    background:#fff;color:#374151;
}
.pill.pending{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
.pill.doing{background:#eef2ff;color:#3730a3;border-color:#c7d2fe}
.pill.done{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
.pill.cancel{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.pill.unknown{background:#f3f4f6;color:#374151;border-color:#e5e7eb}

.kv{display:grid;gap:10px;margin-top:10px}
.kv .row{
    display:flex;justify-content:space-between;gap:10px;
    border:1px solid #f1f5f9;border-radius:12px;
    padding:10px 12px;background:#fff;
}
.k{color:var(--muted);font-weight:900;font-size:12px}
.v{font-weight:900;font-size:13px;text-align:right;max-width:220px;word-break:break-word}

.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}

.tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.tab{
    padding:8px 10px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;
    font-weight:900;font-size:13px;cursor:pointer;
}
.tab.active{border-color:rgba(102,126,234,0.35);background:#f0f2ff;color:var(--primary)}

.box{
    border:1px solid #eef2f7;border-radius:12px;padding:10px;background:#fff;margin-top:10px;
}
.box .title{font-weight:900;margin-bottom:8px}
.box .meta2{color:var(--muted);font-weight:800;font-size:12px}
.box .desc{margin-top:8px;color:#111827;font-weight:800;white-space:pre-wrap;line-height:1.55}
.rxrow{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:8px 0;border-bottom:1px dashed #e5e7eb}
.rxrow:last-child{border-bottom:none}

.notice{padding:12px;border-radius:14px;border:1px dashed #e5e7eb;background:#fafafa;color:#374151;font-weight:800;line-height:1.6;margin-top:10px}

.modal-backdrop{
    position:fixed;inset:0;background:rgba(17,24,39,0.45);
    display:none;align-items:center;justify-content:center;z-index:1000;
    padding:18px;
}
.modal{
    width:min(100%, 1080px);max-width:1080px;background:#fff;border-radius:18px;overflow:hidden;
    box-shadow:0 18px 50px rgba(0,0,0,0.2);max-height:calc(100vh - 36px);display:flex;flex-direction:column;
}
.modal-head{
    padding:16px 18px;border-bottom:1px solid var(--line);
    display:flex;justify-content:space-between;align-items:center;gap:10px;
}
.modal-head h3{font-size:16px;font-weight:900}
.modal-body{padding:16px 18px;overflow:auto;max-height:calc(100vh - 180px);flex:1}
.modal-foot{
    padding:14px 18px;border-top:1px solid var(--line);
    display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:#fff;
    position:sticky;bottom:0;z-index:2;
}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:560px){.form-grid{grid-template-columns:1fr}}
.field{margin-bottom:12px}
label{display:block;font-size:12px;font-weight:900;color:#374151;margin-bottom:6px}
input,select,textarea{
    width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;font-size:14px;font-weight:700;
}
textarea{min-height:90px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}

.toast-wrap{position:fixed;top:16px;right:16px;z-index:1100;display:grid;gap:10px}
.toast{
    min-width:260px;max-width:360px;
    padding:12px 14px;border-radius:14px;color:#fff;
    box-shadow:0 12px 30px rgba(0,0,0,0.18);
    display:flex;gap:10px;align-items:flex-start;
    opacity:0;transform:translateY(-6px);
    transition:.2s;
    font-weight:800;
}
.toast.show{opacity:1;transform:translateY(0)}
.toast.success{background:#16a34a}
.toast.error{background:#dc2626}
.toast .small{font-size:12px;font-weight:700;opacity:.92}
.results{margin-top:10px;border:1px solid var(--line);border-radius:12px;overflow:hidden;display:none}
.res-item{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border-top:1px solid var(--line);background:#fff}
.res-item:first-child{border-top:none}
.res-main{font-weight:900}
.res-sub{font-size:12px;color:var(--muted);font-weight:800;margin-top:2px}
.mini{padding:8px 10px;border-radius:12px;font-weight:900;font-size:12px;border:1px solid var(--line);cursor:pointer;display:inline-flex;align-items:center;gap:6px;background:#fff}
.mini.blue{background:#eff6ff;color:#1d4ed8}
.mini.green{background:#ecfdf5;color:#166534}
.mini.gray{background:#f3f4f6;color:#111827}
.patient-create{margin-top:12px;border:1px dashed var(--line);border-radius:14px;padding:12px;background:#fafafa;display:none}
.patient-summary{margin-top:10px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:#f8fafc;display:none}
.patient-summary .ps-name{font-size:15px;font-weight:900}
.patient-summary .ps-sub{font-size:12px;color:var(--muted);font-weight:800;margin-top:4px}
.patient-create .title{font-weight:1000;margin-bottom:10px;display:flex;align-items:center;gap:8px}
</style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <h1>📅 Quản lý lịch khám</h1>
    </div>
    <div class="header-right">
        <div class="avatar"><?php echo h($admin_initial); ?></div>
        <div class="meta">
            <strong><?php echo h($admin_name); ?></strong>
            <span><?php echo h($admin_email); ?></span>
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
            <li class="nav-item" onclick="window.location.href='patients.php'"><span class="icon">👥</span>Bệnh nhân</li>
            <li class="nav-item active" onclick="window.location.href='appointments.php'"><span class="icon">📅</span>Lịch khám</li>
            <li class="nav-item" onclick="window.location.href='receptionists.php'"><span class="icon">👩‍💼</span>Lễ tân</li>
        </ul>
    </aside>

    <main>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Danh sách lịch khám</h2>
                    <div class="sub">Chọn 1 dòng để xem chi tiết + kết quả khám + đơn thuốc</div>
                </div>

                <div class="toolbar">
                    <input id="searchInput" class="search" placeholder="Tìm theo mã / bệnh nhân / SĐT / bác sĩ / khoa..." />
                    <select id="statusFilter" class="select" title="Lọc trạng thái">
                        <option value="">Tất cả trạng thái</option>
                        <option value="Chờ khám">Chờ khám</option>
                        <option value="Đang khám">Đang khám</option>
                        <option value="Hoàn tất">Hoàn tất</option>
                        <option value="Hủy">Hủy</option>
                    </select>
                    <input id="fromDate" class="date" type="date" title="Từ ngày" />
                    <input id="toDate" class="date" type="date" title="Đến ngày" />
                    <button class="btn btn-light" onclick="quickToday()">Hôm nay</button>
                    <button class="btn btn-primary" onclick="showAddModal()">➕ Thêm lịch</button>
                </div>
            </div>

            <div class="content-grid">
                <div class="panel">
                    <div class="panel-head">
                        <strong>Danh sách</strong>
                        <span class="muted" id="countText">0 lịch</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:90px">Mã</th>
                                    <th>Bệnh nhân</th>
                                    <th style="width:140px">Ngày khám</th>
                                    <th style="width:140px">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody id="apptBody">
                                <tr>
                                    <td colspan="4" style="padding:18px;color:#6b7280;font-weight:800;text-align:center">
                                        ⏳ Đang tải...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel" id="detailPanel">
                    <div class="panel-head">
                        <strong>Chi tiết lịch khám</strong>
                        <button class="btn btn-light" onclick="clearSelection()">Bỏ chọn</button>
                    </div>
                    <div class="panel-body" style="padding:14px">
                        <div class="hint" id="detailHint">
                            Chọn 1 lịch ở danh sách để xem chi tiết và thao tác.
                        </div>

                        <div id="detailContent" style="display:none">
                            <div class="kv">
                                <div class="row"><div class="k">Mã</div><div class="v" id="d_id"></div></div>
                                <div class="row"><div class="k">Bệnh nhân</div><div class="v" id="d_patient"></div></div>
                                <div class="row"><div class="k">SĐT</div><div class="v" id="d_phone"></div></div>
                                <div class="row"><div class="k">Ngày sinh</div><div class="v" id="d_dob"></div></div>
                                <div class="row"><div class="k">Bác sĩ</div><div class="v" id="d_doctor"></div></div>
                                <div class="row"><div class="k">Khoa</div><div class="v" id="d_dept"></div></div>
                                <div class="row"><div class="k">Ngày khám</div><div class="v" id="d_time"></div></div>
                                <div class="row"><div class="k">Trạng thái</div><div class="v" id="d_status"></div></div>
                            </div>

                            <div class="actions">
                                <button type="button" class="btn btn-primary" onclick="editSelected()">✏️ Sửa</button>
                                <button type="button" class="btn btn-danger" onclick="cancelSelected()">🚫 Hủy lịch</button>
                            </div>

                            <div class="tabs">
                                <button class="tab active" onclick="switchTab('info')">📌 Triệu chứng</button>
                                <button class="tab" onclick="switchTab('record')">📋 Kết quả + Đơn thuốc</button>
                            </div>

                            <div id="tab-info">
                                <div class="box">
                                    <div class="title">🩺 Triệu chứng</div>
                                    <div class="desc" id="d_symptoms"></div>
                                </div>
                            </div>

                            <div id="tab-record" style="display:none">
                                <div id="recordWrap"></div>
                                <div id="rxWrap"></div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </section>
    </main>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<div class="modal-backdrop" id="formModal">
    <div class="modal">
        <div class="modal-head">
            <h3 id="formTitle">Thêm lịch khám</h3>
            <button class="btn btn-light" onclick="closeForm()">✖️ Đóng</button>
        </div>

        <form id="apptForm" class="modal-body" autocomplete="off">
            <input type="hidden" name="appointment_id" id="appointment_id" />
            <input type="hidden" name="patient_id" id="patient_id" value="0" />

            <div class="field" id="addPatientArea">
                <label>Tìm bệnh nhân (SĐT hoặc Tên hoặc Mã BN)</label>
                <input id="patientSearch" type="text" placeholder="Nhập SĐT hoặc tên...">

                <div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <button type="button" class="mini green" id="btnShowCreate"><span>👤</span> Tạo mới bệnh nhân</button>
                    <button type="button" class="mini gray" id="btnClearPick" style="display:none;"><span>✖️</span> Bỏ chọn</button>
                    <div class="pill" id="pickedPill" style="display:none;">
                        Đã chọn: <b id="pickedText"></b>
                    </div>
                </div>

                <div class="results" id="searchResults"></div>

                <div class="patient-create" id="createBox">
                    <div class="title">➕ Thêm bệnh nhân mới (tự lưu khi bấm Lưu lịch hẹn)</div>
                    <div class="form-grid">
                        <div class="field">
                            <label>Họ tên *</label>
                            <input name="new_full_name" id="new_full_name" type="text" placeholder="Nhập họ tên">
                        </div>
                        <div class="field">
                            <label>SĐT *</label>
                            <input name="new_phone" id="new_phone" type="text" placeholder="Nhập số điện thoại">
                        </div>
                        <div class="field">
                            <label>Ngày sinh *</label>
                            <input name="new_dob" id="new_dob" type="date">
                        </div>
                        <div class="field">
                            <label>Giới tính</label>
                            <select name="new_gender" id="new_gender">
                                <option value="">-- Chọn --</option>
                                <option value="Male">Nam</option>
                                <option value="Female">Nữ</option>
                            </select>
                        </div>
                        <div class="field" style="grid-column:1/-1;">
                            <label>Địa chỉ</label>
                            <input name="new_address" id="new_address" type="text" placeholder="Nhập địa chỉ">
                        </div>
                    </div>
                    <div style="margin-top:10px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                        <button type="button" class="mini gray" id="btnHideCreate">Ẩn</button>
                    </div>
                </div>
            </div>

            <div class="field" id="editPatientArea" style="display:none;">
                <label>Thông tin bệnh nhân</label>
                <div class="patient-summary" id="editPatientSummary">
                    <div class="ps-name" id="editPatientName"></div>
                    <div class="ps-sub" id="editPatientMeta"></div>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Khoa *</label>
                    <select name="department_id" id="department" required>
                        <option value="">Chọn khoa</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo (int)$d['department_id']; ?>">
                                <?php echo h($d['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Bác sĩ *</label>
                    <select name="doctor_id" id="doctor" required>
                        <option value="">Chọn bác sĩ</option>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Ngày khám *</label>
                    <input type="date" name="appointment_date_only" id="appointment_date_only" required />
                </div>
                <div class="field">
                    <label>Giờ khám *</label>
                    <input type="time" name="appointment_time" id="appointment_time" required />
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Trạng thái</label>
                    <select name="status" id="status">
                        <option value="Chờ khám">Chờ khám</option>
                        <option value="Đang khám">Đang khám</option>
                        <option value="Hoàn tất">Hoàn tất</option>
                        <option value="Hủy">Hủy</option>
                    </select>
                </div>
                <div class="field">
                    <label>Khung giờ làm việc</label>
                    <input value="08:00–12:00 | 13:30–17:00" disabled />
                </div>
            </div>

            <div class="field">
                <label>Triệu chứng *</label>
                <textarea name="symptoms" id="symptoms" required placeholder="VD: sốt, ho, đau họng..."></textarea>
            </div>
        </form>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeForm()">Hủy</button>
            <button class="btn btn-primary" onclick="submitApptForm()">💾 Lưu</button>
        </div>
    </div>
</div>

<script>
const DEPARTMENT_MAP = <?php
    $departmentMap = [];
    foreach ($departments as $d) {
        $departmentMap[(string)$d['department_id']] = $d['department_name'];
    }
    echo json_encode($departmentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

const ALL_DOCTORS = <?php
    echo json_encode($allDoctors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

function toast(message, type='success'){}

function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[m]));
}

function formatDateTime(iso){
    if (!iso) return 'N/A';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('vi-VN');
}
function toDatetimeLocalValue(iso){
    if (!iso) return '';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const pad = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

let selectedId = null;
let selectedCache = null;
let listCache = [];

function switchTab(which){
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    const tabs = document.querySelectorAll('.tab');
    if (which === 'info') tabs[0].classList.add('active');
    if (which === 'record') tabs[1].classList.add('active');

    document.getElementById('tab-info').style.display = (which === 'info') ? '' : 'none';
    document.getElementById('tab-record').style.display = (which === 'record') ? '' : 'none';
}

const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');
const fromDate = document.getElementById('fromDate');
const toDate = document.getElementById('toDate');

[searchInput, statusFilter, fromDate, toDate].forEach(el => {
    el.addEventListener('input', debounce(loadList, 250));
    el.addEventListener('change', debounce(loadList, 250));
});

function quickToday(){
    const now = new Date();
    const pad = n => String(n).padStart(2,'0');
    const y = now.getFullYear(), m = pad(now.getMonth()+1), d = pad(now.getDate());
    fromDate.value = `${y}-${m}-${d}`;
    toDate.value = `${y}-${m}-${d}`;
    loadList();
}

function debounce(fn, ms){
    let t;
    return function(){
        clearTimeout(t);
        t = setTimeout(() => fn(), ms);
    }
}

function pillClass(st){
    st = String(st||'');
    if (st === 'Chờ khám') return 'pending';
    if (st === 'Đang khám') return 'doing';
    if (st === 'Hoàn tất') return 'done';
    if (st === 'Hủy') return 'cancel';
    return 'unknown';
}
function pillHtml(st){
    const cls = pillClass(st);
    return `<span class="pill ${cls}">${escapeHtml(st || 'N/A')}</span>`;
}

function loadList(){
    const q = searchInput.value.trim();
    const st = statusFilter.value;
    const from = fromDate.value;
    const to = toDate.value;

    const body = new URLSearchParams();
    body.set('q', q);
    body.set('status', st);
    body.set('from', from);
    body.set('to', to);

    fetch('appointments.php?action=list', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
    })
    .then(r => r.json())
    .then(res => {
        const tbody = document.getElementById('apptBody');
        tbody.innerHTML = '';

        if (!res.success) {
            tbody.innerHTML = `<tr><td colspan="4" style="padding:18px;text-align:center;color:#6b7280;font-weight:800">⚠️ Không lấy được dữ liệu</td></tr>`;
            return;
        }

        listCache = res.rows || [];
        document.getElementById('countText').textContent = `${listCache.length} lịch`;

        if (!listCache.length) {
            tbody.innerHTML = `<tr><td colspan="4" style="padding:18px;text-align:center;color:#6b7280;font-weight:800">📭 Chưa có lịch khám</td></tr>`;
            clearSelection();
            return;
        }

        listCache.forEach(x => {
            const id = x.appointment_id;
            const isSel = (Number(selectedId) === Number(id));
            const dt = formatDateTime(x.appointment_date);
            const p = x.patient_name || 'N/A';
            tbody.insertAdjacentHTML('beforeend', `
                <tr id="row-${escapeHtml(id)}" class="${isSel ? 'selected':''}" onclick="selectRow(${Number(id)})">
                    <td><strong>#${escapeHtml(id)}</strong></td>
                    <td title="${escapeHtml(p)}">
                        <strong>${escapeHtml(p)}</strong>
                        <div class="muted">${escapeHtml(x.doctor_name || 'N/A')} • ${escapeHtml(x.department_name || 'N/A')}</div>
                    </td>
                    <td title="${escapeHtml(dt)}">${escapeHtml(dt)}</td>
                    <td>${pillHtml(x.status)}</td>
                </tr>
            `);
        });

        if (selectedId && !listCache.some(x => Number(x.appointment_id) === Number(selectedId))) {
            clearSelection();
        }
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
}

function clearSelection(){
    selectedId = null;
    selectedCache = null;
    document.querySelectorAll('#apptBody tr').forEach(x => x.classList.remove('selected'));
    document.getElementById('detailHint').style.display = 'block';
    document.getElementById('detailContent').style.display = 'none';
}

function selectRow(id){
    selectedId = id;
    document.querySelectorAll('#apptBody tr').forEach(x => x.classList.remove('selected'));
    const row = document.getElementById('row-' + id);
    if (row) row.classList.add('selected');

    switchTab('info');

    fetch('appointments.php?action=detail', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`appointment_id=${encodeURIComponent(id)}`
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            toast(res.message || 'Không lấy được chi tiết!', 'error');
            return;
        }
        selectedCache = res.data;

        const a = res.data.appointment || {};
        document.getElementById('detailHint').style.display = 'none';
        document.getElementById('detailContent').style.display = 'block';

        document.getElementById('d_id').textContent = '#' + (a.appointment_id || '');
        document.getElementById('d_patient').textContent = a.patient_name || 'N/A';
        document.getElementById('d_phone').textContent = a.patient_phone || 'N/A';
        document.getElementById('d_dob').textContent = a.patient_dob ? new Date(a.patient_dob).toLocaleDateString('vi-VN') : 'N/A';
        document.getElementById('d_doctor').textContent = a.doctor_name || 'N/A';
        document.getElementById('d_dept').textContent = a.department_name || 'N/A';
        document.getElementById('d_time').textContent = formatDateTime(a.appointment_date);
        document.getElementById('d_status').innerHTML = pillHtml(a.status || 'N/A');
        document.getElementById('d_symptoms').textContent = a.symptoms || 'N/A';

        renderRecordRx(res.data.record, res.data.prescriptions || []);
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
}

function renderRecordRx(r, rxs){
    const recordWrap = document.getElementById('recordWrap');
    const rxWrap = document.getElementById('rxWrap');
    recordWrap.innerHTML = '';
    rxWrap.innerHTML = '';

    if (!r) {
        recordWrap.innerHTML = `<div class="notice">Chưa có kết quả khám (medical_records) cho lịch này.</div>`;
        rxWrap.innerHTML = `<div class="notice">Chưa có đơn thuốc vì chưa có kết quả khám.</div>`;
        return;
    }

    recordWrap.innerHTML = `
        <div class="box">
            <div class="title">📋 Kết quả khám</div>
            <div class="meta2">${escapeHtml(r.examination_date || r.created_at || '')}${r.doctor_name ? (' • 👨‍⚕️ ' + escapeHtml(r.doctor_name)) : ''}</div>
            <div class="desc">
<b>Chẩn đoán:</b> ${escapeHtml(r.diagnosis || 'N/A')}
<b>Phác đồ:</b> ${escapeHtml(r.treatment_plan || 'N/A')}
<b>Ghi chú:</b> ${escapeHtml(r.notes || 'N/A')}
            </div>
            <div class="meta2" style="margin-top:8px">
                HA: ${escapeHtml(r.blood_pressure || 'N/A')} •
                Nhịp tim: ${escapeHtml(r.heart_rate ?? 'N/A')} •
                Nhiệt: ${escapeHtml(r.temperature ?? 'N/A')} •
                Cao: ${escapeHtml(r.height ?? 'N/A')} •
                Nặng: ${escapeHtml(r.weight ?? 'N/A')}
            </div>
        </div>
    `;

    if (!rxs.length) {
        rxWrap.innerHTML = `<div class="notice">Chưa có đơn thuốc cho kết quả khám này.</div>`;
        return;
    }

    let html = `<div class="box"><div class="title">🧾 Đơn thuốc</div>`;
    rxs.forEach(x => {
        const med = x.medication_name ? x.medication_name : ('Thuốc #' + (x.medication_id ?? ''));
        const unit = x.unit ? `(${x.unit})` : '';
        html += `
            <div class="rxrow">
                <div>
                    <div><b>${escapeHtml(med)}</b> ${escapeHtml(unit)}</div>
                    ${x.dosage ? `<div class="meta2">${escapeHtml(x.dosage)}</div>` : ``}
                    ${x.instructions ? `<div class="meta2">${escapeHtml(x.instructions)}</div>` : ``}
                </div>
                <div><b>SL:</b> ${escapeHtml(x.quantity ?? '')}</div>
            </div>
        `;
    });
    html += `</div>`;
    rxWrap.innerHTML = html;
}

const formModal = document.getElementById('formModal');
const deptSel = document.getElementById('department');
const docSel  = document.getElementById('doctor');
const patientSearch = document.getElementById('patientSearch');
const searchResults = document.getElementById('searchResults');
const patientIdInput = document.getElementById('patient_id');
const pickedPill = document.getElementById('pickedPill');
const pickedText = document.getElementById('pickedText');
const btnClearPick = document.getElementById('btnClearPick');
const createBox = document.getElementById('createBox');
const btnShowCreate = document.getElementById('btnShowCreate');
const btnHideCreate = document.getElementById('btnHideCreate');
const appointmentDateOnly = document.getElementById('appointment_date_only');
const appointmentTime = document.getElementById('appointment_time');
const addPatientArea = document.getElementById('addPatientArea');
const editPatientArea = document.getElementById('editPatientArea');
const editPatientSummary = document.getElementById('editPatientSummary');
const editPatientName = document.getElementById('editPatientName');
const editPatientMeta = document.getElementById('editPatientMeta');

function pad2(n){ return String(n).padStart(2,'0'); }
function splitDateTimeValue(iso){
    if (!iso) return {date:'', time:''};
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return {date:'', time:''};
    return {
        date: `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`,
        time: `${pad2(d.getHours())}:${pad2(d.getMinutes())}`
    };
}
function setMinDateTime(){
    const now = new Date();
    const today = `${now.getFullYear()}-${pad2(now.getMonth()+1)}-${pad2(now.getDate())}`;
    appointmentDateOnly.min = today;
    if (!appointmentDateOnly.value) appointmentDateOnly.value = today;
    if (appointmentDateOnly.value === today) {
        appointmentTime.min = `${pad2(now.getHours())}:${pad2(now.getMinutes())}`;
    } else {
        appointmentTime.min = '00:00';
    }
}
function showPicked(p){
    patientIdInput.value = p.patient_id;
    pickedText.textContent = `#${p.patient_id} - ${p.full_name || ''} - ${p.phone || ''}`;
    pickedPill.style.display = 'inline-flex';
    btnClearPick.style.display = 'inline-flex';
    createBox.style.display = 'none';
}
function showEditPatientInfo(p){
    patientIdInput.value = p.patient_id || '';
    editPatientName.textContent = `#${p.patient_id || ''} - ${p.full_name || 'N/A'}`;
    const parts = [];
    if (p.phone) parts.push(`SĐT: ${p.phone}`);
    if (p.date_of_birth) parts.push(`Ngày sinh: ${new Date(p.date_of_birth).toLocaleDateString('vi-VN')}`);
    if (p.gender) parts.push(`Giới tính: ${p.gender}`);
    editPatientMeta.textContent = parts.join(' • ');
    editPatientSummary.style.display = 'block';
}
function clearPicked(){
    patientIdInput.value = '0';
    pickedPill.style.display = 'none';
    btnClearPick.style.display = 'none';
    pickedText.textContent = '';
}
function clearEditPatientInfo(){
    editPatientName.textContent = '';
    editPatientMeta.textContent = '';
    editPatientSummary.style.display = 'none';
}
function clearCreatePatientFields(){
    ['new_full_name','new_phone','new_dob','new_gender','new_address'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.tagName === 'SELECT') el.value = '';
        else el.value = '';
    });
}
function showCreateBox(prefill=''){
    clearPicked();
    createBox.style.display = 'block';
    const q = (prefill || patientSearch.value || '').trim();
    if (q) {
        const phoneLike = /^[+\d][\d\s]{7,20}$/.test(q);
        if (phoneLike && !document.getElementById('new_phone').value) document.getElementById('new_phone').value = q.replace(/\s+/g, '');
        if (!phoneLike && !document.getElementById('new_full_name').value) document.getElementById('new_full_name').value = q;
    }
}
function hideCreateBox(){
    createBox.style.display = 'none';
    clearCreatePatientFields();
}
btnShowCreate.addEventListener('click', () => showCreateBox());
btnHideCreate.addEventListener('click', hideCreateBox);
btnClearPick.addEventListener('click', clearPicked);

let patientSearchTimer = null;
patientSearch.addEventListener('input', () => {
    clearTimeout(patientSearchTimer);
    patientSearchTimer = setTimeout(runPatientSearch, 220);
});

function runPatientSearch(){
    const q = patientSearch.value.trim();
    if (!q) {
        searchResults.style.display = 'none';
        searchResults.innerHTML = '';
        return;
    }
    fetch('appointments.php?action=search_patient', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`q=${encodeURIComponent(q)}`
    })
    .then(r => r.json())
    .then(res => {
        const items = res?.items || [];
        if (!items.length) {
            searchResults.innerHTML = `
                <div class="res-item">
                    <div>
                        <div class="res-main">Không tìm thấy</div>
                        <div class="res-sub">Bạn có thể bấm “Tạo mới bệnh nhân”.</div>
                    </div>
                    <div><button type="button" class="mini green" onclick="showCreateBox('${escapeHtml(q).replace(/'/g, '&#039;')}')">Tạo mới</button></div>
                </div>`;
            searchResults.style.display = 'block';
            return;
        }
        searchResults.innerHTML = items.map(p => {
            const safe = JSON.stringify(p).replace(/</g, '\\u003c');
            return `
                <div class="res-item">
                    <div>
                        <div class="res-main">${escapeHtml('#' + p.patient_id + ' - ' + (p.full_name || ''))}</div>
                        <div class="res-sub">SĐT: ${escapeHtml(p.phone || '')} • NS: ${escapeHtml(p.date_of_birth || '-')} • GT: ${escapeHtml(p.gender || '-')}</div>
                    </div>
                    <div><button type="button" class="mini blue" onclick='window.__pickPatient(${safe})'>Chọn</button></div>
                </div>`;
        }).join('');
        searchResults.style.display = 'block';
    })
    .catch(() => toast('Không tìm được bệnh nhân!', 'error'));
}
window.__pickPatient = (p) => {
    showPicked(p);
    searchResults.style.display = 'none';
    patientSearch.value = '';
};

function showAddModal(){
    document.getElementById('formTitle').textContent = 'Thêm lịch khám';
    addPatientArea.style.display = '';
    editPatientArea.style.display = 'none';
    document.getElementById('apptForm').reset();
    document.getElementById('appointment_id').value = '';
    patientIdInput.value = '0';
    clearPicked();
    clearCreatePatientFields();
    hideCreateBox();
    patientSearch.value = '';
    searchResults.style.display = 'none';
    searchResults.innerHTML = '';
    clearEditPatientInfo();
    docSel.innerHTML = `<option value="">Chọn bác sĩ</option>`;
    setMinDateTime();
    const now = new Date();
    appointmentTime.value = `${pad2(now.getHours())}:${pad2(now.getMinutes())}`;
    formModal.style.display = 'flex';
}
function closeForm(){ formModal.style.display = 'none'; }
function submitApptForm(){ document.getElementById('apptForm').requestSubmit(); }

function normalizeText(str){
    return String(str || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim();
}

function renderDoctorOptions(rows, selectedDoctorId=null, placeholder='Chọn bác sĩ'){
    docSel.innerHTML = `<option value="">${placeholder}</option>`;
    rows.forEach(x => {
        const opt = document.createElement('option');
        opt.value = String(x.doctor_id || '');
        opt.textContent = x.full_name || 'Không rõ';
        docSel.appendChild(opt);
    });
    if (selectedDoctorId) docSel.value = String(selectedDoctorId);
}

function getDoctorsForDepartmentLocal(deptId){
    const deptName = normalizeText(DEPARTMENT_MAP[String(deptId)] || '');
    const matched = ALL_DOCTORS.filter(x => {
        const doctorDeptId = String(x.department_id || '');
        const specialty = normalizeText(x.specialty || '');
        const doctorDeptName = normalizeText(x.doctor_department_name || '');
        return doctorDeptId === String(deptId)
            || (deptName && specialty.includes(deptName))
            || (deptName && doctorDeptName.includes(deptName));
    });
    return matched;
}

function uniqueDoctors(rows){
    const seen = new Set();
    return (rows || []).filter(x => {
        const key = String(x.doctor_id || '');
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

function appendFallbackSelectedDoctor(selectedDoctorId, fallbackDoctorName=''){
    if (selectedDoctorId && ![...docSel.options].some(o => String(o.value) === String(selectedDoctorId))) {
        const opt = document.createElement('option');
        opt.value = String(selectedDoctorId);
        opt.textContent = fallbackDoctorName || ('Bác sĩ #' + selectedDoctorId);
        docSel.appendChild(opt);
        docSel.value = String(selectedDoctorId);
    }
}

function loadDoctorsByDepartment(deptId, selectedDoctorId=null, fallbackDoctorName=''){
    if (!deptId) {
        renderDoctorOptions([], null, 'Chọn bác sĩ');
        return Promise.resolve();
    }

    let rows = uniqueDoctors(getDoctorsForDepartmentLocal(deptId));
    if (rows.length) {
        renderDoctorOptions(rows, selectedDoctorId, 'Chọn bác sĩ');
        appendFallbackSelectedDoctor(selectedDoctorId, fallbackDoctorName);
        return Promise.resolve();
    }

    docSel.innerHTML = `<option value="">Đang tải bác sĩ...</option>`;
    return fetch('appointments.php?action=get_doctors', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`department_id=${encodeURIComponent(deptId)}`
    })
    .then(r => r.json())
    .then(res => {
        rows = uniqueDoctors((res && res.rows) ? res.rows : []);
        renderDoctorOptions(rows, selectedDoctorId, rows.length ? 'Chọn bác sĩ' : 'Không có bác sĩ');
        appendFallbackSelectedDoctor(selectedDoctorId, fallbackDoctorName);
        if (!rows.length) toast('Khoa này chưa có bác sĩ để chọn!', 'error');
    })
    .catch(() => {
        renderDoctorOptions([], selectedDoctorId, 'Không tải được bác sĩ');
        appendFallbackSelectedDoctor(selectedDoctorId, fallbackDoctorName);
        toast('Không tải được danh sách bác sĩ!', 'error');
    });
}
deptSel.addEventListener('change', () => loadDoctorsByDepartment(deptSel.value));
appointmentDateOnly.addEventListener('change', setMinDateTime);

function validateFormBeforeSubmit(formData){
    if (!formData.get('patient_id') || Number(formData.get('patient_id')) <= 0) {
        const newName = (formData.get('new_full_name') || '').trim();
        const newPhone = (formData.get('new_phone') || '').trim();
        const newDob = (formData.get('new_dob') || '').trim();
        if (!newName || !newPhone || !newDob) return 'Vui lòng chọn bệnh nhân hoặc nhập đủ thông tin bệnh nhân mới!';
    }
    if (!(formData.get('appointment_date_only') || '').trim()) return 'Vui lòng chọn ngày khám!';
    if (!(formData.get('appointment_time') || '').trim()) return 'Vui lòng chọn giờ khám!';
    if (!(formData.get('symptoms') || '').trim()) return 'Vui lòng nhập triệu chứng!';
    return '';
}

document.getElementById('apptForm').addEventListener('submit', function(e){
    e.preventDefault();
    const id = document.getElementById('appointment_id').value;
    const action = id ? 'edit' : 'add';
    const formData = new FormData(this);
    const invalidMsg = validateFormBeforeSubmit(formData);
    if (invalidMsg) return toast(invalidMsg, 'error');
    fetch(`appointments.php?action=${action}`, { method:'POST', body: formData })
    .then(r=>r.json())
    .then(res=>{
        toast(res.message || (res.success ? 'Thành công' : 'Thất bại'), res.success ? 'success' : 'error');
        if (!res.success) return;
        closeForm();
        loadList();
        if (action === 'add' && res.appointment_id) setTimeout(() => selectRow(Number(res.appointment_id)), 250);
        if (action === 'edit' && selectedId) setTimeout(() => selectRow(Number(selectedId)), 250);
    })
    .catch(()=>toast('Lỗi kết nối server!', 'error'));
});

function editSelected(){
    if (!selectedCache || !selectedCache.appointment) return toast('Chưa chọn lịch!', 'error');
    const a = selectedCache.appointment;
    const dt = splitDateTimeValue(a.appointment_date);
    document.getElementById('formTitle').textContent = 'Sửa lịch khám';
    addPatientArea.style.display = 'none';
    editPatientArea.style.display = '';
    document.getElementById('appointment_id').value = a.appointment_id || '';
    document.getElementById('status').value = a.status || 'Chờ khám';
    document.getElementById('symptoms').value = a.symptoms || '';
    appointmentDateOnly.value = dt.date || '';
    appointmentTime.value = dt.time || '';
    setMinDateTime();
    patientSearch.value = '';
    searchResults.style.display = 'none';
    searchResults.innerHTML = '';
    clearCreatePatientFields();
    hideCreateBox();
    clearPicked();
    showEditPatientInfo({
        patient_id: a.patient_id || '',
        full_name: a.patient_name || '',
        phone: a.patient_phone || '',
        date_of_birth: a.patient_dob || '',
        gender: a.patient_gender || ''
    });
    document.getElementById('department').value = a.department_id || '';
    loadDoctorsByDepartment(a.department_id || '', a.doctor_id || '', a.doctor_name || '');
    formModal.style.display = 'flex';
}

function cancelSelected(){
    if (!selectedId) return toast('Chưa chọn lịch!', 'error');
    if (!confirm('Bạn có chắc muốn hủy lịch khám #' + selectedId + ' ?')) return;

    fetch('appointments.php?action=cancel', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`appointment_id=${encodeURIComponent(selectedId)}`
    })
    .then(r=>r.json())
    .then(res=>{
        toast(res.message || (res.success ? 'Đã hủy lịch' : 'Thất bại'), res.success ? 'success' : 'error');
        if (!res.success) return;
        loadList();
        setTimeout(() => selectRow(Number(selectedId)), 200);
    })
    .catch(()=>toast('Lỗi kết nối server!', 'error'));
}

loadList();
</script>

</body>
</html>