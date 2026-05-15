<?php

declare(strict_types=1);

if (!defined('DB_PATH')) {
    putenv('DB_PATH_OVERRIDE=' . sys_get_temp_dir() . '/phpunit_unit_tests.sqlite');
}

use PHPUnit\Framework\TestCase;

final class StudentUnitTest extends TestCase
{
    private static string $apiRoot;

    public static function setUpBeforeClass(): void
    {
        self::$apiRoot = dirname(__DIR__);
        require_once self::$apiRoot . '/app/common/define.php';
        require_once self::$apiRoot . '/app/common/db.php';
        require_once self::$apiRoot . '/app/models/Faculty.php';
        require_once self::$apiRoot . '/app/models/Student.php';
        require_once self::$apiRoot . '/app/models/Admin.php';

        $pdo = get_db_connection();
        Admin::ensureSchema($pdo);
        Student::ensureSchema($pdo);
    }

    private function uniqueCode(): string
    {
        return 'SU' . bin2hex(random_bytes(3));
    }

    // ── insertWithPdo + findByStudentCode ─────────────────────────────────────

    public function testInsertAndFindByStudentCode(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Student::insertWithPdo($pdo, [
            'student_code' => $code,
            'full_name' => 'Unit Test Student',
            'date_of_birth' => '2003-06-15',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Đang học',
            'email' => $code . '@example.test',
            'phone' => '0900000001',
            'cccd' => '',
            'address' => 'Hà Nội',
            'admission_date' => '2022-09-01',
            'faculty' => '',
            'avatar' => '',
        ]);

        $found = Student::findByStudentCode($code);

        $this->assertIsArray($found);
        $this->assertSame($code, $found['student_code']);
        $this->assertSame('Unit Test Student', $found['full_name']);
        $this->assertSame('CTK42', $found['class_name']);
        $this->assertSame('Nam', $found['gender']);
        $this->assertSame($code . '@example.test', $found['email']);
    }

    public function testFindByStudentCodeReturnsNullForMissingCode(): void
    {
        $result = Student::findByStudentCode('SV-KHONG-TON-TAI-XYZ-999');

        $this->assertFalse($result);
    }

    // ── Status defaults ───────────────────────────────────────────────────────

    public function testInsertWithEmptyStatusDefaultsToDangHoc(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Student::insertWithPdo($pdo, [
            'student_code' => $code,
            'full_name' => 'Default Status Student',
            'date_of_birth' => '',
            'class_name' => 'CTK43',
            'gender' => 'Nữ',
            'status' => '',
            'email' => '',
            'phone' => '',
            'cccd' => '',
            'address' => '',
            'admission_date' => '',
            'faculty' => '',
            'avatar' => '',
        ]);

        $found = Student::findByStudentCode($code);

        $this->assertIsArray($found);
        $this->assertSame('Đang học', $found['status']);
    }

    // ── Duplicate student code ────────────────────────────────────────────────

    public function testInsertDuplicateStudentCodeThrowsException(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();
        $data = [
            'student_code' => $code,
            'full_name' => 'First Student',
            'date_of_birth' => '',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Đang học',
            'email' => $code . '@example.test',
            'phone' => '',
            'cccd' => '',
            'address' => '',
            'admission_date' => '',
            'faculty' => '',
            'avatar' => '',
        ];

        Student::insertWithPdo($pdo, $data);

        $this->expectException(\PDOException::class);

        $data['full_name'] = 'Second Student (duplicate)';
        $data['email'] = 'dup.' . $code . '@example.test';
        Student::insertWithPdo($pdo, $data);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function testSearchByKeywordFindsMatchingStudent(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();
        $uniqueName = 'ZZSearchTarget' . bin2hex(random_bytes(2));

        Student::insertWithPdo($pdo, [
            'student_code' => $code,
            'full_name' => $uniqueName,
            'date_of_birth' => '',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Đang học',
            'email' => $code . '@search.test',
            'phone' => '',
            'cccd' => '',
            'address' => '',
            'admission_date' => '',
            'faculty' => '',
            'avatar' => '',
        ]);

        $results = Student::search(['keyword' => $uniqueName]);

        $found = false;
        foreach ($results as $row) {
            if ($row['student_code'] === $code) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Search by keyword '$uniqueName' should return the inserted student");
    }

    public function testSearchByNonExistentKeywordReturnsEmpty(): void
    {
        $results = Student::search(['keyword' => 'KEYWORD_THAT_DOES_NOT_MATCH_ANYTHING_XYZ_999']);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    // ── NULL-safe optional fields ─────────────────────────────────────────────

    public function testInsertWithMinimalFieldsSucceeds(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Student::insertWithPdo($pdo, [
            'student_code' => $code,
            'full_name' => 'Minimal Student',
            'date_of_birth' => '',
            'class_name' => 'CTK44',
            'gender' => '',
            'status' => 'Đang học',
            'email' => '',
            'phone' => '',
            'cccd' => '',
            'address' => '',
            'admission_date' => '',
            'faculty' => '',
            'avatar' => '',
        ]);

        $found = Student::findByStudentCode($code);

        $this->assertIsArray($found);
        $this->assertSame($code, $found['student_code']);
        $this->assertSame('Minimal Student', $found['full_name']);
    }
}
