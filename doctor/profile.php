<?php
require_once __DIR__ . '/../auth.php';
require_role('doctor');

require_once __DIR__ . '/../db.php';

$doctor_id = (int)($_SESSION['doctor_id'] ?? 0);
if ($doctor_id <= 0) {
    header('Location: ../login.php');
    exit;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function set_flash($key, $msg) {
    $_SESSION[$key] = $msg;
}

function get_flash($key) {
    if (!isset($_SESSION[$key])) return null;
    $msg = $_SESSION[$key];
    unset($_SESSION[$key]);
    return $msg;
}

// ===== Lấy thông tin bác sĩ + tên khoa (DB của bạn dùng department_id) =====
$stmt = $conn->prepare("
    SELECT d.*, dp.department_name
    FROM doctors d
    LEFT JOIN departments dp ON d.department_id = dp.department_id
    WHERE d.doctor_id = ?
    LIMIT 1
");
$stmt->execute([$doctor_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die('Không tìm thấy bác sĩ');
}

// ===== Xử lý POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // =========================
    // 1) Update profile info + username
    // =========================
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $full_name  = trim($_POST['full_name'] ?? '');
        $gender     = $_POST['gender'] ?? 'Male';
        $phone      = trim($_POST['phone'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $username   = trim($_POST['username'] ?? '');

        if ($full_name === '' || $email === '' || $username === '') {
            set_flash('error_message', 'Vui lòng nhập đầy đủ Họ tên, Email và Username.');
        } else {
            // Validate gender theo DB: enum('Male','Female')
            $allowed_gender = ['Male', 'Female'];
            if (!in_array($gender, $allowed_gender, true)) {
                $gender = 'Male';
            }

            // Check username duplicate
            if (!isset($_SESSION['error_message'])) {
                if ($username !== ($doctor['username'] ?? '')) {
                    $check_u = $conn->prepare("SELECT doctor_id FROM doctors WHERE username = ? AND doctor_id != ? LIMIT 1");
                    $check_u->execute([$username, $doctor_id]);
                    if ($check_u->fetch()) {
                        set_flash('error_message', 'Username đã được sử dụng bởi bác sĩ khác.');
                    }
                }
            }

            // Check email duplicate (DB có unique email)
            if (!isset($_SESSION['error_message'])) {
                if ($email !== ($doctor['email'] ?? '')) {
                    $check_e = $conn->prepare("SELECT doctor_id FROM doctors WHERE email = ? AND doctor_id != ? LIMIT 1");
                    $check_e->execute([$email, $doctor_id]);
                    if ($check_e->fetch()) {
                        set_flash('error_message', 'Email đã được sử dụng bởi bác sĩ khác.');
                    }
                }
            }

            // Update (KHÔNG có specialty, KHÔNG update department ở đây)
            if (!isset($_SESSION['error_message'])) {
                try {
                    $update_stmt = $conn->prepare("
                        UPDATE doctors 
                        SET full_name = ?, gender = ?, phone = ?, email = ?, username = ?
                        WHERE doctor_id = ?
                        LIMIT 1
                    ");
                    $update_stmt->execute([$full_name, $gender, $phone, $email, $username, $doctor_id]);

                    set_flash('success_message', 'Cập nhật thông tin thành công!');
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['username']  = $username;

                    // Refresh doctor data
                    $stmt = $conn->prepare("
                        SELECT d.*, dp.department_name
                        FROM doctors d
                        LEFT JOIN departments dp ON d.department_id = dp.department_id
                        WHERE d.doctor_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$doctor_id]);
                    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

                } catch (PDOException $e) {
                    set_flash('error_message', 'Lỗi cập nhật: ' . $e->getMessage());
                }
            }
        }
    }

    // =========================
    // 2) Change password (DB dùng doctors.password)
    // =========================
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            set_flash('error_message', 'Vui lòng nhập đầy đủ các trường đổi mật khẩu.');
        } elseif ($new_password !== $confirm_password) {
            set_flash('error_message', 'Mật khẩu mới và xác nhận mật khẩu không khớp.');
        } elseif (strlen($new_password) < 6) {
            set_flash('error_message', 'Mật khẩu mới phải có ít nhất 6 ký tự.');
        } else {
            // Verify old password hash (DB của bạn: doctors.password)
            $hash_in_db = $doctor['password'] ?? '';
            if (!$hash_in_db) {
                set_flash('error_message', 'Tài khoản chưa có mật khẩu hợp lệ. Liên hệ admin để hỗ trợ.');
            } elseif (!password_verify($current_password, $hash_in_db)) {
                set_flash('error_message', 'Mật khẩu hiện tại không đúng.');
            } else {
                try {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

                    $upd = $conn->prepare("UPDATE doctors SET password = ? WHERE doctor_id = ? LIMIT 1");
                    $upd->execute([$new_hash, $doctor_id]);

                    set_flash('success_message', 'Đổi mật khẩu thành công!');

                    // Refresh doctor data
                    $stmt = $conn->prepare("
                        SELECT d.*, dp.department_name
                        FROM doctors d
                        LEFT JOIN departments dp ON d.department_id = dp.department_id
                        WHERE d.doctor_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$doctor_id]);
                    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

                } catch (PDOException $e) {
                    set_flash('error_message', 'Lỗi đổi mật khẩu: ' . $e->getMessage());
                }
            }
        }
    }
}

