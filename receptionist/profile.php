<?php
/**
 * profile.php
 * Lễ tân cập nhật thông tin cá nhân + đổi mật khẩu
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

function getReceptionist(PDO $conn, int $id): ?array {
  $st = $conn->prepare("
    SELECT receptionist_id, full_name, gender, phone, email, username, password, created_at
    FROM receptionists
    WHERE receptionist_id = ?
      AND is_deleted = 0
    LIMIT 1
  ");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/* ================= Load data ================= */
$info = getReceptionist($conn, $receptionist_id);
if (!$info) {
  die('Không tìm thấy thông tin lễ tân.');
}

$errors = [];
$success = '';

$full_name  = $info['full_name'] ?? '';
$gender     = $info['gender'] ?? '';
$phone      = $info['phone'] ?? '';
$email      = $info['email'] ?? '';
$username   = $info['username'] ?? '';
$created_at = $info['created_at'] ?? '';

/* ================= Handle Save Profile ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    $errors[] = 'CSRF sai';
  }

  $full_name = trim((string)($_POST['full_name'] ?? ''));
  $gender    = trim((string)($_POST['gender'] ?? ''));
  $phone     = trim((string)($_POST['phone'] ?? ''));
  $email     = trim((string)($_POST['email'] ?? ''));

  if ($full_name === '') $errors[] = 'Vui lòng nhập họ tên';
  if ($gender !== '' && !in_array($gender, ['Male', 'Female'], true)) $errors[] = 'Giới tính không hợp lệ';
  if ($phone !== '' && !preg_match('/^[0-9+\s]{8,15}$/', $phone)) $errors[] = 'Số điện thoại không hợp lệ';
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email không hợp lệ';

  if (!$errors) {
    try {
      $st = $conn->prepare("
        UPDATE receptionists
        SET full_name = ?, gender = ?, phone = ?, email = ?
        WHERE receptionist_id = ?
          AND is_deleted = 0
      ");
      $st->execute([
        $full_name,
        ($gender !== '' ? $gender : null),
        ($phone !== '' ? $phone : null),
        ($email !== '' ? $email : null),
        $receptionist_id
      ]);

      $_SESSION['full_name'] = $full_name;
      $receptionist_name = $full_name;
      $success = 'Đã cập nhật thông tin lễ tân';
      $info = getReceptionist($conn, $receptionist_id);
    } catch (Exception $e) {
      $errors[] = 'Lỗi cập nhật: ' . $e->getMessage();
    }
  }
}

/* ================= Handle Change Password ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    $errors[] = 'CSRF sai';
  }

  $current_password = (string)($_POST['current_password'] ?? '');
  $new_password     = (string)($_POST['new_password'] ?? '');
  $confirm_password = (string)($_POST['confirm_password'] ?? '');

  if ($current_password === '') $errors[] = 'Vui lòng nhập mật khẩu hiện tại';
  if ($new_password === '') $errors[] = 'Vui lòng nhập mật khẩu mới';
  if ($confirm_password === '') $errors[] = 'Vui lòng nhập xác nhận mật khẩu mới';

  if ($new_password !== '' && strlen($new_password) < 6) {
    $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự';
  }

  if ($new_password !== $confirm_password) {
    $errors[] = 'Xác nhận mật khẩu mới không khớp';
  }

  if (!$errors) {
    $dbPassword = (string)($info['password'] ?? '');

    $isValidCurrent = false;

    if (password_verify($current_password, $dbPassword)) {
      $isValidCurrent = true;
    } elseif ($current_password === $dbPassword) {
      $isValidCurrent = true;
    }

    if (!$isValidCurrent) {
      $errors[] = 'Mật khẩu hiện tại không đúng';
    }
  }

  if (!$errors) {
    try {
      $newPasswordHash = password_hash($new_password, PASSWORD_DEFAULT);

      $st = $conn->prepare("
        UPDATE receptionists
        SET password = ?
        WHERE receptionist_id = ?
          AND is_deleted = 0
      ");
      $st->execute([$newPasswordHash, $receptionist_id]);

      $success = 'Đã đổi mật khẩu thành công';
      $info = getReceptionist($conn, $receptionist_id);
    } catch (Exception $e) {
      $errors[] = 'Lỗi đổi mật khẩu: ' . $e->getMessage();
    }
  }
}

/* ================= Reload after save ================= */
if ($info) {
  $full_name  = $info['full_name'] ?? '';
  $gender     = $info['gender'] ?? '';
  $phone      = $info['phone'] ?? '';
  $email      = $info['email'] ?? '';
  $username   = $info['username'] ?? '';
  $created_at = $info['created_at'] ?? '';
}

