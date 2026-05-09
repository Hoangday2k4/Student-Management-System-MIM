<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class StudentIntegrationTest extends IntegrationTestCase
{
    public function testStudentsUnauthorizedWithoutSession(): void
    {
        $response = $this->apiGet('/student.php');

        $this->assertSame(401, $response['status']);
        $this->assertStringContainsString('Unauthorized', $response['raw']);
    }

    public function testStudentAuthorization(): void
    {
        $cookie = $this->loginAsStudent();

        $response = $this->apiPostJson('/course.php', [
            'course_code' => 'COURSE-STU',
            'course_name' => 'Student Forbidden Course',
            'teacher_code' => 'T001',
            'credits' => '3',
        ], $cookie);

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('Forbidden', $response['raw']);
    }

    public function testStudentEnrollmentAndCourseDetail(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $studentCookie = $this->loginAsStudent();

        $course = $this->createCourseAsAdmin('ENROLL-1', 'Enrollment 101', 'T2-(1-3)', '502T5', $adminCookie);
        $this->enrollStudentInCourse($course['id'], 'S001');

        $response = $this->apiGet('/course_detail.php?id=' . $course['id'], $studentCookie);

        $this->assertSame(200, $response['status']);
        $students = $response['json']['students'] ?? [];
        $this->assertTrue($this->studentListContains($students, 'S001'));
    }
}