// ===== Lấy lịch khám sắp tới =====
$today = date('Y-m-d');
$appointments_stmt = $conn->prepare("
    SELECT a.*, 
           p.full_name AS patient_name,
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.doctor_id = ?
      AND DATE(a.appointment_date) >= ?
    ORDER BY a.appointment_date
    LIMIT 5
");
$appointments_stmt->execute([$doctor_id, $today]);
$upcoming_appointments = $appointments_stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = get_flash('success_message');
$error_message   = get_flash('error_message');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ cá nhân - Bác sĩ</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #111827;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.10);
        }
        .header-left h1 { font-size: 20px; margin-bottom: 4px; }
        .header-left p { opacity: 0.9; font-size: 13px; }

        .btn-back {
            background: rgba(255,255,255,0.18);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.2s;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-back:hover { background: rgba(255,255,255,0.26); }

        .container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 26px;
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 22px;
        }

        .profile-card {
            background: white;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 6px 18px rgba(17,24,39,0.06);
            border: 1px solid #eef2f7;
            position: sticky;
            top: 22px;
            height: fit-content;
        }
        .profile-avatar {
            width: 96px;
            height: 96px;
            border-radius: 999px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 36px;
            font-weight: 800;
            margin: 0 auto 14px;
        }
        .profile-name {
            text-align: center;
            font-size: 20px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }
        .profile-specialty {
            text-align: center;
            color: #667eea;
            font-weight: 700;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .profile-info {
            margin-top: 10px;
            display: grid;
            gap: 10px;
        }
        .info-item {
            display: grid;
            grid-template-columns: 22px 1fr;
            gap: 10px;
            align-items: center;
            color: #374151;
            font-size: 14px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #eef2f7;
        }

        .main-content { display: grid; gap: 18px; }

        .card {
            background: white;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 6px 18px rgba(17,24,39,0.06);
            border: 1px solid #eef2f7;
        }

        .card-title {
            font-size: 18px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 14px;
            font-size: 14px;
            border: 1px solid transparent;
        }
        .alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-group { margin-bottom: 12px; }
        .form-label {
            display: block;
            margin-bottom: 6px;
            color: #374151;
            font-weight: 700;
            font-size: 13px;
        }
        .form-control {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            transition: border 0.2s, box-shadow 0.2s;
            background: #fff;
        }
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.18);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #eef2f7;
        }

        .btn {
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
            border: 1px solid transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-save {
            background: #667eea;
            color: white;
            border-color: #667eea;
            padding: 10px 16px;
        }
        .btn-save:hover { filter: brightness(0.96); }

        .btn-cancel {
            background: #f9fafb;
            color: #111827;
            border-color: #e5e7eb;
        }
        .btn-cancel:hover { background: #f3f4f6; }

        .appointments-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .appointments-header a {
            color: #667eea;
            text-decoration: none;
            font-weight: 800;
            font-size: 14px;
        }
        .appointment-item {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .appointment-item:last-child { border-bottom: none; }
        .appointment-info h4 { font-size: 15px; color: #111827; margin-bottom: 3px; }
        .appointment-info p { font-size: 13px; color: #6b7280; }
        .appointment-time {
            background: #ecfdf5;
            color: #065f46;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 13px;
            white-space: nowrap;
            height: fit-content;
        }

        @media (max-width: 900px) {
            .container { grid-template-columns: 1fr; }
            .profile-card { position: static; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="header">
    <div class="header-left">
        <h1>👤 Hồ sơ cá nhân</h1>
        <p>Quản lý thông tin cá nhân</p>
    </div>
    <a href="dashboard.php" class="btn-back">← Lịch khám</a>
</div>

<div class="container">
    <!-- Left -->
    <div class="profile-card">
        <div class="profile-avatar">
            <?php echo h(mb_substr($doctor['full_name'], 0, 1, 'UTF-8')); ?>
        </div>
        <div class="profile-name"><?php echo h($doctor['full_name']); ?></div>
        <div class="profile-specialty">
            <?php echo h($doctor['department_name'] ?? 'Chưa cập nhật khoa'); ?>
        </div>

        <div class="profile-info">
            <div class="info-item">
                <span>👤</span>
                <span><?php echo ($doctor['gender'] ?? '') === 'Male' ? 'Nam' : 'Nữ'; ?></span>
            </div>
            <div class="info-item">
                <span>🧑‍💻</span>
                <span>Username: <?php echo ($doctor['username'] ?? '') ? h($doctor['username']) : 'Chưa cập nhật'; ?></span>
            </div>
            <div class="info-item">
                <span>📞</span>
                <span><?php echo ($doctor['phone'] ?? '') ? h($doctor['phone']) : 'Chưa cập nhật'; ?></span>
            </div>
            <div class="info-item">
                <span>📧</span>
                <span><?php echo ($doctor['email'] ?? '') ? h($doctor['email']) : 'Chưa cập nhật'; ?></span>
            </div>
            <div class="info-item">
                <span>👨‍⚕️</span>
                <span>Bác sĩ từ: <?php echo date('d/m/Y', strtotime($doctor['created_at'])); ?></span>
            </div>
        </div>
    </div>

    <!-- Right -->
    <div class="main-content">

        <div class="card">
            <div class="card-title">✏️ Chỉnh sửa thông tin cá nhân</div>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo h($success_message); ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo h($error_message); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Họ và tên *</label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?php echo h($doctor['full_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username (tài khoản) *</label>
                        <input type="text" name="username" class="form-control"
                               value="<?php echo h($doctor['username'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Giới tính</label>
                        <select name="gender" class="form-control">
                            <option value="Male"   <?php echo ($doctor['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Nam</option>
                            <option value="Female" <?php echo ($doctor['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Nữ</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Số điện thoại</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?php echo h($doctor['phone'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo h($doctor['email'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Khoa</label>
                        <input type="text" class="form-control"
                               value="<?php echo h($doctor['department_name'] ?? 'Chưa cập nhật'); ?>" disabled>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="profile.php" class="btn btn-cancel">Hủy bỏ</a>
                    <button type="submit" class="btn btn-save">💾 Lưu</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-title">🔒 Đổi mật khẩu</div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Mật khẩu hiện tại *</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mật khẩu mới *</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Xác nhận mật khẩu mới *</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-save">🔁 Đổi mật khẩu</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="appointments-header">
                <h3 style="margin:0; font-size:16px; font-weight:800;">📅 Lịch khám sắp tới</h3>
                <a href="dashboard.php">Xem tất cả →</a>
            </div>

            <?php if (empty($upcoming_appointments)): ?>
                <p style="color:#6b7280; text-align:center; padding: 10px 0;">Không có lịch khám sắp tới</p>
            <?php else: ?>
                <?php foreach ($upcoming_appointments as $app): ?>
                    <div class="appointment-item">
                        <div class="appointment-info">
                            <h4><?php echo h($app['patient_name']); ?></h4>
                            <p><?php echo h($app['symptoms'] ?? ''); ?></p>
                        </div>
                        <div class="appointment-time">
                            <?php echo date('H:i d/m', strtotime($app['appointment_date'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>
</body>
</html>