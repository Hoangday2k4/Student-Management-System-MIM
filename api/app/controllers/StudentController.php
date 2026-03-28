<?php
require_once __DIR__ . '/../models/Student.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../helpers/response.php';

class StudentController
{
    private function lowerText(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private function normalizeGender(string $gender): string
    {
        $raw = trim($gender);
        if ($raw === 'Nam' || $raw === 'Nữ') return $raw;
        $g = $this->lowerText($raw);
        if ($g === 'male' || $g === 'nam') return 'Nam';
        if ($g === 'female' || $g === 'nữ' || $g === 'nu') return 'Nữ';
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        $raw = trim($status);
        if ($raw === 'Đang học' || $raw === 'Đã tốt nghiệp' || $raw === 'Tạm dừng') return $raw;
        $s = $this->lowerText($raw);
        if ($s === 'studying' || $s === 'đang học' || $s === 'dang hoc') return 'Đang học';
        if ($s === 'graduated' || $s === 'đã tốt nghiệp' || $s === 'da tot nghiep') return 'Đã tốt nghiệp';
        if ($s === 'suspended' || $s === 'tạm dừng' || $s === 'tam dung') return 'Tạm dừng';
        return '';
    }

    private function formatStudentForResponse(array $student): array
    {
        $student['gender'] = $this->normalizeGender((string)($student['gender'] ?? '')) ?: ($student['gender'] ?? '');
        $student['status'] = $this->normalizeStatus((string)($student['status'] ?? '')) ?: ($student['status'] ?? '');
        $student['avatar_url'] = $this->resolveAvatarUrl($student['avatar'] ?? '');
        return $student;
    }

    private function currentIdentity(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $loginId = isset($_SESSION['login_id']) ? (string)$_SESSION['login_id'] : '';
        if ($loginId === '') {
            return null;
        }
        return Admin::findIdentityByLoginId($loginId);
    }

    private function isStaff(array $identity): bool
    {
        return ($identity['account_type'] ?? '') === 'staff';
    }

    private function isAdmin(array $identity): bool
    {
        return strtolower((string)($identity['login_id'] ?? '')) === 'admin';
    }

    private function isStudent(array $identity): bool
    {
        return ($identity['account_type'] ?? '') === 'student';
    }

    private function resolveAvatarUrl($avatar): string
    {
        if (!$avatar) return '';
        if (preg_match('/^https?:\/\//', $avatar)) return $avatar;
        if (strpos($avatar, '/api/') === 0) return $avatar;
        if (strpos($avatar, 'web/') === 0) return '/api/' . $avatar;
        return '/api/web/image/student_avatar/' . $avatar;
    }

    public function index()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        $loginId = (string)$identity['login_id'];

        if (!$this->isStaff($identity)) {
            if (!$this->isStudent($identity)) {
                jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
                return;
            }
            $student = Student::findByStudentCode($loginId);
            if (!$student) {
                jsonResponse([]);
                return;
            }
            jsonResponse([$this->formatStudentForResponse($student)]);
            return;
        }

        $statusFilter = isset($_GET['status']) ? $this->normalizeStatus((string)$_GET['status']) : '';
        $students = Student::search([
            'keyword' => isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '',
            'class_name' => isset($_GET['class_name']) ? trim((string)$_GET['class_name']) : '',
            'faculty' => isset($_GET['faculty']) ? trim((string)$_GET['faculty']) : '',
            'status' => $statusFilter,
        ]);
        foreach ($students as &$student) {
            $student = $this->formatStudentForResponse($student);
        }
        unset($student);

        jsonResponse($students);
    }

    public function me()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStudent($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $student = Student::findByStudentCode((string)$identity['login_id']);
        if (!$student) {
            jsonResponse(['status' => 'error', 'message' => 'Student not found'], 404);
            return;
        }
        jsonResponse(['status' => 'success', 'data' => $this->formatStudentForResponse($student)]);
    }

