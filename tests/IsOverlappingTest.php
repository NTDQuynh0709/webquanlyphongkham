<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AppointmentRules.php';

final class IsOverlappingTest extends TestCase
{
    /**
     * @dataProvider dp_cases
     */
    public function test_is_overlapping(
        string $startStr,
        string $endStr,
        array $blocksStr,
        bool $expected
    ): void {
        $start = new DateTime($startStr);
        $end   = new DateTime($endStr);

        $blocks = array_map(
            fn(array $b) => [new DateTime($b[0]), new DateTime($b[1])],
            $blocksStr
        );

        $actual = is_overlapping($start, $end, $blocks);

        $this->assertSame($expected, $actual);
    }

    public static function dp_cases(): array
    {
        $d = '2026-03-03';

        return [
            // ========= GROUP A: No blocks =========
            'TC01 - Không có block' => ["$d 09:00:00", "$d 09:20:00", [], false],

            // ========= GROUP B =========
            'TC02 - Nằm hoàn toàn trong block' => [
                "$d 09:05:00", "$d 09:10:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                true
            ],

            'TC03 - Chồng một phần bên phải' => [
                "$d 09:10:00", "$d 09:30:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                true
            ],

            'TC04 - Chồng một phần bên trái' => [
                "$d 08:50:00", "$d 09:05:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                true
            ],

            'TC05 - Bao trùm block' => [
                "$d 08:55:00", "$d 09:25:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                true
            ],

            'TC06 - Không overlap (trước)' => [
                "$d 08:30:00", "$d 08:59:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                false
            ],

            'TC07 - Không overlap (sau)' => [
                "$d 09:21:00", "$d 09:40:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                false
            ],

            // ========= GROUP C =========
            'TC08 - Touch trái' => [
                "$d 08:40:00", "$d 09:00:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                false
            ],

            'TC09 - Touch phải' => [
                "$d 09:20:00", "$d 09:40:00",
                [["$d 09:00:00", "$d 09:20:00"]],
                false
            ],

            // ========= GROUP D =========
            'TC10 - Overlap block thứ 2' => [
                "$d 10:10:00", "$d 10:25:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                    ["$d 11:00:00", "$d 11:20:00"],
                ],
                true
            ],

            'TC11 - Nằm giữa 2 block' => [
                "$d 09:30:00", "$d 09:50:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                ],
                false
            ],

            'TC12 - Touch chain' => [
                "$d 09:40:00", "$d 10:00:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                ],
                false
            ],

            'TC13 - Bao trùm nhiều block' => [
                "$d 09:10:00", "$d 10:10:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                ],
                true
            ],
        ];
    }
}