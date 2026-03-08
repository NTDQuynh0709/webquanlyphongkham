<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AppointmentRules.php';

final class ValidateWorkingHoursTest extends TestCase
{
    /**
     * @dataProvider dp_cases
     */
    public function test_validate_working_hours(
        string $tcId,
        string $desc,
        string $startStr,
        int $serviceMin,
        bool $expectedOk,
        string $expectedMsgContains
    ): void {
        [$ok, $msg] = validate_working_hours(new DateTime($startStr), $serviceMin);

        $this->assertSame($expectedOk, $ok, "$tcId - $desc | start=$startStr service=$serviceMin | msg=$msg");
        $this->assertStringContainsString($expectedMsgContains, $msg, "$tcId - $desc message mismatch");
    }

    public static function dp_cases(): array
{
    $d = '2026-03-03';

    return [

        // ========= GROUP A: Happy path =========
        ['VWH01', 'Start đầu ca sáng',  "$d 08:00:00", 20, true,  'OK'],
        ['VWH02', 'Giữa ca sáng',       "$d 09:10:00", 20, true,  'OK'],
        ['VWH03', 'Start đầu ca chiều', "$d 13:30:00", 20, true,  'OK'],

        // ========= GROUP B: Boundary test ca sáng =========
        // Ca sáng tan 12:00 → latestByRule = 11:50

        ['VWH10', 'Biên -1 (11:49)', "$d 11:49:00", 20, true,  'OK'],
        ['VWH11', 'Biên đúng (11:50)', "$d 11:50:00", 20, true,  'OK'],
        ['VWH12', 'Biên +1 (11:51)', "$d 11:51:00", 20, false, 'trước giờ tan ca ít nhất'],

        // ========= GROUP C: Boundary test ca chiều =========
        // Ca chiều tan 17:00 → latestByRule = 16:50

        ['VWH20', 'Biên -1 (16:49)', "$d 16:49:00", 20, true,  'OK'],
        ['VWH21', 'Biên đúng (16:50)', "$d 16:50:00", 20, true,  'OK'],
        ['VWH22', 'Biên +1 (16:51)', "$d 16:51:00", 20, false, 'trước giờ tan ca ít nhất'],

        // ========= GROUP D: Outside sessions =========
        ['VWH30', 'Trước giờ làm (07:59)', "$d 07:59:00", 20, false, 'Bệnh viện chỉ làm việc'],
        ['VWH31', 'Đúng 12:00 (ngoài ca)', "$d 12:00:00", 20, false, 'Bệnh viện chỉ làm việc'],
        ['VWH32', 'Trong giờ nghỉ trưa', "$d 12:30:00", 20, false, 'Bệnh viện chỉ làm việc'],
        ['VWH33', 'Sau giờ làm (17:01)', "$d 17:01:00", 20, false, 'Bệnh viện chỉ làm việc'],

        // ========= GROUP E: Duration không còn ảnh hưởng =========
        ['VWH40', '11:45 + 20 vượt 12:00 nhưng vẫn OK', "$d 11:45:00", 20, true, 'OK'],
        
    ];
}
}