    public function create()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid payload.'], 400);
            return;
        }

        $studentCode = trim((string)($payload['student_code'] ?? ''));
        $fullName = trim((string)($payload['full_name'] ?? ''));
        $className = trim((string)($payload['class_name'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ''));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ''));

        $errors = [];
        if ($studentCode === '') $errors['student_code'] = 'Hay nhap ma so sinh vien.';
        if ($fullName === '') $errors['full_name'] = 'Hay nhap ho ten.';
        if ($className === '') $errors['class_name'] = 'Hay nhap lop.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email khong hop le.';
        if ($gender === '') $errors['gender'] = 'Gioi tinh khong hop le.';
        if ($status === '') $errors['status'] = 'Trang thai khong hop le.';

        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.', 'fields' => $errors], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            $pdo->exec('PRAGMA busy_timeout = 5000');
            Admin::ensureSchema($pdo);
            Student::ensureSchema($pdo);
            $pdo->beginTransaction();

            $studentId = Student::insertWithPdo($pdo, [
                'student_code' => $studentCode,
                'full_name' => $fullName,
                'date_of_birth' => trim((string)($payload['date_of_birth'] ?? '')),
                'gender' => $gender,
                'class_name' => $className,
                'faculty' => trim((string)($payload['faculty'] ?? '')),
                'email' => $email,
                'phone' => trim((string)($payload['phone'] ?? '')),
                'avatar' => '',
                'status' => $status,
            ]);
            Admin::createAccountWithPdo($pdo, $studentCode, '123456', $fullName, 'student');
            $pdo->commit();

            $student = Student::findById($studentId);
            $student = $this->formatStudentForResponse($student);

            jsonResponse([
                'status' => 'success',
                'data' => $student,
                'account' => ['login_id' => $studentCode, 'default_password' => '123456'],
            ], 201);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $message = (string)$e->getMessage();
            if (strpos($message, 'UNIQUE constraint failed: students.student_code') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Ma so sinh vien da ton tai.',
                    'fields' => ['student_code' => 'Ma so sinh vien da ton tai.'],
                ], 409);
                return;
            }
            if (strpos($message, 'UNIQUE constraint failed: admins.login_id') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Tai khoan dang nhap trung login id da ton tai.',
                    'fields' => ['student_code' => 'Login id da ton tai trong he thong.'],
                ], 409);
                return;
            }
            if (strpos($message, 'database is locked') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'He thong dang ban, vui long thu lai sau it giay.'], 503);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the luu sinh vien.', 'detail' => $message], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Loi he thong.', 'detail' => $e->getMessage()], 500);
        }
    }

    private function normalizeHeader(string $value): string
    {
        $text = trim($value);
        if ($text === '') return '';
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '', $text);
        return $text ?: '';
    }

    private function parseCsvRows(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'rb');
        if (!$handle) return $rows;
        while (($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($data as $cell) {
                $row[] = trim((string)$cell);
            }
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $value = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $value = $value * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $value - 1);
    }

    private function parseXlsxRows(string $filePath): array
    {
        $rows = [];
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Server chua ho tro ZipArchive de doc file xlsx.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Khong mo duoc file xlsx.');
        }

        $sharedStrings = [];
        $sharedXmlRaw = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXmlRaw !== false) {
            $sharedXml = @simplexml_load_string($sharedXmlRaw);
            if ($sharedXml && isset($sharedXml->si)) {
                foreach ($sharedXml->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $text .= (string)$run->t;
                        }
                    }
                    $sharedStrings[] = trim($text);
                }
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbookRelsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $workbookRaw = $zip->getFromName('xl/workbook.xml');
        if ($workbookRelsRaw !== false && $workbookRaw !== false) {
            $relsXml = @simplexml_load_string($workbookRelsRaw);
            $wbXml = @simplexml_load_string($workbookRaw);
            if ($relsXml && $wbXml) {
                $rels = [];
                foreach ($relsXml->Relationship as $rel) {
                    $id = (string)$rel['Id'];
                    $target = (string)$rel['Target'];
                    if ($id !== '' && $target !== '') {
                        $rels[$id] = ltrim(str_replace('\\', '/', $target), '/');
                    }
                }
                $sheet = $wbXml->sheets->sheet[0] ?? null;
                if ($sheet) {
                    $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    if ($rid !== '' && isset($rels[$rid])) {
                        $candidate = $rels[$rid];
                        if (strpos($candidate, 'xl/') !== 0) {
                            $candidate = 'xl/' . $candidate;
                        }
                        $sheetPath = $candidate;
                    }
                }
            }
        }

        $sheetRaw = $zip->getFromName($sheetPath);
        if ($sheetRaw === false) {
            $zip->close();
            throw new RuntimeException('Khong doc duoc du lieu worksheet.');
        }
        $sheetXml = @simplexml_load_string($sheetRaw);
        if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
            $zip->close();
            return $rows;
        }

        foreach ($sheetXml->sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $ref = (string)$cell['r'];
                preg_match('/[A-Z]+/i', $ref, $matches);
                $colLetters = $matches[0] ?? '';
                $colIndex = $this->columnLettersToIndex($colLetters);

                $type = (string)$cell['t'];
                $value = '';
                if ($type === 's') {
                    $idx = (int)($cell->v ?? 0);
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string)($cell->is->t ?? '');
                } else {
                    $value = (string)($cell->v ?? '');
                }
                $row[$colIndex] = trim($value);
            }

            if (!empty($row)) {
                ksort($row);
                $max = max(array_keys($row));
                $normalized = [];
                for ($i = 0; $i <= $max; $i++) {
                    $normalized[] = isset($row[$i]) ? (string)$row[$i] : '';
                }
                $rows[] = $normalized;
            }
        }

        $zip->close();
        return $rows;
    }

    private function mapImportRows(array $rawRows): array
    {
        if (count($rawRows) < 2) {
            return ['rows' => [], 'skipped' => []];
        }

        $headers = $rawRows[0];
        $headerMap = [];
        $headerAlias = [
            'student_code' => ['masosinhvien', 'masosinhvin', 'mssv', 'studentcode'],
            'full_name' => ['hoten', 'hten', 'tensinhvien', 'fullname'],
            'date_of_birth' => ['ngaysinh', 'dateofbirth', 'dob'],
            'gender' => ['gioitinh', 'gender'],
            'class_name' => ['lop', 'lp', 'classname'],
            'faculty' => ['khoa', 'vien', 'khoavien', 'faculty'],
            'email' => ['email'],
            'phone' => ['sodienthoai', 'sdt', 'phone'],
            'status' => ['trangthai', 'status'],
        ];

        foreach ($headers as $index => $label) {
            $normalized = $this->normalizeHeader((string)$label);
            if ($normalized === '') continue;
            foreach ($headerAlias as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && !isset($headerMap[$field])) {
                    $headerMap[$field] = (int)$index;
                    break;
                }
            }
        }

        // Fallback to default 9-column order when some headers are malformed.
        if (count($headers) >= 9) {
            $defaultMap = [
                'student_code' => 0,
                'full_name' => 1,
                'date_of_birth' => 2,
                'gender' => 3,
                'class_name' => 4,
                'faculty' => 5,
                'email' => 6,
                'phone' => 7,
                'status' => 8,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['student_code', 'full_name', 'class_name'] as $requiredField) {
            if (!isset($headerMap[$requiredField])) {
                throw new RuntimeException('File thieu cot bat buoc: ' . $requiredField);
            }
        }

        $rows = [];
        $skipped = [];
        $seenCodes = [];
        for ($i = 1; $i < count($rawRows); $i++) {
            $line = $i + 1;
            $source = $rawRows[$i];

            $row = [
                'student_code' => trim((string)($source[$headerMap['student_code']] ?? '')),
                'full_name' => trim((string)($source[$headerMap['full_name']] ?? '')),
                'date_of_birth' => trim((string)($source[$headerMap['date_of_birth'] ?? -1] ?? '')),
                'gender' => trim((string)($source[$headerMap['gender'] ?? -1] ?? 'Nam')),
                'class_name' => trim((string)($source[$headerMap['class_name']] ?? '')),
                'faculty' => trim((string)($source[$headerMap['faculty'] ?? -1] ?? '')),
                'email' => trim((string)($source[$headerMap['email'] ?? -1] ?? '')),
                'phone' => trim((string)($source[$headerMap['phone'] ?? -1] ?? '')),
                'status' => trim((string)($source[$headerMap['status'] ?? -1] ?? 'Dang hoc')),
            ];

            if ($row['student_code'] === '' && $row['full_name'] === '' && $row['class_name'] === '') {
                continue;
            }
            if ($row['student_code'] === '' || $row['full_name'] === '' || $row['class_name'] === '') {
                $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Thieu truong bat buoc'];
                continue;
            }
            if (isset($seenCodes[$row['student_code']])) {
                $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Trung trong file'];
                continue;
            }
            $seenCodes[$row['student_code']] = true;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function normalizeImportRow(array $row): array
    {
        $gender = $this->normalizeGender((string)($row['gender'] ?? ''));
        if ($gender === '') $gender = $this->normalizeGender('nam');
        $status = $this->normalizeStatus((string)($row['status'] ?? ''));
        if ($status === '') $status = $this->normalizeStatus('studying');

        return [
            'student_code' => trim((string)($row['student_code'] ?? '')),
            'full_name' => trim((string)($row['full_name'] ?? '')),
            'date_of_birth' => trim((string)($row['date_of_birth'] ?? '')),
            'gender' => $gender,
            'class_name' => trim((string)($row['class_name'] ?? '')),
            'faculty' => trim((string)($row['faculty'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
            'status' => $status,
        ];
    }

    public function importBulk()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $action = strtolower(trim((string)($_GET['action'] ?? 'save')));
        if ($action === 'preview') {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                jsonResponse(['status' => 'error', 'message' => 'Chua co file du lieu.'], 400);
                return;
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                jsonResponse(['status' => 'error', 'message' => 'Khong doc duoc file tai len.'], 400);
                return;
            }
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));

            try {
                if ($ext === 'csv') {
                    $rawRows = $this->parseCsvRows((string)$file['tmp_name']);
                } else {
                    $rawRows = $this->parseXlsxRows((string)$file['tmp_name']);
                }
                $mapped = $this->mapImportRows($rawRows);
                $previewRows = array_map(function ($row) {
                    return $this->normalizeImportRow($row);
                }, $mapped['rows']);
                jsonResponse([
                    'status' => 'success',
                    'rows' => $previewRows,
                    'skipped_in_file' => $mapped['skipped'],
                ]);
            } catch (Throwable $e) {
                jsonResponse(['status' => 'error', 'message' => 'Khong the doc file import.', 'detail' => $e->getMessage()], 422);
            }
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        if (empty($rows)) {
            jsonResponse(['status' => 'error', 'message' => 'Khong co du lieu de import.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            $pdo->exec('PRAGMA busy_timeout = 5000');
            Admin::ensureSchema($pdo);
            Student::ensureSchema($pdo);
            $pdo->beginTransaction();

            $inserted = 0;
            $skipped = [];
            $existingStudents = [];
            $existingLogins = [];

            $studentCodes = $pdo->query('SELECT student_code FROM students')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($studentCodes as $code) {
                $existingStudents[strtolower(trim((string)$code))] = true;
            }
            $loginIds = $pdo->query('SELECT login_id FROM admins')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($loginIds as $loginId) {
                $existingLogins[strtolower(trim((string)$loginId))] = true;
            }

            foreach ($rows as $idx => $raw) {
                $line = $idx + 2;
                $row = $this->normalizeImportRow(is_array($raw) ? $raw : []);
                $studentCodeKey = strtolower($row['student_code']);
                if ($row['student_code'] === '' || $row['full_name'] === '' || $row['class_name'] === '') {
                    $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Thieu truong bat buoc'];
                    continue;
                }
                if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Email khong hop le'];
                    continue;
                }
                if (isset($existingStudents[$studentCodeKey])) {
                    $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Da ton tai trong bang sinh vien'];
                    continue;
                }
                if (isset($existingLogins[$studentCodeKey])) {
                    $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Login da ton tai'];
                    continue;
                }

                Student::insertWithPdo($pdo, [
                    'student_code' => $row['student_code'],
                    'full_name' => $row['full_name'],
                    'date_of_birth' => $row['date_of_birth'],
                    'gender' => $row['gender'],
                    'class_name' => $row['class_name'],
                    'faculty' => $row['faculty'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'avatar' => '',
                    'status' => $row['status'],
                ]);
                Admin::createAccountWithPdo($pdo, $row['student_code'], '123456', $row['full_name'], 'student');
                $existingStudents[$studentCodeKey] = true;
                $existingLogins[$studentCodeKey] = true;
                $inserted++;
            }

            $pdo->commit();
            jsonResponse([
                'status' => 'success',
                'inserted_count' => $inserted,
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
                'default_password' => '123456',
            ]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $message = (string)$e->getMessage();
            if (strpos($message, 'database is locked') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'He thong dang ban, vui long thu lai sau it giay.'], 503);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the import sinh vien.', 'detail' => $message], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Loi he thong.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function updateMe()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStudent($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $loginId = (string)$identity['login_id'];
        $current = Student::findByStudentCode($loginId);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'Student not found'], 404);
            return;
        }

        $payload = $_POST;
        $fullName = trim((string)($payload['full_name'] ?? $current['full_name']));
        $className = trim((string)($payload['class_name'] ?? $current['class_name']));
        $email = trim((string)($payload['email'] ?? ($current['email'] ?? '')));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ($current['gender'] ?? '')));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ($current['status'] ?? '')));

        $errors = [];
        if ($fullName === '') $errors['full_name'] = 'Hay nhap ho ten.';
        if ($className === '') $errors['class_name'] = 'Hay nhap lop.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email khong hop le.';
        if ($gender === '') $errors['gender'] = 'Gioi tinh khong hop le.';
        if ($status === '') $errors['status'] = 'Trang thai khong hop le.';

        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.', 'fields' => $errors], 422);
            return;
        }

        $avatar = $current['avatar'] ?? '';
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'Tep avatar vuot gioi han upload_max_filesize.',
                    UPLOAD_ERR_FORM_SIZE => 'Tep avatar vuot gioi han cho phep cua form.',
                    UPLOAD_ERR_PARTIAL => 'Tep avatar tai len chua day du.',
                    UPLOAD_ERR_NO_TMP_DIR => 'May chu thieu thu muc tam.',
                    UPLOAD_ERR_CANT_WRITE => 'May chu khong ghi duoc tep avatar.',
                    UPLOAD_ERR_EXTENSION => 'Tep avatar bi chan boi phan mo rong PHP.',
                ];
                $msg = $uploadErrors[$_FILES['avatar']['error']] ?? 'Loi tai tep avatar.';
                jsonResponse(['status' => 'error', 'message' => $msg], 422);
                return;
            }

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/pjpeg' => 'jpg',
                'image/png' => 'png',
                'image/x-png' => 'png',
                'image/webp' => 'webp',
            ];
            $mime = false;
            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($_FILES['avatar']['tmp_name']);
            }
            if (!$mime && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
                    finfo_close($finfo);
                }
            }
            if (!$mime && function_exists('getimagesize')) {
                $imgInfo = getimagesize($_FILES['avatar']['tmp_name']);
                $mime = $imgInfo['mime'] ?? false;
            }
            if (!isset($allowed[$mime])) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Dinh dang avatar khong hop le.',
                    'detail' => $mime ? ('mime=' . $mime) : 'khong xac dinh duoc mime',
                ], 422);
                return;
            }
            $uploadDir = __DIR__ . '/../../web/image/student_avatar';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                jsonResponse(['status' => 'error', 'message' => 'Khong tao duoc thu muc luu avatar.'], 500);
                return;
            }
            $filename = 'student_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            $targetPath = $uploadDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                jsonResponse(['status' => 'error', 'message' => 'Khong the tai avatar len.'], 500);
                return;
            }
            $avatar = '/api/web/image/student_avatar/' . $filename;
        }

        $ok = Student::updateProfileByStudentCode($loginId, [
            'full_name' => $fullName,
            'date_of_birth' => trim((string)($payload['date_of_birth'] ?? ($current['date_of_birth'] ?? ''))),
            'gender' => $gender,
            'class_name' => $className,
            'faculty' => trim((string)($payload['faculty'] ?? ($current['faculty'] ?? ''))),
            'email' => $email,
            'phone' => trim((string)($payload['phone'] ?? ($current['phone'] ?? ''))),
            'avatar' => $avatar,
            'status' => $status,
        ]);

        if (!$ok) {
            jsonResponse(['status' => 'error', 'message' => 'Khong the cap nhat ho so.'], 500);
            return;
        }

        Admin::updateNameByLoginId($loginId, $fullName);
        $student = Student::findByStudentCode($loginId);
        jsonResponse(['status' => 'success', 'data' => $this->formatStudentForResponse($student)]);
    }

    public function adminDetail()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isAdmin($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $studentCode = trim((string)($_GET['student_code'] ?? ''));
        if ($studentCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã sinh viên.'], 422);
            return;
        }
        $student = Student::findByStudentCode($studentCode);
        if (!$student) {
            jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy sinh viên.'], 404);
            return;
        }
        jsonResponse(['status' => 'success', 'data' => $this->formatStudentForResponse($student)]);
    }

    public function adminUpdate()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isAdmin($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
            return;
        }

        $oldCode = trim((string)($payload['old_student_code'] ?? ''));
        $newCode = trim((string)($payload['student_code'] ?? ''));
        $fullName = trim((string)($payload['full_name'] ?? ''));
        $className = trim((string)($payload['class_name'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ''));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ''));
        $faculty = trim((string)($payload['faculty'] ?? ''));
        $dateOfBirth = trim((string)($payload['date_of_birth'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));

        $errors = [];
        if ($oldCode === '') $errors['old_student_code'] = 'Thiếu mã sinh viên gốc.';
        if ($newCode === '') $errors['student_code'] = 'Hãy nhập mã số sinh viên.';
        if ($fullName === '') $errors['full_name'] = 'Hãy nhập họ tên.';
        if ($className === '') $errors['class_name'] = 'Hãy nhập lớp.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email không hợp lệ.';
        if ($gender === '') $errors['gender'] = 'Giới tính không hợp lệ.';
        if ($status === '') $errors['status'] = 'Trạng thái không hợp lệ.';
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.', 'fields' => $errors], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Student::ensureSchema($pdo);
            Admin::ensureSchema($pdo);

            $stmt = $pdo->prepare('SELECT * FROM students WHERE student_code = :code LIMIT 1');
            $stmt->execute([':code' => $oldCode]);
            $current = $stmt->fetch();
            if (!$current) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy sinh viên.'], 404);
                return;
            }

            $pdo->beginTransaction();

            if (strtolower($newCode) !== strtolower($oldCode)) {
                $stmt = $pdo->prepare('SELECT 1 FROM students WHERE student_code = :code LIMIT 1');
                $stmt->execute([':code' => $newCode]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Mã số sinh viên đã tồn tại.', 'fields' => ['student_code' => 'Mã số sinh viên đã tồn tại.']], 409);
                    return;
                }
                $stmt = $pdo->prepare('SELECT 1 FROM admins WHERE lower(login_id)=lower(:login_id) AND lower(login_id)<>lower(:old) LIMIT 1');
                $stmt->execute([':login_id' => $newCode, ':old' => $oldCode]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Login ID đã tồn tại trong hệ thống.', 'fields' => ['student_code' => 'Login ID đã tồn tại trong hệ thống.']], 409);
                    return;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE students SET
                    student_code = :new_code,
                    full_name = :full_name,
                    date_of_birth = :date_of_birth,
                    gender = :gender,
                    class_name = :class_name,
                    faculty = :faculty,
                    email = :email,
                    phone = :phone,
                    status = :status
                 WHERE student_code = :old_code'
            );
            $stmt->execute([
                ':new_code' => $newCode,
                ':full_name' => $fullName,
                ':date_of_birth' => $dateOfBirth ?: null,
                ':gender' => $gender,
                ':class_name' => $className,
                ':faculty' => $faculty ?: null,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':status' => $status,
                ':old_code' => $oldCode,
            ]);

            if (strtolower($newCode) !== strtolower($oldCode)) {
                $stmt = $pdo->prepare('UPDATE course_enrollments SET student_code = :new_code WHERE student_code = :old_code');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
                $stmt = $pdo->prepare('UPDATE course_scores SET student_code = :new_code WHERE student_code = :old_code');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
                $stmt = $pdo->prepare('UPDATE admins SET login_id = :new_code WHERE lower(login_id)=lower(:old_code)');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
            }
            $stmt = $pdo->prepare('UPDATE admins SET name = :name WHERE lower(login_id)=lower(:login_id)');
            $stmt->execute([':name' => $fullName, ':login_id' => $newCode]);

            $pdo->commit();
            $student = Student::findByStudentCode($newCode);
            jsonResponse(['status' => 'success', 'data' => $this->formatStudentForResponse($student)]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Không thể cập nhật sinh viên.', 'detail' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Lỗi hệ thống.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function adminDelete()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isAdmin($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $studentCode = trim((string)($_GET['student_code'] ?? ''));
        if ($studentCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã sinh viên.'], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Student::ensureSchema($pdo);
            Admin::ensureSchema($pdo);
            $stmt = $pdo->prepare('SELECT 1 FROM students WHERE student_code = :code LIMIT 1');
            $stmt->execute([':code' => $studentCode]);
            if (!$stmt->fetch()) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy sinh viên.'], 404);
                return;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM course_scores WHERE student_code = :code');
            $stmt->execute([':code' => $studentCode]);
            $stmt = $pdo->prepare('DELETE FROM course_enrollments WHERE student_code = :code');
            $stmt->execute([':code' => $studentCode]);
            $stmt = $pdo->prepare('DELETE FROM students WHERE student_code = :code');
            $stmt->execute([':code' => $studentCode]);
            $stmt = $pdo->prepare('DELETE FROM admins WHERE lower(login_id)=lower(:code) AND account_type = "student"');
            $stmt->execute([':code' => $studentCode]);
            $pdo->commit();

            jsonResponse(['status' => 'success', 'message' => 'Đã xóa sinh viên và tài khoản liên quan.']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa sinh viên.', 'detail' => $e->getMessage()], 500);
        }
    }
}
