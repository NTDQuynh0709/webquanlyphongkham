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
function genderText($g) { return ($g === 'Female') ? 'Nữ' : 'Nam'; }

function statusText($status) {
    $status = (int)$status;
    if ($status === 1) return 'Đang làm';
    if ($status === 2) return 'Tạm ngưng';
    return 'Đã nghỉ';
}

// ===== HEADER ADMIN =====
$admin_id = (int)$_SESSION['admin_id'];

$admin = null;
try {
    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $admin = null;
}

if (!$admin) {
    $admin = [
        'full_name' => $_SESSION['full_name'] ?? 'Admin',
        'email'     => $_SESSION['email'] ?? ''
    ];
}

$admin_name    = $admin['full_name'] ?? 'Admin';
$admin_email   = $admin['email'] ?? '';
$admin_initial = mb_substr($admin_name, 0, 1, 'UTF-8');

/* =========================
   AJAX HANDLER
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = (string)$_GET['action'];
        $response = ['success' => false, 'message' => ''];

        // ===== ADD =====
        if ($action === 'add') {
            $full_name = trim($_POST['full_name'] ?? '');
            $gender = (($_POST['gender'] ?? 'Male') === 'Female') ? 'Female' : 'Male';
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $status = (int)($_POST['status'] ?? 1);

            if (!in_array($status, [0, 1, 2], true)) $status = 1;

            if ($full_name === '' || $email === '' || $username === '' || $password === '') {
                echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ Họ tên, Email, Username, Mật khẩu!']);
                exit;
            }

            // Trùng email/username
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM receptionists
                WHERE (email = :email OR username = :username)
            ");
            $stmt->execute([
                'email' => $email,
                'username' => $username
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Email hoặc username đã tồn tại!']);
                exit;
            }

            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("
                INSERT INTO receptionists (
                    full_name, gender, phone, email, username, password, status, created_at
                )
                VALUES (
                    :full_name, :gender, :phone, :email, :username, :password, :status, NOW()
                )
            ");
            $stmt->execute([
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'username' => $username,
                'password' => $hashed_password,
                'status' => $status
            ]);

            $receptionist_id = (int)$conn->lastInsertId();

            $response['success'] = true;
            $response['message'] = 'Thêm lễ tân thành công!';
            $response['receptionist'] = [
                'receptionist_id' => $receptionist_id,
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'username' => $username,
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // ===== EDIT =====
        if ($action === 'edit') {
            $receptionist_id = (int)($_POST['receptionist_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $gender = (($_POST['gender'] ?? 'Male') === 'Female') ? 'Female' : 'Male';
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $status = (int)($_POST['status'] ?? 1);

            if (!in_array($status, [0, 1, 2], true)) $status = 1;

            if ($receptionist_id <= 0 || $full_name === '' || $email === '' || $username === '') {
                echo json_encode(['success' => false, 'message' => 'Thiếu dữ liệu cập nhật!']);
                exit;
            }

            // Trùng email/username
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM receptionists
                WHERE (email = :email OR username = :username)
                AND receptionist_id != :receptionist_id
            ");
            $stmt->execute([
                'email' => $email,
                'username' => $username,
                'receptionist_id' => $receptionist_id
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Email hoặc username đã tồn tại!']);
                exit;
            }

            // check tồn tại
            $stmt = $conn->prepare("SELECT receptionist_id FROM receptionists WHERE receptionist_id = :id");
            $stmt->execute(['id' => $receptionist_id]);
            if (!$stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Lễ tân không tồn tại!']);
                exit;
            }

            $sql = "
                UPDATE receptionists SET
                    full_name = :full_name,
                    gender = :gender,
                    phone = :phone,
                    email = :email,
                    username = :username,
                    status = :status
            ";
            $params = [
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'username' => $username,
                'status' => $status,
                'id' => $receptionist_id
            ];

            if ($password !== '') {
                $sql .= ", password = :password";
                $params['password'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql .= " WHERE receptionist_id = :id";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            $response['success'] = true;
            $response['message'] = 'Cập nhật lễ tân thành công!';
            $response['receptionist'] = [
                'receptionist_id' => $receptionist_id,
                'full_name' => $full_name,
                'gender' => $gender,
                'phone' => $phone,
                'email' => $email,
                'username' => $username,
                'status' => $status
            ];
        }

        // ===== CHANGE STATUS =====
        if ($action === 'change_status') {
            $receptionist_id = (int)($_POST['receptionist_id'] ?? 0);
            $status = (int)($_POST['status'] ?? 1);

            if (!in_array($status, [0, 1, 2], true)) $status = 1;

            if ($receptionist_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Thiếu receptionist_id!']);
                exit;
            }

            $stmt = $conn->prepare("SELECT full_name FROM receptionists WHERE receptionist_id = :id");
            $stmt->execute(['id' => $receptionist_id]);
            $name = $stmt->fetchColumn();

            if ($name === false) {
                echo json_encode(['success' => false, 'message' => 'Lễ tân không tồn tại!']);
                exit;
            }

            $stmt = $conn->prepare("UPDATE receptionists SET status = :status WHERE receptionist_id = :id");
            $stmt->execute([
                'status' => $status,
                'id' => $receptionist_id
            ]);

            $response['success'] = true;
            $response['message'] = 'Đã cập nhật trạng thái lễ tân thành công!';
            $response['receptionist'] = [
                'receptionist_id' => $receptionist_id,
                'status' => $status
            ];
        }

        echo json_encode($response);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

/* =========================
   PAGE DATA
========================= */
$stmt = $conn->query("
    SELECT receptionist_id, full_name, gender, phone, email, username, status, created_at
    FROM receptionists
    ORDER BY created_at DESC
");
$receptionists = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($receptionists);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - Quản lý lễ tân</title>
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
        display:flex;justify-content:space-between;align-items:center;gap:10px;
    }
    .panel-head strong{font-size:14px}

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
    .dot.pause{background:#f59e0b}
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
        font-weight:900;color:var(--primary);
        flex:0 0 auto;
    }
    .detail-name{font-weight:900;font-size:16px}
    .detail-sub{color:var(--muted);font-weight:800;font-size:13px;margin-top:2px}

    .kv{display:grid;gap:10px;margin-top:10px}
    .kv .row{
        display:flex;justify-content:space-between;gap:10px;
        border:1px solid #f1f5f9;border-radius:12px;
        padding:10px 12px;background:#fff;
    }
    .k{color:var(--muted);font-weight:900;font-size:12px}
    .v{font-weight:900;font-size:13px;text-align:right;max-width:220px;word-break:break-word}

    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}

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
        <h1>👩‍💼 Quản lý lễ tân</h1>
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
            <li class="nav-item" onclick="window.location.href='patients.php'"><span class="icon">👥</span>Bệnh nhân</li>
            <li class="nav-item" onclick="window.location.href='appointments.php'"><span class="icon">📅</span>Lịch khám</li>
            <li class="nav-item active" onclick="window.location.href='receptionists.php'"><span class="icon">👩‍💼</span>Lễ tân</li>
        </ul>
    </aside>

    <main>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Danh sách lễ tân</h2>
                    <div class="sub">Tổng: <strong><?php echo (int)$total; ?></strong> tài khoản</div>
                </div>
                <div class="toolbar">
                    <input id="searchInput" class="search" placeholder="Tìm theo mã / tên / SĐT / email / username..." />
                    <button class="btn btn-primary" onclick="showAddModal()">➕ Thêm lễ tân</button>
                </div>
            </div>

            <div class="content-grid">
                <div class="panel">
                    <div class="panel-head">
                        <strong>Danh sách</strong>
                    </div>
                    <div class="table-wrap">
                        <table id="receptionistsTable">
                            <thead>
                                <tr>
                                    <th>Mã</th>
                                    <th>Họ tên</th>
                                    <th>Liên hệ</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($receptionists)): ?>
                                <tr>
                                    <td colspan="4" style="padding:18px;color:#6b7280;font-weight:800;text-align:center">Chưa có lễ tân nào</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($receptionists as $r): ?>
                                    <?php
                                        $status = isset($r['status']) ? (int)$r['status'] : 1;
                                        $contact = trim(($r['phone'] ?? '') . ' • ' . ($r['email'] ?? ''), " \t\n\r\0\x0B•");
                                        if ($contact === '') $contact = 'N/A';
                                    ?>
                                    <tr
                                        id="receptionist-<?php echo (int)$r['receptionist_id']; ?>"
                                        data-receptionist='<?php echo h(json_encode([
                                            'receptionist_id' => (int)$r['receptionist_id'],
                                            'full_name' => $r['full_name'],
                                            'gender' => $r['gender'] ?? 'Male',
                                            'phone' => $r['phone'] ?? '',
                                            'email' => $r['email'] ?? '',
                                            'username' => $r['username'] ?? '',
                                            'status' => $status,
                                            'created_at' => $r['created_at'] ?? null,
                                        ], JSON_UNESCAPED_UNICODE)); ?>'
                                    >
                                        <td><strong>#<?php echo (int)$r['receptionist_id']; ?></strong></td>
                                        <td><strong><?php echo h($r['full_name']); ?></strong></td>
                                        <td><?php echo h($contact); ?></td>
                                        <td>
                                            <span class="status-pill">
                                                <?php if ($status === 1): ?>
                                                    <span class="dot"></span>
                                                <?php elseif ($status === 2): ?>
                                                    <span class="dot pause"></span>
                                                <?php else: ?>
                                                    <span class="dot off"></span>
                                                <?php endif; ?>
                                                <?php echo h(statusText($status)); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel" id="detailPanel">
                    <div class="panel-head">
                        <strong>Chi tiết lễ tân</strong>
                        <button class="btn btn-light" onclick="clearSelection()">Bỏ chọn</button>
                    </div>
                    <div class="panel-body" style="padding:14px">
                        <div class="muted" id="detailHint" style="font-weight:800;line-height:1.45">
                            Chọn 1 lễ tân ở danh sách để xem chi tiết và thao tác.
                        </div>

                        <div id="detailContent" style="display:none">
                            <div class="detail-top">
                                <div class="ava" id="detailAva">LT</div>
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
                                <div class="row"><div class="k">Username</div><div class="v" id="d_username"></div></div>
                            </div>

                            <div class="actions">
                                <button class="btn btn-primary" onclick="editSelected()">✏️ Sửa</button>
                                <button class="btn btn-primary" onclick="openStatusModal()">🔁 Cập nhật trạng thái</button>
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
<div class="modal-backdrop" id="receptionistModal">
    <div class="modal">
        <div class="modal-head">
            <h3 id="modalTitle">Thêm lễ tân</h3>
            <button class="btn btn-light" onclick="closeModal()" title="Đóng">✖️ Đóng</button>
        </div>

        <form id="receptionistForm" class="modal-body">
            <input type="hidden" id="receptionist_id" name="receptionist_id">

            <div class="grid">
                <div class="field">
                    <label>Họ tên *</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                <div class="field">
                    <label>Trạng thái</label>
                    <select id="status" name="status">
                        <option value="1">Đang làm</option>
                        <option value="2">Tạm ngưng</option>
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
                    <input type="text" id="phone" name="phone" placeholder="VD: 0909xxxxxx">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label>Email *</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="field">
                    <label>Username *</label>
                    <input type="text" id="username" name="username" required>
                </div>
            </div>

            <div class="field">
                <label>Mật khẩu <span id="pwHint" class="muted">(bắt buộc khi thêm)</span></label>
                <input type="password" id="password" name="password">
            </div>
        </form>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeModal()">Hủy</button>
            <button class="btn btn-primary" onclick="submitReceptionistForm()">💾 Lưu</button>
        </div>
    </div>