$resetUrl = 'profile.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Hồ sơ lễ tân</title>
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
    select, input, textarea{
      width:100%; padding:10px 12px; border:1px solid var(--line);
      border-radius:12px; outline:none; background:#fff;
    }
    textarea{ min-height:90px; resize:vertical; }
    .btn{ border:none; border-radius:12px; padding:10px 14px; font-weight:900; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
    .btn-pri{ background:var(--pri); color:#fff; }
    .btn-gray{ background:#f3f4f6; color:#111827; border:1px solid var(--line); }
    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; margin-top:12px; }
    .alert{ padding:12px 14px; border-radius:12px; font-weight:800; margin-top:10px; }
    .ok{ background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .bad{ background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; }
    .pill{
      padding:8px 10px; border:1px solid var(--line); border-radius:999px;
      background:#fff; color:var(--muted); font-size:12px; font-weight:900;
      display:inline-flex; align-items:center; gap:8px;
    }
    .readonly{
      background:#f9fafb;
      color:#6b7280;
    }
    .section-title{
      font-size:15px; font-weight:1000; color:#111827; margin:0 0 12px;
      display:flex; align-items:center; gap:8px;
    }
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
    <h1><i class="fas fa-id-badge"></i> Hồ sơ lễ tân</h1>
    <div class="sub">
      Cập nhật thông tin tài khoản lễ tân đang đăng nhập
    </div>
  </div>

  <div class="nav">
    <a href="create_appointment.php"><i class="fas fa-plus"></i> Tạo lịch khám</a>
    <a href="appointments_list.php"><i class="fas fa-list"></i> DS lịch khám</a>
    <a href="patients_list.php"><i class="fas fa-users"></i> DS bệnh nhân</a>
    <a class="active" href="profile.php"><i class="fas fa-id-badge"></i> Hồ sơ lễ tân</a>
  </div>

  <?php if ($success): ?>
    <div class="card">
      <div class="alert ok"><i class="fas fa-check"></i> <?php echo h($success); ?></div>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="card">
      <div class="alert bad">
        <div><i class="fas fa-triangle-exclamation"></i> Có lỗi:</div>
        <ul style="margin:8px 0 0 18px;">
          <?php foreach($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="post" autocomplete="off" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"/>

      <div style="margin-bottom:12px;">
        <div class="section-title">
          <i class="fas fa-address-card"></i> Thông tin tài khoản
        </div>

        <div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <div class="pill">
            Mã lễ tân:
            <b>#<?php echo (int)$receptionist_id; ?></b>
          </div>

          <div class="pill">
            Tên đăng nhập:
            <b><?php echo h($username ?: '-'); ?></b>
          </div>

          <div class="pill">
            Ngày tạo:
            <b><?php echo $created_at ? h(date('d/m/Y H:i', strtotime($created_at))) : '-'; ?></b>
          </div>
        </div>
      </div>

      <div class="grid">
        <div>
          <label>Họ tên</label>
          <input type="text" name="full_name" value="<?php echo h($full_name); ?>" placeholder="Nhập họ tên" required>
        </div>

        <div>
          <label>Giới tính</label>
          <select name="gender">
            <option value="">-- Chọn giới tính --</option>
            <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Nam</option>
            <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Nữ</option>
          </select>
        </div>

        <div>
          <label>Số điện thoại</label>
          <input type="text" name="phone" value="<?php echo h($phone); ?>" placeholder="Nhập số điện thoại">
        </div>

        <div>
          <label>Email</label>
          <input type="email" name="email" value="<?php echo h($email); ?>" placeholder="Nhập email">
        </div>

        <div>
          <label>Mã lễ tân</label>
          <input type="text" value="<?php echo (int)$receptionist_id; ?>" class="readonly" readonly>
        </div>

        <div>
          <label>Tên đăng nhập</label>
          <input type="text" value="<?php echo h($username); ?>" class="readonly" readonly>
        </div>
      </div>

      <div class="row">
        <button class="btn btn-gray" type="button" id="btnResetInfo">
          <i class="fas fa-rotate-left"></i> Reset
        </button>

        <button class="btn btn-pri" type="submit" name="save" value="1">
          <i class="fas fa-floppy-disk"></i> Lưu thông tin
        </button>
      </div>
    </form>
  </div>

  <div class="card">
    <form method="post" autocomplete="off" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"/>

      <div style="margin-bottom:12px;">
        <div class="section-title">
          <i class="fas fa-lock"></i> Thay đổi mật khẩu
        </div>
      </div>

      <div class="grid">
        <div style="grid-column:1/-1;">
          <label>Mật khẩu hiện tại</label>
          <input type="password" name="current_password" placeholder="Nhập mật khẩu hiện tại">
        </div>

        <div>
          <label>Mật khẩu mới</label>
          <input type="password" name="new_password" placeholder="Nhập mật khẩu mới">
        </div>

        <div>
          <label>Xác nhận mật khẩu mới</label>
          <input type="password" name="confirm_password" placeholder="Nhập lại mật khẩu mới">
        </div>
      </div>

      <div class="row">
        <button class="btn btn-gray" type="button" id="btnResetPassword">
          <i class="fas fa-rotate-left"></i> Reset
        </button>

        <button class="btn btn-pri" type="submit" name="change_password" value="1">
          <i class="fas fa-key"></i> Đổi mật khẩu
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const RESET_URL = <?php echo json_encode($resetUrl); ?>;
document.getElementById('btnResetInfo').addEventListener('click', ()=>{
  window.location.href = RESET_URL;
});
document.getElementById('btnResetPassword').addEventListener('click', ()=>{
  window.location.href = RESET_URL;
});
</script>
</body>
</html>