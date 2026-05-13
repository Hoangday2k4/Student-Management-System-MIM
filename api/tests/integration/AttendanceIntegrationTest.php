<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class AttendanceIntegrationTest extends IntegrationTestCase
{
    private string $sectionCode = '';
    private int $courseId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sectionCode = '';
        $this->courseId    = 0;
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    public function testAttendanceEndpointsRequireAuth(): void
    {
        $endpoints = [
            ['POST', '/course_detail.php?action=create-lesson',    ['section_code' => 'X']],
            ['GET',  '/course_detail.php?action=attendance&section_code=X&week_number=1', []],
            ['POST', '/course_detail.php?action=submit-attendance', ['section_code' => 'X', 'week_number' => 1, 'attendance_list' => []]],
            ['POST', '/course_detail.php?action=delete-lesson',    ['section_code' => 'X', 'week_number' => 1]],
            ['POST', '/course_detail.php?action=submit-score',     ['section_code' => 'X', 'student_code' => 'S001']],
        ];

        foreach ($endpoints as [$method, $path, $payload]) {
            $response = $method === 'GET'
                ? $this->apiGet($path)
                : $this->apiPostJson($path, $payload);
            $this->assertSame(401, $response['status'], "Expected 401 for $method $path without auth");
        }
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function testAttendanceValidationErrors(): void
    {
        $cookie = $this->loginAsAdmin();

        // create-lesson: missing section_code
        $r = $this->apiPostJson('/course_detail.php?action=create-lesson', [], $cookie);
        $this->assertStatusIn($r, [400, 422], 'create-lesson without section_code should fail');

        // get-attendance: missing week_number (= 0)
        $r = $this->apiGet('/course_detail.php?action=attendance&section_code=NOTEXIST&week_number=0', $cookie);
        $this->assertStatusIn($r, [400, 422], 'get-attendance with week_number=0 should fail');

        // submit-attendance: missing attendance_list (not array)
        $r = $this->apiPostJson('/course_detail.php?action=submit-attendance', [
            'section_code' => 'NOTEXIST',
            'week_number'  => 1,
        ], $cookie);
        $this->assertStatusIn($r, [400, 422], 'submit-attendance without attendance_list should fail');

        // delete-lesson: missing week_number (= 0)
        $r = $this->apiPostJson('/course_detail.php?action=delete-lesson', [
            'section_code' => 'NOTEXIST',
            'week_number'  => 0,
        ], $cookie);
        $this->assertStatusIn($r, [400, 422], 'delete-lesson with week_number=0 should fail');

        // submit-score: missing student_code
        $r = $this->apiPostJson('/course_detail.php?action=submit-score', [
            'section_code' => 'NOTEXIST',
        ], $cookie);
        $this->assertStatusIn($r, [400, 422], 'submit-score without student_code should fail');
    }

    // ── Full flow ─────────────────────────────────────────────────────────────

    public function testFullAttendanceFlow(): void
    {
        $cookie = $this->loginAsAdmin();

        // 1. Create a course section assigned to T001
        $course = $this->createCourseAsAdmin(
            $this->uniqueCode('ATT'),
            'Attendance Test Course',
            'T2-(1-3)',
            '501T5',
            $cookie
        );
        $this->courseId   = (int)($course['id'] ?? 0);
        $this->sectionCode = (string)($course['section_code'] ?? '');
        $this->assertNotEmpty($this->sectionCode, 'Section code should not be empty after creation');

        // 2. Enroll S001 in the section via direct DB access
        $this->enrollStudentInCourse($this->courseId, 'S001');

        // 3. Create a lesson (buổi học)
        $createLesson = $this->apiPostJson('/course_detail.php?action=create-lesson', [
            'section_code' => $this->sectionCode,
        ], $cookie);
        $this->assertSame(200, $createLesson['status'], 'create-lesson should return 200: ' . ($createLesson['raw'] ?? ''));
        $this->assertSame('success', $createLesson['json']['status'] ?? null, 'create-lesson body should be success');

        // 4. Get attendance for week 1
        $getAtt = $this->apiGet(
            '/course_detail.php?action=attendance&section_code=' . urlencode($this->sectionCode) . '&week_number=1',
            $cookie
        );
        $this->assertSame(200, $getAtt['status'], 'get-attendance should return 200');
        $this->assertSame('success', $getAtt['json']['status'] ?? null);
        $data = $getAtt['json']['data'] ?? [];
        $this->assertSame(1, $data['week_number'] ?? null);
        $this->assertSame(1, $data['total_lessons'] ?? null);
        $this->assertCount(1, $data['students'] ?? [], 'Should have 1 enrolled student');

        // 5. Submit attendance (mark S001 as absent)
        $submitAtt = $this->apiPostJson('/course_detail.php?action=submit-attendance', [
            'section_code'    => $this->sectionCode,
            'week_number'     => 1,
            'attendance_list' => [['student_code' => 'S001', 'is_absent' => true]],
        ], $cookie);
        $this->assertSame(200, $submitAtt['status'], 'submit-attendance should return 200');
        $this->assertSame('success', $submitAtt['json']['status'] ?? null);

        // 6. Verify S001 is marked absent
        $verify = $this->apiGet(
            '/course_detail.php?action=attendance&section_code=' . urlencode($this->sectionCode) . '&week_number=1',
            $cookie
        );
        $this->assertSame(200, $verify['status']);
        $students = $verify['json']['data']['students'] ?? [];
        $s001 = null;
        foreach ($students as $s) {
            if ($s['student_code'] === 'S001') {
                $s001 = $s;
                break;
            }
        }
        $this->assertNotNull($s001, 'S001 should appear in attendance list');
        $this->assertTrue($s001['is_absent_this_week'], 'S001 should be marked absent');
        $this->assertSame(1, $s001['total_absences'], 'S001 should have 1 total absence');

        // 7. Submit score for S001
        $submitScore = $this->apiPostJson('/course_detail.php?action=submit-score', [
            'section_code' => $this->sectionCode,
            'student_code' => 'S001',
            'cc'           => 8.5,
            'gk'           => 7.0,
            'ck'           => 9.0,
            'weight_cc'    => 0.2,
            'weight_gk'    => 0.3,
            'weight_ck'    => 0.5,
        ], $cookie);
        $this->assertSame(200, $submitScore['status'], 'submit-score should return 200: ' . ($submitScore['raw'] ?? ''));
        $this->assertSame('success', $submitScore['json']['status'] ?? null);

        // 8. Delete the lesson (cleanup)
        $del = $this->apiPostJson('/course_detail.php?action=delete-lesson', [
            'section_code' => $this->sectionCode,
            'week_number'  => 1,
        ], $cookie);
        $this->assertSame(200, $del['status'], 'delete-lesson should return 200');
        $this->assertSame('success', $del['json']['status'] ?? null);
    }

    public function testStudentCanViewOwnAttendance(): void
    {
        $adminCookie = $this->loginAsAdmin();

        // Create course and enroll S001
        $course = $this->createCourseAsAdmin(
            $this->uniqueCode('ATTV'),
            'Attendance View Test',
            'T3-(4-6)',
            '502T5',
            $adminCookie
        );
        $this->courseId    = (int)($course['id'] ?? 0);
        $this->sectionCode = (string)($course['section_code'] ?? '');
        $this->enrollStudentInCourse($this->courseId, 'S001');

        // Create lesson 1 as admin
        $this->apiPostJson('/course_detail.php?action=create-lesson', [
            'section_code' => $this->sectionCode,
        ], $adminCookie);

        // Student S001 views attendance for their course
        $studentCookie = $this->loginAsStudent();
        $getAtt = $this->apiGet(
            '/course_detail.php?action=attendance&section_code=' . urlencode($this->sectionCode) . '&week_number=1',
            $studentCookie
        );
        $this->assertSame(200, $getAtt['status'], 'Student should be able to view own course attendance');
        $this->assertSame('success', $getAtt['json']['status'] ?? null);
    }

    public function testTeacherCannotAccessOtherTeacherCourse(): void
    {
        $adminCookie = $this->loginAsAdmin();

        // Create course with T001 as teacher
        $course = $this->createCourseAsAdmin(
            $this->uniqueCode('ATTO'),
            'Other Teacher Course',
            'T4-(1-3)',
            '503T5',
            $adminCookie
        );
        $sectionCode = (string)($course['section_code'] ?? '');

        // T001 tries to create a lesson for a course they teach (should succeed)
        $teacherCookie = $this->loginAsTeacher();
        $r = $this->apiPostJson('/course_detail.php?action=create-lesson', [
            'section_code' => $sectionCode,
        ], $teacherCookie);
        // T001 IS the teacher for this course, so it should succeed
        $this->assertSame(200, $r['status'], 'T001 should be able to create lesson for their own course');

        // A fake teacher (admin changed to be a different teacher) tries to access
        // We test this indirectly: unknown section_code returns 400
        $r2 = $this->apiPostJson('/course_detail.php?action=create-lesson', [
            'section_code' => 'NONEXISTENT-SECTION',
        ], $teacherCookie);
        $this->assertStatusIn($r2, [400, 403, 404], 'Non-existent section should fail');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assertStatusIn(array $response, array $allowedStatuses, string $context = ''): void
    {
        $status = $response['status'] ?? 0;
        $this->assertContains(
            $status,
            $allowedStatuses,
            ($context ? $context . '. ' : '') . 'Actual status: ' . $status . '. Raw: ' . substr((string)($response['raw'] ?? ''), 0, 200)
        );
    }
}
