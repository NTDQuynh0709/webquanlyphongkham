<?php
require_once __DIR__ . '/../auth.php';
require_role('doctor');

require_once __DIR__ . '/../db.php';

$doctor_id = (int)($_SESSION['doctor_id'] ?? 0);
if ($doctor_id <= 0) {
    header('Location: ../login.php');
    exit;
}

// Lấy thông tin bác sĩ
$stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ?");
$stmt->execute([$doctor_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die('Không tìm thấy bác sĩ');
}

/**
 * Ngày được chọn để xem danh sách lịch
 * - Mặc định hôm nay
 * - Validate định dạng YYYY-MM-DD
 */
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// Lấy lịch khám theo ngày đã chọn
$stmt = $conn->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.symptoms,
        a.status,
        p.full_name AS patient_name,
        p.phone,
        TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.doctor_id = ?
      AND DATE(a.appointment_date) = ?
      AND a.status IN ('pending','completed','cancelled')
    ORDER BY a.appointment_date ASC
");
$stmt->execute([$doctor_id, $selected_date]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$doctor_initial = mb_substr($doctor['full_name'] ?? 'B', 0, 1, 'UTF-8');
$selected_date_label = date('d/m/Y', strtotime($selected_date));

// Chỉ cho khám lịch TRONG NGÀY HÔM NAY (không quá khứ, không tương lai)
$todayYmd = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bác sĩ - Lịch khám</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root{
            --bg:#f5f7fa;
            --card:#ffffff;
            --text:#111827;
            --muted:#6b7280;
            --line:#eef2f7;
            --primary:#667eea;
            --primary2:#764ba2;
            --shadow: 0 10px 24px rgba(17,24,39,0.08);
            --shadow2: 0 6px 18px rgba(17,24,39,0.06);
            --radius: 16px;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary2) 100%);
            color: white;
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.10);
            gap: 14px;
        }

        .header-left h1 { font-size: 20px; margin-bottom: 4px; letter-spacing: .2px; }
        .header-left p { opacity: 0.92; font-size: 13px; }

        .doctor-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doctor-avatar {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: rgba(255,255,255,0.95);
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: 18px;
            font-weight: 900;
        }

        .doctor-meta strong { display:block; font-size: 14px; }
        .doctor-meta span { font-size: 12px; opacity: 0.92; }

        .logout-btn {
            background: rgba(255,255,255,0.18);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            padding: 8px 12px;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            font-size: 13px;
            transition: background 0.2s;
            margin-left: 12px;
            white-space: nowrap;
        }
        .logout-btn:hover { background: rgba(255,255,255,0.26); }

        /* Layout */
        .layout {
            max-width: 1200px;
            margin: 0 auto;
            padding: 22px;
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 18px;
        }

        /* Sidebar */
        .sidebar {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow2);
            padding: 14px;
            height: fit-content;
            position: sticky;
            top: 16px;
        }

        .nav-title {
            font-size: 12px;
            font-weight: 900;
            color: var(--muted);
            letter-spacing: 0.6px;
            text-transform: uppercase;
            padding: 8px 10px 12px;
        }

        .nav-menu { list-style: none; display: grid; gap: 6px; }

        .nav-item {
            padding: 12px 12px;
            border-radius: 14px;
            transition: all 0.2s;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #111827;
            border: 1px solid transparent;
            user-select: none;
        }

        .nav-item:hover {
            background: #f8fafc;
            border-color: var(--line);
        }

        .nav-item.active {
            background: #f0f2ff;
            border-color: rgba(102,126,234,0.25);
            color: var(--primary);
            font-weight: 900;
        }

        .nav-icon { font-size: 18px; width: 22px; text-align: center; }

        /* Content */
        .content { display: grid; gap: 16px; }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow2);
            overflow: hidden;
        }

        .card-header {
            padding: 16px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }

        .card-title {
            display: flex;
            align-items: baseline;
            gap: 10px;
            flex-wrap: wrap;
        }

        .card-title h2 {
            font-size: 16px;
            font-weight: 900;
            color: #111827;
        }

        .badge {
            background: var(--primary);
            color: white;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
        }

        .subtext {
            color: var(--muted);
            font-size: 13px;
            margin-top: 2px;
        }

        /* Date picker */
        .filters {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-input {
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            transition: border 0.2s, box-shadow 0.2s;
        }
        .date-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.18);
        }

        .hint {
            font-size: 12px;
            color: var(--muted);
            font-weight: 700;
        }

        /* Table */
        .table-wrap { width: 100%; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            text-align: left;
            padding: 12px 14px;
            background: #f8fafc;
            color: #374151;
            font-size: 12px;
            font-weight: 900;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        tbody td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            font-size: 14px;
        }

        tbody tr:hover { background: #fafafa; }

        .patient {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            background: #e0e7ff;
            color: #3730a3;
            display: grid;
            place-items: center;
            font-weight: 900;
        }

        .patient-name { font-weight: 900; }
        .patient-phone { color: var(--muted); font-size: 12px; margin-top: 2px; }

        /* Status pill */
        .status {
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            display: inline-block;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .status.pending, .status { background: #fff7ed; color: #9a3412; border-color:#fed7aa; }
        .status.completed { background: #ecfdf5; color: #065f46; border-color:#a7f3d0; }
        .status.cancelled { background: #fef2f2; color: #991b1b; border-color:#fecaca; }

        /* Button */
        .btn {
            padding: 9px 12px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 900;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: filter 0.2s, transform 0.1s;
            font-size: 13px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .btn:active { transform: translateY(1px); }

        .btn-primary { background: var(--primary); color: white; border-color: var(--primary); }
        .btn-primary:hover { filter: brightness(0.96); }

        .btn-light { background:#f9fafb; color:#111827; border-color:#e5e7eb; }
        .btn-disabled { opacity:.6; cursor:not-allowed; }

        /* Empty */
        .empty {
            padding: 40px 16px;
            text-align: center;
            color: var(--muted);
        }
        .empty .icon { font-size: 40px; margin-bottom: 10px; opacity: 0.75; }
        .empty h3 { color: #111827; margin-bottom: 6px; font-weight: 900; }

        /* Responsive */
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; }
        }

        @media (max-width: 520px) {
            .doctor-meta { display: none; }
            .logout-btn { margin-left: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>📅 Lịch khám</h1>
            <p>Chào mừng <?php echo h($doctor['full_name']); ?></p>
        </div>

        <div style="display:flex; align-items:center;">
            <div class="doctor-info">
                <div class="doctor-avatar"><?php echo h($doctor_initial); ?></div>
                <div class="doctor-meta">
                    <strong><?php echo h($doctor['full_name']); ?></strong>
                    <span><?php echo h($doctor['specialty'] ?? ''); ?></span>
                </div>
            </div>
            <a href="../logout.php" class="logout-btn">🚪 Đăng xuất</a>
            
      
        </div>
    </div>

    <div class="layout">
        <aside class="sidebar">
            <div class="nav-title">Menu</div>
            <ul class="nav-menu">
                <li class="nav-item active">
                    <span class="nav-icon">📅</span>
                    Lịch khám
                </li>
                <li class="nav-item" onclick="window.location.href='records.php'">
                    <span class="nav-icon">📋</span>
                    Hồ sơ bệnh án
                </li>

                <li class="nav-item" onclick="window.location.href='profile.php'">
                    <span class="nav-icon">👤</span>
                    Hồ sơ cá nhân
                </li>
            </ul>
        </aside>

        <main class="content">
            <section class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">
                            <h2>Danh sách lịch khám</h2>
                            <span class="badge"><?php echo count($appointments); ?></span>
                        </div>
                        <div class="subtext">
                            Ngày: <strong><?php echo h($selected_date_label); ?></strong>
                            <span style="margin-left:8px;" class="hint">• Chọn ngày để tự xem danh sách</span>
                            <?php if ($selected_date !== $todayYmd): ?>
                                <div class="hint" style="margin-top:6px;">
                                    ⚠️ Chỉ được khám lịch <b>trong ngày hôm nay</b>. Ngày khác sẽ không thể khám.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters">
                        <form id="dateForm" method="GET">
                            <input
                                id="datePicker"
                                class="date-input"
                                type="date"
                                name="date"
                                value="<?php echo h($selected_date); ?>"
                            >
                        </form>
                    </div>
                </div>

                <?php if (empty($appointments)): ?>
                    <div class="empty">
                        <div class="icon">📭</div>
                        <h3>Không có lịch khám</h3>
                        <p>Không có lịch nào vào ngày <?php echo h($selected_date_label); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Bệnh nhân</th>
                                    <th>Tuổi</th>
                                    <th>Triệu chứng</th>
                                    <th>Trạng thái</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($appointments as $app): ?>
                                <?php
                                    $status_text = 'Không rõ';
                                    $status_class = 'unknown';
                                    switch ($app['status']) {
                                        case 'pending':
                                            $status_text = 'Chờ khám';
                                            $status_class = 'pending';
                                            break;
                                        case 'completed':
                                            $status_text = 'Đã khám';
                                            $status_class = 'completed';
                                            break;
                                        case 'cancelled':
                                            $status_text = 'Đã hủy';
                                            $status_class = 'cancelled';
                                            break;
                                    }

                                    $symptoms = $app['symptoms'] ?? '';
                                    $symptoms_short = (mb_strlen($symptoms, 'UTF-8') > 60)
                                        ? mb_substr($symptoms, 0, 60, 'UTF-8') . '...'
                                        : $symptoms;

                                    $patient_name = $app['patient_name'] ?? '';
                                    $patient_initial = mb_substr($patient_name, 0, 1, 'UTF-8');

                                    // RULE: không khám lịch huỷ + không khám lịch quá khứ/tương lai
                                    $apptDT = $app['appointment_date'] ?? '';
                                    $apptTs = $apptDT ? strtotime($apptDT) : 0;
                                    $isToday = ($apptDT && date('Y-m-d', $apptTs) === $todayYmd);

                                    $isCancelled = (($app['status'] ?? '') === 'cancelled');
                                    $isCompleted = (($app['status'] ?? '') === 'completed');

                                    // chỉ cho khám lịch hôm nay và chưa huỷ/chưa hoàn thành
                                    $canExam = $isToday && !$isCancelled && !$isCompleted;

                                    // Nếu muốn chặn khám trước giờ hẹn thì mở dòng dưới:
                                    // $canExam = $canExam && ($apptTs <= time());
                                ?>
                                <tr>
                                    <td>
                                        <div class="patient">
                                            <div class="avatar"><?php echo h($patient_initial); ?></div>
                                            <div>
                                                <div class="patient-name"><?php echo h($patient_name); ?></div>
                                                <div class="patient-phone"><?php echo h($app['phone'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <?php echo (int)($app['age'] ?? 0); ?> tuổi
                                    </td>
                                    <td>
                                        <span title="<?php echo h($symptoms); ?>">
                                            <?php echo h($symptoms_short); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status <?php echo h($status_class); ?>">
                                            <?php echo h($status_text); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($canExam): ?>
                                            <a
                                                href="exam.php?id=<?php echo (int)$app['appointment_id']; ?>"
                                                class="btn btn-primary"
                                            >🩺 Khám</a>
                                        <?php else: ?>
                                            <span class="btn btn-light btn-disabled">🚫 Không thể khám</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
        document.getElementById('datePicker').addEventListener('change', function () {
            document.getElementById('dateForm').submit();
        });
    </script>
</body>
</html>