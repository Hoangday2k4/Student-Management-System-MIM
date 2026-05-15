<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class SelfProfileIntegrationTest extends IntegrationTestCase
{
    // ── Student /me ───────────────────────────────────────────────────────────

    public function testStudentCanGetOwnProfile(): void
    {
        $cookie = $this->loginAsStudent();

        $response = $this->apiGet('/student_me.php', $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertSame('success', $response['json']['status'] ?? null);
        $data = $response['json']['data'] ?? [];
        $this->assertSame('S001', $data['student_code'] ?? null);
        $this->assertArrayHasKey('full_name', $data);
        $this->assertArrayHasKey('class_name', $data);
        $this->assertArrayHasKey('email', $data);
    }

    public function testStudentCanUpdateOwnProfile(): void
    {
        $cookie = $this->loginAsStudent();

        $response = $this->apiPostJson('/student_me.php', [
            'full_name' => 'Updated Student Name',
            'email' => 's001@example.test',
            'phone' => '0900000099',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'date_of_birth' => '2003-01-01',
            'class_name' => 'CTK42',
        ], $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertSame('success', $response['json']['status'] ?? null);
        $this->assertSame('Updated Student Name', $response['json']['data']['full_name'] ?? null);
    }

    public function testStudentProfileUpdateIgnoresStudentCodeChange(): void
    {
        $cookie = $this->loginAsStudent();

        // Attempt to change own student_code — must be ignored
        $response = $this->apiPostJson('/student_me.php', [
            'student_code' => 'HACKED_CODE',
            'full_name' => 'Attempted Hijack',
            'email' => 's001@example.test',
            'phone' => '',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'date_of_birth' => '',
            'class_name' => 'CTK42',
        ], $cookie);

        $this->assertSame(200, $response['status']);
        // Code must remain S001
        $this->assertSame('S001', $response['json']['data']['student_code'] ?? null);
    }

    public function testStudentMeRequiresAuth(): void
    {
        $response = $this->apiGet('/student_me.php');

        $this->assertSame(401, $response['status']);
    }

    public function testAdminCannotAccessStudentMeEndpoint(): void
    {
        $cookie = $this->loginAsAdmin();

        $response = $this->apiGet('/student_me.php', $cookie);

        // Admin is not a student — must return 403 or 404
        $this->assertContains(
            $response['status'],
            [403, 404],
            'Admin accessing /student_me.php should receive 403 or 404. Raw: ' . substr((string)($response['raw'] ?? ''), 0, 200)
        );
    }

    // ── Teacher /me ───────────────────────────────────────────────────────────

    public function testTeacherCanGetOwnProfile(): void
    {
        $cookie = $this->loginAsTeacher();

        $response = $this->apiGet('/teacher_me.php', $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertSame('success', $response['json']['status'] ?? null);
        $data = $response['json']['data'] ?? [];
        $this->assertSame('T001', $data['teacher_code'] ?? null);
        $this->assertArrayHasKey('full_name', $data);
        $this->assertArrayHasKey('email', $data);
    }

    public function testTeacherCanUpdateOwnProfile(): void
    {
        $cookie = $this->loginAsTeacher();

        $response = $this->apiPostJson('/teacher_me.php', [
            'full_name' => 'Updated Teacher Name',
            'email' => 't001@example.test',
            'phone' => '0900000088',
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'date_of_birth' => '1985-06-15',
            'academic_title' => 'ThS',
            'department' => '',
        ], $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertSame('success', $response['json']['status'] ?? null);
        $this->assertSame('Updated Teacher Name', $response['json']['data']['full_name'] ?? null);
    }

    public function testTeacherMeRequiresAuth(): void
    {
        $response = $this->apiGet('/teacher_me.php');

        $this->assertSame(401, $response['status']);
    }

    public function testStudentCannotAccessTeacherMeEndpoint(): void
    {
        $cookie = $this->loginAsStudent();

        $response = $this->apiGet('/teacher_me.php', $cookie);

        $this->assertContains(
            $response['status'],
            [403, 404],
            'Student accessing /teacher_me.php should receive 403 or 404. Raw: ' . substr((string)($response['raw'] ?? ''), 0, 200)
        );
    }
}
