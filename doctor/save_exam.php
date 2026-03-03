<?php
// doctor/save_exam.php
require_once __DIR__ . '/../auth.php';
require_role('doctor');

require_once __DIR__ . '/../db.php';

$doctor_id = (int)($_SESSION['doctor_id'] ?? 0);
if ($doctor_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

/**
 * =========================
 *  ENCRYPTION CONFIG
 * =========================
 * Bạn cần set biến môi trường DB_ENC_KEY là chuỗi base64 của 32 bytes (AES-256).
 * Ví dụ tạo key (chạy 1 lần):
 *   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
 *
 * Rồi set vào .env / config server:
 *   DB_ENC_KEY=xxxxxxxx
 */
function enc_key(): string {
    $b64 = getenv('DB_ENC_KEY') ?: '';
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== 32) {
        // Fail fast để khỏi lưu rác không giải mã được
        throw new RuntimeException('Missing/invalid DB_ENC_KEY (must be base64 of 32 bytes).');
    }
    return $raw;
}

/**
 * AES-256-GCM: trả về base64(iv:12 | tag:16 | ciphertext)
 * - Nếu input rỗng => trả về null (để DB lưu NULL cho gọn)
 */
function encrypt_db(?string $plain): ?string {
    if ($plain === null) return null;
    $plain = (string)$plain;
    if (trim($plain) === '') return null;

    $key = enc_key();
    $iv  = random_bytes(12); // chuẩn GCM thường dùng 12 bytes
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',   // AAD (không dùng)
        16    // tag length
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

// ===== Helpers =====
function post_trim(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $default));
}
function post_int_nullable(string $key): ?int {
    if (!isset($_POST[$key]) || $_POST[$key] === '') return null;
    if (!is_numeric($_POST[$key])) return null;
    return (int)$_POST[$key];
}
function post_float_nullable(string $key): ?float {
    if (!isset($_POST[$key]) || $_POST[$key] === '') return null;
    if (!is_numeric($_POST[$key])) return null;
    return (float)$_POST[$key];
}
function valid_date_ymd(string $s): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

// ===== Input =====
$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$return_date    = post_trim('return_date', date('Y-m-d'));
if (!valid_date_ymd($return_date)) $return_date = date('Y-m-d');

if ($appointment_id <= 0) {
    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;
}

$blood_pressure = post_trim('blood_pressure', '');
$heart_rate     = post_int_nullable('heart_rate');
$temperature    = post_float_nullable('temperature');
$height         = post_float_nullable('height');
$weight         = post_float_nullable('weight');

$diagnosis      = post_trim('diagnosis', '');
$treatment_plan = post_trim('treatment_plan', '');
$notes          = post_trim('notes', '');

// ✅ Tiền sử / Dị ứng (lưu vào patients.medical_history)
$medical_history = post_trim('medical_history', '');

// Prescription
$prescription_note = post_trim('prescription_note', '');

$medication_names = $_POST['medication_name'] ?? [];
$dosages          = $_POST['dosage'] ?? [];
$days_list        = $_POST['days'] ?? [];
$instructions     = $_POST['instructions'] ?? [];

if ($diagnosis === '') {
    header('Location: exam.php?id=' . $appointment_id . '&date=' . urlencode($return_date));
    exit;
}

// Chuẩn hoá thuốc
$items = [];
if (is_array($medication_names)) {
    $count = count($medication_names);
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string)($medication_names[$i] ?? ''));
        $dos  = trim((string)($dosages[$i] ?? ''));
        $dayv = (int)($days_list[$i] ?? 0);
        $ins  = trim((string)($instructions[$i] ?? ''));

        if ($name === '' && $dos === '' && $dayv <= 0 && $ins === '') continue;
        if ($name === '') continue;
        if ($dayv < 1) $dayv = 1;

        $items[] = [
            'medication_name' => $name,
            'dosage' => $dos,
            'days' => $dayv,
            'instructions' => $ins
        ];
    }
}

// Load appointment + check doctor
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.patient_id, a.doctor_id
    FROM appointments a
    WHERE a.appointment_id = ?
    LIMIT 1
");
$stmt->execute([$appointment_id]);
$appt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appt || (int)$appt['doctor_id'] !== $doctor_id) {
    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;
}

$patient_id = (int)($appt['patient_id'] ?? 0);
if ($patient_id <= 0) {
    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;
}

