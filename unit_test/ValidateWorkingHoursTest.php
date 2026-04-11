<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../unit_test_logic/ValidateWorkingHours_Logic.php';

final class ValidateWorkingHoursTest extends TestCase
{
    /**
     * @dataProvider dp_cases
     */
    public function test_validate_working_hours(
        string $startStr,
        int $serviceMin,
        bool $expectedOk,
        string $expectedMsgContains
    ): void {
        [$ok, $msg] = validate_working_hours(new DateTime($startStr), $serviceMin);

        $this->assertSame($expectedOk, $ok);
        $this->assertStringContainsString($expectedMsgContains, $msg);
    }

    public static function dp_cases(): array
    {
        $d = '2026-03-03';

        return [

            // ========= GROUP A =========
            'TC01 - Start đầu ca sáng'  => ["$d 08:00:00", 20, true,  'OK'],
            'TC02 - Giữa ca sáng'       => ["$d 09:10:00", 20, true,  'OK'],
            'TC03 - Start đầu ca chiều' => ["$d 13:30:00", 20, true,  'OK'],

            // ========= GROUP B =========
            'TC04 - Biên -1 (11:49)' => ["$d 11:49:00", 20, true,  'OK'],
            'TC05 - Biên đúng (11:50)' => ["$d 11:50:00", 20, true,  'OK'],
            'TC06 - Biên +1 (11:51)' => ["$d 11:51:00", 20, false, 'trước giờ tan ca ít nhất'],

            // ========= GROUP C =========
            'TC07 - Biên -1 (16:49)' => ["$d 16:49:00", 20, true,  'OK'],
            'TC08 - Biên đúng (16:50)' => ["$d 16:50:00", 20, true,  'OK'],
            'TC09 - Biên +1 (16:51)' => ["$d 16:51:00", 20, false, 'trước giờ tan ca ít nhất'],

            // ========= GROUP D =========
            'TC10 - Trước giờ làm (07:59)' => ["$d 07:59:00", 20, false, 'Bệnh viện chỉ làm việc'],
            'TC11 - Đúng 12:00 (ngoài ca)' => ["$d 12:00:00", 20, false, 'Bệnh viện chỉ làm việc'],
            'TC12 - Trong giờ nghỉ trưa'   => ["$d 12:30:00", 20, false, 'Bệnh viện chỉ làm việc'],
            'TC13 - Sau giờ làm (17:01)'   => ["$d 17:01:00", 20, false, 'Bệnh viện chỉ làm việc'],

            // ========= GROUP E =========
            'TC14 - 11:45 + 20 vẫn OK' => ["$d 11:45:00", 20, true, 'OK'],
        ];
    }
}