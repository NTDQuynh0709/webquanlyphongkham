<?php
require_once __DIR__ . '/../auth.php';
require_role('admin');

require_once __DIR__ . '/../db.php';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    header('Location: ../login.php');
    exit;
}


$admin_id = (int)$_SESSION['admin_id'];

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Lấy thông tin admin (nếu bạn có bảng admins)
$admin = null;
try {
    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
} catch (Exception $e) {
    // Nếu bạn không có bảng admins thì fallback tên
    $admin = ['full_name' => $_SESSION['full_name'] ?? 'Admin', 'email' => $_SESSION['email'] ?? ''];
}

$admin_name = $admin['full_name'] ?? 'Admin';
$admin_initial = mb_substr($admin_name, 0, 1, 'UTF-8');

/**
 * Ngày chọn để xem lịch khám
 */
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// ====== STATS ======
$today = date('Y-m-d');

// Tổng bác sĩ
$stmt = $conn->prepare("SELECT COUNT(*) FROM doctors");
$stmt->execute();
$total_doctors = (int)$stmt->fetchColumn();

// Tổng bệnh nhân
$stmt = $conn->prepare("SELECT COUNT(*) FROM patients");
$stmt->execute();
$total_patients = (int)$stmt->fetchColumn();

// Tổng lịch hôm nay
$stmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=?");
$stmt->execute([$today]);
$total_today_appointments = (int)$stmt->fetchColumn();

// Lịch đang chờ hôm nay
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM appointments 
    WHERE DATE(appointment_date)=?
      AND status IN ('pending')
");
$stmt->execute([$today]);
$total_today_pending = (int)$stmt->fetchColumn();

// ====== APPOINTMENTS BY SELECTED DATE ======
$stmt = $conn->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.symptoms,
        a.status,
        p.full_name AS patient_name,
        p.phone AS patient_phone,
        d.full_name AS doctor_name,
        dep.department_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN departments dep ON a.department_id = dep.department_id
    WHERE DATE(a.appointment_date) = ?
    ORDER BY a.appointment_date ASC
");
$stmt->execute([$selected_date]);
$appointments = $stmt->fetchAll();

// ====== RECENT MEDICAL RECORDS ======
$recent_records = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            mr.record_id,
            mr.created_at,
            mr.diagnosis,
            p.full_name AS patient_name,
            d.full_name AS doctor_name
        FROM medical_records mr
        JOIN appointments a ON mr.appointment_id = a.appointment_id
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN doctors d ON mr.doctor_id = d.doctor_id
        ORDER BY mr.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_records = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_records = [];
}

// ====== STATUS MAP ======
function statusLabel($status) {
    switch ($status) {
        case 'pending': return ['Chờ khám', 'pending'];
        case 'completed': return ['Đã khám', 'completed'];
        case 'cancelled': return ['Đã hủy', 'cancelled'];
        default: return ['Không rõ', 'unknown'];
    }
}