</div>

<!-- Modal Status -->
<div class="modal-backdrop" id="statusModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-head">
            <h3>Cập nhật trạng thái lễ tân</h3>
            <button class="btn btn-light" onclick="closeStatusModal()" title="Đóng">✖️ Đóng</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="status_receptionist_id">
            <div class="field">
                <label>Trạng thái</label>
                <select id="status_select">
                    <option value="1">Đang làm</option>
                    <option value="2">Tạm ngưng</option>
                    <option value="0">Đã nghỉ</option>
                </select>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeStatusModal()">Hủy</button>
            <button class="btn btn-primary" onclick="submitStatusChange()">Lưu trạng thái</button>
        </div>
    </div>
</div>

<script>
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

function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

function genderText(g){ return g === 'Female' ? 'Nữ' : 'Nam'; }

function statusTextLocal(status){
    status = Number(status);
    if (status === 1) return 'Đang làm';
    if (status === 2) return 'Tạm ngưng';
    return 'Đã nghỉ';
}

function statusPill(status){
    status = Number(status);
    let cls = 'off';
    let text = 'Đã nghỉ';

    if (status === 1) {
        cls = '';
        text = 'Đang làm';
    } else if (status === 2) {
        cls = 'pause';
        text = 'Tạm ngưng';
    }

    return `<span class="status-pill"><span class="dot ${cls}"></span>${text}</span>`;
}

