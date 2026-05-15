<?php

declare(strict_types=1);

if (!defined('DB_PATH')) {
    putenv('DB_PATH_OVERRIDE=' . sys_get_temp_dir() . '/phpunit_unit_tests.sqlite');
}

use PHPUnit\Framework\TestCase;

final class TeacherUnitTest extends TestCase
{
    private static string $apiRoot;

    public static function setUpBeforeClass(): void
    {
        self::$apiRoot = dirname(__DIR__);
        require_once self::$apiRoot . '/app/common/define.php';
        require_once self::$apiRoot . '/app/common/db.php';
        require_once self::$apiRoot . '/app/models/Faculty.php';
        require_once self::$apiRoot . '/app/models/Teacher.php';
        require_once self::$apiRoot . '/app/models/Admin.php';

        $pdo = get_db_connection();
        Admin::ensureSchema($pdo);
        Teacher::ensureSchema($pdo);
    }

    private function uniqueCode(): string
    {
        return 'TU' . bin2hex(random_bytes(3));
    }

    // ── insertWithPdo + findByTeacherCode ─────────────────────────────────────

    public function testInsertAndFindByTeacherCode(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Teacher::insertWithPdo($pdo, [
            'teacher_code' => $code,
            'full_name' => 'Unit Test Teacher',
            'date_of_birth' => '1985-03-20',
            'gender' => 'Nam',
            'academic_title' => 'ThS',
            'department' => '',
            'email' => $code . '@example.test',
            'phone' => '0900000099',
            'status' => 'Đang công tác',
            'avatar' => '',
        ]);

        $found = Teacher::findByTeacherCode($code);

        $this->assertIsArray($found);
        $this->assertSame($code, $found['teacher_code']);
        $this->assertSame('Unit Test Teacher', $found['full_name']);
        $this->assertSame('ThS', $found['academic_title']);
        $this->assertSame($code . '@example.test', $found['email']);
    }

    public function testFindByTeacherCodeReturnsNullForMissingCode(): void
    {
        $result = Teacher::findByTeacherCode('GV-KHONG-TON-TAI-XYZ-999');

        $this->assertFalse($result);
    }

    // ── Status defaults ───────────────────────────────────────────────────────

    public function testInsertWithEmptyStatusDefaultsToDangCongTac(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Teacher::insertWithPdo($pdo, [
            'teacher_code' => $code,
            'full_name' => 'Default Status Teacher',
            'date_of_birth' => '',
            'gender' => 'Nữ',
            'academic_title' => '',
            'department' => '',
            'email' => $code . '@example.test',
            'phone' => '',
            'status' => '',
            'avatar' => '',
        ]);

        $found = Teacher::findByTeacherCode($code);

        $this->assertIsArray($found);
        $this->assertSame('Đang công tác', $found['status']);
    }

    // ── Email auto-fill ───────────────────────────────────────────────────────

    public function testInsertWithEmptyEmailAutoFillsFromCode(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Teacher::insertWithPdo($pdo, [
            'teacher_code' => $code,
            'full_name' => 'Auto Email Teacher',
            'date_of_birth' => '',
            'gender' => 'Nam',
            'academic_title' => '',
            'department' => '',
            'email' => '',
            'phone' => '',
            'status' => 'Đang công tác',
            'avatar' => '',
        ]);

        $found = Teacher::findByTeacherCode($code);

        $this->assertIsArray($found);
        $this->assertNotEmpty($found['email'], 'Email should be auto-filled when empty');
        $this->assertStringContainsString(strtolower($code), strtolower($found['email']));
    }

    // ── Duplicate teacher code ────────────────────────────────────────────────

    public function testInsertDuplicateTeacherCodeThrowsException(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();
        $data = [
            'teacher_code' => $code,
            'full_name' => 'First Teacher',
            'date_of_birth' => '',
            'gender' => 'Nam',
            'academic_title' => '',
            'department' => '',
            'email' => $code . '@example.test',
            'phone' => '',
            'status' => 'Đang công tác',
            'avatar' => '',
        ];

        Teacher::insertWithPdo($pdo, $data);

        $this->expectException(\PDOException::class);

        $data['full_name'] = 'Second Teacher (duplicate)';
        $data['email'] = 'dup.' . $code . '@example.test';
        Teacher::insertWithPdo($pdo, $data);
    }

    // ── Lookup is case-insensitive ─────────────────────────────────────────────

    public function testFindByTeacherCodeIsCaseInsensitive(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Teacher::insertWithPdo($pdo, [
            'teacher_code' => $code,
            'full_name' => 'Case Test Teacher',
            'date_of_birth' => '',
            'gender' => 'Nam',
            'academic_title' => '',
            'department' => '',
            'email' => $code . '@example.test',
            'phone' => '',
            'status' => 'Đang công tác',
            'avatar' => '',
        ]);

        $foundUpper = Teacher::findByTeacherCode(strtoupper($code));
        $foundLower = Teacher::findByTeacherCode(strtolower($code));

        $this->assertIsArray($foundUpper);
        $this->assertIsArray($foundLower);
        $this->assertSame($code, $foundUpper['teacher_code']);
    }

    // ── Minimal fields ────────────────────────────────────────────────────────

    public function testInsertWithMinimalFieldsSucceeds(): void
    {
        $pdo = get_db_connection();
        $code = $this->uniqueCode();

        Teacher::insertWithPdo($pdo, [
            'teacher_code' => $code,
            'full_name' => 'Minimal Teacher',
            'date_of_birth' => '',
            'gender' => '',
            'academic_title' => '',
            'department' => '',
            'email' => '',
            'phone' => '',
            'status' => '',
            'avatar' => '',
        ]);

        $found = Teacher::findByTeacherCode($code);

        $this->assertIsArray($found);
        $this->assertSame($code, $found['teacher_code']);
    }
}
