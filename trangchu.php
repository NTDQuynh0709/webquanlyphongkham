<?php
// ============================
// index.php (FULL)
// Theme: Blue -> Purple (xanh -> tím)
// Doctors: load from DB (ít thông tin)
// ============================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ====== DB CONFIG ======
$DB_HOST = "localhost";
$DB_NAME = "phongkhamsql";
$DB_USER = "root";
$DB_PASS = "";

// Link trang đặt lịch (đổi tên file nếu bạn dùng file khác)
$BOOKING_URL = "booking.php";

// ====== Helpers ======
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function initial_letter(string $name): string {
    $name = trim($name);
    if ($name === '') return 'A';
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}

// ====== Connect DB ======
$pdo = null;
$db_error = null;

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $ex) {
    $db_error = $ex->getMessage();
}

// ====== Load doctors (minimal) ======
$doctors = [];
$doctors_count = 0;

if ($pdo) {
    try {
        // Nếu bảng doctors của bạn KHÔNG có is_active/is_deleted/profile_image
        // hãy sửa query theo đúng schema của bạn.
        $stmt = $pdo->query("
            SELECT
                d.doctor_id,
                d.full_name,
                COALESCE(d.profile_image,'') AS profile_image,
                COALESCE(dep.department_name,'Chưa phân khoa') AS department_name
            FROM doctors d
            LEFT JOIN departments dep ON dep.department_id = d.department_id
            WHERE (d.is_deleted = 0 OR d.is_deleted IS NULL)
              AND (d.is_active = 1 OR d.is_active IS NULL)
            ORDER BY dep.department_name ASC, d.full_name ASC
            LIMIT 12
        ");
        $doctors = $stmt->fetchAll();
        $doctors_count = count($doctors);
    } catch (Throwable $ex) {
        $db_error = $db_error ?: $ex->getMessage();
    }
}

// ====== Page content data ======
$clinic = [
    'name' => 'Phòng Khám ABC',
    'tagline' => 'Chăm sóc sức khỏe toàn diện',
    'hotline' => '(012) 345 6789',
    'email' => 'info@phongkhamabc.com',
    'address' => '123 Đường ABC, Quận XYZ, TP. HCM',
    'hours' => [
        'weekdays' => 'Thứ 2 - Thứ 6: 08:00 - 17:00',
        'sat' => 'Thứ 7: 08:00 - 12:00',
        'sun' => 'Chủ nhật: Nghỉ'
    ]
];

// KPI hiển thị đẹp
$kpi = [
    ['value' => '15+', 'label' => 'Năm kinh nghiệm', 'icon' => 'fa-award'],
    ['value' => '10k+', 'label' => 'Bệnh nhân hài lòng', 'icon' => 'fa-face-smile'],
    ['value' => $pdo ? ($doctors_count . '+') : '50+', 'label' => 'Bác sĩ/Chuyên gia', 'icon' => 'fa-user-doctor'],
    ['value' => '24/7', 'label' => 'Hỗ trợ', 'icon' => 'fa-headset'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo e($clinic['name']); ?> - <?php echo e($clinic['tagline']); ?></title>

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
            --line: rgba(99,102,241,.10);

            --blue: #0ea5e9;
            --blue2:#2563eb;
            --purple:#7c3aed;

            --grad: linear-gradient(135deg, var(--blue) 0%, var(--blue2) 45%, var(--purple) 100%);
            --gradSoft: linear-gradient(135deg, rgba(14,165,233,.16) 0%, rgba(37,99,235,.14) 50%, rgba(124,58,237,.14) 100%);

            --shadow: 0 14px 34px rgba(11,18,32,.10);
            --shadow2: 0 10px 22px rgba(11,18,32,.07);
            --radius: 18px;
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

        a{ text-decoration: none; }
        .container{ max-width: 1160px; }

        /* ===== Top Bar ===== */
        .topbar{
            background: var(--grad);
            color:#fff;
            padding: 10px 0;
        }
        .topbar .mini{
            display:flex; flex-wrap:wrap; gap:14px;
            align-items:center;
            font-weight:900;
        }
        .topbar .mini .item{
            display:flex; align-items:center; gap:10px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.18);
            padding: 8px 10px;
            border-radius: 14px;
            backdrop-filter: blur(8px);
        }
        .topbar .mini small{
            display:block;
            opacity:.92;
            font-weight:800;
            font-size: 11px;
        }
        .topbar .mini strong{
            display:block;
            font-weight: 900;
            line-height: 1.05;
            font-size: 13px;
        }
        .topbar .social{
            display:flex; justify-content:flex-end;
            gap: 14px;
        }
        .topbar .social a{
            width:38px;height:38px;
            display:grid; place-items:center;
            border-radius: 14px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.18);
            color:#fff;
            transition:.2s;
        }
        .topbar .social a:hover{ background: rgba(255,255,255,.18); transform: translateY(-1px); }

        /* ===== Navbar ===== */
        .navwrap{
            position: sticky; top:0; z-index: 1000;
            background: rgba(255,255,255,.82);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(99,102,241,.10);
        }
        .brand{
            display:flex; align-items:center; gap:12px;
        }
        .brand .logo{
            width:44px;height:44px;
            border-radius: 16px;
            background: var(--grad);
            display:grid; place-items:center;
            color:#fff;
            box-shadow: 0 18px 26px rgba(37,99,235,.20);
        }
        .brand .name{
            font-weight: 900;
            letter-spacing: -.2px;
            line-height: 1.05;
        }
        .brand .tag{
            color: var(--muted);
            font-weight: 800;
            font-size: 12px;
        }
        .nav-link{
            font-weight: 900;
            color: var(--text) !important;
            opacity:.78;
        }
        .nav-link:hover{ opacity: 1; }

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

        /* ===== Hero ===== */
        .hero{ padding: 52px 0 28px; }
        .hero-card{
            border-radius: 26px;
            border: 1px solid rgba(99,102,241,.10);
            box-shadow: var(--shadow);
            overflow: hidden;
            background:
                radial-gradient(1000px 380px at 15% 10%, rgba(14,165,233,.18), transparent 60%),
                radial-gradient(1000px 380px at 85% 10%, rgba(124,58,237,.16), transparent 65%),
                #fff;
        }
        .hero-inner{ padding: 34px; }
        .pill{
            display:inline-flex; align-items:center; gap:10px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(37,99,235,.10);
            border: 1px solid rgba(37,99,235,.16);
            font-weight: 900;
            font-size: 12px;
        }
        .hero h1{
            margin: 12px 0 10px;
            font-weight: 900;
            letter-spacing: -0.6px;
            font-size: clamp(32px, 3.1vw, 48px);
        }
        .hero h1 .shine{
            background: var(--grad);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .hero p{
            margin: 0 0 18px;
            color: var(--muted);
            font-weight: 700;
            line-height: 1.78;
            font-size: 15px;
        }
        .kpis{
            display:grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 12px;
            margin-top: 16px;
        }
        @media (max-width: 992px){ .kpis{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media (max-width: 520px){ .kpis{ grid-template-columns: 1fr; } }

        .kpi{
            padding: 14px;
            border-radius: 18px;
            border: 1px solid rgba(99,102,241,.10);
            background: rgba(255,255,255,.82);
            box-shadow: var(--shadow2);
            display:flex;
            align-items:center;
            gap: 12px;
        }
        .kpi .ico{
            width:46px;height:46px;
            border-radius: 18px;
            background: var(--gradSoft);
            border: 1px solid rgba(99,102,241,.12);
            display:grid; place-items:center;
            color: #1d4ed8;
            font-size: 18px;
        }
        .kpi .v{ font-size: 20px; font-weight: 900; line-height: 1.1; }
        .kpi .t{
            color: var(--muted);
            font-weight: 900;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .45px;
            margin-top: 2px;
        }

        .hero-img{
            height: 100%;
            min-height: 340px;
            background:
                linear-gradient(180deg, rgba(11,18,32,.12), rgba(11,18,32,.40)),
                url("https://images.unsplash.com/photo-1576091160550-2173dba999ef?auto=format&fit=crop&w=1400&q=80");
            background-size: cover;
            background-position: center;
            border-left: 1px solid rgba(99,102,241,.10);
            position: relative;
        }
        .hero-float{
            position:absolute;
            left: 16px; right: 16px; bottom: 16px;
            background: rgba(255,255,255,.90);
            border: 1px solid rgba(99,102,241,.12);
            box-shadow: var(--shadow2);
            border-radius: 20px;
            padding: 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .hero-float strong{ font-weight: 900; }
        .hero-float span{ color: var(--muted); font-weight: 800; font-size: 12px; }

        /* ===== Sections ===== */
        .section{ padding: 56px 0; }
        .head{
            text-align:center;
            margin-bottom: 22px;
        }
        .head h2{
            margin:0;
            font-weight: 900;
            letter-spacing: -.3px;
        }
        .head p{
            margin: 10px 0 0;
            color: var(--muted);
            font-weight: 700;
        }

        .cardx{
            border-radius: var(--radius);
            border: 1px solid rgba(99,102,241,.10);
            background: rgba(255,255,255,.82);
            box-shadow: var(--shadow2);
        }

        /* ===== About ===== */
        .about-grid{
            display:grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 14px;
            align-items: stretch;
        }
        @media (max-width: 992px){ .about-grid{ grid-template-columns: 1fr; } }

        .about-photo{
            border-radius: 26px;
            overflow:hidden;
            border: 1px solid rgba(99,102,241,.10);
            box-shadow: var(--shadow);
            min-height: 340px;
            background:
                linear-gradient(180deg, rgba(11,18,32,.08), rgba(11,18,32,.40)),
                url("https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?auto=format&fit=crop&w=1400&q=80");
            background-size: cover;
            background-position: center;
            position: relative;
        }
        .chips{
            position:absolute;
            top: 14px;
            left: 14px;
            display:flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .chip{
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(99,102,241,.12);
            border-radius: 999px;
            padding: 8px 10px;
            font-weight: 900;
            font-size: 12px;
            box-shadow: var(--shadow2);
            display:inline-flex;
            align-items:center;
            gap: 8px;
        }

        .about-content{ padding: 18px; }
        .about-content h3{
            font-weight: 900;
            margin: 0 0 10px;
            letter-spacing: -.2px;
        }
        .about-content p{
            color: var(--muted);
            font-weight: 700;
            line-height: 1.8;
            margin: 0 0 14px;
        }

        .feat-list{
            display:grid;
            gap: 10px;
            margin-top: 12px;
        }
        .feat{
            background:#fff;
            border: 1px solid rgba(99,102,241,.10);
            border-radius: 18px;
            padding: 12px;
            display:flex;
            gap: 12px;
            align-items:flex-start;
        }
        .feat .ico{
            width:46px;height:46px;
            border-radius: 18px;
            background: var(--gradSoft);
            border: 1px solid rgba(99,102,241,.12);
            display:grid; place-items:center;
            color: #1d4ed8;
            font-size: 18px;
        }
        .feat strong{ display:block; font-weight: 900; }
        .feat span{
            display:block;
            margin-top: 4px;
            color: var(--muted);
            font-weight: 700;
            font-size: 13px;
            line-height: 1.6;
        }

        /* ===== Services ===== */
        .grid3{
            display:grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 12px;
        }
        @media(max-width: 992px){ .grid3{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media(max-width: 520px){ .grid3{ grid-template-columns: 1fr; } }

        .svc{
            padding: 16px;
            border-radius: 22px;
            border: 1px solid rgba(99,102,241,.10);
            background: #fff;
            box-shadow: var(--shadow2);
            height: 100%;
            transition: .2s;
        }
        .svc:hover{ transform: translateY(-2px); box-shadow: 0 18px 38px rgba(11,18,32,.12); }
        .svc .ico{
            width:54px;height:54px;
            border-radius: 20px;
            background: var(--grad);
            color:#fff;
            display:grid; place-items:center;
            font-size: 20px;
            box-shadow: 0 18px 26px rgba(37,99,235,.18);
            margin-bottom: 12px;
        }
        .svc h4{ font-weight: 900; margin: 0 0 8px; }
        .svc p{ color: var(--muted); font-weight: 700; margin:0; line-height: 1.75; }

        /* ===== Doctors ===== */
        .doctors-wrap{
            border-radius: 26px;
            border: 1px solid rgba(99,102,241,.10);
            background: rgba(255,255,255,.70);
            box-shadow: var(--shadow);
            padding: 18px;
        }
        .doctors-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap: 12px;
            flex-wrap: wrap;
            padding: 6px 6px 16px;
        }
        .doctors-head h2{
            margin:0;
            font-weight: 900;
            letter-spacing: -.2px;
        }
        .doctors-head p{
            margin: 8px 0 0;
            color: var(--muted);
            font-weight: 700;
        }
        .badgex{
            background: var(--grad);
            color:#fff;
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 12px;
            box-shadow: 0 18px 26px rgba(37,99,235,.18);
        }

        .doctors-grid{
            display:grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 12px;
        }
        @media(max-width: 992px){ .doctors-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media(max-width: 520px){ .doctors-grid{ grid-template-columns: 1fr; } }

        .doc{
            border-radius: 24px;
            border: 1px solid rgba(99,102,241,.10);
            background: #fff;
            box-shadow: var(--shadow2);
            overflow:hidden;
            transition: .2s;
            height: 100%;
        }
        .doc:hover{ transform: translateY(-3px); box-shadow: 0 20px 44px rgba(11,18,32,.14); }
        .doc-top{
            padding: 14px;
            background: var(--gradSoft);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap: 12px;
            border-bottom: 1px solid rgba(99,102,241,.10);
        }
        .avatar{
            width:48px;height:48px;
            border-radius: 999px;
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(99,102,241,.12);
            display:grid; place-items:center;
            font-weight: 900;
            color: #1d4ed8;
            overflow:hidden;
        }
        .avatar img{ width:100%;height:100%; object-fit:cover; display:block; }
        .doc-pill{
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(99,102,241,.12);
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 12px;
            white-space:nowrap;
        }
        .doc-body{ padding: 14px; }
        .doc-name{ margin: 0 0 6px; font-weight: 900; font-size: 15px; }
        .doc-dept{
            color: var(--muted);
            font-weight: 800;
            font-size: 13px;
            display:flex;
            align-items:center;
            gap: 8px;
        }
        .doc-actions{
            display:flex;
            gap: 8px;
            margin-top: 12px;
        }
        .btnx{
            flex: 1;
            display:inline-flex;
            justify-content:center;
            align-items:center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 14px;
            font-weight: 900;
            font-size: 13px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .btnx-primary{
            background: var(--grad);
            color:#fff;
            box-shadow: 0 18px 26px rgba(37,99,235,.16);
        }
        .btnx-primary:hover{ filter: brightness(.98); color:#fff; }
        .btnx-soft{
            background: rgba(14,165,233,.10);
            border-color: rgba(14,165,233,.18);
            color: var(--text);
        }
        .btnx-soft:hover{ background: rgba(14,165,233,.14); color: var(--text); }

        /* ===== CTA Banner ===== */
        .cta{
            border-radius: 26px;
            border: 1px solid rgba(99,102,241,.10);
            box-shadow: var(--shadow);
            padding: 22px;
            background: var(--grad);
            color:#fff;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .cta h3{ margin:0; font-weight: 900; letter-spacing: -.2px; }
        .cta p{ margin: 6px 0 0; opacity:.92; font-weight: 700; line-height: 1.7; }
        .cta .btn-white{
            background: rgba(255,255,255,.92);
            color: #0b1220;
            border: 1px solid rgba(255,255,255,.25);
            padding: 12px 16px;
            border-radius: 14px;
            font-weight: 900;
            box-shadow: 0 18px 26px rgba(0,0,0,.12);
        }
        .cta .btn-white:hover{ background: #fff; }

        /* ===== Contact ===== */
        .contact-box{
            border-radius: 26px;
            border: 1px solid rgba(99,102,241,.10);
            box-shadow: var(--shadow);
            background: rgba(255,255,255,.70);
            padding: 18px;
            height: 100%;
        }
        .contact-item{
            display:flex;
            gap: 12px;
            align-items:flex-start;
            padding: 12px;
            border-radius: 18px;
            border: 1px solid rgba(99,102,241,.10);
            background: #fff;
            box-shadow: var(--shadow2);
            margin-bottom: 10px;
        }
        .contact-item .ico{
            width:46px;height:46px;
            border-radius: 18px;
            background: var(--gradSoft);
            border: 1px solid rgba(99,102,241,.12);
            display:grid; place-items:center;
            color: #1d4ed8;
            font-size: 18px;
        }
        .contact-item strong{ display:block; font-weight: 900; }
        .contact-item span{
            display:block;
            margin-top: 4px;
            color: var(--muted);
            font-weight: 700;
            line-height: 1.65;
        }

        /* ===== Footer ===== */
        footer{
            margin-top: 40px;
            background: #071023;
            color: rgba(255,255,255,.86);
            border-top: 1px solid rgba(255,255,255,.08);
        }
        footer h5{ color:#fff; font-weight: 900; }
        footer a{ color: rgba(255,255,255,.86); font-weight: 700; }
        footer a:hover{ color:#fff; }
        .footline{
            border-top: 1px solid rgba(255,255,255,.10);
            margin-top: 18px;
            padding-top: 14px;
            opacity: .95;
            text-align:center;
            font-weight: 800;
        }
    </style>
</head>

<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="container">
        <div class="row g-2 align-items-center">
            <div class="col-lg-8">
                <div class="mini">
                    <div class="item">
                        <i class="fa-solid fa-phone"></i>
                        <div>
                            <small>Hotline</small>
                            <strong><?php echo e($clinic['hotline']); ?></strong>
                        </div>
                    </div>
                    <div class="item">
                        <i class="fa-solid fa-envelope"></i>
                        <div>
                            <small>Email</small>
                            <strong><?php echo e($clinic['email']); ?></strong>
                        </div>
                    </div>
                    <div class="item">
                        <i class="fa-regular fa-clock"></i>
                        <div>
                            <small>Giờ làm việc</small>
                            <strong>08:00 - 17:00</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="social">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-youtube"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- NAVBAR -->
<div class="navwrap">
    <nav class="navbar navbar-expand-lg py-3">
        <div class="container">
            <a class="navbar-brand brand" href="index.php">
                <div class="logo"><i class="fa-solid fa-hospital"></i></div>
                <div>
                    <div class="name"><?php echo e($clinic['name']); ?></div>
                    <div class="tag"><?php echo e($clinic['tagline']); ?></div>
                </div>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navMain">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <li class="nav-item"><a class="nav-link" href="#about">Giới thiệu</a></li>
                    <li class="nav-item"><a class="nav-link" href="#services">Dịch vụ</a></li>
                    <li class="nav-item"><a class="nav-link" href="#doctors">Bác sĩ</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Liên hệ</a></li>
                    <li class="nav-item ms-lg-3">
                        <a class="btn btn-main" href="<?php echo e($BOOKING_URL); ?>">
                            <i class="fa-regular fa-calendar-days me-2"></i>Đặt lịch ngay
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</div>

<!-- HERO -->
<section class="hero">
    <div class="container">
        <div class="hero-card">
            <div class="row g-0 align-items-stretch">
                <div class="col-lg-7">
                    <div class="hero-inner">
                        <div class="pill"><i class="fa-solid fa-shield-heart"></i> Uy tín • Tận tâm • Minh bạch</div>

                        <h1>Chăm Sóc Sức Khỏe <span class="shine">Toàn Diện</span> Cho Bạn &amp; Gia Đình</h1>

                        <p>
                            Phòng Khám ABC cung cấp hệ thống khám chữa bệnh chất lượng, quy trình khoa học,
                            ưu tiên an toàn và bảo mật. Đặt lịch trước để được sắp xếp khung giờ phù hợp,
                            giảm thời gian chờ và được tư vấn rõ ràng theo từng tình trạng.
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-main" href="<?php echo e($BOOKING_URL); ?>">
                                <i class="fa-regular fa-calendar-days me-2"></i>Đặt lịch khám
                            </a>
                            <a class="btn btn-soft" href="#services">
                                <i class="fa-solid fa-list-ul me-2"></i>Xem dịch vụ
                            </a>
                        </div>

                        <div class="kpis">
                            <?php foreach ($kpi as $x): ?>
                                <div class="kpi">
                                    <div class="ico"><i class="fa-solid <?php echo e($x['icon']); ?>"></i></div>
                                    <div>
                                        <div class="v"><?php echo e($x['value']); ?></div>
                                        <div class="t"><?php echo e($x['label']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($db_error): ?>
                            <div class="mt-3" style="color:#b91c1c;font-weight:900;">
                                DB: <?php echo e($db_error); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="hero-img">
                        <div class="hero-float">
                            <div>
                                <strong>Hỗ trợ đặt lịch nhanh</strong><br>
                                <span>Xác nhận qua điện thoại / email</span>
                            </div>
                            <a class="btn btn-main" href="<?php echo e($BOOKING_URL); ?>">
                                <i class="fa-solid fa-arrow-right me-2"></i>Đặt lịch
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>

<!-- ABOUT -->
<section id="about" class="section">
    <div class="container">
        <div class="head">
            <h2>Về chúng tôi</h2>
            <p>Không gian hiện đại • Quy trình rõ ràng • Lấy người bệnh làm trung tâm</p>
        </div>

        <div class="about-grid">
            <div class="about-photo">
                <div class="chips">
                    <div class="chip"><i class="fa-solid fa-certificate"></i> Chuẩn quy trình</div>
                    <div class="chip"><i class="fa-solid fa-microscope"></i> Thiết bị hiện đại</div>
                    <div class="chip"><i class="fa-solid fa-lock"></i> Bảo mật</div>
                </div>
            </div>

            <div class="cardx about-content">
                <h3>Chất lượng điều trị và trải nghiệm dịch vụ</h3>
                <p>
                    Phòng khám tập trung vào chẩn đoán chính xác, tư vấn dễ hiểu và kế hoạch theo dõi sau khám.
                    Hệ thống chuyên khoa đa dạng cùng dịch vụ cận lâm sàng giúp hỗ trợ bác sĩ đưa ra quyết định kịp thời.
                </p>
                <p>
                    Chúng tôi đề cao minh bạch chi phí, quy trình tiếp nhận rõ ràng, và thái độ phục vụ thân thiện.
                    Người bệnh có thể đặt lịch trước để được ưu tiên khung giờ phù hợp.
                </p>

                <div class="feat-list">
                    <div class="feat">
                        <div class="ico"><i class="fa-solid fa-user-doctor"></i></div>
                        <div>
                            <strong>Đội ngũ chuyên môn</strong>
                            <span>Bác sĩ giàu kinh nghiệm, tư vấn rõ ràng và tôn trọng người bệnh.</span>
                        </div>
                    </div>

                    <div class="feat">
                        <div class="ico"><i class="fa-solid fa-stethoscope"></i></div>
                        <div>
                            <strong>Quy trình nhanh gọn</strong>
                            <span>Tiếp nhận linh hoạt, giảm thời gian chờ, tối ưu trải nghiệm.</span>
                        </div>
                    </div>

                    <div class="feat">
                        <div class="ico"><i class="fa-solid fa-hand-holding-heart"></i></div>
                        <div>
                            <strong>Chăm sóc sau khám</strong>
                            <span>Nhắc tái khám, hướng dẫn theo dõi và hỗ trợ tư vấn khi cần.</span>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <a class="btn btn-main" href="<?php echo e($BOOKING_URL); ?>">
                        <i class="fa-regular fa-calendar-days me-2"></i>Đặt lịch ngay
                    </a>
                    <a class="btn btn-soft" href="#contact">
                        <i class="fa-solid fa-phone me-2"></i>Liên hệ tư vấn
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- SERVICES -->
<section id="services" class="section">
    <div class="container">
        <div class="head">
            <h2>Dịch vụ nổi bật</h2>
            <p>Gói dịch vụ tối ưu cho nhiều nhu cầu chăm sóc sức khỏe</p>
        </div>

        <div class="grid3">
            <div class="svc">
                <div class="ico"><i class="fa-solid fa-heart-pulse"></i></div>
                <h4>Khám nội tổng quát</h4>
                <p>Đánh giá sức khỏe tổng quát, tầm soát sớm, tư vấn dinh dưỡng và lối sống.</p>
            </div>

            <div class="svc">
                <div class="ico"><i class="fa-solid fa-stethoscope"></i></div>
                <h4>Khám chuyên khoa</h4>
                <p>Đa chuyên khoa: Tai Mũi Họng, Da Liễu, Nhi, Tim mạch, Sản… theo lịch hẹn.</p>
            </div>

            <div class="svc">
                <div class="ico"><i class="fa-solid fa-vials"></i></div>
                <h4>Xét nghiệm – cận lâm sàng</h4>
                <p>Hỗ trợ chẩn đoán: xét nghiệm cơ bản, tầm soát, theo dõi chỉ số định kỳ.</p>
            </div>

            <div class="svc">
                <div class="ico"><i class="fa-solid fa-person-pregnant"></i></div>
                <h4>Sản – phụ khoa</h4>
                <p>Khám thai định kỳ, tư vấn tiền sản, theo dõi sức khỏe mẹ và bé.</p>
            </div>

            <div class="svc">
                <div class="ico"><i class="fa-solid fa-child"></i></div>
                <h4>Nhi khoa</h4>
                <p>Thăm khám trẻ em, tư vấn dinh dưỡng, theo dõi tăng trưởng và sức khỏe.</p>
            </div>

            <div class="svc">
                <div class="ico"><i class="fa-solid fa-truck-medical"></i></div>
                <h4>Hỗ trợ khẩn cấp</h4>
                <p>Hướng dẫn xử trí ban đầu và phối hợp hỗ trợ chuyển tuyến khi cần.</p>
            </div>
        </div>
    </div>
</section>

<!-- DOCTORS -->
<section id="doctors" class="section">
    <div class="container">
        <div class="doctors-wrap">
            <div class="doctors-head">
                <div>
                    <h2>Đội ngũ bác sĩ</h2>
                    <p>Hiển thị tối giản, tự động lấy từ cơ sở dữ liệu</p>
                </div>
                <div class="badgex">
                    <?php echo $pdo ? ('Đang hiển thị: ' . (int)$doctors_count) : 'Không kết nối DB'; ?>
                </div>
            </div>

            <?php if (!$pdo): ?>
                <div class="text-center py-4" style="color:#b91c1c;font-weight:900;">
                    Không thể kết nối cơ sở dữ liệu. Vui lòng kiểm tra DB config.
                </div>
            <?php elseif (empty($doctors)): ?>
                <div class="text-center py-4" style="color:var(--muted);font-weight:900;">
                    Chưa có bác sĩ nào để hiển thị.
                </div>
            <?php else: ?>
                <div class="doctors-grid">
                    <?php foreach ($doctors as $d): ?>
                        <?php
                            $name = (string)($d['full_name'] ?? '');
                            $dept = (string)($d['department_name'] ?? 'Chưa phân khoa');
                            $img  = trim((string)($d['profile_image'] ?? ''));
                            $ini  = initial_letter($name);
                        ?>
                        <div class="doc">
                            <div class="doc-top">
                                <div class="avatar">
                                    <?php if ($img !== ''): ?>
                                        <img src="<?php echo e($img); ?>" alt="<?php echo e($name); ?>">
                                    <?php else: ?>
                                        <?php echo e($ini); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="doc-pill"><i class="fa-solid fa-circle-check me-2"></i>Sẵn sàng</div>
                            </div>
                            <div class="doc-body">
                                <div class="doc-name"><?php echo e($name); ?></div>
                                <div class="doc-dept"><i class="fa-solid fa-hospital"></i> <?php echo e($dept); ?></div>
                                <div class="doc-actions">
                                    <a class="btnx btnx-primary" href="<?php echo e($BOOKING_URL); ?>">
                                        <i class="fa-regular fa-calendar-days"></i> Đặt lịch
                                    </a>
                                    <a class="btnx btnx-soft" href="#contact">
                                        <i class="fa-solid fa-message"></i> Tư vấn
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a class="btn btn-soft" href="<?php echo e($BOOKING_URL); ?>">
                    <i class="fa-solid fa-arrow-right me-2"></i>Chuyển sang trang đặt lịch
                </a>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="section pt-0">
    <div class="container">
        <div class="cta">
            <div>
                <h3>Đặt lịch trước để được ưu tiên khung giờ phù hợp</h3>
                <p>Nhập thông tin cơ bản, chọn khoa khám và thời gian — phòng khám sẽ xác nhận sớm.</p>
            </div>
            <a class="btn-white" href="<?php echo e($BOOKING_URL); ?>">
                <i class="fa-regular fa-calendar-days me-2"></i>Đi tới đặt lịch
            </a>
        </div>
    </div>
</section>

<!-- CONTACT -->
<section id="contact" class="section">
    <div class="container">
        <div class="head">
            <h2>Liên hệ</h2>
            <p>Luôn sẵn sàng hỗ trợ bạn</p>
        </div>

        <div class="row g-3 align-items-stretch">
            <div class="col-lg-6">
                <div class="contact-box">
                    <div class="contact-item">
                        <div class="ico"><i class="fa-solid fa-location-dot"></i></div>
                        <div>
                            <strong>Địa chỉ</strong>
                            <span><?php echo e($clinic['address']); ?></span>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="ico"><i class="fa-solid fa-phone"></i></div>
                        <div>
                            <strong>Hotline</strong>
                            <span><?php echo e($clinic['hotline']); ?></span>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="ico"><i class="fa-solid fa-envelope"></i></div>
                        <div>
                            <strong>Email</strong>
                            <span><?php echo e($clinic['email']); ?></span>
                        </div>
                    </div>

                    <div class="contact-item mb-0">
                        <div class="ico"><i class="fa-regular fa-clock"></i></div>
                        <div>
                            <strong>Giờ làm việc</strong>
                            <span>
                                <?php echo e($clinic['hours']['weekdays']); ?><br>
                                <?php echo e($clinic['hours']['sat']); ?><br>
                                <?php echo e($clinic['hours']['sun']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-main" href="<?php echo e($BOOKING_URL); ?>">
                            <i class="fa-regular fa-calendar-days me-2"></i>Đặt lịch
                        </a>
                        <a class="btn btn-soft" href="#doctors">
                            <i class="fa-solid fa-user-doctor me-2"></i>Xem bác sĩ
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="contact-box">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3919.4241677414477!2d106.6981!3d10.7756!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTDCsDQ2JzMyLjEiTiAxMDbCsDQxJzUzLjIiRQ!5e0!3m2!1svi!2s!4v1620000000000!5m2!1svi!2s"
                        width="100%" height="430"
                        style="border:0;border-radius:22px;"
                        allowfullscreen="" loading="lazy"></iframe>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <h5><?php echo e($clinic['name']); ?></h5>
                <p style="margin:10px 0 0; color:rgba(255,255,255,.78); font-weight:700; line-height:1.75;">
                    Chúng tôi cam kết dịch vụ y tế chất lượng, minh bạch và tận tâm. Đặt lịch trước để tiết kiệm thời gian.
                </p>
                <div class="d-flex gap-3 mt-3">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-youtube"></i></a>
                </div>
            </div>

            <div class="col-lg-4">
                <h5>Liên kết</h5>
                <div class="d-grid gap-2 mt-3">
                    <a href="#about">Giới thiệu</a>
                    <a href="#services">Dịch vụ</a>
                    <a href="#doctors">Bác sĩ</a>
                    <a href="#contact">Liên hệ</a>
                </div>
            </div>

            <div class="col-lg-4">
                <h5>Đặt lịch nhanh</h5>
                <p style="margin:10px 0 0; color:rgba(255,255,255,.78); font-weight:700; line-height:1.75;">
                    Chuyển sang trang đặt lịch để chọn khoa khám và thời gian phù hợp.
                </p>
                <div class="mt-3">
                    <a class="btn btn-main" href="<?php echo e($BOOKING_URL); ?>">
                        <i class="fa-regular fa-calendar-days me-2"></i>Đi tới đặt lịch
                    </a>
                </div>
            </div>
        </div>

        <div class="footline">
            © 2026 <?php echo e($clinic['name']); ?>. All rights reserved.
        </div>
    </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>