let selectedId = null;

function getRowById(id){ return document.getElementById('receptionist-' + id); }
function getDataFromRow(row){ return row ? JSON.parse(row.dataset.receptionist) : null; }

function bindRowClicks() {
    document.querySelectorAll('#receptionistsTable tbody tr[id^="receptionist-"]').forEach(tr => {
        tr.onclick = () => selectRow(tr);
    });
}

function selectRow(tr){
    document.querySelectorAll('#receptionistsTable tbody tr').forEach(x => x.classList.remove('selected'));
    tr.classList.add('selected');

    const r = getDataFromRow(tr);
    selectedId = r.receptionist_id;

    document.getElementById('detailHint').style.display = 'none';
    document.getElementById('detailContent').style.display = 'block';

    const init = (r.full_name || 'LT').trim().charAt(0).toUpperCase();
    document.getElementById('detailAva').textContent = init || 'LT';
    document.getElementById('detailName').textContent = r.full_name || '';
    document.getElementById('detailSub').textContent = statusTextLocal(r.status);

    document.getElementById('d_id').textContent = '#' + r.receptionist_id;
    document.getElementById('d_status').innerHTML = statusPill(r.status);
    document.getElementById('d_gender').textContent = genderText(r.gender);
    document.getElementById('d_phone').textContent = r.phone || 'N/A';
    document.getElementById('d_email').textContent = r.email || 'N/A';
    document.getElementById('d_username').textContent = r.username || 'N/A';
}

