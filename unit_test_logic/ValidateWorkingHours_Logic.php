<?php
declare(strict_types=1);

const SERVICE_MIN = 20;
const STEP_MIN = 5;
const LAST_BOOKING_BEFORE_SHIFT_END_MIN = 10;

function add_minutes(DateTime $dt, int $min): DateTime {
    $c = clone $dt;
    $c->modify(($min >= 0 ? '+' : '') . $min . ' minutes');
    return $c;
}

function get_work_sessions(string $dateYmd): array {
    return [
        [new DateTime("$dateYmd 08:00:00"), new DateTime("$dateYmd 12:00:00")],
        [new DateTime("$dateYmd 13:30:00"), new DateTime("$dateYmd 17:00:00")],
    ];
}

function validate_working_hours(DateTime $start, int $serviceMin = SERVICE_MIN): array {
    $sessions = get_work_sessions($start->format('Y-m-d'));
    $end = add_minutes($start, $serviceMin);

    foreach ($sessions as [$s, $e]) {
        if ($start >= $s && $start < $e) {
            $latestByRule = add_minutes($e, -LAST_BOOKING_BEFORE_SHIFT_END_MIN);

            if ($start > $latestByRule) {
                return [
                    false,
                    "Giờ đặt lịch phải trước giờ tan ca ít nhất " .
                    LAST_BOOKING_BEFORE_SHIFT_END_MIN .
                    " phút (ca này tan lúc " . $e->format('H:i') . ")"
                ];
            }

            if ($end > $e) {
                return [
                    false,
                    "Lịch khám vượt quá giờ kết thúc ca làm việc (" . $e->format('H:i') . ")."
                ];
            }

            return [true, "OK"];
        }
    }

    return [false, "Bệnh viện chỉ làm việc 08:00–12:00 và 13:30–17:00."];
}