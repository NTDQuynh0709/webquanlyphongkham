<?php
require_once __DIR__ . '/../auth.php';
require_role('doctor');

require_once __DIR__ . '/../db.php';

$doctor_id = (int)($_SESSION['doctor_id'] ?? 0);
if ($doctor_id <= 0) {
    header('Location: ../login.php');
    exit;
}

// Helper
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function calcAge(?string $dob): ?int {
    if (empty($dob)) return null;
    try {
        $d = new DateTime($dob);
        $n = new DateTime();
        return (int)$d->diff($n)->y;
    } catch (Exception $e) {
        return null;
    }
}
function genderText(?string $gender): string {
    if ($gender === 'Male') return 'Nam';
    if ($gender === 'Female') return 'Nữ';
    return '—';
}

// Lấy thông tin bác sĩ
$stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ? LIMIT 1");
$stmt->execute([$doctor_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die('Không tìm thấy bác sĩ');
}

/**
 * Ngày quay lại (Back về đúng ngày đang xem ở dashboard)
 * VD: exam.php?id=123&date=2026-02-22
 */
$return_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date)) {
    $return_date = date('Y-m-d');
}

/**
 * Không có id => quay về dashboard luôn
 */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;
}

$appointment_id = (int)$_GET['id'];
if ($appointment_id <= 0) {
    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;
}

/**
 * Lấy thông tin appointment + check thuộc về bác sĩ đang đăng nhập
 * ✅ Thêm p.medical_history để hiển thị/nhập tiền sử + dị ứng
 */
$stmt = $conn->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.symptoms,
        a.status,
        a.patient_id,
        p.full_name AS patient_name,
        p.phone,
        p.gender,
        p.date_of_birth,
        p.medical_history,
        d.department_name AS department_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN departments d ON a.department_id = d.department_id
    WHERE a.appointment_id = ?
      AND a.doctor_id = ?
    LIMIT 1
