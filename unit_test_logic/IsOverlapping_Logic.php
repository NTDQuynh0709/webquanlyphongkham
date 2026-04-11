<?php
declare(strict_types=1);

function is_overlapping(DateTime $start, DateTime $end, array $blocks): bool {
    foreach ($blocks as [$bs, $be]) {
        if ($start < $be && $end > $bs) {
            return true;
        }
    }
    return false;
}