<?php
declare(strict_types=1);

const SERVICE_MIN = 20;


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
            if ($end > $e) {
                return [
                    false,
                    "Lịch khám vượt quá thời gian làm việc của ca này (kết thúc lúc " . $e->format('H:i') . ")"
                ];
            }

            return [true, 'OK'];
        }
    }

    return [false, 'Phòng khám chỉ làm việc 08:00–12:00 và 13:30–17:00.'];
}