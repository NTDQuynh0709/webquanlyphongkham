<?php
declare(strict_types=1);

/**
 * Logic thuần để test (không DB, không session, không HTML)
 */

const SERVICE_MIN = 20;
const STEP_MIN = 5;
const LAST_BOOKING_BEFORE_SHIFT_END_MIN = 10;

function add_minutes(DateTime $dt, int $min): DateTime {
  $c = clone $dt;
  $c->modify(($min >= 0 ? '+' : '') . $min . ' minutes');
  return $c;
}

/** Sáng 08:00-12:00, Chiều 13:30-17:00 */
function get_work_sessions(string $dateYmd): array {
  return [
    [new DateTime("$dateYmd 08:00:00"), new DateTime("$dateYmd 12:00:00")],
    [new DateTime("$dateYmd 13:30:00"), new DateTime("$dateYmd 17:00:00")],
  ];
}

/**
 * Parse input: empty / phone / patient_id / text
 */
function classify_patient_search_input(string $q): array {
    $raw = trim($q);

    if ($raw === '') {
        return ['kind' => 'empty', 'compact' => ''];
    }

    $compact = preg_replace('/\s+/', '', $raw);

    // phone: +? 10-15 digits
    if (preg_match('/^\+?\d{10,15}$/', $compact)) {
        return ['kind' => 'phone', 'compact' => $compact];
    }

    // patient_id: chỉ digits dưới 10 số
    if (preg_match('/^\d{1,9}$/', $compact)) {
        return ['kind' => 'patient_id', 'compact' => $compact];
    }

    return ['kind' => 'text', 'compact' => $compact];
}

/**
 * Check lịch start có nằm trong giờ làm không
 * + đặt cuối trước tan ca 10 phút
 * + không được vượt tan ca theo duration SERVICE_MIN
 */
function validate_working_hours(DateTime $start, int $serviceMin = SERVICE_MIN): array {
  $sessions = get_work_sessions($start->format('Y-m-d'));
  $end = add_minutes($start, $serviceMin);

  foreach ($sessions as [$s, $e]) {
    if ($start >= $s && $start < $e) {
      $latestByRule = add_minutes($e, -LAST_BOOKING_BEFORE_SHIFT_END_MIN);
      if ($start > $latestByRule) {
        return [false, "Giờ đặt lịch phải trước giờ tan ca ít nhất ".LAST_BOOKING_BEFORE_SHIFT_END_MIN." phút (ca này tan lúc ".$e->format('H:i').")"];
      }

      

      return [true, "OK"];
    }
  }

  return [false, "Bệnh viện chỉ làm việc 08:00–12:00 và 13:30–17:00."];
}

/** allow touching edges */
function is_overlapping(DateTime $start, DateTime $end, array $blocks): bool {
  foreach ($blocks as [$bs, $be]) {
    if ($start < $be && $end > $bs) return true;
  }
  return false;
}