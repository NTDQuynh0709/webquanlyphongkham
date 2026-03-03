<?php
if (!isset($conn) || !($conn instanceof PDO)) return;

try {
  // Chỉ chạy 1 lần/ngày (lưu trong session)
  $today = date('Y-m-d');
  if (!empty($_SESSION['auto_cancel_ran']) && $_SESSION['auto_cancel_ran'] === $today) return;
  $_SESSION['auto_cancel_ran'] = $today;

  $stmt = $conn->prepare("
    UPDATE appointments
    SET status='cancelled'
    WHERE DATE(appointment_date) < CURDATE()
      AND status NOT IN ('completed','cancelled')
  ");
  $stmt->execute();
} catch (Exception $e) {}