try {
    $conn->beginTransaction();

    /**
     * =========================
     *  CHỈ THÊM MÃ HOÁ Ở ĐÂY
     * =========================
     * Bạn có thể chọn mã hoá nhiều/ít tuỳ ý.
     * Thường nên mã hoá: medical_history, diagnosis, treatment_plan, notes,
     * prescription_note, medication_name, dosage, instructions.
     * (blood_pressure nếu muốn cũng có thể mã hoá; ở đây mình giữ nguyên vì là dạng đơn giản)
     */

    // 1) Update patients.medical_history (MÃ HOÁ)
    $enc_medical_history = encrypt_db($medical_history);
    $stmt = $conn->prepare("UPDATE patients SET medical_history = ? WHERE patient_id = ? LIMIT 1");
    $stmt->execute([$enc_medical_history, $patient_id]);

    // 2) Insert/Update medical_records (unique appointment_id) (MÃ HOÁ diagnosis/treatment_plan/notes)
    $stmt = $conn->prepare("SELECT record_id FROM medical_records WHERE appointment_id = ? LIMIT 1");
    $stmt->execute([$appointment_id]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    $enc_diagnosis      = encrypt_db($diagnosis);
    $enc_treatment_plan = encrypt_db($treatment_plan);
    $enc_notes          = encrypt_db($notes);

    if ($rec) {
        $record_id = (int)$rec['record_id'];

        $stmt = $conn->prepare("
            UPDATE medical_records
            SET
                patient_id = ?,
                doctor_id = ?,
                diagnosis = ?,
                treatment_plan = ?,
                notes = ?,
                blood_pressure = ?,
                heart_rate = ?,
                temperature = ?,
                height = ?,
                weight = ?,
                examination_date = NOW()
            WHERE record_id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $patient_id,
            $doctor_id,
            $enc_diagnosis,
            $enc_treatment_plan,
            $enc_notes,
            $blood_pressure !== '' ? $blood_pressure : null,
            $heart_rate,
            $temperature,
            $height,
            $weight,
            $record_id
        ]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO medical_records
                (patient_id, appointment_id, doctor_id, diagnosis, treatment_plan, notes, blood_pressure, heart_rate, temperature, height, weight, examination_date)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $patient_id,
            $appointment_id,
            $doctor_id,
            $enc_diagnosis,
            $enc_treatment_plan,
            $enc_notes,
            $blood_pressure !== '' ? $blood_pressure : null,
            $heart_rate,
            $temperature,
            $height,
            $weight
        ]);

        $record_id = (int)$conn->lastInsertId();
    }

    // 3) Prescription header + items (MÃ HOÁ note + tên thuốc/dosage/instructions)
    $should_have_prescription = (count($items) > 0) || ($prescription_note !== '');

    $stmt = $conn->prepare("SELECT prescription_id FROM prescription_headers WHERE record_id = ? LIMIT 1");
    $stmt->execute([$record_id]);
    $ph = $stmt->fetch(PDO::FETCH_ASSOC);

    $enc_prescription_note = encrypt_db($prescription_note);

    if ($should_have_prescription) {
        if ($ph) {
            $prescription_id = (int)$ph['prescription_id'];
            $stmt = $conn->prepare("
                UPDATE prescription_headers
                SET
                    appointment_id = ?,
                    patient_id = ?,
                    doctor_id = ?,
                    note = ?
                WHERE prescription_id = ?
                LIMIT 1
            ");
            $stmt->execute([$appointment_id, $patient_id, $doctor_id, $enc_prescription_note, $prescription_id]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO prescription_headers (record_id, appointment_id, patient_id, doctor_id, note)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$record_id, $appointment_id, $patient_id, $doctor_id, $enc_prescription_note]);
            $prescription_id = (int)$conn->lastInsertId();
        }

        // Xoá items cũ -> insert lại
        $stmt = $conn->prepare("DELETE FROM prescription_items WHERE prescription_id = ?");
        $stmt->execute([$prescription_id]);

        if (count($items) > 0) {
            $stmtItem = $conn->prepare("
                INSERT INTO prescription_items (prescription_id, medication_name, dosage, days, instructions)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($items as $it) {
                $stmtItem->execute([
                    $prescription_id,
                    encrypt_db($it['medication_name']),
                    encrypt_db($it['dosage']),
                    (int)$it['days'],
                    encrypt_db($it['instructions']),
                ]);
            }
        }
    } else {
        // Nếu không có thuốc & không note -> xoá header (nếu có)
        if ($ph) {
            $stmt = $conn->prepare("DELETE FROM prescription_headers WHERE record_id = ? LIMIT 1");
            $stmt->execute([$record_id]);
        }
    }

    // 4) appointment completed
    $stmt = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ? LIMIT 1");
    $stmt->execute([$appointment_id]);

    $conn->commit();

    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    header('Location: exam.php?id=' . $appointment_id . '&date=' . urlencode($return_date));
    exit;
}