<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Nếu bạn đã tách hàm ra file riêng (khuyến nghị), đổi path cho đúng:
require_once __DIR__ . '/../src/AppointmentRules.php';

// Nếu bạn CHƯA tách mà đang để trong create_appointment.php thì KHÔNG nên include trực tiếp
// vì sẽ dính session/auth/HTML. Hãy copy hàm classify_patient_search_input() sang AppointmentRules.php.

final class ClassifyPatientSearchInputTest extends TestCase
{
    /**
     * @dataProvider dp_cases
     */
    public function test_classify_patient_search_input(
        string $tcId,
        string $desc,
        string $input,
        string $expectedKind,
        string $expectedCompact
    ): void {
        $actual = classify_patient_search_input($input);

        $this->assertIsArray($actual, "$tcId - $desc should return array");
        $this->assertArrayHasKey('kind', $actual, "$tcId - $desc should have kind");
        $this->assertArrayHasKey('compact', $actual, "$tcId - $desc should have compact");

        $this->assertSame($expectedKind, $actual['kind'], "$tcId - $desc kind mismatch");
        $this->assertSame($expectedCompact, $actual['compact'], "$tcId - $desc compact mismatch");
    }

    public static function dp_cases(): array
    {
        return [
            // TC ID, Mô tả, Input, Expected kind, Expected compact
            ['TC01', 'Chuỗi rỗng',                   "   ",              'empty',      ""],
            ['TC02', 'SĐT 10 số',                    "0912345678",       'phone',      "0912345678"],
            ['TC03', 'SĐT có dấu +',                 "+84912345678",     'phone',      "+84912345678"],
            ['TC04', 'SĐT có khoảng trắng',          "09 12 345 678",    'phone',      "0912345678"],
            ['TC05', 'SĐT 8 số (biên dưới)',         "12345678",         'phone',      "12345678"],
            ['TC06', 'SĐT 15 số (biên trên)',        "123456789012345",  'phone',      "123456789012345"],
            ['TC07', '16 số (vượt biên)',            "1234567890123456", 'patient_id', "1234567890123456"],
            ['TC08', 'Mã bệnh nhân 3 số',            "123",              'patient_id', "123"],
            ['TC09', 'Mã bệnh nhân có khoảng trắng', "  456  ",          'patient_id', "456"],
            ['TC10', 'Tên bệnh nhân',                "Nguyen Van A",     'text',       "NguyenVanA"],
            ['TC11', 'Tên có dấu',                   "Nguyễn Văn Á",     'text',       "NguyễnVănÁ"],
            ['TC12', 'Tên + số',                     "An 1999",          'text',       "An1999"],
            ['TC13', 'SĐT có chữ',                   "09123abc78",       'text',       "09123abc78"],
            ['TC14', 'Ký tự đặc biệt',               "@#$$%",            'text',       "@#$$%"],
            ['TC15', 'Dấu + nhưng không đủ số',      "+123",             'text',       "+123"],
        ];
    }
}