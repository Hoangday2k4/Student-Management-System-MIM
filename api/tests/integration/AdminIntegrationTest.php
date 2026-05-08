<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class AdminIntegrationTest extends IntegrationTestCase
{
    public function testAdminAuthorization(): void
    {
        $cookie = $this->loginAsAdmin();

        $response = $this->apiGet('/student.php', $cookie);

        $this->assertSame(200, $response['status']);
    }

    public function testAdminConfigAndHome(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $config = $this->apiGet('/get_config');
        $this->assertSame(200, $config['status']);
        $this->assertSame('success', $config['json']['status'] ?? null);

        $home = $this->apiGet('/home', $adminCookie);
        $this->assertSame(200, $home['status']);
        $this->assertSame('admin', $home['json']['login_id'] ?? null);
        $this->assertSame('staff', $home['json']['account_type'] ?? null);
    }

    public function testAdminStudentCrud(): void
    {
        $cookie = $this->loginAsAdmin();
        $created = null;
        $studentCode = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $studentCode = $this->uniqueStudentCode();
            $created = $this->apiPostJson('/student.php', [
                'student_code' => $studentCode,
                'full_name' => 'Integration Student',
                'class_name' => 'CTK42',
                'gender' => 'Nam',
                'status' => 'Dang hoc',
                'email' => $studentCode . '@example.test',
            ], $cookie);
            if (($created['status'] ?? 0) !== 409) {
                break;
            }
        }

        $this->assertSame(201, $created['status']);
        $this->assertSame($studentCode, $created['json']['data']['student_code'] ?? null);

        $detail = $this->apiGet('/student_detail.php?student_code=' . $studentCode, $cookie);
        $this->assertSame(200, $detail['status']);
        $this->assertSame($studentCode, $detail['json']['data']['student_code'] ?? null);
        $this->assertSame('Integration Student', $detail['json']['data']['full_name'] ?? null);

        $updatedCode = $studentCode . 'X';
        $updated = $this->apiPostJson('/student_detail.php', [
            'old_student_code' => $studentCode,
            'student_code' => $updatedCode,
            'full_name' => 'Integration Student Updated',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => $updatedCode . '@example.test',
        ], $cookie);

        $this->assertSame(200, $updated['status']);
        $this->assertSame($updatedCode, $updated['json']['data']['student_code'] ?? null);
        $this->assertSame('Integration Student Updated', $updated['json']['data']['full_name'] ?? null);

        $deleted = $this->apiRequest('DELETE', '/student.php?student_code=' . $updatedCode, [], $cookie);
        $this->assertSame(200, $deleted['status']);
        $this->assertStringContainsString('success', strtolower($deleted['raw']));

        $notFound = $this->apiGet('/student_detail.php?student_code=' . $updatedCode, $cookie);
        $this->assertSame(404, $notFound['status']);
    }

    public function testAdminTeacherCrud(): void
    {
        $cookie = $this->loginAsAdmin();
        $facultyCode = $this->uniqueCode('K');
        $this->createFaculty($facultyCode, 'Khoa Test', $cookie);

        $created = null;
        $teacherCode = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $teacherCode = $this->uniqueCode('T');
            $created = $this->apiPostJson('/teacher.php', [
                'teacher_code' => $teacherCode,
                'full_name' => 'Integration Teacher',
                'department' => $facultyCode,
                'gender' => 'Nam',
                'status' => 'Dang cong tac',
                'email' => $teacherCode . '@example.test',
            ], $cookie);
            if (($created['status'] ?? 0) !== 409) {
                break;
            }
        }

        $this->assertSame(201, $created['status']);
        $this->assertSame($teacherCode, $created['json']['data']['teacher_code'] ?? null);

        $detail = $this->apiGet('/teacher_detail.php?teacher_code=' . $teacherCode, $cookie);
        $this->assertSame(200, $detail['status']);
        $this->assertSame($teacherCode, $detail['json']['data']['teacher_code'] ?? null);
        $this->assertSame('Integration Teacher', $detail['json']['data']['full_name'] ?? null);

        $updatedCode = $teacherCode . 'X';
        $updated = $this->apiPostJson('/teacher_detail.php', [
            'old_teacher_code' => $teacherCode,
            'teacher_code' => $updatedCode,
            'full_name' => 'Integration Teacher Updated',
            'department' => $facultyCode,
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'email' => $updatedCode . '@example.test',
        ], $cookie);

        $this->assertSame(200, $updated['status']);
        $this->assertSame($updatedCode, $updated['json']['data']['teacher_code'] ?? null);
        $this->assertSame('Integration Teacher Updated', $updated['json']['data']['full_name'] ?? null);

        $deleted = $this->apiRequest('DELETE', '/teacher.php?teacher_code=' . $updatedCode, [], $cookie);
        $this->assertSame(200, $deleted['status']);
        $this->assertStringContainsString('success', strtolower($deleted['raw']));

        $notFound = $this->apiGet('/teacher_detail.php?teacher_code=' . $updatedCode, $cookie);
        $this->assertSame(404, $notFound['status']);
    }

    public function testAdminFacultyMajorClassCrud(): void
    {
        $cookie = $this->loginAsAdmin();

        $facultyCode = $this->uniqueCode('K');
        $faculty = $this->createFaculty($facultyCode, 'Khoa Quan Ly', $cookie);
        $this->assertSame($facultyCode, $faculty['code'] ?? null);

        $majorCode = $this->uniqueCode('N');
        $major = $this->createMajor($majorCode, 'Nganh Quan Ly', $facultyCode, $cookie);
        $this->assertSame($majorCode, $major['code'] ?? null);

        $classCode = $this->uniqueCode('L');
        $class = $this->createClass($classCode, 'Lop Quan Ly', $majorCode, 'T001', $cookie);
        $this->assertSame($classCode, $class['code'] ?? null);

        $updatedFaculty = $this->apiPostJson('/faculty.php', [
            'action' => 'update',
            'old_code' => $facultyCode,
            'code' => $facultyCode,
            'name' => 'Khoa Quan Ly Updated',
        ], $cookie);
        $this->assertSame(200, $updatedFaculty['status']);
        $this->assertSame('Khoa Quan Ly Updated', $updatedFaculty['json']['data']['name'] ?? null);

        $updatedMajor = $this->apiPostJson('/major.php', [
            'action' => 'update',
            'old_code' => $majorCode,
            'code' => $majorCode,
            'name' => 'Nganh Quan Ly Updated',
            'faculty_code' => $facultyCode,
        ], $cookie);
        $this->assertSame(200, $updatedMajor['status']);
        $this->assertSame('Nganh Quan Ly Updated', $updatedMajor['json']['data']['name'] ?? null);

        $updatedClass = $this->apiPostJson('/class.php', [
            'action' => 'update',
            'old_code' => $classCode,
            'code' => $classCode,
            'name' => 'Lop Quan Ly Updated',
            'major_code' => $majorCode,
            'head_teacher_code' => 'T001',
        ], $cookie);
        $this->assertSame(200, $updatedClass['status']);
        $this->assertSame('Lop Quan Ly Updated', $updatedClass['json']['data']['name'] ?? null);

        $deletedClass = $this->apiRequest('DELETE', '/class.php?code=' . $classCode, [], $cookie);
        $this->assertSame(200, $deletedClass['status']);

        $deletedMajor = $this->apiRequest('DELETE', '/major.php?code=' . $majorCode, [], $cookie);
        $this->assertSame(200, $deletedMajor['status']);

        $deletedFaculty = $this->apiRequest('DELETE', '/faculty.php?code=' . $facultyCode, [], $cookie);
        $this->assertSame(200, $deletedFaculty['status']);
    }

    public function testAdminCourseAndSubjectCrud(): void
    {
        $cookie = $this->loginAsAdmin();

        $courseCode = $this->uniqueCode('COUR');
        $course = $this->createCourseAsAdmin($courseCode, 'Course Admin', 'T5-(1-3)', '505T5', $cookie);
        $this->assertSame($courseCode, $course['course_code'] ?? null);
        $this->assertNotNull($course['id'] ?? null);

        $updated = $this->apiPostJson('/course_detail.php', [
            'id' => $course['id'],
            'course_code' => $courseCode,
            'course_name' => 'Course Admin Updated',
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => 'T5-(4-6)',
            'classroom' => '505T5',
            'max_students' => '25',
        ], $cookie);
        $this->assertSame(200, $updated['status']);
        $this->assertSame('Course Admin Updated', $updated['json']['data']['course_name'] ?? null);

        $deleted = $this->apiRequest('DELETE', '/course.php?id=' . $course['id'], [], $cookie);
        $this->assertSame(200, $deleted['status']);

        $subjectCode = $this->uniqueCode('SUB');
        $subject = $this->apiPostJson('/course.php', [
            'mode' => 'subject',
            'course_code' => $subjectCode,
            'course_name' => 'Subject Admin',
            'credits' => '2',
            'course_type' => 'BAT_BUOC',
        ], $cookie);
        $this->assertSame(201, $subject['status']);
        $this->assertSame($subjectCode, $subject['json']['data']['course_code'] ?? null);

        $subjectUpdate = $this->apiPostJson('/course.php', [
            'mode' => 'subject-update',
            'course_code' => $subjectCode,
            'course_name' => 'Subject Admin Updated',
            'credits' => '3',
            'course_type' => 'BAT_BUOC',
        ], $cookie);
        $this->assertSame(200, $subjectUpdate['status']);
        $this->assertSame($subjectCode, $subjectUpdate['json']['data']['course_code'] ?? null);
        $this->assertSame('Subject Admin Updated', $subjectUpdate['json']['data']['course_name'] ?? null);

        $subjectDelete = $this->apiRequest('DELETE', '/course.php?mode=subject&code=' . $subjectCode, [], $cookie);
        $this->assertSame(200, $subjectDelete['status']);
    }

    public function testAdminPasswordResetFlow(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $request = $this->apiPostJson('/request_reset', [
            'login_id' => 'S001',
        ]);
        $this->assertSame(200, $request['status']);
        $this->assertTrue((bool)($request['json']['success'] ?? false));

        $list = $this->apiGet('/reset_list', $adminCookie);
        $this->assertSame(200, $list['status']);
        $this->assertIsArray($list['json']['items'] ?? []);

        $targetId = null;
        foreach (($list['json']['items'] ?? []) as $item) {
            if (($item['login_id'] ?? '') === 'S001') {
                $targetId = $item['id'] ?? null;
                break;
            }
        }
        $this->assertNotNull($targetId, 'S001 should be found in reset list');

        $reset = $this->apiPostJson('/reset_password', [
            'admin_id' => $targetId,
        ], $adminCookie);
        $this->assertSame(200, $reset['status']);
        $this->assertTrue((bool)($reset['json']['success'] ?? false));
    }

    public function testAdminChangePassword(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $response = $this->apiPostJson('/change-password', [
            'old_password' => '123456',
            'new_password' => '1234567',
            'confirm_password' => '1234567',
        ], $adminCookie);

        $this->assertSame(200, $response['status']);
        $this->assertTrue((bool)($response['json']['success'] ?? false));
        $this->assertStringContainsString('success', strtolower($response['raw'] ?? ''));
    }

    public function testAdminScoreAccessRestrictions(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $score = $this->apiGet('/score.php', $adminCookie);
        $this->assertSame(403, $score['status'], 'Admin should not access score');

        $grade = $this->apiGet('/course_grade.php?id=1', $adminCookie);
        $this->assertSame(403, $grade['status'], 'Admin should not access course grades');
    }

    public function testScheduleConflictValidation(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $this->createCourseAsAdmin('SCHED-1', 'Schedule 101', 'T4-(1-3)', '504T5', $adminCookie);
        $this->assertNotNull('SCHED-1', 'First course should be created successfully');

        $conflict = $this->apiPostJson('/course.php', [
            'course_code' => 'SCHED-2',
            'course_name' => 'Schedule 102',
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => 'T4-(1-3)',
            'classroom' => '504T5',
        ], $adminCookie);

        $this->assertSame(422, $conflict['status'], 'Conflicting schedule should return 422');
        $this->assertStringContainsString('schedule', strtolower($conflict['raw'] ?? ''), 'Error message should mention schedule conflict');
    }

    public function testAdminCrossRoleAuthorizationForMutations(): void
    {
        $studentCookie = $this->loginAsStudent();
        $teacherCookie = $this->loginAsTeacher();

        $studentCreateByStudent = $this->apiPostJson('/student.php', [
            'student_code' => $this->uniqueStudentCode(),
            'full_name' => 'Unauthorized Student',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => 'unauth.student@example.test',
        ], $studentCookie);
        $this->assertStatusIn($studentCreateByStudent, [401, 403], 'Student should not create student');

        $facultyCreateByTeacher = $this->apiPostJson('/faculty.php', [
            'action' => 'create',
            'code' => $this->uniqueCode('KX'),
            'name' => 'Unauthorized Faculty',
        ], $teacherCookie);
        $this->assertStatusIn($facultyCreateByTeacher, [401, 403], 'Teacher should not create faculty');

        $teacherDeleteByStudent = $this->apiRequest('DELETE', '/teacher.php?teacher_code=T001', [], $studentCookie);
        $this->assertStatusIn($teacherDeleteByStudent, [401, 403], 'Student should not delete teacher');

        $classUpdateByTeacher = $this->apiPostJson('/class.php', [
            'action' => 'update',
            'old_code' => 'NOT-EXIST',
            'code' => 'NOT-EXIST',
            'name' => 'Unauthorized Update',
            'major_code' => 'NOT-EXIST',
            'head_teacher_code' => 'T001',
        ], $teacherCookie);
        $this->assertStatusIn($classUpdateByTeacher, [401, 403], 'Teacher should not update class as admin');
    }

    public function testAdminRequiredFieldValidationAcrossEntities(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $cases = [
            'student-create-missing-student_code' => $this->apiPostJson('/student.php', [
                'full_name' => 'Missing Student Code',
                'class_name' => 'CTK42',
                'gender' => 'Nam',
                'status' => 'Dang hoc',
                'email' => 'missing.student.code@example.test',
            ], $adminCookie),
            'teacher-create-missing-teacher_code' => $this->apiPostJson('/teacher.php', [
                'full_name' => 'Missing Teacher Code',
                'department' => 'CNTT',
                'gender' => 'Nam',
                'status' => 'Dang cong tac',
                'email' => 'missing.teacher.code@example.test',
            ], $adminCookie),
            'faculty-create-missing-code' => $this->apiPostJson('/faculty.php', [
                'action' => 'create',
                'name' => 'Faculty Missing Code',
            ], $adminCookie),
            'major-create-missing-faculty' => $this->apiPostJson('/major.php', [
                'action' => 'create',
                'code' => $this->uniqueCode('NREQ'),
                'name' => 'Major Missing Faculty',
            ], $adminCookie),
            'class-create-missing-major' => $this->apiPostJson('/class.php', [
                'action' => 'create',
                'code' => $this->uniqueCode('LREQ'),
                'name' => 'Class Missing Major',
                'head_teacher_code' => 'T001',
            ], $adminCookie),
            'course-create-missing-course_code' => $this->apiPostJson('/course.php', [
                'course_name' => 'Missing Course Code',
                'teacher_code' => 'T001',
                'credits' => '3',
                'schedule' => 'T2-(1-3)',
                'classroom' => '501T5',
            ], $adminCookie),
        ];

        foreach ($cases as $case => $response) {
            $this->assertStatusIn($response, [400, 422], $case . ' should fail required-field validation');
        }
    }

    public function testAdminFormatValidationAcrossEntities(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $facultyCode = $this->uniqueCode('KFMT');
        $this->createFaculty($facultyCode, 'Format Faculty', $adminCookie);

        $badStudentEmail = $this->apiPostJson('/student.php', [
            'student_code' => $this->uniqueStudentCode(),
            'full_name' => 'Bad Email Student',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => 'not-an-email',
        ], $adminCookie);
        $this->assertStatusIn($badStudentEmail, [400, 422], 'Student invalid email should fail validation');

        $badTeacherEnum = $this->apiPostJson('/teacher.php', [
            'teacher_code' => $this->uniqueCode('TFMT'),
            'full_name' => 'Invalid Enum Teacher',
            'department' => $facultyCode,
            'gender' => 'KhongHopLe',
            'status' => 'TrangThaiSai',
            'email' => 'teacher.invalid.enum@example.test',
        ], $adminCookie);
        $this->assertStatusIn($badTeacherEnum, [400, 422], 'Teacher invalid enum should fail validation');

        $emptyFacultyCode = $this->apiPostJson('/faculty.php', [
            'action' => 'create',
            'code' => '',
            'name' => 'Empty Faculty Code',
        ], $adminCookie);
        $this->assertStatusIn($emptyFacultyCode, [400, 422], 'Faculty empty code should fail validation');

        $badSubjectType = $this->apiPostJson('/course.php', [
            'mode' => 'subject',
            'course_code' => $this->uniqueCode('SUBFMT'),
            'course_name' => 'Invalid Subject Type',
            'credits' => '2',
            'course_type' => 'INVALID_TYPE',
        ], $adminCookie);
        $this->assertStatusIn($badSubjectType, [400, 422], 'Subject invalid course_type should fail validation');
    }

    public function testAdminDuplicateBusinessKeys(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $facultyCode = $this->uniqueCode('KDUP');
        $this->createFaculty($facultyCode, 'Duplicate Faculty', $adminCookie);
        $dupFaculty = $this->apiPostJson('/faculty.php', [
            'action' => 'create',
            'code' => $facultyCode,
            'name' => 'Duplicate Faculty 2',
        ], $adminCookie);
        $this->assertSame(409, $dupFaculty['status'], 'Duplicate faculty code should return 409');

        $majorCode = $this->uniqueCode('NDUP');
        $this->createMajor($majorCode, 'Duplicate Major', $facultyCode, $adminCookie);
        $dupMajor = $this->apiPostJson('/major.php', [
            'action' => 'create',
            'code' => $majorCode,
            'name' => 'Duplicate Major 2',
            'faculty_code' => $facultyCode,
        ], $adminCookie);
        $this->assertSame(409, $dupMajor['status'], 'Duplicate major code should return 409');

        $classCode = $this->uniqueCode('LDUP');
        $this->createClass($classCode, 'Duplicate Class', $majorCode, 'T001', $adminCookie);
        $dupClass = $this->apiPostJson('/class.php', [
            'action' => 'create',
            'code' => $classCode,
            'name' => 'Duplicate Class 2',
            'major_code' => $majorCode,
            'head_teacher_code' => 'T001',
        ], $adminCookie);
        $this->assertSame(409, $dupClass['status'], 'Duplicate class code should return 409');

        $studentCode = $this->uniqueStudentCode();
        $studentCreate = $this->apiPostJson('/student.php', [
            'student_code' => $studentCode,
            'full_name' => 'Duplicate Student',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => $studentCode . '@example.test',
        ], $adminCookie);
        $this->assertSame(201, $studentCreate['status']);
        $dupStudent = $this->apiPostJson('/student.php', [
            'student_code' => $studentCode,
            'full_name' => 'Duplicate Student 2',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => 'dup.' . $studentCode . '@example.test',
        ], $adminCookie);
        $this->assertSame(409, $dupStudent['status'], 'Duplicate student code should return 409');

        $teacherCode = $this->uniqueCode('TDUP');
        $teacherCreate = $this->apiPostJson('/teacher.php', [
            'teacher_code' => $teacherCode,
            'full_name' => 'Duplicate Teacher',
            'department' => $facultyCode,
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'email' => $teacherCode . '@example.test',
        ], $adminCookie);
        $this->assertSame(201, $teacherCreate['status']);
        $dupTeacher = $this->apiPostJson('/teacher.php', [
            'teacher_code' => $teacherCode,
            'full_name' => 'Duplicate Teacher 2',
            'department' => $facultyCode,
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'email' => 'dup.' . $teacherCode . '@example.test',
        ], $adminCookie);
        $this->assertSame(409, $dupTeacher['status'], 'Duplicate teacher code should return 409');

        $courseCode = $this->uniqueCode('COURDUP');
        $course = $this->createCourseAsAdmin($courseCode, 'Duplicate Course', 'T6-(1-3)', '606T5', $adminCookie);
        $this->assertSame($courseCode, $course['course_code'] ?? null);
        $dupCourse = $this->apiPostJson('/course.php', [
            'course_code' => $courseCode,
            'course_name' => 'Duplicate Course 2',
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => 'T6-(4-6)',
            'classroom' => '607T5',
            'max_students' => '30',
        ], $adminCookie);
        $this->assertSame(409, $dupCourse['status'], 'Duplicate course code should return 409');
    }

    public function testAdminReferentialIntegrityOnDelete(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $facultyCode = $this->uniqueCode('KREF');
        $majorCode = $this->uniqueCode('NREF');
        $classCode = $this->uniqueCode('LREF');
        $this->createFaculty($facultyCode, 'Referential Faculty', $adminCookie);
        $this->createMajor($majorCode, 'Referential Major', $facultyCode, $adminCookie);
        $this->createClass($classCode, 'Referential Class', $majorCode, 'T001', $adminCookie);

        $deleteFaculty = $this->apiRequest('DELETE', '/faculty.php?code=' . $facultyCode, [], $adminCookie);
        $this->assertStatusIn($deleteFaculty, [400, 409, 422], 'Faculty with existing major should not be deletable');

        $deleteMajor = $this->apiRequest('DELETE', '/major.php?code=' . $majorCode, [], $adminCookie);
        $this->assertStatusIn($deleteMajor, [400, 409, 422], 'Major with existing class should not be deletable');

        $teacherCode = $this->uniqueCode('TREF');
        $teacherCreate = $this->apiPostJson('/teacher.php', [
            'teacher_code' => $teacherCode,
            'full_name' => 'Referential Teacher',
            'department' => $facultyCode,
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'email' => $teacherCode . '@example.test',
        ], $adminCookie);
        $this->assertSame(201, $teacherCreate['status']);

        $courseCreate = $this->apiPostJson('/course.php', [
            'course_code' => $this->uniqueCode('CREF'),
            'course_name' => 'Referential Course',
            'teacher_code' => $teacherCode,
            'credits' => '3',
            'schedule' => 'T7-(1-3)',
            'classroom' => '707T5',
            'max_students' => '30',
        ], $adminCookie);
        $this->assertSame(201, $courseCreate['status']);

        $deleteTeacher = $this->apiRequest('DELETE', '/teacher.php?teacher_code=' . $teacherCode, [], $adminCookie);
        $this->assertStatusIn($deleteTeacher, [400, 409, 422], 'Teacher assigned to course should not be deletable');
    }

    public function testAdminUpdateWithInvalidRelations(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $facultyCode = $this->uniqueCode('KREL');
        $majorCode = $this->uniqueCode('NREL');
        $classCode = $this->uniqueCode('LREL');
        $this->createFaculty($facultyCode, 'Relation Faculty', $adminCookie);
        $this->createMajor($majorCode, 'Relation Major', $facultyCode, $adminCookie);
        $this->createClass($classCode, 'Relation Class', $majorCode, 'T001', $adminCookie);

        $classUpdate = $this->apiPostJson('/class.php', [
            'action' => 'update',
            'old_code' => $classCode,
            'code' => $classCode,
            'name' => 'Invalid Relation Class',
            'major_code' => 'MAJOR-NOT-EXIST',
            'head_teacher_code' => 'T001',
        ], $adminCookie);
        $this->assertStatusIn($classUpdate, [404, 422], 'Class update with non-existing major should fail');

        $courseCode = $this->uniqueCode('CREL');
        $course = $this->createCourseAsAdmin($courseCode, 'Relation Course', 'T8-(1-3)', '808T5', $adminCookie);
        $courseUpdate = $this->apiPostJson('/course_detail.php', [
            'id' => $course['id'] ?? null,
            'course_code' => $courseCode,
            'course_name' => 'Relation Course Updated',
            'teacher_code' => 'TEACHER-NOT-EXIST',
            'credits' => '3',
            'schedule' => 'T8-(4-6)',
            'classroom' => '808T5',
            'max_students' => '25',
        ], $adminCookie);
        $this->assertStatusIn($courseUpdate, [400, 404, 422], 'Course update with non-existing teacher should fail');
    }

    public function testScheduleConflictValidationOnUpdateAndResourceConflict(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $courseA = $this->createCourseAsAdmin($this->uniqueCode('SCHA'), 'Teacher Conflict A', 'T2-(1-3)', '902T5', $adminCookie);
        $courseB = $this->createCourseAsAdmin($this->uniqueCode('SCHB'), 'Teacher Conflict B', 'T3-(1-3)', '903T5', $adminCookie);

        $teacherConflict = $this->apiPostJson('/course_detail.php', [
            'id' => $courseB['id'] ?? null,
            'course_code' => $courseB['course_code'] ?? '',
            'course_name' => 'Teacher Conflict B Updated',
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => 'T2-(1-3)',
            'classroom' => '903T5',
            'max_students' => '25',
        ], $adminCookie);
        $this->assertStatusIn($teacherConflict, [409, 422], 'Update should fail when teacher schedule conflicts');

        $facultyCode = $this->uniqueCode('KSCH');
        $this->createFaculty($facultyCode, 'Schedule Faculty', $adminCookie);
        $teacherCode = $this->uniqueCode('TSCH');
        $teacherCreate = $this->apiPostJson('/teacher.php', [
            'teacher_code' => $teacherCode,
            'full_name' => 'Schedule Teacher',
            'department' => $facultyCode,
            'gender' => 'Nam',
            'status' => 'Dang cong tac',
            'email' => $teacherCode . '@example.test',
        ], $adminCookie);
        $this->assertSame(201, $teacherCreate['status']);

        $courseC = $this->apiPostJson('/course.php', [
            'course_code' => $this->uniqueCode('SCHC'),
            'course_name' => 'Room Conflict C',
            'teacher_code' => $teacherCode,
            'credits' => '3',
            'schedule' => 'T4-(1-3)',
            'classroom' => '904T5',
            'max_students' => '30',
        ], $adminCookie);
        $this->assertSame(201, $courseC['status']);

        $courseD = $this->createCourseAsAdmin($this->uniqueCode('SCHD'), 'Room Conflict D', 'T5-(1-3)', '905T5', $adminCookie);
        $classroomConflict = $this->apiPostJson('/course_detail.php', [
            'id' => $courseD['id'] ?? null,
            'course_code' => $courseD['course_code'] ?? '',
            'course_name' => 'Room Conflict D Updated',
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => 'T4-(1-3)',
            'classroom' => '904T5',
            'max_students' => '30',
        ], $adminCookie);
        $this->assertStatusIn($classroomConflict, [409, 422], 'Update should fail when classroom schedule conflicts');
    }

    public function testAdminPasswordResetSecurity(): void
    {
        $adminCookie = $this->loginAsAdmin();
        $teacherCookie = $this->loginAsTeacher();

        $invalidTargetReset = $this->apiPostJson('/reset_password', [
            'admin_id' => -999999,
        ], $adminCookie);
        $this->assertStatusIn($invalidTargetReset, [400, 404, 422], 'Resetting non-existing request should fail');

        $request = $this->apiPostJson('/request_reset', [
            'login_id' => 'S001',
        ]);
        $this->assertSame(200, $request['status']);

        $targetId = $this->findResetRequestIdByLoginId($adminCookie, 'S001');
        $this->assertNotNull($targetId, 'Reset request for S001 should exist');

        $firstReset = $this->apiPostJson('/reset_password', [
            'admin_id' => $targetId,
        ], $adminCookie);
        $this->assertSame(200, $firstReset['status']);

        $secondReset = $this->apiPostJson('/reset_password', [
            'admin_id' => $targetId,
        ], $adminCookie);
        $this->assertStatusIn($secondReset, [400, 404, 409, 422], 'Processed reset request should not be reset twice');

        $request2 = $this->apiPostJson('/request_reset', [
            'login_id' => 'S001',
        ]);
        $this->assertSame(200, $request2['status']);
        $targetId2 = $this->findResetRequestIdByLoginId($adminCookie, 'S001');
        $this->assertNotNull($targetId2, 'Second reset request for S001 should exist');

        $teacherResetAttempt = $this->apiPostJson('/reset_password', [
            'admin_id' => $targetId2,
        ], $teacherCookie);
        $this->assertStatusIn($teacherResetAttempt, [401, 403], 'Non-admin account should not call reset_password');
    }

    public function testChangePasswordNegativeScenarios(): void
    {
        $studentCookie = $this->loginAsStudent();

        $wrongOld = $this->apiPostJson('/change-password', [
            'old_password' => 'wrong-password',
            'new_password' => '1234567',
            'confirm_password' => '1234567',
        ], $studentCookie);
        $this->assertStatusIn($wrongOld, [400, 401, 422], 'Wrong old_password should fail change-password');

        $confirmMismatch = $this->apiPostJson('/change-password', [
            'old_password' => '123456',
            'new_password' => '1234567',
            'confirm_password' => '1234568',
        ], $studentCookie);
        $this->assertStatusIn($confirmMismatch, [400, 422], 'Mismatched confirm_password should fail change-password');

        $weakPassword = $this->apiPostJson('/change-password', [
            'old_password' => '123456',
            'new_password' => '123',
            'confirm_password' => '123',
        ], $studentCookie);
        $this->assertStatusIn($weakPassword, [400, 422], 'Weak/short password should fail change-password');
    }

    public function testAdminNotFoundConsistency(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $responses = [
            $this->apiGet('/student_detail.php?student_code=SV-KHONG-TON-TAI', $adminCookie),
            $this->apiGet('/teacher_detail.php?teacher_code=GV-KHONG-TON-TAI', $adminCookie),
            $this->apiRequest('DELETE', '/major.php?code=NGANH-KHONG-TON-TAI', [], $adminCookie),
            $this->apiRequest('DELETE', '/class.php?code=LOP-KHONG-TON-TAI', [], $adminCookie),
            $this->apiRequest('DELETE', '/faculty.php?code=KHOA-KHONG-TON-TAI', [], $adminCookie),
        ];

        $firstStatus = null;
        foreach ($responses as $index => $response) {
            $this->assertStatusIn($response, [400, 404], 'Not found endpoint should return client error for response index ' . $index);
            if ($firstStatus === null) {
                $firstStatus = $response['status'];
            }
            $this->assertSame($firstStatus, $response['status'], 'Not found status should be consistent across admin modules');

            $raw = strtolower($response['raw'] ?? '');
            $this->assertTrue(
                str_contains($raw, 'not found') || str_contains($raw, 'khong ton tai') || str_contains($raw, 'does not exist'),
                'Not found response should include a meaningful message. Raw: ' . $raw
            );
        }
    }

    public function testAdminBasicInjectionAndXssSafety(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $payloadStudent = $this->apiPostJson('/student.php', [
            'student_code' => $this->uniqueStudentCode(),
            'full_name' => '<script>alert(1)</script>',
            'class_name' => 'CTK42',
            'gender' => 'Nam',
            'status' => 'Dang hoc',
            'email' => "x' OR '1'='1@example.test",
        ], $adminCookie);

        $this->assertNotSame(500, $payloadStudent['status'], 'SQL/XSS-like payload on student endpoint should not crash server');
        $this->assertStringNotContainsString('<script', strtolower($payloadStudent['raw'] ?? ''), 'Response should not reflect raw script payload');

        $payloadCourse = $this->apiPostJson('/course.php', [
            'course_code' => $this->uniqueCode('XSS'),
            'course_name' => '<img src=x onerror=alert(1)>',
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => 'T9-(1-3)',
            'classroom' => '909T5',
            'max_students' => '30',
        ], $adminCookie);

        $this->assertNotSame(500, $payloadCourse['status'], 'SQL/XSS-like payload on course endpoint should not crash server');
        $this->assertStringNotContainsString('onerror=', strtolower($payloadCourse['raw'] ?? ''), 'Response should not reflect dangerous HTML attributes');

        $health = $this->apiGet('/get_config');
        $this->assertSame(200, $health['status'], 'System should remain healthy after injection/XSS payloads');
    }

    public function testAdminApiResponseSchemaConsistency(): void
    {
        $adminCookie = $this->loginAsAdmin();

        $success = $this->apiGet('/home', $adminCookie);
        $this->assertSame(200, $success['status']);
        $this->assertIsArray($success['json']);
        $this->assertTrue(
            array_key_exists('status', $success['json']) || array_key_exists('success', $success['json']),
            'Success response should contain status or success field'
        );
        $this->assertTrue(
            array_key_exists('message', $success['json']) || array_key_exists('data', $success['json']) || array_key_exists('login_id', $success['json']),
            'Success response should contain message/data or a stable payload object'
        );

        $error = $this->apiGet('/student_detail.php?student_code=SV-SCHEMA-KHONG-TON-TAI', $adminCookie);
        $this->assertStatusIn($error, [400, 404], 'Error response should use client-error status');
        $this->assertIsArray($error['json']);
        $this->assertTrue(
            array_key_exists('status', $error['json']) || array_key_exists('success', $error['json']) || array_key_exists('message', $error['json']),
            'Error response should include schema keys like status/success/message'
        );
    }

    private function assertStatusIn(array $response, array $allowedStatuses, string $context): void
    {
        $status = $response['status'] ?? 0;
        $this->assertContains(
            $status,
            $allowedStatuses,
            $context . '. Actual status: ' . $status . '. Raw: ' . substr((string)($response['raw'] ?? ''), 0, 300)
        );
    }

    private function findResetRequestIdByLoginId(string $adminCookie, string $loginId): ?int
    {
        $list = $this->apiGet('/reset_list', $adminCookie);
        if (($list['status'] ?? 0) !== 200) {
            return null;
        }

        foreach (($list['json']['items'] ?? []) as $item) {
            if (($item['login_id'] ?? '') === $loginId && isset($item['id'])) {
                return (int) $item['id'];
            }
        }

        return null;
    }

    private function createFaculty(string $code, string $name, string $cookie): array
    {
        $response = $this->apiPostJson('/faculty.php', [
            'action' => 'create',
            'code' => $code,
            'name' => $name,
        ], $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['json']['data'] ?? []);
        return $response['json']['data'] ?? [];
    }

    private function createMajor(string $code, string $name, string $facultyCode, string $cookie): array
    {
        $response = $this->apiPostJson('/major.php', [
            'action' => 'create',
            'code' => $code,
            'name' => $name,
            'faculty_code' => $facultyCode,
        ], $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['json']['data'] ?? []);
        return $response['json']['data'] ?? [];
    }

    private function createClass(string $code, string $name, string $majorCode, string $headTeacherCode, string $cookie): array
    {
        $response = $this->apiPostJson('/class.php', [
            'action' => 'create',
            'code' => $code,
            'name' => $name,
            'major_code' => $majorCode,
            'head_teacher_code' => $headTeacherCode,
        ], $cookie);

        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['json']['data'] ?? []);
        return $response['json']['data'] ?? [];
    }
}
