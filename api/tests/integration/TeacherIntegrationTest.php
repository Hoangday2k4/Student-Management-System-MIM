<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class TeacherIntegrationTest extends IntegrationTestCase
{
    public function testTeacherAuthorization(): void
    {
        $cookie = $this->loginAsTeacher();

        $response = $this->apiGet('/student.php', $cookie);

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('Forbidden', $response['raw']);
    }

    public function testTeacherGradingFlow(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $teacherCookie = $this->loginAsTeacher();
        $studentCookie = $this->loginAsStudent();

        $course = $this->createCourseAsAdmin('GRADE-1', 'Grading 101', 'T3-(1-3)', '503T5', $adminCookie);
        $this->enrollStudentInCourse($course['id'], 'S001');

        $save = $this->apiPostJson('/course_grade.php', [
            'id' => $course['id'],
            'weight_cc' => 0.3,
            'weight_gk' => 0.3,
            'weight_ck' => 0.4,
            'scores' => [
                ['student_code' => 'S001', 'cc' => 8, 'gk' => 7, 'ck' => 9],
            ],
        ], $teacherCookie);

        $this->assertSame(200, $save['status']);
        $this->assertStringContainsString('success', strtolower($save['raw']));

        $scoreList = $this->apiGet('/score.php', $studentCookie);
        $this->assertSame(200, $scoreList['status']);
        $this->assertTrue($this->scoreListContainsCourse($scoreList['json'], $course['course_code']));
    }
}