");
$stmt->execute([$appointment_id, $doctor_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    header('Location: dashboard.php?date=' . urlencode($return_date));
    exit;
}

$age = calcAge($patient['date_of_birth'] ?? null);

/**
 * Nếu đã có medical_records cho appointment_id => load lên để sửa
 */
$stmt = $conn->prepare("SELECT * FROM medical_records WHERE appointment_id = ? LIMIT 1");
$stmt->execute([$appointment_id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

$record_id = $record ? (int)$record['record_id'] : 0;

$prescription_header = null;
$prescription_items = [];
if ($record_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM prescription_headers WHERE record_id = ? LIMIT 1");
    $stmt->execute([$record_id]);
    $prescription_header = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prescription_header) {
        $stmt = $conn->prepare("SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY item_id ASC");
        $stmt->execute([(int)$prescription_header['prescription_id']]);
        $prescription_items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$patient_name = $patient['patient_name'] ?? '';
$patient_initial = mb_substr($patient_name ?: 'B', 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Khám bệnh - <?php echo h($patient_name); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .patient-info-header {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .patient-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: white;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
        }

        .patient-details h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .patient-details p {
            opacity: 0.9;
            font-size: 14px;
        }

        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .btn-back:hover { background: rgba(255,255,255,0.3); }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }

        .exam-form {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 20px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .vital-signs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .form-group { margin-bottom: 20px; }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .medication-row {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr 3fr auto;
            gap: 10px;
            margin-bottom: 10px;
            align-items: end;
        }

        .medication-row .field {
            display: flex;
            flex-direction: column;
        }
        .mini-label{
            display:block;
            font-size:12px;
            color:#666;
            margin-bottom:6px;
            font-weight:600;
        }
        .mini-hint{
            margin-top:6px;
            font-size:12px;
            color:#888;
            line-height:1.3;
        }

        @media (max-width: 900px) {
            .medication-row {
                grid-template-columns: 1fr 1fr;
                align-items: start;
            }
            .medication-row button.btn-remove-med {
                height: 44px;
                align-self: end;
            }
        }
        @media (max-width: 520px) {
            .medication-row {
                grid-template-columns: 1fr;
            }
        }

        .btn-add-med {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px dashed #90caf9;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-add-med:hover { background: #bbdefb; }

        .btn-remove-med {
            background: #ffebee;
            color: #c62828;
            border: none;
            padding: 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-remove-med:disabled{
            opacity:.6;
            cursor:not-allowed;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }

        .btn-save {
            flex: 1;
            padding: 15px;
            background: #4caf50;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-save:hover { background: #43a047; }

        .btn-cancel {
            padding: 15px 30px;
            background: #f5f5f5;
            color: #666;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            text-align: center;
        }

        .btn-cancel:hover { background: #eee; }

        .patient-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .summary-item {
            padding: 10px;
            background: white;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
        }

        .summary-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .summary-value {
            font-weight: 500;
            color: #333;
            word-break: break-word;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="patient-info-header">
            <div class="patient-avatar">
                <?php echo h($patient_initial); ?>
            </div>
            <div class="patient-details">
                <h1>🩺 Khám bệnh: <?php echo h($patient_name); ?></h1>
                <p>Bác sĩ: <?php echo h($doctor['full_name'] ?? ''); ?> | <?php echo h($patient['department_name'] ?? ''); ?></p>
            </div>
        </div>

        <a href="dashboard.php?date=<?php echo h($return_date); ?>" class="btn-back">← Quay lại lịch</a>
    </div>

    <div class="container">
        <form id="examForm" method="POST" action="save_exam.php" autocomplete="off">
            <input type="hidden" name="appointment_id" value="<?php echo (int)$appointment_id; ?>">
            <input type="hidden" name="return_date" value="<?php echo h($return_date); ?>">

            <div class="exam-form">
                <div class="patient-summary">
                    <h3>📄 Thông tin bệnh nhân</h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-label">Tuổi/Giới tính</div>
                            <div class="summary-value">
                                <?php echo $age !== null ? (int)$age : '—'; ?> tuổi /
                                <?php echo h(genderText($patient['gender'] ?? null)); ?>
                            </div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Số điện thoại</div>
                            <div class="summary-value"><?php echo h($patient['phone'] ?? '—'); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Ngày sinh</div>
                            <div class="summary-value">
                                <?php
                                $dob = $patient['date_of_birth'] ?? null;
                                echo !empty($dob) ? h(date('d/m/Y', strtotime($dob))) : '—';
                                ?>
                            </div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Triệu chứng ban đầu</div>
                            <div class="summary-value"><?php echo !empty($patient['symptoms']) ? h($patient['symptoms']) : '—'; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Tiền sử / Dị ứng -->
                <h2 class="section-title">⚠ Tiền sử / Dị ứng</h2>
                <div class="form-group">
                    <textarea name="medical_history" class="form-control"
                              placeholder="- Dị ứng: ...&#10;- Bệnh nền: ...&#10;- Tiền sử khác: ..."><?php
                        echo h($patient['medical_history'] ?? '');
                    ?></textarea>
                </div>

                <h2 class="section-title">📊 Dấu hiệu sinh tồn</h2>
                <div class="vital-signs-grid">
                    <div class="form-group">
                        <label>Huyết áp (mmHg)</label>
                        <input type="text" name="blood_pressure" class="form-control" placeholder="VD: 120/80"
                               value="<?php echo $record ? h($record['blood_pressure'] ?? '') : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Nhịp tim (bpm)</label>
                        <input type="number" name="heart_rate" class="form-control" placeholder="VD: 75" min="0"
                               value="<?php echo ($record && $record['heart_rate'] !== null) ? (int)$record['heart_rate'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Nhiệt độ (°C)</label>
                        <input type="number" step="0.1" name="temperature" class="form-control" placeholder="VD: 36.5" min="0"
                               value="<?php echo ($record && $record['temperature'] !== null) ? h($record['temperature']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Chiều cao (cm)</label>
                        <input type="number" step="0.1" name="height" class="form-control" placeholder="VD: 165.5" min="0"
                               value="<?php echo ($record && $record['height'] !== null) ? h($record['height']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Cân nặng (kg)</label>
                        <input type="number" step="0.1" name="weight" class="form-control" placeholder="VD: 55.5" min="0"
                               value="<?php echo ($record && $record['weight'] !== null) ? h($record['weight']) : ''; ?>">
                    </div>
                </div>

                <h2 class="section-title">🩺 Chẩn đoán</h2>
                <div class="form-group">
                    <textarea name="diagnosis" class="form-control" required
                              placeholder="VD: Chẩn đoán chính: ...&#10;Chẩn đoán kèm theo: ..."><?php
                        echo $record ? h($record['diagnosis'] ?? '') : '';
                    ?></textarea>
                </div>

                <h2 class="section-title">💡 Kế hoạch điều trị</h2>
                <div class="form-group">
                    <textarea name="treatment_plan" class="form-control"
                              placeholder="- Hướng điều trị:&#10;- Dặn dò:&#10;- Tái khám:"><?php
                        echo $record ? h($record['treatment_plan'] ?? '') : '';
                    ?></textarea>
                </div>

                <h2 class="section-title">💊 Kê đơn thuốc</h2>

                <div class="form-group">
                    <label>Ghi chú đơn thuốc</label>
                    <textarea name="prescription_note" class="form-control"
                              placeholder="VD: Uống thuốc đúng giờ. Nếu đau tăng/sốt cao, tái khám ngay."><?php
                        echo $prescription_header ? h($prescription_header['note'] ?? '') : '';
                    ?></textarea>
                </div>

                <div id="medications-container">
                    <?php if (!empty($prescription_items)): ?>
                        <?php foreach ($prescription_items as $idx => $it): ?>
                            <div class="medication-row">
                                <div class="field">
                                    <label class="mini-label">Tên thuốc</label>
                                    <input type="text" name="medication_name[]" class="form-control" placeholder="VD: Omeprazole 20mg" required
                                           value="<?php echo h($it['medication_name'] ?? ''); ?>">
                                </div>

                                <div class="field">
                                    <label class="mini-label">Liều dùng</label>
                                    <input type="text" name="dosage[]" class="form-control" placeholder="VD: 1 viên x 2 lần/ngày"
                                           value="<?php echo h($it['dosage'] ?? ''); ?>">
                                </div>

                                <div class="field">
                                    <label class="mini-label">Số ngày</label>
                                    <input type="number" min="1" name="days[]" class="form-control" placeholder="VD: 5" required
                                           value="<?php echo (int)($it['days'] ?? 1); ?>">
                                </div>

                                <div class="field">
                                    <label class="mini-label">Hướng dẫn</label>
                                    <input type="text" name="instructions[]" class="form-control" placeholder="VD: Uống sau ăn / trước ăn 30 phút"
                                           value="<?php echo h($it['instructions'] ?? ''); ?>">
                                </div>

                                <button type="button" class="btn-remove-med" onclick="removeMedication(this)" <?php echo ($idx === 0 && count($prescription_items) === 1) ? 'disabled' : ''; ?>>🗑️</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="medication-row">
                            <div class="field">
                                <label class="mini-label">Tên thuốc</label>
                                <input type="text" name="medication_name[]" class="form-control" placeholder="VD: Omeprazole 20mg" required>
                            </div>

                            <div class="field">
                                <label class="mini-label">Liều dùng</label>
                                <input type="text" name="dosage[]" class="form-control" placeholder="VD: 1 viên x 2 lần/ngày">
                            </div>

                            <div class="field">
                                <label class="mini-label">Số ngày</label>
                                <input type="number" min="1" name="days[]" class="form-control" placeholder="VD: 5" value="1" required>
                            </div>

                            <div class="field">
                                <label class="mini-label">Hướng dẫn</label>
                                <input type="text" name="instructions[]" class="form-control" placeholder="VD: Uống sau ăn / trước ăn 30 phút">
                            </div>

                            <button type="button" class="btn-remove-med" onclick="removeMedication(this)" disabled>🗑️</button>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn-add-med" onclick="addMedication()">➕ Thêm thuốc</button>

                <h2 class="section-title">📝 Ghi chú thêm</h2>
                <div class="form-group">
                    <textarea name="notes" class="form-control" placeholder="VD: Hẹn tái khám..."><?php
                        echo $record ? h($record['notes'] ?? '') : '';
                    ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="dashboard.php?date=<?php echo h($return_date); ?>" class="btn-cancel">Hủy bỏ</a>
                    <button type="submit" class="btn-save">💾 Lưu kết quả khám</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function addMedication() {
            const container = document.getElementById('medications-container');
            const newRow = document.createElement('div');
            newRow.className = 'medication-row';
            newRow.innerHTML = `
                <div class="field">
                    <label class="mini-label">Tên thuốc</label>
                    <input type="text" name="medication_name[]" class="form-control" placeholder="VD: Omeprazole 20mg" required>
                    <div class="mini-hint">Tên + hàm lượng (nếu có)</div>
                </div>

                <div class="field">
                    <label class="mini-label">Liều dùng</label>
                    <input type="text" name="dosage[]" class="form-control" placeholder="VD: 1 viên x 2 lần/ngày">
                    <div class="mini-hint">Mỗi lần bao nhiêu, ngày mấy lần</div>
                </div>

                <div class="field">
                    <label class="mini-label">Số ngày</label>
                    <input type="number" min="1" name="days[]" class="form-control" placeholder="VD: 5" value="1" required>
                    <div class="mini-hint">Dùng trong bao nhiêu ngày</div>
                </div>

                <div class="field">
                    <label class="mini-label">Hướng dẫn</label>
                    <input type="text" name="instructions[]" class="form-control" placeholder="VD: Uống sau ăn / trước ăn 30 phút">
                    <div class="mini-hint">Thời điểm uống + lưu ý</div>
                </div>

                <button type="button" class="btn-remove-med" onclick="removeMedication(this)">🗑️</button>
            `;
            container.appendChild(newRow);

            const removeBtns = document.querySelectorAll('.btn-remove-med');
            removeBtns.forEach(btn => btn.disabled = false);
        }

        function removeMedication(button) {
            const row = button.closest('.medication-row');
            if (!row) return;

            const rows = document.querySelectorAll('.medication-row');
            if (rows.length > 1) {
                row.remove();

                const rowsAfter = document.querySelectorAll('.medication-row');
                if (rowsAfter.length === 1) {
                    const firstRemove = document.querySelector('.btn-remove-med');
                    if (firstRemove) firstRemove.disabled = true;
                }
            }
        }

        document.getElementById('examForm').addEventListener('submit', function(e) {
            const diagnosis = document.querySelector('textarea[name="diagnosis"]').value.trim();
            if (!diagnosis) {
                e.preventDefault();
                alert('Vui lòng nhập chẩn đoán!');
                return false;
            }

            const medNames = document.querySelectorAll('input[name="medication_name[]"]');
            let valid = true;

            medNames.forEach(input => {
                if (!input.value.trim()) {
                    valid = false;
                    input.style.borderColor = '#f44336';
                } else {
                    input.style.borderColor = '#ddd';
                }
            });

            if (!valid) {
                e.preventDefault();
                alert('Vui lòng điền đầy đủ tên thuốc!');
                return false;
            }

            const days = document.querySelectorAll('input[name="days[]"]');
            days.forEach(d => {
                const v = parseInt(d.value || '0', 10);
                if (isNaN(v) || v < 1) {
                    valid = false;
                    d.style.borderColor = '#f44336';
                } else {
                    d.style.borderColor = '#ddd';
                }
            });

            if (!valid) {
                e.preventDefault();
                alert('Số ngày dùng thuốc phải >= 1');
                return false;
            }

            return true;
        });
    </script>
</body>
</html>