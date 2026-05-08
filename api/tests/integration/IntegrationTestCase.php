<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected string $baseUrl;
    private static bool $seeded = false;
    private static string $sessionSavePath = '';

    public static function setUpBeforeClass(): void
    {
        self::bootstrapDatabase();
    }

    protected function setUp(): void
    {
        $this->baseUrl = rtrim(getenv('INTEGRATION_BASE_URL') ?: 'http://127.0.0.1:8001', '/');
    }

    protected function loginAsStudent(): string
    {
        return $this->createSessionCookie('S001');
    }

    protected function loginAsTeacher(): string
    {
        return $this->createSessionCookie('T001');
    }

    protected function loginAsAdmin(): string
    {
        return $this->createSessionCookie('admin');
    }

    protected function createCourseAsAdmin(string $courseCode, string $courseName, string $schedule, string $classroom, string $cookie): array
    {
        $response = $this->apiPostJson('/course.php', [
            'course_code' => $courseCode,
            'course_name' => $courseName,
            'teacher_code' => 'T001',
            'credits' => '3',
            'schedule' => $schedule,
            'classroom' => $classroom,
            'max_students' => '30',
        ], $cookie);

        $this->assertSame(201, $response['status']);

        $data = $response['json']['data'] ?? null;
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['id']) && preg_match('/"id"\s*:\s*(\d+)/', $response['raw'], $matches)) {
            $data['id'] = (int) $matches[1];
        }
        if (!isset($data['course_code']) && preg_match('/"course_code"\s*:\s*"([^"]+)"/', $response['raw'], $matches)) {
            $data['course_code'] = $matches[1];
        }

        return $data;
    }

    protected function enrollStudentInCourse(int $courseId, string $studentCode): void
    {
        $apiRoot = dirname(__DIR__, 2);
        require_once $apiRoot . '/app/common/define.php';
        require_once $apiRoot . '/app/common/db.php';
        require_once $apiRoot . '/app/models/Course.php';

        $pdo = get_db_connection();
        Course::appendEnrollmentsWithPdo($pdo, $courseId, [$studentCode]);
    }

    protected function seedStudentForDeletion(string $studentCode): void
    {
        $apiRoot = dirname(__DIR__, 2);
        require_once $apiRoot . '/app/common/define.php';
        require_once $apiRoot . '/app/common/db.php';
        require_once $apiRoot . '/app/models/Admin.php';
        require_once $apiRoot . '/app/models/Student.php';

        $pdo = get_db_connection();
        Admin::ensureSchema($pdo);
        Student::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT 1 FROM SinhVien WHERE MaSV = :code LIMIT 1');
        $stmt->execute([':code' => $studentCode]);
        if (!$stmt->fetchColumn()) {
            Student::insertWithPdo($pdo, [
                'student_code' => $studentCode,
                'full_name' => 'Deletable Student',
                'date_of_birth' => '',
                'class_name' => 'CTK42',
                'gender' => 'Nam',
                'status' => 'Dang hoc',
                'email' => $studentCode . '@example.test',
                'phone' => '',
                'cccd' => '',
                'address' => '',
                'admission_date' => '',
                'faculty' => '',
                'avatar' => '',
            ]);
        }

        $pdo->prepare(
            'INSERT OR IGNORE INTO TaiKhoan (LoginId, MatKhau, HoTen, LoaiTaiKhoan, TrangThai, Created)
             VALUES (:login_id, :password, :name, "student", 1, CURRENT_TIMESTAMP)'
        )->execute([
            ':login_id' => $studentCode,
            ':password' => '123456',
            ':name' => 'Deletable Student',
        ]);
    }

    protected function studentListContains(array $students, string $studentCode): bool
    {
        foreach ($students as $student) {
            if (($student['student_code'] ?? '') === $studentCode) {
                return true;
            }
        }
        return false;
    }

    protected function scoreListContainsCourse($items, string $courseCode): bool
    {
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $row) {
            if (($row['course_code'] ?? '') === $courseCode) {
                return true;
            }
        }
        return false;
    }

    protected function apiGet(string $path, string $cookie = ''): array
    {
        return $this->apiRequest('GET', $path, [], $cookie);
    }

    protected function apiPostJson(string $path, array $payload, string $cookie = ''): array
    {
        $body = json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];
        return $this->apiRequest('POST', $path, $headers, $cookie, $body === false ? '' : $body);
    }

    protected function uniqueStudentCode(): string
    {
        return 'S' . bin2hex(random_bytes(3));
    }

    protected function uniqueCode(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(3));
    }

    protected function apiRequest(string $method, string $path, array $headers = [], string $cookie = '', string $body = ''): array
    {
        $url = $this->baseUrl . $path;
        if ($cookie !== '') {
            $headers['Cookie'] = $cookie;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $raw = file_get_contents($url, false, $context);
        $raw = $raw === false ? '' : $raw;

        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 0';
        $status = 0;
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            $status = (int) $matches[1];
        }

        $payload = $raw;
        $startObject = strpos($payload, '{');
        $startArray = strpos($payload, '[');
        if ($startObject !== false || $startArray !== false) {
            $start = $startObject === false ? $startArray : ($startArray === false ? $startObject : min($startObject, $startArray));
            $payload = substr($payload, $start);
        }

        $json = json_decode($payload, true);

        return [
            'status' => $status,
            'json' => is_array($json) ? $json : [],
            'raw' => $raw,
        ];
    }

    private static function bootstrapDatabase(): void
    {
        if (self::$seeded) {
            return;
        }

        $apiRoot = dirname(__DIR__, 2);
        require_once $apiRoot . '/app/common/define.php';
        require_once $apiRoot . '/app/common/db.php';
        require_once $apiRoot . '/app/models/Admin.php';
        require_once $apiRoot . '/app/models/Student.php';
        require_once $apiRoot . '/app/models/Teacher.php';
        require_once $apiRoot . '/app/models/Course.php';
        require_once $apiRoot . '/app/models/Major.php';

        $pdo = get_db_connection();
        Admin::ensureSchema($pdo);
        Student::ensureSchema($pdo);
        Teacher::ensureSchema($pdo);
        Course::ensureSchema($pdo);
        Major::ensureSchema($pdo);

        if (!Admin::findByLoginId('T001')) {
            Admin::createAccountWithPdo($pdo, 'T001', '123456', 'Test Teacher', 'teacher');
        }
        if (!Teacher::findByTeacherCode('T001')) {
            Teacher::insertWithPdo($pdo, [
                'teacher_code' => 'T001',
                'full_name' => 'Test Teacher',
                'date_of_birth' => '',
                'email' => 't001@example.test',
                'phone' => '',
                'gender' => 'Nam',
                'homeroom_class' => '',
                'status' => 'Dang cong tac',
                'avatar' => '',
            ]);
        }

        if (!Admin::findByLoginId('S001')) {
            Admin::createAccountWithPdo($pdo, 'S001', '123456', 'Test Student', 'student');
        }
        if (!Student::findByStudentCode('S001')) {
            Student::insertWithPdo($pdo, [
                'student_code' => 'S001',
                'full_name' => 'Test Student',
                'date_of_birth' => '',
                'class_name' => 'CTK42',
                'gender' => 'Nam',
                'status' => 'Dang hoc',
                'email' => 's001@example.test',
                'phone' => '',
                'cccd' => '',
                'address' => '',
                'admission_date' => '',
                'faculty' => '',
                'avatar' => '',
            ]);
        }

        self::$sessionSavePath = getenv('SESSION_SAVE_PATH') ?: sys_get_temp_dir();
        if (!is_dir(self::$sessionSavePath)) {
            mkdir(self::$sessionSavePath, 0777, true);
        }

        self::$seeded = true;
    }

    private function createSessionCookie(string $loginId): string
    {
        $savePath = self::$sessionSavePath ?: sys_get_temp_dir();
        ini_set('session.save_path', $savePath);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $sessionId = bin2hex(random_bytes(16));
        session_id($sessionId);
        session_start();
        $_SESSION['login_id'] = $loginId;
        $_SESSION['login_time'] = date('Y-m-d H:i');
        session_write_close();

        return 'PHPSESSID=' . $sessionId;
    }
}
