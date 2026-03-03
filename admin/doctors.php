<?php
require_once __DIR__ . '/../auth.php';
require_role('admin');

require_once __DIR__ . '/../db.php';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    header('Location: ../login.php');
    exit;
}


function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function genderText($g) { return $g === 'Female' ? 'Nữ' : 'Nam'; }
function statusText($active) { return ((int)$active === 1) ? 'Đang làm' : 'Đã nghỉ'; }

/**
 * Map "specialty" (text) -> department_id theo departments.department_name
 * - Nếu specialty trống / không tìm thấy -> default Khác (id=6) nếu có, nếu không thì NULL
 */
function resolveDepartmentId(PDO $conn, string $specialty): ?int {
    $specialty = trim($specialty);
    if ($specialty === '') {
        // default "Khác" (id=6) nếu tồn tại
        try {
            $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_id = 6 LIMIT 1");
            $stmt->execute();
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (Exception $e) {
            return null;
        }
    }

    try {
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = :name LIMIT 1");
        $stmt->execute(['name' => $specialty]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
    } catch (Exception $e) {
        // ignore
    }

    // Không match được tên -> default "Khác" (id=6) nếu tồn tại
    try {
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_id = 6 LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Exception $e) {
        return null;
    }
}

// ===== HEADER ADMIN (GIỐNG DASHBOARD) =====
$admin_id = (int)$_SESSION['admin_id'];

$admin = null;
try {
    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Nếu không có bảng admins thì fallback session
    $admin = ['full_name' => $_SESSION['full_name'] ?? 'Admin', 'email' => $_SESSION['email'] ?? ''];
}

$admin_name  = $admin['full_name'] ?? ($_SESSION['full_name'] ?? 'Admin');
$admin_email = $admin['email'] ?? ($_SESSION['email'] ?? '');
$admin_initial = mb_substr($admin_name, 0, 1, 'UTF-8');

// ===== AJAX HANDLER =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = (string)$_GET['action'];
        $response = ['success' => false, 'message' => ''];

        // ===== ADD =====
        if ($action === 'add') {
            $full_name = trim($_POST['full_name'] ?? '');
            $gender = ($_POST['gender'] ?? 'Male') === 'Female' ? 'Female' : 'Male';
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $specialty = trim($_POST['specialty'] ?? ''); // giữ tên field như cũ để UI không đổi
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $is_active = (int)($_POST['is_active'] ?? 1);
            $profile_image = null;

            if ($full_name === '' || $email === '' || $username === '' || $password === '') {
                echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ Họ tên, Email, Username, Mật khẩu!']);
                exit;
            }

            // Trùng email/username
            $stmt = $conn->prepare("SELECT COUNT(*) FROM doctors WHERE (email = :email OR username = :username) AND is_deleted = 0");
            $stmt->execute(['email' => $email, 'username' => $username]);
            if ((int)$stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Email hoặc username đã tồn tại!']);
                exit;
            }

            // Upload ảnh
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../Uploads/doctors/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

                $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($_FILES['profile_image']['name']));
                $file_name = uniqid('doc_', true) . '_' . $safeName;
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $file_path)) {
                    $profile_image = $file_name;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Lỗi khi tải lên ảnh!']);
                    exit;
                }
            }

            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            // === FIX DB: specialty -> department_id ===
            $department_id = resolveDepartmentId($conn, $specialty);

            $stmt = $conn->prepare("
                INSERT INTO doctors (full_name, gender, phone, email, department_id, username, password, created_at, profile_image, is_deleted, is_active)
                VALUES (:full_name, :gender, :phone, :email, :department_id, :username, :password, NOW(), :profile_image, 0, :is_active)
            ");
            $stmt->execute([
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'department_id' => $department_id,
                'username' => $username,
                'password' => $hashed_password,
                'profile_image' => $profile_image,
                'is_active' => $is_active ? 1 : 0
            ]);

            $doctor_id = (int)$conn->lastInsertId();

            // lấy lại tên khoa để trả về UI như "specialty"
            $dept_name = $specialty;
            try {
                if ($department_id !== null) {
                    $st2 = $conn->prepare("SELECT department_name FROM departments WHERE department_id = :id LIMIT 1");
                    $st2->execute(['id' => $department_id]);
                    $n = $st2->fetchColumn();
                    if ($n !== false) $dept_name = (string)$n;
                }
            } catch (Exception $e) {}

            $response['success'] = true;
            $response['message'] = 'Thêm bác sĩ thành công!';
            $response['doctor'] = [
                'doctor_id' => $doctor_id,
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'specialty' => $dept_name, // giữ key specialty cho UI
                'username' => $username,
                'is_active' => $is_active ? 1 : 0,
                'profile_image' => $profile_image
            ];
        }

        // ===== EDIT =====
        if ($action === 'edit') {
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $gender = ($_POST['gender'] ?? 'Male') === 'Female' ? 'Female' : 'Male';
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $specialty = trim($_POST['specialty'] ?? ''); // giữ như cũ
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $is_active = (int)($_POST['is_active'] ?? 1);

            if ($doctor_id <= 0 || $full_name === '' || $email === '' || $username === '') {
                echo json_encode(['success' => false, 'message' => 'Thiếu dữ liệu cập nhật!']);
                exit;
            }

            // Trùng email/username
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM doctors
                WHERE (email = :email OR username = :username)
                AND doctor_id != :doctor_id
                AND is_deleted = 0
            ");
            $stmt->execute(['email' => $email, 'username' => $username, 'doctor_id' => $doctor_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Email hoặc username đã tồn tại!']);
                exit;
            }

            // Ảnh hiện tại
            $stmt = $conn->prepare("SELECT profile_image FROM doctors WHERE doctor_id = :doctor_id AND is_deleted = 0");
            $stmt->execute(['doctor_id' => $doctor_id]);
            $current_image = $stmt->fetchColumn();

            if ($current_image === false) {
                echo json_encode(['success' => false, 'message' => 'Bác sĩ không tồn tại hoặc đã bị xóa!']);
                exit;
            }

            $profile_image = $current_image ?: null;

            // Upload ảnh mới
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../Uploads/doctors/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

                $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($_FILES['profile_image']['name']));
                $file_name = uniqid('doc_', true) . '_' . $safeName;
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $file_path)) {
                    if ($current_image && file_exists($upload_dir . $current_image)) unlink($upload_dir . $current_image);
                    $profile_image = $file_name;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Lỗi khi tải lên ảnh!']);
                    exit;
                }
            } elseif (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
                if ($current_image && file_exists(__DIR__ . '/../Uploads/doctors/' . $current_image)) {
                    unlink(__DIR__ . '/../Uploads/doctors/' . $current_image);
                }
                $profile_image = null;
            }

            // === FIX DB: specialty -> department_id ===
            $department_id = resolveDepartmentId($conn, $specialty);

            // Update
            $sql = "UPDATE doctors SET full_name=:full_name, gender=:gender, phone=:phone,
                    email=:email, department_id=:department_id, username=:username, profile_image=:profile_image, is_active=:is_active";
            $params = [
                'doctor_id' => $doctor_id,
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'department_id' => $department_id,
                'username' => $username,
                'profile_image' => $profile_image,
                'is_active' => $is_active ? 1 : 0,
            ];

            if ($password !== '') {
                $sql .= ", password=:password";
                $params['password'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql .= " WHERE doctor_id=:doctor_id AND is_deleted=0";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            // lấy lại tên khoa để trả về UI như "specialty"
            $dept_name = $specialty;
            try {
                if ($department_id !== null) {
                    $st2 = $conn->prepare("SELECT department_name FROM departments WHERE department_id = :id LIMIT 1");
                    $st2->execute(['id' => $department_id]);
                    $n = $st2->fetchColumn();
                    if ($n !== false) $dept_name = (string)$n;
                }
            } catch (Exception $e) {}

            $response['success'] = true;
            $response['message'] = 'Cập nhật bác sĩ thành công!';
            $response['doctor'] = [
                'doctor_id' => $doctor_id,
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'specialty' => $dept_name, // giữ key specialty cho UI
                'username' => $username,
                'is_active' => $is_active ? 1 : 0,
                'profile_image' => $profile_image
            ];
        }

        // ===== CHANGE STATUS (Đổi trạng thái) =====
        if ($action === 'change_status') {
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 1);

            if ($doctor_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Thiếu doctor_id!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT full_name FROM doctors WHERE doctor_id=:doctor_id AND is_deleted=0");
            $stmt->execute(['doctor_id' => $doctor_id]);
            $name = $stmt->fetchColumn();

            if ($name === false) {
                echo json_encode(['success' => false, 'message' => 'Bác sĩ không tồn tại hoặc đã bị xóa!']);
                exit;
            }

            $stmt = $conn->prepare("UPDATE doctors SET is_active=:is_active WHERE doctor_id=:doctor_id AND is_deleted=0");
            $stmt->execute(['is_active' => $is_active ? 1 : 0, 'doctor_id' => $doctor_id]);

            $response['success'] = true;
            $response['message'] = $is_active ? 'Đã chuyển sang trạng thái: Đang làm.' : 'Đã chuyển sang trạng thái: Đã nghỉ.';
            $response['doctor'] = ['doctor_id' => $doctor_id, 'is_active' => $is_active ? 1 : 0];
        }

        // ===== DELETE (ẩn) =====
        if ($action === 'delete') {
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            if ($doctor_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Thiếu doctor_id!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT profile_image FROM doctors WHERE doctor_id=:doctor_id AND is_deleted=0");
            $stmt->execute(['doctor_id' => $doctor_id]);
            $img = $stmt->fetchColumn();

            if ($img === false) {
                echo json_encode(['success' => false, 'message' => 'Bác sĩ không tồn tại hoặc đã bị xóa!']);
                exit;
            }

            if (!empty($img)) {
                $p = __DIR__ . '/../Uploads/doctors/' . $img;
                if (file_exists($p)) unlink($p);
            }

            $stmt = $conn->prepare("UPDATE doctors SET is_deleted=1, profile_image=NULL WHERE doctor_id=:doctor_id");
            $stmt->execute(['doctor_id' => $doctor_id]);

            $response['success'] = true;
            $response['message'] = 'Đã xóa (ẩn) bác sĩ. Dữ liệu lịch khám/đơn thuốc được giữ lại.';
        }

        echo json_encode($response);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

// ===== PAGE DATA =====
// === FIX DB: doctors.specialty -> join departments, alias department_name as specialty ===
$stmt = $conn->query("
    SELECT d.doctor_id,
           d.full_name,
           dep.department_name AS specialty,
           d.department_id,
           d.is_active,
           d.gender,
           d.phone,
           d.email,
           d.username,
           d.profile_image,
           d.created_at
    FROM doctors d
    LEFT JOIN departments dep ON dep.department_id = d.department_id
    WHERE d.is_deleted=0
    ORDER BY d.created_at DESC
");
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - Quản lý bác sĩ</title>
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
        --shadow: 0 6px 18px rgba(17,24,39,0.06);
        --radius: 16px;
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
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
    }
    .panel-head strong{font-size:14px}
    .panel-body{padding:14px}
    .hint{color:var(--muted);font-size:13px;line-height:1.45}

    .table-wrap{width:100%;overflow-x:auto}
    table{width:100%;border-collapse:collapse}
    thead th{
        padding:12px 14px;background:#f8fafc;
        color:#374151;font-size:12px;font-weight:900;
        border-bottom:1px solid var(--line);
        white-space:nowrap;
        text-align:left;
    }
    tbody td{
        padding:14px;border-bottom:1px solid #f1f5f9;
        vertical-align:middle;font-size:14px;
    }
    tbody tr:hover{background:#fafafa}
    tbody tr{cursor:pointer}
    tbody tr.selected{background:#f0f2ff !important}

    .status-pill{
        display:inline-flex;align-items:center;gap:8px;
        padding:6px 10px;border-radius:999px;
        font-size:12px;font-weight:900;
        border:1px solid #e5e7eb;
        background:#fff;
    }
    .dot{width:8px;height:8px;border-radius:999px;background:#16a34a}
    .dot.off{background:#dc2626}

    .detail-top{
        display:flex;align-items:center;gap:12px;flex-wrap:wrap;
        margin-bottom:12px;
    }
    .ava{
        width:56px;height:56px;border-radius:999px;
        border:1px solid #e5e7eb;
        overflow:hidden;
        background:#eef2ff;
        display:grid;place-items:center;
        font-weight:900;
        color:var(--primary);
        flex:0 0 auto;
    }
    .ava img{width:100%;height:100%;object-fit:cover}
    .detail-name{font-weight:900;font-size:16px}
    .detail-sub{color:var(--muted);font-weight:800;font-size:13px;margin-top:2px}

    .kv{display:grid;gap:10px;margin-top:10px}
    .kv .row{
        display:flex;justify-content:space-between;gap:10px;
        border:1px solid #f1f5f9;border-radius:12px;
        padding:10px 12px;
        background:#fff;
    }
    .k{color:var(--muted);font-weight:900;font-size:12px}
    .v{font-weight:900;font-size:13px;text-align:right;max-width:200px;word-break:break-word}

    .actions{
        display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;
    }

    .modal-backdrop{
        position:fixed;inset:0;background:rgba(17,24,39,0.45);
        display:none;align-items:center;justify-content:center;z-index:1000;
        padding:18px;
    }
    .modal{width:100%;max-width:560px;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,0.2)}
    .modal-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
    .modal-head h3{font-size:16px;font-weight:900}
    .modal-body{padding:16px 18px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:520px){.grid{grid-template-columns:1fr}}
    .field{margin-bottom:12px}
    label{display:block;font-size:12px;font-weight:900;color:#374151;margin-bottom:6px}
    input,select{
        width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;
        font-size:14px;font-weight:700;
    }
    input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(102,126,234,0.18)}
    .preview{display:flex;align-items:center;gap:10px;margin-top:10px}
    .preview img{width:52px;height:52px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb}
    .muted{color:var(--muted);font-size:12px}
    .modal-foot{
        padding:14px 18px;border-top:1px solid var(--line);
        display:flex;justify-content:flex-end;gap:10px;background:#fff;
    }

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
</style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <h1>👨‍⚕️ Quản lý bác sĩ</h1>
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
            <li class="nav-item active" onclick="window.location.href='doctors.php'"><span class="icon">👨‍⚕️</span>Bác sĩ</li>
            <li class="nav-item" onclick="window.location.href='patients.php'"><span class="icon">👥</span>Bệnh nhân</li>
            <li class="nav-item" onclick="window.location.href='appointments.php'"><span class="icon">📅</span>Lịch khám</li>
            <li class="nav-item" onclick="window.location.href='receptionists.php'"><span class="icon">👩‍💼</span>Lễ tân</li>
        </ul>
    </aside>

    <main>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Danh sách bác sĩ</h2>
                    <div class="sub">Tổng: <strong><?php echo count($doctors); ?></strong> bác sĩ</div>
                </div>
                <div class="toolbar">
                    <input id="searchInput" class="search" placeholder="Tìm theo mã / tên / chuyên khoa..." />
                    <button class="btn btn-primary" onclick="showAddModal()">➕ Thêm bác sĩ</button>
                </div>
            </div>

            <div class="content-grid">
                <!-- LEFT: LIST -->
                <div class="panel">
                    <div class="panel-head">
                        <strong>Danh sách</strong>
                    </div>
                    <div class="table-wrap">
                        <table id="doctorsTable">
                            <thead>
                                <tr>
                                    <th>Mã</th>
                                    <th>Bác sĩ</th>
                                    <th>Chuyên khoa</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($doctors)): ?>
                                <tr>
                                    <td colspan="4" style="padding:18px;color:#6b7280;font-weight:800;text-align:center">Chưa có bác sĩ nào</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($doctors as $d): ?>
                                    <?php $is_active = isset($d['is_active']) ? (int)$d['is_active'] : 1; ?>
                                    <tr
                                        id="doctor-<?php echo (int)$d['doctor_id']; ?>"
                                        data-doctor='<?php echo h(json_encode([
                                            'doctor_id' => (int)$d['doctor_id'],
                                            'full_name' => $d['full_name'],
                                            'gender' => $d['gender'],
                                            'phone' => $d['phone'] ?? '',
                                            'email' => $d['email'] ?? '',
                                            'specialty' => $d['specialty'] ?? '',
                                            'username' => $d['username'] ?? '',
                                            'profile_image' => $d['profile_image'] ?? null,
                                            'is_active' => $is_active,
                                            'created_at' => $d['created_at'] ?? null,
                                        ], JSON_UNESCAPED_UNICODE)); ?>'
                                    >
                                        <td><strong>#<?php echo (int)$d['doctor_id']; ?></strong></td>
                                        <td><strong><?php echo h($d['full_name']); ?></strong></td>
                                        <td><?php echo h(($d['specialty'] ?? '') ?: 'N/A'); ?></td>
                                        <td>
                                            <span class="status-pill">
                                                <span class="dot <?php echo $is_active ? '' : 'off'; ?>"></span>
                                                <?php echo h(statusText($is_active)); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- RIGHT: DETAILS -->
                <div class="panel" id="detailPanel">
                    <div class="panel-head">
                        <strong>Chi tiết bác sĩ</strong>
                        <button class="btn btn-light" onclick="clearSelection()">Bỏ chọn</button>
                    </div>
                    <div class="panel-body">
                        <div class="hint" id="detailHint">
                            Chọn 1 bác sĩ ở danh sách để xem chi tiết và thao tác.
                        </div>

                        <div id="detailContent" style="display:none">
                            <div class="detail-top">
                                <div class="ava" id="detailAva">BS</div>
                                <div>
                                    <div class="detail-name" id="detailName"></div>
                                    <div class="detail-sub" id="detailSub"></div>
                                </div>
                            </div>

                            <div class="kv">
                                <div class="row"><div class="k">Mã</div><div class="v" id="d_id"></div></div>
                                <div class="row"><div class="k">Trạng thái</div><div class="v" id="d_status"></div></div>
                                <div class="row"><div class="k">Giới tính</div><div class="v" id="d_gender"></div></div>
                                <div class="row"><div class="k">SĐT</div><div class="v" id="d_phone"></div></div>
                                <div class="row"><div class="k">Email</div><div class="v" id="d_email"></div></div>
                                <div class="row"><div class="k">Chuyên khoa</div><div class="v" id="d_specialty"></div></div>
                                <div class="row"><div class="k">Username</div><div class="v" id="d_username"></div></div>
                            </div>

                            <div class="actions">
                                <button class="btn btn-primary" onclick="editSelected()">✏️ Sửa</button>
                                <button class="btn btn-primary" id="statusBtn" onclick="toggleStatus()">🔁 Đổi trạng thái</button>
                                <button class="btn btn-danger" onclick="showDeleteConfirmSelected()">🗑️ Xóa</button>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<!-- Modal Add/Edit -->
<div class="modal-backdrop" id="doctorModal">
    <div class="modal">
        <div class="modal-head">
            <h3 id="modalTitle">Thêm bác sĩ</h3>
            <button class="btn btn-light" onclick="closeModal()" title="Đóng">✖️ Đóng</button>
        </div>
        <form id="doctorForm" class="modal-body" enctype="multipart/form-data">
            <input type="hidden" id="doctor_id" name="doctor_id">

            <div class="grid">
                <div class="field">
                    <label>Họ tên *</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                <div class="field">
                    <label>Trạng thái</label>
                    <select id="is_active" name="is_active">
                        <option value="1">Đang làm</option>
                        <option value="0">Đã nghỉ</option>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label>Giới tính</label>
                    <select id="gender" name="gender">
                        <option value="Male">Nam</option>
                        <option value="Female">Nữ</option>
                    </select>
                </div>
                <div class="field">
                    <label>Số điện thoại</label>
                    <input type="text" id="phone" name="phone">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label>Email *</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="field">
                    <label>Chuyên khoa</label>
                    <!-- giữ input text như cũ, backend sẽ map sang department_id -->
                    <input type="text" id="specialty" name="specialty">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label>Username *</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="field">
                    <label>Mật khẩu <span id="pwHint" class="muted">(bắt buộc khi thêm)</span></label>
                    <input type="password" id="password" name="password">
                </div>
            </div>

            <div class="field">
                <label>Ảnh đại diện</label>
                <input type="file" id="profile_image" name="profile_image" accept="image/*">
                <div class="preview" id="imagePreview"></div>
                <div class="preview" id="removeWrap" style="display:none;margin-top:8px;">
                    <input type="checkbox" id="remove_image" name="remove_image" value="1" style="width:auto;">
                    <label for="remove_image" style="margin:0;font-weight:800;color:#374151;">Xóa ảnh hiện tại</label>
                </div>
            </div>
        </form>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeModal()">Hủy</button>
            <button class="btn btn-primary" onclick="submitDoctorForm()">💾 Lưu</button>
        </div>
    </div>
</div>

<!-- Modal Delete Confirm -->
<div class="modal-backdrop" id="deleteConfirmModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-head">
            <h3>Xác nhận xóa</h3>
            <button class="btn btn-light" onclick="closeDeleteConfirm()" title="Đóng">✖️ Đóng</button>
        </div>
        <div class="modal-body">
            <p id="deleteConfirmMessage" style="color:#374151;font-weight:800;line-height:1.5"></p>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeDeleteConfirm()">Hủy</button>
            <button class="btn btn-danger" id="confirmDeleteBtn">Xóa</button>
        </div>
    </div>
</div>

<script>
/* ===== Toast ===== */
function toast(message, type = 'success') {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'success' ? 'success' : 'error');
    el.innerHTML = `<div>${type === 'success' ? '✅' : '⚠️'}</div>
                    <div>
                        <div>${message}</div>
                        <div class="small">${new Date().toLocaleString()}</div>
                    </div>`;
    wrap.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 200);
    }, 2800);
}

