<?php
declare(strict_types=1);

function classify_patient_search_input(string $q): array {
    $raw = trim($q);

    if ($raw === '') {
        return ['kind' => 'empty', 'compact' => ''];
    }

    $compact = preg_replace('/\s+/', '', $raw);

    if (preg_match('/^\+?\d{10,15}$/', $compact)) {
        return ['kind' => 'phone', 'compact' => $compact];
    }

    if (preg_match('/^\d{1,9}$/', $compact)) {
        return ['kind' => 'patient_id', 'compact' => $compact];
    }

    return ['kind' => 'text', 'compact' => $compact];
}