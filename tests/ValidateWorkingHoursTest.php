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
            ['VWH01', 'Start đầu ca sáng',            "$d 08:00:00", 20, true,  'OK'],
            ['VWH02', 'Giữa ca sáng',                "$d 09:10:00", 20, true,  'OK'],
            ['VWH03', 'Start đầu ca chiều',          "$d 13:30:00", 20, true,  'OK'],
            ['VWH04', 'Giữa ca chiều',               "$d 15:00:00", 20, true,  'OK'],

            // “LatestByRule” biên đúng: 11:50 + 20 = 12:10? (KHÔNG: 11:50+20=12:10 => vượt tan ca)
            // LƯU Ý: Với SERVICE_MIN=20, start=11:40 mới end=12:00.
            ['VWH05', 'Biên hợp lệ sát tan ca sáng (11:40 + 20 = 12:00)', "$d 11:40:00", 20, true, 'OK'],
            ['VWH06', 'Biên hợp lệ sát tan ca chiều (16:40 + 20 = 17:00)', "$d 16:40:00", 20, true, 'OK'],

            // ========= GROUP B: Outside sessions =========
            ['VWH10', 'Trước giờ làm (07:59)',       "$d 07:59:00", 20, false, 'Bệnh viện chỉ làm việc'],
            ['VWH11', 'Đúng 12:00 là ngoài ca',      "$d 12:00:00", 20, false, 'Bệnh viện chỉ làm việc'],
            ['VWH12', 'Trong giờ nghỉ trưa 12:30',   "$d 12:30:00", 20, false, 'Bệnh viện chỉ làm việc'],
            ['VWH13', 'Trước ca chiều (13:29)',      "$d 13:29:00", 20, false, 'Bệnh viện chỉ làm việc'],
            ['VWH14', 'Đúng 17:00 là ngoài ca',      "$d 17:00:00", 20, false, 'Bệnh viện chỉ làm việc'],
            ['VWH15', 'Sau giờ làm (17:01)',         "$d 17:01:00", 20, false, 'Bệnh viện chỉ làm việc'],

            // ========= GROUP C: Last booking rule (tan ca - 10p) =========
            // Ca sáng tan 12:00, latestByRule = 11:50, nhưng còn bị duration rule nữa.
            // 11:51 vi phạm last booking rule (đúng theo code: check last booking trước duration).
            ['VWH20', 'Vi phạm đặt cuối ca sáng (11:51)', "$d 11:51:00", 20, false, 'trước giờ tan ca ít nhất'],
            ['VWH21', 'Vi phạm đặt cuối ca sáng (11:59)', "$d 11:59:00", 20, false, 'trước giờ tan ca ít nhất'],
            ['VWH22', 'Vi phạm đặt cuối ca chiều (16:51)', "$d 16:51:00", 20, false, 'trước giờ tan ca ít nhất'],
            ['VWH23', 'Vi phạm đặt cuối ca chiều (16:59)', "$d 16:59:00", 20, false, 'trước giờ tan ca ít nhất'],

            // ========= GROUP D: Duration overflow =========
            // duration vượt tan ca nhưng start vẫn trước latestByRule => sẽ vào nhánh overflow.
            ['VWH30', 'Vượt tan ca sáng (11:45 + 20 = 12:05)', "$d 11:45:00", 20, false, 'không được vượt quá giờ tan ca'],
            ['VWH31', 'Vượt tan ca chiều (16:45 + 20 = 17:05)', "$d 16:45:00", 20, false, 'không được vượt quá giờ tan ca'],

            // duration dài hơn thực tế
            ['VWH32', 'Khám dài 40p gây vượt ca sáng', "$d 11:30:00", 40, false, 'không được vượt quá giờ tan ca'],
            ['VWH33', 'Khám 30p vẫn OK (16:20 + 30 = 16:50)', "$d 16:20:00", 30, true, 'OK'],
            ['VWH34', 'Khám 45p vượt ca chiều (16:20 + 45 = 17:05)', "$d 16:20:00", 45, false, 'không được vượt quá giờ tan ca'],

            // ========= GROUP E: ServiceMin đặc biệt =========
            ['VWH40', 'Service = 0 (config lỗi) vẫn OK nếu trong ca', "$d 10:00:00", 0, true, 'OK'],
            ['VWH41', 'Service rất lớn chắc chắn vượt ca', "$d 08:30:00", 500, false, 'không được vượt quá giờ tan ca'],
        ];
    }
}