/* ===== State ===== */
let selectedDoctorId = null;

/* ===== Helpers ===== */
function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
function statusPill(active){
    const isOn = Number(active) === 1;
    return `<span class="status-pill"><span class="dot ${isOn ? '' : 'off'}"></span>${isOn ? 'Đang làm' : 'Đã nghỉ'}</span>`;
}
function genderText(g){
    return g === 'Female' ? 'Nữ' : 'Nam';
}
function getRowById(id){ return document.getElementById('doctor-' + id); }
function getDoctorFromRow(row){ return row ? JSON.parse(row.dataset.doctor) : null; }

/* ===== Bind click rows ===== */
function bindRowClicks() {
    document.querySelectorAll('#doctorsTable tbody tr[id^="doctor-"]').forEach(tr => {
        tr.onclick = () => selectRow(tr);
    });
}

/* ===== Select + show details ===== */
function selectRow(tr){
    document.querySelectorAll('#doctorsTable tbody tr').forEach(x => x.classList.remove('selected'));
    tr.classList.add('selected');

    const d = getDoctorFromRow(tr);
    selectedDoctorId = d.doctor_id;

    // show detail panel
    document.getElementById('detailHint').style.display = 'none';
    document.getElementById('detailContent').style.display = 'block';

    // avatar
    const ava = document.getElementById('detailAva');
    if (d.profile_image) {
        ava.innerHTML = `<img src="/Uploads/doctors/${d.profile_image}" alt="ava">`;
    } else {
        const init = (d.full_name || 'BS').trim().charAt(0).toUpperCase();
        ava.textContent = init || 'BS';
    }

    document.getElementById('detailName').textContent = d.full_name || '';
    document.getElementById('detailSub').innerHTML = `${d.specialty || 'N/A'} • ${statusTextLocal(d.is_active)}`;

    document.getElementById('d_id').textContent = '#' + d.doctor_id;
    document.getElementById('d_status').innerHTML = statusPill(d.is_active);
    document.getElementById('d_gender').textContent = genderText(d.gender);
    document.getElementById('d_phone').textContent = d.phone || 'N/A';
    document.getElementById('d_email').textContent = d.email || 'N/A';
    document.getElementById('d_specialty').textContent = d.specialty || 'N/A';
    document.getElementById('d_username').textContent = d.username || 'N/A';

    const statusBtn = document.getElementById('statusBtn');
    const isOn = Number(d.is_active) === 1;
    statusBtn.textContent = isOn ? '🔁 Đổi trạng thái: Nghỉ' : '🔁 Đổi trạng thái: Làm';
}

