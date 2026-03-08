<?php
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

function j($data, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function appts(PDO $conn, string $ymd): array {
  $stmt = $conn->prepare("
    SELECT a.appointment_id, a.appointment_date, a.symptoms, a.status,
           p.full_name AS patient_name, p.phone AS patient_phone,
           d.full_name AS doctor_name, dep.department_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id=p.patient_id
    LEFT JOIN doctors d ON a.doctor_id=d.doctor_id
    LEFT JOIN departments dep ON a.department_id=dep.department_id
    WHERE DATE(a.appointment_date)=?
    ORDER BY a.appointment_date DESC
  ");
  $stmt->execute([$ymd]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ================= AJAX ================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) j(['ok'=>false,'message'=>'CSRF sai'],403);
  $action = (string)$_POST['action'];

  try {
    if ($action === 'list') {
      $date = (string)($_POST['date'] ?? $today);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $date = $today;
      j(['ok'=>true,'items'=>appts($conn,$date)]);
    }

    // ====== CANCEL (hủy lịch) ======
    if ($action === 'cancel') {
      $id=(int)($_POST['appointment_id']??0);
      if ($id<=0) j(['ok'=>false,'message'=>'ID không hợp lệ'],422);

      // Không cho hủy nếu đã completed
      $stmt = $conn->prepare("SELECT status FROM appointments WHERE appointment_id=?");
      $stmt->execute([$id]);
      $status = $stmt->fetchColumn();

      if (!$status) j(['ok'=>false,'message'=>'Không tìm thấy lịch hẹn'],404);

      if ($status === 'completed') {
        j(['ok'=>false,'message'=>'Không thể hủy lịch đã hoàn thành'],422);
      }

      // Nếu đã cancelled rồi thì thôi
      if ($status === 'cancelled') {
        j(['ok'=>true,'message'=>'Lịch đã được hủy trước đó']);
      }

      $stmt=$conn->prepare("UPDATE appointments SET status='cancelled' WHERE appointment_id=?");
      $stmt->execute([$id]);

      j(['ok'=>true,'message'=>'Đã hủy lịch hẹn']);
    }

    j(['ok'=>false,'message'=>'Action không hợp lệ'],400);
  } catch (Exception $e) {
    j(['ok'=>false,'message'=>'Lỗi: '.$e->getMessage()],500);
  }
}

/* ================= PAGE DATA ================= */
$todayItems = appts($conn,$today);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>DS lịch khám</title>
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
    .nav{ margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .nav a{
      text-decoration:none; background:#fff; border:1px solid var(--line);
      padding:10px 14px; border-radius:999px; font-weight:900; color:#111827;
      display:inline-flex; align-items:center; gap:8px;
    }
    .nav a.active{ background:var(--pri); color:#fff; border-color:transparent; }

    .card{
      background:var(--card); border:1px solid var(--line); border-radius:16px;
      padding:16px; box-shadow:0 6px 20px rgba(17,24,39,.06); margin-top:14px;
    }
    label{ display:block; font-size:13px; font-weight:800; margin:0 0 6px; color:#374151; }
    input{
      width:100%; max-width:100%;
      padding:10px 12px; border:1px solid var(--line); border-radius:12px;
      outline:none; background:#fff;
    }
    .btn{
      border:none; border-radius:12px; padding:10px 14px; font-weight:900;
      cursor:pointer; display:inline-flex; align-items:center; gap:8px;
    }
    .btn-gray{ background:#f3f4f6; color:#111827; border:1px solid var(--line); }

    .toolbar{ display:flex; gap:12px; flex-wrap:wrap; align-items:end; justify-content:space-between; }
    .toolbar .left{ display:flex; gap:12px; flex-wrap:wrap; align-items:end; }
    .pill{ padding:8px 10px; border:1px solid var(--line); border-radius:999px; background:#fff; color:var(--muted); font-size:12px; font-weight:800; }

    table{ width:100%; border-collapse:separate; border-spacing:0 10px; }
    th{ font-size:12px; color:var(--muted); text-align:left; padding:0 10px; }
    td{ background:#fff; padding:12px 10px; border:1px solid var(--line); }
    tr td:first-child{ border-radius:14px 0 0 14px; }
    tr td:last-child{ border-radius:0 14px 14px 0; }

    .act{ display:flex; gap:8px; flex-wrap:wrap; }
    .mini{ padding:8px 10px; border-radius:12px; font-weight:900; font-size:12px; border:1px solid var(--line); cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .mini.blue{ background:#eff6ff; color:#1d4ed8; }
    .mini.red{ background:#fff1f2; color:#b91c1c; }
    .mini.disabled{ opacity:.55; cursor:not-allowed; }
    .empty{ background:#fafafa; border:1px dashed var(--line); padding:16px; border-radius:14px; color:var(--muted); font-weight:800; }
  </style>
</head>
<body>

<div class="wrap">
  <div class="topbar">
    <div>
      <i class="fas fa-user"></i> <?php echo htmlspecialchars($receptionist_name); ?>
    </div>

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
    <h1><i class="fas fa-list"></i> Danh sách lịch khám</h1>
  </div>

  <div class="nav">
  <a href="create_appointment.php"><i class="fas fa-plus"></i> Tạo lịch khám</a>
  <a class="active" href="appointments_list.php"><i class="fas fa-list"></i> DS lịch khám</a>
  <a href="patients_list.php"><i class="fas fa-users"></i> DS bệnh nhân</a>
  <a href="profile.php"><i class="fas fa-id-badge"></i> Hồ sơ lễ tân</a>
</div>

  <div class="card">
    <div class="toolbar">
      <div class="left">
        <div>
          <label>Ngày xem danh sách</label>
          <input id="list_date" type="date" value="<?php echo htmlspecialchars($today); ?>">
        </div>
        <button class="btn btn-gray" id="btnReload" type="button"><i class="fas fa-rotate"></i> Tải lại</button>
      </div>
      <div class="pill" id="countPill">0 lịch</div>
    </div>

    <div style="overflow:auto;margin-top:10px;">
      <table>
        <thead>
          <tr>
            <th>Ngày</th><th>Bệnh nhân</th><th>SĐT</th><th>Khoa</th><th>Bác sĩ</th><th>Trạng thái</th><th>Thao tác</th>
          </tr>
        </thead>
        <tbody id="listBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const $ = (id)=>document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
function mysqlToDate(s){ return s ? s.slice(0,10) : ''; }

async function api(action, payload={}){
  const fd=new FormData();
  fd.append('csrf',CSRF);
  fd.append('action',action);
  Object.entries(payload).forEach(([k,v])=>fd.append(k,v));
  const res = await fetch(location.href,{method:'POST',body:fd});
  return res.json();
}

function renderList(items){
  $('countPill').textContent = (items?.length||0) + ' lịch';
  const tbody = $('listBody');

  if(!items || items.length===0){
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty">Không có lịch khám trong ngày này.</div></td></tr>`;
    return;
  }

  tbody.innerHTML = items.map(it=>{
    const date = mysqlToDate(it.appointment_date);
    const status = String(it.status || '').toLowerCase();

    const canEditCancel = (status === 'pending');

    return `
      <tr>
        <td>${esc(date)}</td>
        <td><b>${esc(it.patient_name||'')}</b></td>
        <td>${esc(it.patient_phone||'')}</td>
        <td>${esc(it.department_name||'-')}</td>
        <td>${esc(it.doctor_name||'-')}</td>
        <td>${esc(it.status||'-')}</td>
        <td>
          <div class="act">
            ${
              canEditCancel
              ? `
                <a class="mini blue" href="create_appointment.php?edit=${it.appointment_id}">
                  <i class="fas fa-pen"></i> Sửa
                </a>
                <button class="mini red" type="button" onclick="cancelAppt(${it.appointment_id})">
                  <i class="fas fa-ban"></i> Hủy lịch
                </button>
              `
              : `<span class="pill">Không thao tác</span>`
            }
          </div>
        </td>
      </tr>`;
  }).join('');
}

async function loadList(){
  const data = await api('list',{date:$('list_date').value});
  if(data.ok) renderList(data.items);
}

async function cancelAppt(id){
  if(!confirm('Hủy lịch hẹn #' + id + ' ?')) return;
  const data = await api('cancel',{appointment_id:id});
  if(!data.ok) return alert(data.message||'Không thể hủy');
  await loadList();
}

renderList(<?php echo json_encode($todayItems, JSON_UNESCAPED_UNICODE); ?>);

$('btnReload').addEventListener('click', loadList);
$('list_date').addEventListener('change', loadList);
</script>
</body>
</html>