$selected_date_label = date('d/m/Y', strtotime($selected_date));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Dashboard</title>
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
            --shadow2: 0 6px 18px rgba(17,24,39,0.06);
            --radius: 16px;
        }
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--text)}

        /* Header */
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

        /* Layout */
        .layout{
            max-width:1200px;margin:0 auto;padding:22px;
            display:grid;grid-template-columns:260px 1fr;gap:18px;
        }
        @media(max-width:980px){.layout{grid-template-columns:1fr}}

        /* Sidebar */
        .sidebar{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:var(--radius);
            box-shadow:var(--shadow2);
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

        /* Content */
        .content{display:grid;gap:16px}
        .card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:var(--radius);
            box-shadow:var(--shadow2);
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
        .badge{
            background:var(--primary);
            color:#fff;
            padding:4px 10px;border-radius:999px;
            font-size:12px;font-weight:900;
        }

        /* Stats */
        .stats{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
            padding:16px 18px;
        }
        @media(max-width:980px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:520px){.stats{grid-template-columns:1fr}}
        .stat{
            border:1px solid var(--line);
            border-radius:16px;
            padding:14px;
            background:#fff;
            display:flex;gap:12px;align-items:center;
        }
        .stat-ico{
            width:44px;height:44px;border-radius:14px;
            display:grid;place-items:center;font-size:20px;font-weight:900;
            background:#f0f2ff;color:var(--primary);
        }
        .stat strong{font-size:22px;display:block}
        .stat span{color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}

        /* Filters */
        .filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .date-input{
            padding:10px 12px;
            border:1px solid #e5e7eb;
            border-radius:12px;
            background:#fff;
            font-size:14px;font-weight:800;
        }
        .date-input:focus{
            outline:none;border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(102,126,234,0.18);
        }

        /* Table */
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
            vertical-align:top;font-size:14px;
        }
        tbody tr:hover{background:#fafafa}

        .small{color:var(--muted);font-size:12px;margin-top:3px}
        .status{
            display:inline-block;
            padding:6px 12px;border-radius:999px;
            font-size:12px;font-weight:900;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .status.pending,.status{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
        .status.completed{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
        .status.cancelled{background:#fef2f2;color:#991b1b;border-color:#fecaca}
        .status.unknown{background:#f3f4f6;color:#374151;border-color:#e5e7eb}

        .btn{
            display:inline-flex;align-items:center;gap:8px;
            padding:9px 12px;border-radius:12px;
            font-weight:900;font-size:13px;text-decoration:none;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
        .btn-primary:hover{filter:brightness(.97)}
        .btn-light{background:#f9fafb;color:#111827;border-color:#e5e7eb}
        .btn-light:hover{background:#f3f4f6}

        .empty{
            padding:36px 16px;text-align:center;color:var(--muted);
        }
        .empty .ico{font-size:40px;margin-bottom:10px;opacity:.75}
        .empty h3{color:#111827;margin-bottom:6px;font-weight:900}

        .two-col{
            display:grid;grid-template-columns:1.2fr .8fr;gap:16px;
        }
        @media(max-width:980px){.two-col{grid-template-columns:1fr}}
        .list{
            padding:16px 18px;
            display:grid;gap:10px;
        }
        .item{
            border:1px solid var(--line);
            border-radius:14px;
            padding:12px;
            background:#fff;
        }
        .item strong{display:block}
        .item .muted{color:var(--muted);font-size:12px;margin-top:4px}
    </style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <h1>📊 Trang chủ</h1>
        
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
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="nav-title">Quản trị</div>
        <ul class="nav">
            
            <li class="nav-item active" onclick="window.location.href='dashboard.php'"><span class="icon">📊</span>Trang chủ</li>
            <li class="nav-item" onclick="window.location.href='doctors.php'"><span class="icon">👨‍⚕️</span>Bác sĩ</li>
            <li class="nav-item" onclick="window.location.href='patients.php'"><span class="icon">👥</span>Bệnh nhân</li>
            <li class="nav-item" onclick="window.location.href='appointments.php'"><span class="icon">📅</span>Lịch khám</li>
            <li class="nav-item " onclick="window.location.href='receptionists.php'"><span class="icon">👩‍💼</span>Lễ tân</li>
        </ul>
    </aside>

    <!-- CONTENT -->
    <main class="content">

        <!-- STATS CARD -->
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Thống kê nhanh</h2>
                    <div class="sub">Tổng quan hệ thống hôm nay</div>
                </div>
                <div class="filters">
                    <a class="btn btn-light" href="doctors.php">+ Thêm bác sĩ</a>
                    <a class="btn btn-light" href="patients.php">+ Thêm bệnh nhân</a>
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-ico">👨‍⚕️</div>
                    <div>
                        <strong><?php echo $total_doctors; ?></strong>
                        <span>Bác sĩ</span>
                    </div>
                </div>
                <div class="stat">
                    <div class="stat-ico">👥</div>
                    <div>
                        <strong><?php echo $total_patients; ?></strong>
                        <span>Bệnh nhân</span>
                    </div>
                </div>
                <div class="stat">
                    <div class="stat-ico">📅</div>
                    <div>
                        <strong><?php echo $total_today_appointments; ?></strong>
                        <span>Lịch hôm nay</span>
                    </div>
                </div>
                <div class="stat">
                    <div class="stat-ico">⏳</div>
                    <div>
                        <strong><?php echo $total_today_pending; ?></strong>
                        <span>Đang chờ</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="two-col">
            <!-- APPOINTMENTS BY DATE -->
            <section class="card">
                <div class="card-head">
                    <div>
                        <h2>Lịch khám theo ngày <span class="badge"><?php echo count($appointments); ?></span></h2>
                        <div class="sub">Ngày: <strong><?php echo h($selected_date_label); ?></strong> • Chọn ngày để tự xem</div>
                    </div>
                    <div class="filters">
                        <form id="dateForm" method="GET">
                            <input id="datePicker" class="date-input" type="date" name="date" value="<?php echo h($selected_date); ?>">
                        </form>
                        <a class="btn btn-primary" href="appointments.php">Quản lý lịch</a>
                    </div>
                </div>

                <?php if (empty($appointments)): ?>
                    <div class="empty">
                        <div class="ico">📭</div>
                        <h3>Không có lịch khám</h3>
                        <p>Không có lịch nào vào ngày <?php echo h($selected_date_label); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Giờ</th>
                                    <th>Bệnh nhân</th>
                                    <th>Bác sĩ</th>
                                    <th>Khoa</th>
                                    <th>Triệu chứng</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($appointments as $a): ?>
                                <?php
                                    [$st_text, $st_class] = statusLabel($a['status'] ?? '');
                                    $time = date('H:i', strtotime($a['appointment_date']));
                                    $sym = $a['symptoms'] ?? '';
                                    $sym_short = (mb_strlen($sym, 'UTF-8') > 55) ? mb_substr($sym, 0, 55, 'UTF-8').'...' : $sym;
                                ?>
                                <tr>
                                    <td><strong><?php echo h($time); ?></strong></td>
                                    <td>
                                        <strong><?php echo h($a['patient_name']); ?></strong>
                                        <div class="small"><?php echo h($a['patient_phone']); ?></div>
                                    </td>
                                    <td><?php echo h($a['doctor_name']); ?></td>
                                    <td><?php echo h($a['department_name'] ?? ''); ?></td>
                                    <td title="<?php echo h($sym); ?>"><?php echo h($sym_short); ?></td>
                                    <td><span class="status <?php echo h($st_class); ?>"><?php echo h($st_text); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- RECENT RECORDS -->
            <section class="card">
                <div class="card-head">
                    <div>
                        <h2>Hồ sơ gần đây</h2>
                        <div class="sub">5 hồ sơ bệnh án mới nhất</div>
                    </div>
                    <a class="btn btn-primary" href="records.php">Xem tất cả</a>
                </div>

                <?php if (empty($recent_records)): ?>
                    <div class="empty">
                        <div class="ico">📄</div>
                        <h3>Chưa có hồ sơ</h3>
                        <p>Hệ thống chưa có hồ sơ bệnh án nào.</p>
                    </div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($recent_records as $r): ?>
                            <?php
                                $diag = $r['diagnosis'] ?? '';
                                $diag_short = (mb_strlen($diag, 'UTF-8') > 70) ? mb_substr($diag, 0, 70, 'UTF-8').'...' : $diag;
                            ?>
                            <div class="item">
                                <strong><?php echo h($r['patient_name']); ?></strong>
                                <div class="muted">Bác sĩ: <?php echo h($r['doctor_name']); ?> • <?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></div>
                                <div class="muted" title="<?php echo h($diag); ?>">Chẩn đoán: <?php echo h($diag_short); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
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