function statusTextLocal(active){
    return Number(active) === 1 ? 'Đang làm' : 'Đã nghỉ';
}

function clearSelection(){
    selectedDoctorId = null;
    document.querySelectorAll('#doctorsTable tbody tr').forEach(x => x.classList.remove('selected'));
    document.getElementById('detailHint').style.display = 'block';
    document.getElementById('detailContent').style.display = 'none';
}

/* ===== Actions (detail panel) ===== */
function getSelectedDoctor(){
    if(!selectedDoctorId) return null;
    const row = getRowById(selectedDoctorId);
    return getDoctorFromRow(row);
}

function editSelected(){
    const d = getSelectedDoctor();
    if(!d) return toast('Chưa chọn bác sĩ!', 'error');
    openEditFromRow(d.doctor_id);
}

function toggleStatus(){
    const d = getSelectedDoctor();
    if(!d) return toast('Chưa chọn bác sĩ!', 'error');

    const next = (Number(d.is_active) === 1) ? 0 : 1;

    fetch('doctors.php?action=change_status', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `doctor_id=${encodeURIComponent(d.doctor_id)}&is_active=${encodeURIComponent(next)}`
    })
    .then(r => r.json())
    .then(data => {
        toast(data.message, data.success ? 'success' : 'error');
        if(!data.success) return;

        const row = getRowById(d.doctor_id);
        const payload = getDoctorFromRow(row);
        payload.is_active = next;
        row.dataset.doctor = JSON.stringify(payload);

        // update list status (col 3)
        row.children[3].innerHTML = statusPill(next);

        // refresh details
        selectRow(row);
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
}

function showDeleteConfirmSelected(){
    const d = getSelectedDoctor();
    if(!d) return toast('Chưa chọn bác sĩ!', 'error');

    document.getElementById('deleteConfirmMessage').textContent =
        `Bạn có chắc chắn muốn xóa (ẩn) bác sĩ "${d.full_name}"? Dữ liệu lịch khám/đơn thuốc sẽ được giữ lại.`;
    document.getElementById('confirmDeleteBtn').onclick = () => deleteDoctor(d.doctor_id);
    document.getElementById('deleteConfirmModal').style.display = 'flex';
}

function deleteDoctor(id){
    fetch('doctors.php?action=delete', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `doctor_id=${encodeURIComponent(id)}`
    })
    .then(r => r.json())
    .then(data => {
        toast(data.message, data.success ? 'success' : 'error');
        if(!data.success) return;

        const row = getRowById(id);
        if(row) row.remove();
        closeDeleteConfirm();
        clearSelection();
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
}

function closeDeleteConfirm(){ document.getElementById('deleteConfirmModal').style.display = 'none'; }

/* ===== Modal Add/Edit ===== */
const doctorModal = document.getElementById('doctorModal');

function showAddModal(){
    document.getElementById('modalTitle').textContent = 'Thêm bác sĩ';
    document.getElementById('doctorForm').reset();
    document.getElementById('doctor_id').value = '';
    document.getElementById('password').required = true;
    document.getElementById('pwHint').textContent = '(bắt buộc khi thêm)';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('removeWrap').style.display = 'none';
    document.getElementById('is_active').value = '1';
    doctorModal.style.display = 'flex';
}
function closeModal(){ doctorModal.style.display = 'none'; }

function openEditFromRow(id){
    const row = getRowById(id);
    if(!row) return;
    const d = getDoctorFromRow(row);

    document.getElementById('modalTitle').textContent = 'Sửa bác sĩ';
    document.getElementById('doctor_id').value = d.doctor_id;
    document.getElementById('full_name').value = d.full_name || '';
    document.getElementById('gender').value = (d.gender === 'Female') ? 'Female' : 'Male';
    document.getElementById('phone').value = d.phone || '';
    document.getElementById('email').value = d.email || '';
    document.getElementById('specialty').value = d.specialty || '';
    document.getElementById('username').value = d.username || '';
    document.getElementById('is_active').value = (Number(d.is_active) === 1) ? '1' : '0';

    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('pwHint').textContent = '(để trống nếu không đổi)';

    if (d.profile_image) {
        document.getElementById('imagePreview').innerHTML = `<img src="/Uploads/doctors/${d.profile_image}" alt="preview">`;
        document.getElementById('removeWrap').style.display = 'flex';
    } else {
        document.getElementById('imagePreview').innerHTML = '';
        document.getElementById('removeWrap').style.display = 'none';
    }

    doctorModal.style.display = 'flex';
}

/* ===== Preview ảnh ===== */
document.getElementById('profile_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('imagePreview');
    if (!file) { preview.innerHTML = ''; return; }
    const reader = new FileReader();
    reader.onload = (ev) => preview.innerHTML = `<img src="${ev.target.result}" alt="preview">`;
    reader.readAsDataURL(file);
});

/* ===== Submit form (AJAX) ===== */
function submitDoctorForm(){
    document.getElementById('doctorForm').requestSubmit();
}

document.getElementById('doctorForm').addEventListener('submit', function(e){
    e.preventDefault();
    const doctorId = document.getElementById('doctor_id').value;
    const action = doctorId ? 'edit' : 'add';
    const formData = new FormData(this);

    fetch(`doctors.php?action=${action}`, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            toast(data.message, data.success ? 'success' : 'error');
            if(!data.success) return;

            if(action === 'add') addRow(data.doctor);
            else updateRow(data.doctor);

            closeModal();
        })
        .catch(() => toast('Lỗi kết nối server!', 'error'));
});

