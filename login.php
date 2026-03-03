<?php

session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu'
        ]);
        exit;
    }

    try {
        /* ===== ADMIN ===== */
        $stmt = $conn->prepare("
            SELECT admin_id, username, password
            FROM admins
            WHERE username = :username
        ");
        $stmt->execute(['username' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['username'] = $admin['username'];

            echo json_encode([
                'success' => true,
                'redirect' => 'admin/dashboard.php'
            ]);
            exit;
        }

        /* ===== BÁC SĨ ===== */
        $stmt = $conn->prepare("
            SELECT doctor_id, username, password
            FROM doctors
            WHERE username = :username
              AND is_deleted = 0
        ");
        $stmt->execute(['username' => $username]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($doctor && password_verify($password, $doctor['password'])) {
            $_SESSION['doctor_id'] = $doctor['doctor_id'];
            $_SESSION['role'] = 'doctor';
            $_SESSION['username'] = $doctor['username'];

            echo json_encode([
                'success' => true,
                'redirect' => 'doctor/dashboard.php'
            ]);
            exit;
        }

        /* ===== LỄ TÂN ===== */
        $stmt = $conn->prepare("
            SELECT receptionist_id, username, password
            FROM receptionists
            WHERE username = :username
        ");
        $stmt->execute(['username' => $username]);
        $receptionist = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($receptionist && password_verify($password, $receptionist['password'])) {
            $_SESSION['receptionist_id'] = $receptionist['receptionist_id'];
            $_SESSION['role'] = 'receptionist';
            $_SESSION['username'] = $receptionist['username'];

            echo json_encode([
                'success' => true,
                'redirect' => 'receptionist/create_appointment.php'
            ]);
            exit;
        }

        /* ===== KHÔNG ĐÚNG ===== */
        echo json_encode([
            'success' => false,
            'message' => 'Tên đăng nhập hoặc mật khẩu không đúng'
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi hệ thống'
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng Nhập Hệ Thống</title>

<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet"
 href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">

<div class="w-full max-w-md bg-white rounded-lg shadow-lg p-6">
    <h2 class="text-2xl font-bold text-center mb-6">
        Đăng Nhập Hệ Thống
    </h2>

    <form id="loginForm" class="space-y-4">
        <div>
            <label class="block text-sm font-medium">Tên đăng nhập</label>
            <input name="username" required
                class="w-full px-4 py-2 border rounded focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium">Mật khẩu</label>
            <input type="password" name="password" required
                class="w-full px-4 py-2 border rounded focus:ring-2 focus:ring-blue-500">
        </div>

        <button type="submit"
            class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700">
            Đăng nhập
        </button>
    </form>

    <p id="errorMessage" class="text-red-500 text-sm mt-4 hidden"></p>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function(e){
    e.preventDefault();
    const form = this;
    const msg = document.getElementById('errorMessage');

    fetch('login.php', {
        method: 'POST',
        body: new FormData(form)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            window.location.href = d.redirect;
        } else {
            msg.textContent = d.message;
            msg.classList.remove('hidden');
        }
    })
    .catch(() => {
        msg.textContent = 'Lỗi hệ thống, vui lòng thử lại';
        msg.classList.remove('hidden');
    });
});
</script>

</body>
</html>
