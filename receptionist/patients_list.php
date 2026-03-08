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

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function j($data, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================= AJAX ================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) j(['ok'=>false,'message'=>'CSRF sai'],403);
  $action = (string)$_POST['action'];

  try {
    if ($action === 'list_patients') {
      $q = trim((string)($_POST['q'] ?? ''));
      $limit = (int)($_POST['limit'] ?? 100);
      if ($limit <= 0 || $limit > 200) $limit = 100;

      if ($q !== '') {
        $like = "%$q%";
        $stmt = $conn->prepare("
          SELECT patient_id, full_name, phone, date_of_birth, gender, address, created_at, updated_at
          FROM patients
          WHERE is_deleted=0 AND (full_name LIKE ? OR phone LIKE ?)
          ORDER BY updated_at DESC
          LIMIT $limit
        ");
        $stmt->execute([$like, $like]);
      } else {
        $stmt = $conn->prepare("
          SELECT patient_id, full_name, phone, date_of_birth, gender, address, created_at, updated_at
          FROM patients
          WHERE is_deleted=0
          ORDER BY updated_at DESC
          LIMIT $limit
        ");
        $stmt->execute();
      }

      $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      j(['ok'=>true,'items'=>$items]);
    }

    if ($action === 'patient_appointments') {
      $patient_id = (int)($_POST['patient_id'] ?? 0);
      if ($patient_id <= 0) j(['ok'=>false,'message'=>'ID không hợp lệ'],422);

      $stmt = $conn->prepare("
        SELECT a.appointment_id, a.appointment_date, a.status, a.symptoms,
               d.full_name AS doctor_name,
               dep.department_name
        FROM appointments a
        LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
        LEFT JOIN departments dep ON a.department_id = dep.department_id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC
      ");
      $stmt->execute([$patient_id]);
      $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      j(['ok'=>true,'items'=>$items]);
    }

    j(['ok'=>false,'message'=>'Action không hợp lệ'],400);

  } catch (Exception $e) {
    j(['ok'=>false,'message'=>'Lỗi: '.$e->getMessage()],500);
  }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>DS bệnh nhân</title>
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
    .hero .sub{ margin-top:6px; opacity:.9; font-size:13px; }

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

    .empty{ background:#fafafa; border:1px dashed var(--line); padding:16px; border-radius:14px; color:var(--muted); font-weight:800; }

    /* dòng bệnh nhân */
    tr.patient-row{ cursor:pointer; }
    tr.patient-row:hover td{ box-shadow:0 4px 14px rgba(17,24,39,.06); }
    tr.patient-row.active td{
      background:#eef2ff;
      border-color:#c7d2fe;
    }

    /* detail row */
    tr.detail-row td{
      background:#f8fafc;
      border-style:dashed;
    }
    .detail-wrap{
      padding:10px 10px;
    }
    .detail-title{
      display:flex; align-items:center; justify-content:space-between;
      gap:12px; margin-bottom:8px;
    }
    .detail-title b{ color:#111827; }
    .mini-pill{
      padding:6px 10px; border:1px solid var(--line); border-radius:999px;
      background:#fff; color:var(--muted); font-size:12px; font-weight:900;
      display:inline-flex; gap:8px; align-items:center;
    }

    /* bảng lịch trong detail */
    .appt-table{ width:100%; border-collapse:separate; border-spacing:0 8px; }
    .appt-table th{ padding:0 8px; font-size:12px; color:var(--muted); }
    .appt-table td{ padding:10px 8px; }

    .status{ font-weight:900; }
    .st-pending{ color:#f59e0b; }
    .st-confirmed{ color:#2563eb; }
    .st-completed{ color:#16a34a; }
    .st-cancelled{ color:#dc2626; }

    .close-btn{
      border:none; cursor:pointer;
      background:#fff; border:1px solid var(--line);
      border-radius:12px; padding:8px 10px;
      font-weight:900; color:#111827;
      display:inline-flex; align-items:center; gap:8px;
    }
  </style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div><i class="fas fa-user"></i> <?php echo htmlspecialchars($receptionist_name); ?></div>

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
         ">
        <i class="fas fa-right-from-bracket"></i> Đăng xuất
      </a>
    </div>
  </div>

  <div class="hero">
    <h1><i class="fas fa-users"></i> Danh sách bệnh nhân</h1>
  </div>

  <div class="nav">
  <a href="create_appointment.php"><i class="fas fa-plus"></i> Tạo lịch khám</a>
  <a href="appointments_list.php"><i class="fas fa-list"></i> DS lịch khám</a>
  <a class="active" href="patients_list.php"><i class="fas fa-users"></i> DS bệnh nhân</a>
  <a href="profile.php"><i class="fas fa-id-badge"></i> Hồ sơ lễ tân</a>
</div>

  <div class="card">
    <div class="toolbar">
      <div class="left">
        <div>
          <label>Tìm bệnh nhân (tên hoặc SĐT)</label>
          <input id="patient_q" placeholder="VD: Lan hoặc 0912...">
        </div>
        <button class="btn btn-gray" id="btnPatientSearch" type="button">
          <i class="fas fa-magnifying-glass"></i> Tìm
        </button>
        <button class="btn btn-gray" id="btnPatientReload" type="button">
          <i class="fas fa-rotate"></i> Tải lại
        </button>
      </div>
      <div class="pill" id="patientCountPill">0 bệnh nhân</div>
    </div>

    <div style="overflow:auto;margin-top:10px;">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Họ tên</th><th>SĐT</th><th>Ngày sinh</th><th>Giới tính</th><th>Địa chỉ</th>
          </tr>
        </thead>
        <tbody id="patientBody"></tbody>
      </table>
    </div>
  </div>

</div>

<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const $ = (id)=>document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));

async function api(action, payload={}){
  const fd=new FormData();
  fd.append('csrf',CSRF);
  fd.append('action',action);
  Object.entries(payload).forEach(([k,v])=>fd.append(k,v));
  const res = await fetch(location.href,{method:'POST',body:fd});
  return res.json();
}

function mysqlToDate(s){ return s ? s.slice(0,10) : ''; }

function statusClass(st){
  if(st==='completed') return 'st-completed';
  if(st==='cancelled') return 'st-cancelled';
  if(st==='confirmed') return 'st-confirmed';
  return 'st-pending';
}
function statusText(st){
  if(st==='completed') return 'Hoàn thành';
  if(st==='cancelled') return 'Đã hủy';
  if(st==='confirmed') return 'Đã xác nhận';
  return 'Chờ xử lý';
}

function removeDetailRow(){
  const old = document.getElementById('patientDetailRow');
  if(old) old.remove();
}

function clearActive(){
  document.querySelectorAll('tr.patient-row.active').forEach(x=>x.classList.remove('active'));
}

function renderPatients(items){
  $('patientCountPill').textContent = (items?.length||0) + ' bệnh nhân';
  const tbody = $('patientBody');

  if(!items || items.length===0){
    tbody.innerHTML = `<tr><td colspan="6"><div class="empty">Không có bệnh nhân.</div></td></tr>`;
    return;
  }

  tbody.innerHTML = items.map(p=>{
    const safeNameAttr = String(p.full_name ?? '').replace(/"/g,'&quot;');
    const safePhoneAttr = String(p.phone ?? '').replace(/"/g,'&quot;');
    return `
      <tr class="patient-row" data-id="${esc(p.patient_id)}" data-name="${safeNameAttr}" data-phone="${safePhoneAttr}">
        <td>${esc(p.patient_id)}</td>
        <td><b>${esc(p.full_name||'')}</b></td>
        <td>${esc(p.phone||'')}</td>
        <td>${esc(p.date_of_birth||'')}</td>
        <td>${esc(p.gender||'')}</td>
        <td>${esc(p.address||'')}</td>
      </tr>
    `;
  }).join('');
}

async function loadPatients(){
  const q = $('patient_q').value.trim();
  const data = await api('list_patients',{q,limit:100});
  if(!data.ok) return alert(data.message||'Không tải được DS bệnh nhân');

  renderPatients(data.items);
  removeDetailRow();
  clearActive();
}

function detailRowLoading(colspan, name, phone){
  return `
    <tr class="detail-row" id="patientDetailRow">
      <td colspan="${colspan}">
        <div class="detail-wrap">
          <div class="detail-title">
            <div>
              <b><i class="fas fa-calendar-check"></i> Lịch khám của: ${esc(name||'')}</b>
              <span class="mini-pill" style="margin-left:10px;"><i class="fas fa-phone"></i> ${esc(phone||'')}</span>
            </div>
            <button class="close-btn" type="button" onclick="closeDetail()">
              <i class="fas fa-xmark"></i> Đóng
            </button>
          </div>
          <div class="empty">Đang tải lịch khám...</div>
        </div>
      </td>
    </tr>
  `;
}

function renderAppointmentsInsideDetail(items){
  if(!items || items.length===0){
    return `<div class="empty">Bệnh nhân chưa có lịch khám.</div>`;
  }

  const rows = items.map(a=>`
    <tr>
      <td>${esc(mysqlToDate(a.appointment_date))}</td>
      <td>${esc(a.department_name || '-')}</td>
      <td>${esc(a.doctor_name || '-')}</td>
      <td class="status ${statusClass(a.status)}">${esc(statusText(a.status))}</td>
      <td>${esc(a.symptoms || '')}</td>
    </tr>
  `).join('');

  return `
    <div style="overflow:auto;">
      <table class="appt-table">
        <thead>
          <tr>
            <th>Ngày</th>
            <th>Khoa</th>
            <th>Bác sĩ</th>
            <th>Trạng thái</th>
            <th>Triệu chứng</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

function closeDetail(){
  removeDetailRow();
  clearActive();
}

$('patientBody').addEventListener('click', async (e)=>{
  const tr = e.target.closest('tr.patient-row');
  if(!tr) return;

  const id = parseInt(tr.dataset.id,10);
  const name = tr.dataset.name || '';
  const phone = tr.dataset.phone || '';
  if(!id) return;

  // Nếu click đúng dòng đang active: toggle đóng/mở
  if(tr.classList.contains('active')){
    closeDetail();
    return;
  }

  // xóa detail cũ + bỏ active cũ
  removeDetailRow();
  clearActive();

  // set active mới
  tr.classList.add('active');

  // chèn detail row ngay sau dòng được chọn
  const colspan = tr.children.length || 6;
  tr.insertAdjacentHTML('afterend', detailRowLoading(colspan, name, phone));

  // gọi ajax lấy lịch
  const data = await api('patient_appointments', {patient_id:id});
  const detail = document.getElementById('patientDetailRow');
  if(!detail) return;

  if(!data.ok){
    detail.querySelector('.detail-wrap').innerHTML = `<div class="empty">${esc(data.message || 'Không tải được lịch')}</div>`;
    return;
  }

  detail.querySelector('.detail-wrap').innerHTML = `
    <div class="detail-title">
      <div>
        <b><i class="fas fa-calendar-check"></i> Lịch khám của: ${esc(name||'')}</b>
        <span class="mini-pill" style="margin-left:10px;"><i class="fas fa-phone"></i> ${esc(phone||'')}</span>
        <span class="mini-pill" style="margin-left:10px;"><i class="fas fa-hashtag"></i> ID: ${esc(id)}</span>
      </div>
      <button class="close-btn" type="button" onclick="closeDetail()">
        <i class="fas fa-xmark"></i> Đóng
      </button>
    </div>
    ${renderAppointmentsInsideDetail(data.items)}
  `;
});

$('btnPatientSearch').addEventListener('click', loadPatients);
$('btnPatientReload').addEventListener('click', ()=>{
  $('patient_q').value='';
  loadPatients();
});
$('patient_q').addEventListener('keydown', (e)=>{ if(e.key==='Enter') loadPatients(); });

loadPatients();
</script>
</body>
</html>