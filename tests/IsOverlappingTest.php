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
        string $tcId,
        string $desc,
        string $startStr,
        string $endStr,
        array $blocksStr,   // [ [bs, be], ... ] as string
        bool $expected
    ): void {
        $start = new DateTime($startStr);
        $end   = new DateTime($endStr);

        $blocks = array_map(
            fn(array $b) => [new DateTime($b[0]), new DateTime($b[1])],
            $blocksStr
        );

        $actual = is_overlapping($start, $end, $blocks);
        $this->assertSame($expected, $actual, "$tcId - $desc");
    }

    public static function dp_cases(): array
    {
        $d = '2026-03-03';

        return [
            // ========= GROUP A: No blocks =========
            ['OVL01', 'Không có block', "$d 09:00:00", "$d 09:20:00", [], false],

            // ========= GROUP B: One block overlap patterns =========
            // block: 09:00-09:20
            ['OVL10', 'Nằm hoàn toàn trong block', "$d 09:05:00", "$d 09:10:00",
                [["$d 09:00:00", "$d 09:20:00"]], true],

            ['OVL11', 'Chồng một phần bên phải', "$d 09:10:00", "$d 09:30:00",
                [["$d 09:00:00", "$d 09:20:00"]], true],

            ['OVL12', 'Chồng một phần bên trái', "$d 08:50:00", "$d 09:05:00",
                [["$d 09:00:00", "$d 09:20:00"]], true],

            ['OVL13', 'Khoảng mới bao trùm block', "$d 08:55:00", "$d 09:25:00",
                [["$d 09:00:00", "$d 09:20:00"]], true],

            ['OVL14', 'Không overlap (nằm trước)', "$d 08:30:00", "$d 08:59:00",
                [["$d 09:00:00", "$d 09:20:00"]], false],

            ['OVL15', 'Không overlap (nằm sau)', "$d 09:21:00", "$d 09:40:00",
                [["$d 09:00:00", "$d 09:20:00"]], false],

            // ========= GROUP C: Touching edges allowed =========
            ['OVL20', 'End == block start (touch trái)', "$d 08:40:00", "$d 09:00:00",
                [["$d 09:00:00", "$d 09:20:00"]], false],

            ['OVL21', 'Start == block end (touch phải)', "$d 09:20:00", "$d 09:40:00",
                [["$d 09:00:00", "$d 09:20:00"]], false],

            // ========= GROUP D: Multiple blocks =========
            // blocks: 09:00-09:20, 10:00-10:20, 11:00-11:20
            ['OVL30', 'Overlap block thứ 2', "$d 10:10:00", "$d 10:25:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                    ["$d 11:00:00", "$d 11:20:00"],
                ], true],

            ['OVL31', 'Nằm giữa block1 và block2', "$d 09:30:00", "$d 09:50:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                ], false],

            ['OVL32', 'Touch chain: end == start block2 vẫn false', "$d 09:40:00", "$d 10:00:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                ], false],

            ['OVL33', 'Overlap do bao trùm nhiều block', "$d 09:10:00", "$d 10:10:00",
                [
                    ["$d 09:00:00", "$d 09:20:00"],
                    ["$d 10:00:00", "$d 10:20:00"],
                ], true],

            
        ];
    }
}