/* ===== List row helpers ===== */
function addRow(d){
    const tbody = document.querySelector('#doctorsTable tbody');

    // nếu đang có dòng "chưa có"
    if (tbody.querySelector('tr td[colspan="4"]')) tbody.innerHTML = '';

    const tr = document.createElement('tr');
    tr.id = `doctor-${d.doctor_id}`;

    const payload = {
        doctor_id: d.doctor_id,
        full_name: d.full_name,
        gender: d.gender,
        phone: d.phone,
        email: d.email,
        specialty: d.specialty,
        username: d.username,
        is_active: Number(d.is_active) === 1 ? 1 : 0,
        profile_image: d.profile_image ? d.profile_image : null
    };
    tr.dataset.doctor = JSON.stringify(payload);

    tr.innerHTML = `
        <td><strong>#${d.doctor_id}</strong></td>
        <td><strong>${escapeHtml(d.full_name)}</strong></td>
        <td>${escapeHtml(d.specialty || 'N/A')}</td>
        <td>${statusPill(d.is_active)}</td>
    `;
    tbody.prepend(tr);
    bindRowClicks();
}

function updateRow(d){
    const tr = getRowById(d.doctor_id);
    if(!tr) return;

    const payload = {
        doctor_id: d.doctor_id,
        full_name: d.full_name,
        gender: d.gender,
        phone: d.phone,
        email: d.email,
        specialty: d.specialty,
        username: d.username,
        is_active: Number(d.is_active) === 1 ? 1 : 0,
        profile_image: d.profile_image ? d.profile_image : null
    };
    tr.dataset.doctor = JSON.stringify(payload);

    tr.children[1].innerHTML = `<strong>${escapeHtml(d.full_name)}</strong>`;
    tr.children[2].textContent = d.specialty || 'N/A';
    tr.children[3].innerHTML = statusPill(d.is_active);

    bindRowClicks();
    if(selectedDoctorId === d.doctor_id) selectRow(tr);
}

/* ===== Search filter ===== */
document.getElementById('searchInput').addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#doctorsTable tbody tr[id^="doctor-"]').forEach(tr => {
        const d = getDoctorFromRow(tr);
        const text = (`${d.doctor_id} ${d.full_name} ${d.specialty}`).toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
    });
});

/* ===== Init ===== */
bindRowClicks();
</script>
</body>
</html>