<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class ImportIntegrationTest extends IntegrationTestCase
{
    // ── Student import (save step) ────────────────────────────────────────────

    public function testStudentImportSaveRequiresAuth(): void
    {
        $response = $this->apiPostJson('/student_import.php', [
            'rows' => [
                [
                    'student_code' => 'SIMPORT001',
                    'full_name' => 'Import Student',
                    'class_name' => 'CTK42',
                    'gender' => 'Nam',
                    'status' => 'Dang hoc',
                    'email' => 'simport001@example.test',
                ],
            ],
        ]);

        $this->assertSame(401, $response['status']);
    }

    public function testStudentImportSaveRequiresAdminRole(): void
    {
        $studentCookie = $this->loginAsStudent();

        $response = $this->apiPostJson('/student_import.php', [
            'rows' => [
                [
                    'student_code' => 'SIMPORT002',
                    'full_name' => 'Import Student Unauthorized',
                    'class_name' => 'CTK42',
                    'gender' => 'Nam',
                    'status' => 'Dang hoc',
                    'email' => 'simport002@example.test',
                ],
            ],
        ], $studentCookie);

        $this->assertContains(
            $response['status'],
            [401, 403],
            'Student role should not be able to import. Status: ' . $response['status']
        );
    }

    public function testStudentImportSaveInsertsValidRows(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $code1 = $this->uniqueStudentCode();
        $code2 = $this->uniqueStudentCode();

        $response = $this->apiPostJson('/student_import.php', [
            'rows' => [
                [
                    'student_code' => $code1,
                    'full_name' => 'Import Student One',
                    'class_name' => 'CTK42',
                    'gender' => 'Nam',
                    'status' => 'Dang hoc',
                    'email' => $code1 . '@import.test',
                    'phone' => '',
                    'cccd' => '',
                    'address' => '',
                    'admission_date' => '',
                    'major' => '',
                    'faculty' => '',
                    'date_of_birth' => '',
                ],
                [
                    'student_code' => $code2,
                    'full_name' => 'Import Student Two',
                    'class_name' => 'CTK43',
                    'gender' => 'Nu',
                    'status' => 'Dang hoc',
                    'email' => $code2 . '@import.test',
                    'phone' => '',
                    'cccd' => '',
                    'address' => '',
                    'admission_date' => '',
                    'major' => '',
                    'faculty' => '',
                    'date_of_birth' => '',
                ],
            ],
        ], $adminCookie);

        $this->assertSame(200, $response['status']);
        $this->assertSame('success', $response['json']['status'] ?? null);
        $this->assertGreaterThanOrEqual(2, (int)($response['json']['inserted_count'] ?? 0));

        // Verify records are actually persisted
        $detail1 = $this->apiGet('/student_detail.php?student_code=' . $code1, $adminCookie);
        $this->assertSame(200, $detail1['status']);
        $this->assertSame($code1, $detail1['json']['data']['student_code'] ?? null);

        $detail2 = $this->apiGet('/student_detail.php?student_code=' . $code2, $adminCookie);
        $this->assertSame(200, $detail2['status']);
    }

    public function testStudentImportSaveSkipsDuplicateCodes(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $existing = $this->uniqueStudentCode();

        // First insert
        $this->apiPostJson('/student.php', [
            'student_code' => $existing,
            'full_name' => 'Pre-existing Student',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => $existing . '@existing.test',
        ], $adminCookie);

        // Import same code again
        $response = $this->apiPostJson('/student_import.php', [
            'rows' => [
                [
                    'student_code' => $existing,
                    'full_name' => 'Duplicate via Import',
                    'class_name' => 'CTK42',
                    'gender' => 'Nam',
                    'status' => 'Dang hoc',
                    'email' => 'dup.' . $existing . '@import.test',
                    'phone' => '',
                    'cccd' => '',
                    'address' => '',
                    'admission_date' => '',
                    'major' => '',
                    'faculty' => '',
                    'date_of_birth' => '',
                ],
            ],
        ], $adminCookie);

        $this->assertSame(200, $response['status']);
        // inserted_count should be 0 (duplicate skipped), skipped_count >= 1
        $this->assertGreaterThanOrEqual(1, (int)($response['json']['skipped_count'] ?? 0));
    }

    public function testStudentImportSaveWithEmptyRowsReturnsSuccess(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $response = $this->apiPostJson('/student_import.php', [
            'rows' => [],
        ], $adminCookie);

        // Empty rows should return success with 0 inserted
        $this->assertContains($response['status'], [200, 422]);
        if ($response['status'] === 200) {
            $this->assertSame(0, (int)($response['json']['inserted_count'] ?? 0));
        }
    }

    // ── Teacher import (save step) ────────────────────────────────────────────

    public function testTeacherImportSaveRequiresAuth(): void
    {
        $response = $this->apiPostJson('/teacher_import.php', [
            'rows' => [
                [
                    'teacher_code' => 'TIMPORT001',
                    'full_name' => 'Import Teacher',
                    'department' => '',
                    'gender' => 'Nam',
                    'status' => 'Dang cong tac',
                    'email' => 'timport001@example.test',
                ],
            ],
        ]);

        $this->assertSame(401, $response['status']);
    }

    public function testTeacherImportSaveInsertsValidRows(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $code = $this->uniqueCode('TI');

        $response = $this->apiPostJson('/teacher_import.php', [
            'rows' => [
                [
                    'teacher_code' => $code,
                    'full_name' => 'Imported Teacher',
                    'department' => '',
                    'gender' => 'Nam',
                    'status' => 'Dang cong tac',
                    'email' => $code . '@import.test',
                    'phone' => '',
                    'academic_title' => 'ThS',
                    'date_of_birth' => '',
                    'avatar' => '',
                ],
            ],
        ], $adminCookie);

        $this->assertSame(200, $response['status']);
        $this->assertSame('success', $response['json']['status'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int)($response['json']['inserted_count'] ?? 0));

        $detail = $this->apiGet('/teacher_detail.php?teacher_code=' . $code, $adminCookie);
        $this->assertSame(200, $detail['status']);
        $this->assertSame($code, $detail['json']['data']['teacher_code'] ?? null);
    }

    public function testTeacherImportSaveSkipsDuplicateCodes(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $existing = $this->uniqueCode('TDUP');

        $this->apiPostJson('/teacher.php', [
            'teacher_code' => $existing,
            'full_name' => 'Pre-existing Teacher',
            'department' => '',
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'email' => $existing . '@existing.test',
        ], $adminCookie);

        $response = $this->apiPostJson('/teacher_import.php', [
            'rows' => [
                [
                    'teacher_code' => $existing,
                    'full_name' => 'Duplicate via Import',
                    'department' => '',
                    'gender' => 'Nam',
                    'status' => 'Dang cong tac',
                    'email' => 'dup.' . $existing . '@import.test',
                    'phone' => '',
                    'academic_title' => '',
                    'date_of_birth' => '',
                    'avatar' => '',
                ],
            ],
        ], $adminCookie);

        $this->assertSame(200, $response['status']);
        $this->assertGreaterThanOrEqual(1, (int)($response['json']['skipped_count'] ?? 0));
    }

    public function testTeacherImportSaveRequiresAdminRole(): void
    {
        $teacherCookie = $this->loginAsTeacher();

        $response = $this->apiPostJson('/teacher_import.php', [
            'rows' => [
                [
                    'teacher_code' => $this->uniqueCode('TAUTH'),
                    'full_name' => 'Unauthorized Import',
                    'department' => '',
                    'gender' => 'Nam',
                    'status' => 'Dang cong tac',
                    'email' => 'unauth@import.test',
                ],
            ],
        ], $teacherCookie);

        $this->assertContains(
            $response['status'],
            [401, 403],
            'Teacher role should not be able to import teachers. Status: ' . $response['status']
        );
    }
}
