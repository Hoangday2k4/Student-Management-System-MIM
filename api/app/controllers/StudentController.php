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
        if ($raw === '') return '';

        $g = $this->lowerText($raw);
        if ($g === 'male' || $g === 'nam') return 'Nam';
        if ($g === 'female' || $g === 'nu' || $g === 'nữ') return 'Nữ';

        // Accept mojibake values from old data/files.
        if ($g === 'nă¡â»â¯') return 'Nữ';
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        $raw = trim($status);
        if ($raw === '') return '';

        $s = $this->lowerText($raw);
        if ($s === 'studying' || $s === 'dang hoc' || $s === 'đang học' || $s === 'ä‘ang há»c') return 'Đang học';
        if ($s === 'graduated' || $s === 'da tot nghiep' || $s === 'đã tốt nghiệp' || $s === 'ä‘ä‚â£ tă¡â»â€˜t nghiă¡â»â€¡p') return 'Đã tốt nghiệp';
        if ($s === 'suspended' || $s === 'tam dung' || $s === 'tạm dừng' || $s === 'tă¡âºâ¡m dă¡â»â«ng') return 'Tạm dừng';
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
        $cccd = trim((string)($payload['cccd'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $admissionDate = trim((string)($payload['admission_date'] ?? ''));
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
                'cccd' => $cccd,
                'address' => $address,
                'admission_date' => $admissionDate,
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
            if (strpos($message, 'UNIQUE constraint failed: SinhVien.MaSV') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Ma so sinh vien da ton tai.',
                    'fields' => ['student_code' => 'Ma so sinh vien da ton tai.'],
                ], 409);
                return;
            }
            if (strpos($message, 'UNIQUE constraint failed: SinhVien.CCCD') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'CCCD da ton tai.',
                    'fields' => ['cccd' => 'CCCD da ton tai.'],
                ], 409);
                return;
            }
            if (strpos($message, 'UNIQUE constraint failed: TaiKhoan.LoginId') !== false) {
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
            'cccd' => ['cccd', 'cancuoc', 'cancuoccongdan'],
            'date_of_birth' => ['ngaysinh', 'dateofbirth', 'dob'],
            'gender' => ['gioitinh', 'gender'],
            'address' => ['diachi', 'address'],
            'class_name' => ['lop', 'lp', 'classname'],
            'admission_date' => ['ngaynhaphoc', 'admissiondate', 'ngayvaotruong'],
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

        // Fallback to default 11-column order when some headers are malformed.
        if (count($headers) >= 11) {
            $defaultMap = [
                'student_code' => 0,
                'full_name' => 1,
                'cccd' => 2,
                'date_of_birth' => 3,
                'gender' => 4,
                'address' => 5,
                'phone' => 6,
                'email' => 7,
                'class_name' => 8,
                'admission_date' => 9,
                'status' => 10,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        } elseif (count($headers) >= 9) {
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
                'cccd' => trim((string)($source[$headerMap['cccd'] ?? -1] ?? '')),
                'date_of_birth' => trim((string)($source[$headerMap['date_of_birth'] ?? -1] ?? '')),
                'gender' => trim((string)($source[$headerMap['gender'] ?? -1] ?? 'Nam')),
                'address' => trim((string)($source[$headerMap['address'] ?? -1] ?? '')),
                'class_name' => trim((string)($source[$headerMap['class_name']] ?? '')),
                'admission_date' => trim((string)($source[$headerMap['admission_date'] ?? -1] ?? '')),
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
            'cccd' => trim((string)($row['cccd'] ?? '')),
            'date_of_birth' => trim((string)($row['date_of_birth'] ?? '')),
            'gender' => $gender,
            'address' => trim((string)($row['address'] ?? '')),
            'class_name' => trim((string)($row['class_name'] ?? '')),
            'admission_date' => trim((string)($row['admission_date'] ?? '')),
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

                // Preview rule: only rows without format errors and MSSV not existing in SinhVien.
                $pdo = get_db_connection();
                Student::ensureSchema($pdo);
                $existingStudents = [];
                $studentCodes = $pdo->query('SELECT MaSV FROM SinhVien')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($studentCodes as $code) {
                    $existingStudents[strtolower(trim((string)$code))] = true;
                }

                $previewRows = [];
                $previewSkipped = is_array($mapped['skipped']) ? $mapped['skipped'] : [];
                foreach ($mapped['rows'] as $idx => $rawRow) {
                    $line = $idx + 2;
                    $row = $this->normalizeImportRow(is_array($rawRow) ? $rawRow : []);
                    $studentCode = trim((string)($row['student_code'] ?? ''));
                    $studentCodeKey = strtolower($studentCode);

                    if ($studentCode === '' || trim((string)($row['full_name'] ?? '')) === '' || trim((string)($row['class_name'] ?? '')) === '') {
                        $previewSkipped[] = ['line' => $line, 'student_code' => $studentCode, 'reason' => 'Thieu truong bat buoc'];
                        continue;
                    }
                    if ((string)($row['email'] ?? '') !== '' && !filter_var((string)$row['email'], FILTER_VALIDATE_EMAIL)) {
                        $previewSkipped[] = ['line' => $line, 'student_code' => $studentCode, 'reason' => 'Email khong hop le'];
                        continue;
                    }
                    if (isset($existingStudents[$studentCodeKey])) {
                        $previewSkipped[] = ['line' => $line, 'student_code' => $studentCode, 'reason' => 'Da ton tai trong bang sinh vien'];
                        continue;
                    }

                    $previewRows[] = $row;
                }

                jsonResponse([
                    'status' => 'success',
                    'rows' => $previewRows,
                    'skipped_in_file' => $previewSkipped,
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

            $studentCodes = $pdo->query('SELECT MaSV FROM SinhVien')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($studentCodes as $code) {
                $existingStudents[strtolower(trim((string)$code))] = true;
            }
            $loginIds = $pdo->query('SELECT LoginId FROM TaiKhoan')->fetchAll(PDO::FETCH_COLUMN);
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
                $savepoint = 'sp_student_import_' . $idx;
                $pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    Student::insertWithPdo($pdo, [
                        'student_code' => $row['student_code'],
                        'full_name' => $row['full_name'],
                        'date_of_birth' => $row['date_of_birth'],
                        'gender' => $row['gender'],
                        'class_name' => $row['class_name'],
                        'cccd' => $row['cccd'],
                        'address' => $row['address'],
                        'admission_date' => $row['admission_date'],
                        'faculty' => $row['faculty'],
                        'email' => $row['email'],
                        'phone' => $row['phone'],
                        'avatar' => '',
                        'status' => $row['status'],
                    ]);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $existingStudents[$studentCodeKey] = true;
                    // Try creating account; if LoginId already exists, keep student and continue.
                    try {
                        if (!isset($existingLogins[$studentCodeKey])) {
                            Admin::createAccountWithPdo($pdo, $row['student_code'], '123456', $row['full_name'], 'student');
                            $existingLogins[$studentCodeKey] = true;
                        }
                    } catch (PDOException $accError) {
                        $accMsg = (string)$accError->getMessage();
                        if (strpos($accMsg, 'UNIQUE constraint failed: TaiKhoan.LoginId') !== false) {
                            $existingLogins[$studentCodeKey] = true;
                        } else {
                            throw $accError;
                        }
                    }
                    $inserted++;
                } catch (PDOException $rowError) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $msg = (string)$rowError->getMessage();
                    if (strpos($msg, 'UNIQUE constraint failed: SinhVien.MaSV') !== false) {
                        $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'Da ton tai trong bang sinh vien'];
                        $existingStudents[$studentCodeKey] = true;
                        continue;
                    }
                    if (strpos($msg, 'UNIQUE constraint failed: SinhVien.CCCD') !== false) {
                        $skipped[] = ['line' => $line, 'student_code' => $row['student_code'], 'reason' => 'CCCD da ton tai'];
                        continue;
                    }
                    throw $rowError;
                }
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
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ sinh viÄ‚Âªn.'], 422);
            return;
        }
        $student = Student::findByStudentCode($studentCode);
        if (!$student) {
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y sinh viÄ‚Âªn.'], 404);
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
            jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.'], 400);
            return;
        }

        $oldCode = trim((string)($payload['old_student_code'] ?? ''));
        $newCode = trim((string)($payload['student_code'] ?? ''));
        $fullName = trim((string)($payload['full_name'] ?? ''));
        $className = trim((string)($payload['class_name'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ''));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ''));
        $dateOfBirth = trim((string)($payload['date_of_birth'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $cccd = trim((string)($payload['cccd'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $admissionDate = trim((string)($payload['admission_date'] ?? ''));

        $errors = [];
        if ($oldCode === '') $errors['old_student_code'] = 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ sinh viÄ‚Âªn gĂ¡Â»â€˜c.';
        if ($newCode === '') $errors['student_code'] = 'HÄ‚Â£y nhĂ¡ÂºÂ­p mÄ‚Â£ sĂ¡Â»â€˜ sinh viÄ‚Âªn.';
        if ($fullName === '') $errors['full_name'] = 'HÄ‚Â£y nhĂ¡ÂºÂ­p hĂ¡Â»Â tÄ‚Âªn.';
        if ($className === '') $errors['class_name'] = 'HÄ‚Â£y nhĂ¡ÂºÂ­p lĂ¡Â»â€ºp.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.';
        if ($gender === '') $errors['gender'] = 'GiĂ¡Â»â€ºi tÄ‚Â­nh khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.';
        if ($status === '') $errors['status'] = 'TrĂ¡ÂºÂ¡ng thÄ‚Â¡i khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.';
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.', 'fields' => $errors], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Student::ensureSchema($pdo);
            Admin::ensureSchema($pdo);

            $stmt = $pdo->prepare('SELECT * FROM SinhVien WHERE MaSV = :code LIMIT 1');
            $stmt->execute([':code' => $oldCode]);
            $current = $stmt->fetch();
            if (!$current) {
                jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y sinh viÄ‚Âªn.'], 404);
                return;
            }

            $pdo->beginTransaction();

            if (strtolower($newCode) !== strtolower($oldCode)) {
                $stmt = $pdo->prepare('SELECT 1 FROM SinhVien WHERE MaSV = :code LIMIT 1');
                $stmt->execute([':code' => $newCode]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'MÄ‚Â£ sĂ¡Â»â€˜ sinh viÄ‚Âªn Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.', 'fields' => ['student_code' => 'MÄ‚Â£ sĂ¡Â»â€˜ sinh viÄ‚Âªn Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.']], 409);
                    return;
                }
                $stmt = $pdo->prepare('SELECT 1 FROM TaiKhoan WHERE lower(LoginId)=lower(:login_id) AND lower(LoginId)<>lower(:old) LIMIT 1');
                $stmt->execute([':login_id' => $newCode, ':old' => $oldCode]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Login ID Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i trong hĂ¡Â»â€¡ thĂ¡Â»â€˜ng.', 'fields' => ['student_code' => 'Login ID Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i trong hĂ¡Â»â€¡ thĂ¡Â»â€˜ng.']], 409);
                    return;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE SinhVien SET
                    MaSV = :new_code,
                    HoTen = :full_name,
                    NgaySinh = :date_of_birth,
                    GioiTinh = :gender,
                    CCCD = :cccd,
                    DiaChi = :address,
                    MaLop = :class_name,
                    NgayNhapHoc = :admission_date,
                    Email = :email,
                    SoDienThoai = :phone,
                    TrangThai = :status
                 WHERE MaSV = :old_code'
            );
            $stmt->execute([
                ':new_code' => $newCode,
                ':full_name' => $fullName,
                ':date_of_birth' => $dateOfBirth ?: null,
                ':gender' => $gender,
                ':cccd' => $cccd ?: null,
                ':address' => $address ?: null,
                ':class_name' => $className,
                ':admission_date' => $admissionDate ?: null,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':status' => $status,
                ':old_code' => $oldCode,
            ]);

            if (strtolower($newCode) !== strtolower($oldCode)) {
                $stmt = $pdo->prepare('UPDATE KetQuaHocTap SET MaSV = :new_code WHERE MaSV = :old_code');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
                $stmt = $pdo->prepare('UPDATE TaiKhoan SET LoginId = :new_code WHERE lower(LoginId)=lower(:old_code)');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
            }
            $stmt = $pdo->prepare('UPDATE TaiKhoan SET HoTen = :name WHERE lower(LoginId)=lower(:login_id)');
            $stmt->execute([':name' => $fullName, ':login_id' => $newCode]);

            $pdo->commit();
            $student = Student::findByStudentCode($newCode);
            jsonResponse(['status' => 'success', 'data' => $this->formatStudentForResponse($student)]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng thĂ¡Â»Æ’ cĂ¡ÂºÂ­p nhĂ¡ÂºÂ­t sinh viÄ‚Âªn.', 'detail' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'LĂ¡Â»â€”i hĂ¡Â»â€¡ thĂ¡Â»â€˜ng.', 'detail' => $e->getMessage()], 500);
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
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ sinh viÄ‚Âªn.'], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Student::ensureSchema($pdo);
            Admin::ensureSchema($pdo);
            $stmt = $pdo->prepare('SELECT 1 FROM SinhVien WHERE MaSV = :code LIMIT 1');
            $stmt->execute([':code' => $studentCode]);
            if (!$stmt->fetch()) {
                jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y sinh viÄ‚Âªn.'], 404);
                return;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM KetQuaHocTap WHERE MaSV = :code');
            $stmt->execute([':code' => $studentCode]);
            $stmt = $pdo->prepare('DELETE FROM SinhVien WHERE MaSV = :code');
            $stmt->execute([':code' => $studentCode]);
            $stmt = $pdo->prepare('DELETE FROM TaiKhoan WHERE lower(LoginId)=lower(:code) AND LoaiTaiKhoan = "student"');
            $stmt->execute([':code' => $studentCode]);
            $pdo->commit();

            jsonResponse(['status' => 'success', 'message' => 'Ă„ÂÄ‚Â£ xÄ‚Â³a sinh viÄ‚Âªn vÄ‚Â  tÄ‚Â i khoĂ¡ÂºÂ£n liÄ‚Âªn quan.']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng thĂ¡Â»Æ’ xÄ‚Â³a sinh viÄ‚Âªn.', 'detail' => $e->getMessage()], 500);
        }
    }
}