function clearSelection(){
    selectedId = null;
    document.querySelectorAll('#receptionistsTable tbody tr').forEach(x => x.classList.remove('selected'));
    document.getElementById('detailHint').style.display = 'block';
    document.getElementById('detailContent').style.display = 'none';
}

function getSelected(){
    if(!selectedId) return null;
    const row = getRowById(selectedId);
    return getDataFromRow(row);
}

function editSelected(){
    const r = getSelected();
    if(!r) return toast('Chưa chọn lễ tân!', 'error');
    openEdit(r.receptionist_id);
}

const statusModal = document.getElementById('statusModal');

function openStatusModal(){
    const r = getSelected();
    if(!r) return toast('Chưa chọn lễ tân!', 'error');

    document.getElementById('status_receptionist_id').value = r.receptionist_id;
    document.getElementById('status_select').value = String(r.status ?? 1);
    statusModal.style.display = 'flex';
}

function closeStatusModal(){
    statusModal.style.display = 'none';
}

function submitStatusChange(){
    const receptionistId = document.getElementById('status_receptionist_id').value;
    const status = document.getElementById('status_select').value;

    fetch('receptionists.php?action=change_status', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `receptionist_id=${encodeURIComponent(receptionistId)}&status=${encodeURIComponent(status)}`
    })
    .then(r => r.json())
    .then(data => {
        toast(data.message, data.success ? 'success' : 'error');
        if(!data.success) return;

        const row = getRowById(receptionistId);
        const payload = getDataFromRow(row);
        payload.status = Number(status);
        row.dataset.receptionist = JSON.stringify(payload);
        row.children[3].innerHTML = statusPill(status);

        selectRow(row);
        closeStatusModal();
    })
    .catch(() => toast('Lỗi kết nối server!', 'error'));
}

