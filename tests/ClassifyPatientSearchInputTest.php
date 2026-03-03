<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AppointmentRules.php';

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

        $this->assertSame($expectedKind, $actual['kind'], "$tcId - $desc kind mismatch");
        $this->assertSame($expectedCompact, $actual['compact'], "$tcId - $desc compact mismatch");
    }

    public static function dp_cases(): array
    {
        return [
            // ===== EMPTY =====
            ['TC01', 'Chuỗi rỗng', "   ", 'empty', ""],

            // ===== PATIENT ID (1–9 digits) =====
            ['TC02', 'Mã BN 1 số', "1", 'patient_id', "1"],
            ['TC03', 'Mã BN 5 số', "12345", 'patient_id', "12345"],
            ['TC04', 'Mã BN 9 số (biên trên)', "123456789", 'patient_id', "123456789"],
            ['TC05', 'Mã BN có khoảng trắng', "  456  ", 'patient_id', "456"],

            // ===== PHONE (10–15 digits) =====
            ['TC06', 'SĐT 10 số', "0912345678", 'phone', "0912345678"],
            ['TC07', 'SĐT 11 số', "84912345678", 'phone', "84912345678"],
            ['TC08', 'SĐT 15 số (biên trên)', "123456789012345", 'phone', "123456789012345"],
            ['TC09', 'SĐT có dấu +', "+84912345678", 'phone', "+84912345678"],
            ['TC10', 'SĐT có khoảng trắng', "09 12 345 678", 'phone', "0912345678"],

            // ===== TEXT =====
            ['TC11', '16 số (vượt biên)', "1234567890123456", 'text', "1234567890123456"],
            ['TC12', 'Tên bệnh nhân', "Nguyen Van A", 'text', "NguyenVanA"],
            ['TC13', 'Tên có dấu', "Nguyễn Văn Á", 'text', "NguyễnVănÁ"],
            ['TC14', 'SĐT có chữ', "09123abc78", 'text', "09123abc78"],
            ['TC15', 'Dấu + nhưng không đủ số', "+123", 'text', "+123"],
            ['TC16', 'Ký tự đặc biệt', "@#$$%", 'text', "@#$$%"],
        ];
    }
}