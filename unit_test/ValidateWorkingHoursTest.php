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

            'TC01 - Start đầu ca sáng'  => ["$d 08:00:00", 20, true,  'OK'],
            'TC02 - Giữa ca sáng'       => ["$d 09:10:00", 20, true,  'OK'],
            'TC03 - Start đầu ca chiều' => ["$d 13:30:00", 20, true,  'OK'],

            // Biên ca sáng: 11:40
            'TC04 - Biên -1 (11:39)'    => ["$d 11:39:00", 20, true,  'OK'],
            'TC05 - Biên đúng (11:40)'  => ["$d 11:40:00", 20, true,  'OK'],
            'TC06 - Biên +1 (11:41)'    => ["$d 11:41:00", 20, false, 'vượt quá thời gian làm việc'],

            // Biên ca chiều: 16:40
            'TC07 - Biên -1 (16:39)'    => ["$d 16:39:00", 20, true,  'OK'],
            'TC08 - Biên đúng (16:40)'  => ["$d 16:40:00", 20, true,  'OK'],
            'TC09 - Biên +1 (16:41)'    => ["$d 16:41:00", 20, false, 'vượt quá thời gian làm việc'],

            'TC10 - Trước giờ làm (07:59)' => ["$d 07:59:00", 20, false, 'Phòng khám chỉ làm việc'],
            'TC11 - Đúng 12:00 (ngoài ca)' => ["$d 12:00:00", 20, false, 'Phòng khám chỉ làm việc'],
            'TC12 - Trong giờ nghỉ trưa'   => ["$d 12:30:00", 20, false, 'Phòng khám chỉ làm việc'],
            'TC13 - Sau giờ làm (17:01)'   => ["$d 17:01:00", 20, false, 'Phòng khám chỉ làm việc'],

            'TC14 - 11:10 + 20 vẫn OK'     => ["$d 11:10:00", 20, true, 'OK'],
        ];
    }
}