const modal = document.getElementById('receptionistModal');

function showAddModal(){
    document.getElementById('modalTitle').textContent = 'Thêm lễ tân';
    document.getElementById('receptionistForm').reset();
    document.getElementById('receptionist_id').value = '';
    document.getElementById('password').required = true;
    document.getElementById('pwHint').textContent = '(bắt buộc khi thêm)';
    document.getElementById('status').value = '1';
    modal.style.display = 'flex';
}

function closeModal(){ modal.style.display = 'none'; }

function openEdit(id){
    const row = getRowById(id);
    if(!row) return;
    const r = getDataFromRow(row);

    document.getElementById('modalTitle').textContent = 'Sửa lễ tân';
    document.getElementById('receptionist_id').value = r.receptionist_id;
    document.getElementById('full_name').value = r.full_name || '';
    document.getElementById('gender').value = (r.gender === 'Female') ? 'Female' : 'Male';
    document.getElementById('phone').value = r.phone || '';
    document.getElementById('email').value = r.email || '';
    document.getElementById('username').value = r.username || '';
    document.getElementById('status').value = String(r.status ?? 1);

    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('pwHint').textContent = '(để trống nếu không đổi)';

    modal.style.display = 'flex';
}

function submitReceptionistForm(){
    document.getElementById('receptionistForm').requestSubmit();
}

document.getElementById('receptionistForm').addEventListener('submit', function(e){
    e.preventDefault();
    const id = document.getElementById('receptionist_id').value;
    const action = id ? 'edit' : 'add';

    const formData = new FormData(this);

    fetch(`receptionists.php?action=${action}`, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            toast(data.message, data.success ? 'success' : 'error');
            if(!data.success) return;

            if(action === 'add') addRow(data.receptionist);
            else updateRow(data.receptionist);

            closeModal();
        })
        .catch(() => toast('Lỗi kết nối server!', 'error'));
});

function addRow(r){
    const tbody = document.querySelector('#receptionistsTable tbody');
    if (tbody.querySelector('tr td[colspan="4"]')) tbody.innerHTML = '';

    const tr = document.createElement('tr');
    tr.id = `receptionist-${r.receptionist_id}`;

    const payload = {
        receptionist_id: r.receptionist_id,
        full_name: r.full_name,
        gender: r.gender,
        phone: r.phone,
        email: r.email,
        username: r.username,
        status: Number(r.status)
    };
    tr.dataset.receptionist = JSON.stringify(payload);

    const contact = [r.phone, r.email].filter(Boolean).join(' • ') || 'N/A';

    tr.innerHTML = `
        <td><strong>#${r.receptionist_id}</strong></td>
        <td><strong>${escapeHtml(r.full_name)}</strong></td>
        <td>${escapeHtml(contact)}</td>
        <td>${statusPill(r.status)}</td>
    `;
    tbody.prepend(tr);
    bindRowClicks();
}

function updateRow(r){
    const tr = getRowById(r.receptionist_id);
    if(!tr) return;

    const payload = getDataFromRow(tr);
    payload.full_name = r.full_name;
    payload.gender = r.gender;
    payload.phone = r.phone;
    payload.email = r.email;
    payload.username = r.username;
    payload.status = Number(r.status);
    tr.dataset.receptionist = JSON.stringify(payload);

    const contact = [r.phone, r.email].filter(Boolean).join(' • ') || 'N/A';

    tr.children[1].innerHTML = `<strong>${escapeHtml(r.full_name)}</strong>`;
    tr.children[2].textContent = contact;
    tr.children[3].innerHTML = statusPill(payload.status);

    bindRowClicks();
    if(selectedId === r.receptionist_id) selectRow(tr);
}

document.getElementById('searchInput').addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#receptionistsTable tbody tr[id^="receptionist-"]').forEach(tr => {
        const r = getDataFromRow(tr);
        const text = (`${r.receptionist_id} ${r.full_name} ${r.phone} ${r.email} ${r.username}`).toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
    });
});

bindRowClicks();
</script>
</body>
</html>