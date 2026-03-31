<?php
require_once __DIR__ . '/../models/Teacher.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../helpers/response.php';

class TeacherController
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
        if ($raw === 'Đang công tác' || $raw === 'Tạm nghỉ' || $raw === 'Đã nghỉ') return $raw;
        $s = $this->lowerText($raw);
        if ($s === 'working' || $s === 'đang công tác' || $s === 'dang cong tac') return 'Đang công tác';
        if ($s === 'on_leave' || $s === 'tạm nghỉ' || $s === 'tam nghi') return 'Tạm nghỉ';
        if ($s === 'retired' || $s === 'đã nghỉ' || $s === 'da nghi') return 'Đã nghỉ';
        return '';
    }

    private function formatTeacherForResponse(array $teacher): array
    {
        $teacher['gender'] = $this->normalizeGender((string)($teacher['gender'] ?? '')) ?: ($teacher['gender'] ?? '');
        $teacher['status'] = $this->normalizeStatus((string)($teacher['status'] ?? '')) ?: ($teacher['status'] ?? '');
        $teacher['avatar_url'] = $this->resolveAvatarUrl($teacher['avatar'] ?? '');
        return $teacher;
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

    private function isTeacher(array $identity): bool
    {
        return ($identity['account_type'] ?? '') === 'teacher';
    }

    private function resolveAvatarUrl($avatar): string
    {
        if (!$avatar) return '';
        if (preg_match('/^https?:\/\//', $avatar)) return $avatar;
        if (strpos($avatar, '/api/') === 0) return $avatar;
        if (strpos($avatar, 'web/') === 0) return '/api/' . $avatar;
        return '/api/web/image/teacher_avatar/' . $avatar;
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
            if (!$this->isTeacher($identity)) {
                jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
                return;
            }
            $teacher = Teacher::findByTeacherCode($loginId);
            if (!$teacher) {
                jsonResponse([]);
                return;
            }
            jsonResponse([$this->formatTeacherForResponse($teacher)]);
            return;
        }

        $statusFilter = isset($_GET['status']) ? $this->normalizeStatus((string)$_GET['status']) : '';
        $teachers = Teacher::search([
            'keyword' => isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '',
            'department' => isset($_GET['department']) ? trim((string)$_GET['department']) : '',
            'status' => $statusFilter,
        ]);
        foreach ($teachers as &$teacher) {
            $teacher = $this->formatTeacherForResponse($teacher);
        }
        unset($teacher);

        jsonResponse($teachers);
    }

    public function me()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isTeacher($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $teacher = Teacher::findByTeacherCode((string)$identity['login_id']);
        if (!$teacher) {
            jsonResponse(['status' => 'error', 'message' => 'Teacher not found'], 404);
            return;
        }
        jsonResponse(['status' => 'success', 'data' => $this->formatTeacherForResponse($teacher)]);
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

        $teacherCode = trim((string)($payload['teacher_code'] ?? ''));
        $fullName = trim((string)($payload['full_name'] ?? ''));
        $department = trim((string)($payload['department'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ''));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ''));

        $errors = [];
        if ($teacherCode === '') $errors['teacher_code'] = 'Hay nhap ma giao vien.';
        if ($fullName === '') $errors['full_name'] = 'Hay nhap ho ten.';
        if ($department === '') $errors['department'] = 'Hay nhap khoa/bo mon.';
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
            Teacher::ensureSchema($pdo);
            $pdo->beginTransaction();

            $teacherId = Teacher::insertWithPdo($pdo, [
                'teacher_code' => $teacherCode,
                'full_name' => $fullName,
                'date_of_birth' => trim((string)($payload['date_of_birth'] ?? '')),
                'gender' => $gender,
                'department' => $department,
                'homeroom_class' => trim((string)($payload['homeroom_class'] ?? '')),
                'email' => $email,
                'phone' => trim((string)($payload['phone'] ?? '')),
                'avatar' => '',
                'status' => $status,
            ]);
            Admin::createAccountWithPdo($pdo, $teacherCode, '123456', $fullName, 'teacher');
            $pdo->commit();

            $teacher = Teacher::findById($teacherId);
            $teacher = $this->formatTeacherForResponse($teacher);

            jsonResponse([
                'status' => 'success',
                'data' => $teacher,
                'account' => ['login_id' => $teacherCode, 'default_password' => '123456'],
            ], 201);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $message = (string)$e->getMessage();
            if (strpos($message, 'UNIQUE constraint failed: GiangVien.MaGV') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Ma giao vien da ton tai.',
                    'fields' => ['teacher_code' => 'Ma giao vien da ton tai.'],
                ], 409);
                return;
            }
            if (strpos($message, 'UNIQUE constraint failed: TaiKhoan.LoginId') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Tai khoan dang nhap trung login id da ton tai.',
                    'fields' => ['teacher_code' => 'Login id da ton tai trong he thong.'],
                ], 409);
                return;
            }
            if (strpos($message, 'database is locked') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'He thong dang ban, vui long thu lai sau it giay.'], 503);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the luu giao vien.', 'detail' => $message], 500);
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
            'teacher_code' => ['msgv', 'magiaovien', 'mgv', 'teachercode'],
            'full_name' => ['hoten', 'hten', 'fullname', 'tengiaovien'],
            'date_of_birth' => ['ngaysinh', 'dateofbirth', 'dob'],
            'gender' => ['gioitinh', 'gender'],
            'department' => ['khoa', 'vien', 'khoavien', 'bomon', 'khoabomon', 'department'],
            'email' => ['email'],
            'homeroom_class' => ['lopphutrach', 'lopchunhiem', 'homeroomclass'],
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

        if (count($headers) >= 9) {
            $defaultMap = [
                'teacher_code' => 0,
                'full_name' => 1,
                'date_of_birth' => 2,
                'gender' => 3,
                'department' => 4,
                'email' => 5,
                'homeroom_class' => 6,
                'phone' => 7,
                'status' => 8,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['teacher_code', 'full_name', 'department'] as $requiredField) {
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
                'teacher_code' => trim((string)($source[$headerMap['teacher_code']] ?? '')),
                'full_name' => trim((string)($source[$headerMap['full_name']] ?? '')),
                'date_of_birth' => trim((string)($source[$headerMap['date_of_birth'] ?? -1] ?? '')),
                'gender' => trim((string)($source[$headerMap['gender'] ?? -1] ?? 'Nam')),
                'department' => trim((string)($source[$headerMap['department']] ?? '')),
                'email' => trim((string)($source[$headerMap['email'] ?? -1] ?? '')),
                'homeroom_class' => trim((string)($source[$headerMap['homeroom_class'] ?? -1] ?? '')),
                'phone' => trim((string)($source[$headerMap['phone'] ?? -1] ?? '')),
                'status' => trim((string)($source[$headerMap['status'] ?? -1] ?? 'Dang cong tac')),
            ];

            if ($row['teacher_code'] === '' && $row['full_name'] === '' && $row['department'] === '') {
                continue;
            }
            if ($row['teacher_code'] === '' || $row['full_name'] === '' || $row['department'] === '') {
                $skipped[] = ['line' => $line, 'teacher_code' => $row['teacher_code'], 'reason' => 'Thieu truong bat buoc'];
                continue;
            }
            if (isset($seenCodes[$row['teacher_code']])) {
                $skipped[] = ['line' => $line, 'teacher_code' => $row['teacher_code'], 'reason' => 'Trung trong file'];
                continue;
            }
            $seenCodes[$row['teacher_code']] = true;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function normalizeImportRow(array $row): array
    {
        $gender = $this->normalizeGender((string)($row['gender'] ?? ''));
        if ($gender === '') $gender = $this->normalizeGender('nam');
        $status = $this->normalizeStatus((string)($row['status'] ?? ''));
        if ($status === '') $status = $this->normalizeStatus('working');

        return [
            'teacher_code' => trim((string)($row['teacher_code'] ?? '')),
            'full_name' => trim((string)($row['full_name'] ?? '')),
            'date_of_birth' => trim((string)($row['date_of_birth'] ?? '')),
            'gender' => $gender,
            'department' => trim((string)($row['department'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'homeroom_class' => trim((string)($row['homeroom_class'] ?? '')),
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
            Teacher::ensureSchema($pdo);
            $pdo->beginTransaction();

            $inserted = 0;
            $skipped = [];
            $existingTeachers = [];
            $existingLogins = [];

            $teacherCodes = $pdo->query('SELECT MaGV FROM GiangVien')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($teacherCodes as $code) {
                $existingTeachers[strtolower(trim((string)$code))] = true;
            }
            $loginIds = $pdo->query('SELECT LoginId FROM TaiKhoan')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($loginIds as $loginId) {
                $existingLogins[strtolower(trim((string)$loginId))] = true;
            }

            foreach ($rows as $idx => $raw) {
                $line = $idx + 2;
                $row = $this->normalizeImportRow(is_array($raw) ? $raw : []);
                $teacherCodeKey = strtolower($row['teacher_code']);

                if ($row['teacher_code'] === '' || $row['full_name'] === '' || $row['department'] === '') {
                    $skipped[] = ['line' => $line, 'teacher_code' => $row['teacher_code'], 'reason' => 'Thieu truong bat buoc'];
                    continue;
                }
                if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $skipped[] = ['line' => $line, 'teacher_code' => $row['teacher_code'], 'reason' => 'Email khong hop le'];
                    continue;
                }
                if (isset($existingTeachers[$teacherCodeKey])) {
                    $skipped[] = ['line' => $line, 'teacher_code' => $row['teacher_code'], 'reason' => 'Da ton tai trong bang giao vien'];
                    continue;
                }
                if (isset($existingLogins[$teacherCodeKey])) {
                    $skipped[] = ['line' => $line, 'teacher_code' => $row['teacher_code'], 'reason' => 'Login da ton tai'];
                    continue;
                }

                Teacher::insertWithPdo($pdo, [
                    'teacher_code' => $row['teacher_code'],
                    'full_name' => $row['full_name'],
                    'date_of_birth' => $row['date_of_birth'],
                    'gender' => $row['gender'],
                    'department' => $row['department'],
                    'homeroom_class' => $row['homeroom_class'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'avatar' => '',
                    'status' => $row['status'],
                ]);
                Admin::createAccountWithPdo($pdo, $row['teacher_code'], '123456', $row['full_name'], 'teacher');
                $existingTeachers[$teacherCodeKey] = true;
                $existingLogins[$teacherCodeKey] = true;
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
            jsonResponse(['status' => 'error', 'message' => 'Khong the import giao vien.', 'detail' => $message], 500);
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
        if (!$this->isTeacher($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $loginId = (string)$identity['login_id'];
        $current = Teacher::findByTeacherCode($loginId);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'Teacher not found'], 404);
            return;
        }

        $payload = $_POST;
        $fullName = trim((string)($payload['full_name'] ?? $current['full_name']));
        $department = trim((string)($payload['department'] ?? $current['department']));
        $email = trim((string)($payload['email'] ?? ($current['email'] ?? '')));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ($current['gender'] ?? '')));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ($current['status'] ?? '')));

        $errors = [];
        if ($fullName === '') $errors['full_name'] = 'Hay nhap ho ten.';
        if ($department === '') $errors['department'] = 'Hay nhap khoa/bo mon.';
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
            $uploadDir = __DIR__ . '/../../web/image/teacher_avatar';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                jsonResponse(['status' => 'error', 'message' => 'Khong tao duoc thu muc luu avatar.'], 500);
                return;
            }
            $filename = 'teacher_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            $targetPath = $uploadDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                jsonResponse(['status' => 'error', 'message' => 'Khong the tai avatar len.'], 500);
                return;
            }
            $avatar = '/api/web/image/teacher_avatar/' . $filename;
        }

        $ok = Teacher::updateProfileByTeacherCode($loginId, [
            'full_name' => $fullName,
            'date_of_birth' => trim((string)($payload['date_of_birth'] ?? ($current['date_of_birth'] ?? ''))),
            'gender' => $gender,
            'department' => $department,
            'homeroom_class' => trim((string)($payload['homeroom_class'] ?? ($current['homeroom_class'] ?? ''))),
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
        $teacher = Teacher::findByTeacherCode($loginId);
        jsonResponse(['status' => 'success', 'data' => $this->formatTeacherForResponse($teacher)]);
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
        $teacherCode = trim((string)($_GET['teacher_code'] ?? ''));
        if ($teacherCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã giáo viên.'], 422);
            return;
        }
        $teacher = Teacher::findByTeacherCode($teacherCode);
        if (!$teacher) {
            jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy giáo viên.'], 404);
            return;
        }
        jsonResponse(['status' => 'success', 'data' => $this->formatTeacherForResponse($teacher)]);
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

        $oldCode = trim((string)($payload['old_teacher_code'] ?? ''));
        $newCode = trim((string)($payload['teacher_code'] ?? ''));
        $fullName = trim((string)($payload['full_name'] ?? ''));
        $department = trim((string)($payload['department'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $gender = $this->normalizeGender((string)($payload['gender'] ?? ''));
        $status = $this->normalizeStatus((string)($payload['status'] ?? ''));
        $dateOfBirth = trim((string)($payload['date_of_birth'] ?? ''));
        $homeroomClass = trim((string)($payload['homeroom_class'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));

        $errors = [];
        if ($oldCode === '') $errors['old_teacher_code'] = 'Thiếu mã giáo viên gốc.';
        if ($newCode === '') $errors['teacher_code'] = 'Hãy nhập mã giáo viên.';
        if ($fullName === '') $errors['full_name'] = 'Hãy nhập họ tên.';
        if ($department === '') $errors['department'] = 'Hãy nhập khoa/bộ môn.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email không hợp lệ.';
        if ($gender === '') $errors['gender'] = 'Giới tính không hợp lệ.';
        if ($status === '') $errors['status'] = 'Trạng thái không hợp lệ.';
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.', 'fields' => $errors], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Teacher::ensureSchema($pdo);
            Admin::ensureSchema($pdo);
            $stmt = $pdo->prepare('SELECT * FROM GiangVien WHERE MaGV = :code LIMIT 1');
            $stmt->execute([':code' => $oldCode]);
            if (!$stmt->fetch()) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy giáo viên.'], 404);
                return;
            }

            $pdo->beginTransaction();
            if (strtolower($newCode) !== strtolower($oldCode)) {
                $stmt = $pdo->prepare('SELECT 1 FROM GiangVien WHERE MaGV = :code LIMIT 1');
                $stmt->execute([':code' => $newCode]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Mã giáo viên đã tồn tại.', 'fields' => ['teacher_code' => 'Mã giáo viên đã tồn tại.']], 409);
                    return;
                }
                $stmt = $pdo->prepare('SELECT 1 FROM TaiKhoan WHERE lower(LoginId)=lower(:login_id) AND lower(LoginId)<>lower(:old) LIMIT 1');
                $stmt->execute([':login_id' => $newCode, ':old' => $oldCode]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Login ID đã tồn tại trong hệ thống.', 'fields' => ['teacher_code' => 'Login ID đã tồn tại trong hệ thống.']], 409);
                    return;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE GiangVien SET
                    MaGV = :new_code,
                    HoTen = :full_name,
                    NgaySinh = :date_of_birth,
                    GioiTinh = :gender,
                    LopPhuTrach = :homeroom_class,
                    Email = :email,
                    SoDienThoai = :phone,
                    TrangThai = :status
                 WHERE MaGV = :old_code'
            );
            $stmt->execute([
                ':new_code' => $newCode,
                ':full_name' => $fullName,
                ':date_of_birth' => $dateOfBirth ?: null,
                ':gender' => $gender,
                ':homeroom_class' => $homeroomClass ?: null,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':status' => $status,
                ':old_code' => $oldCode,
            ]);

            if (strtolower($newCode) !== strtolower($oldCode)) {
                $stmt = $pdo->prepare('UPDATE LopHocPhan SET MaGV = :new_code WHERE MaGV = :old_code');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
                $stmt = $pdo->prepare('UPDATE TaiKhoan SET LoginId = :new_code WHERE lower(LoginId)=lower(:old_code)');
                $stmt->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
            }
            $stmt = $pdo->prepare('UPDATE TaiKhoan SET HoTen = :name WHERE lower(LoginId)=lower(:login_id)');
            $stmt->execute([':name' => $fullName, ':login_id' => $newCode]);

            $pdo->commit();
            $teacher = Teacher::findByTeacherCode($newCode);
            jsonResponse(['status' => 'success', 'data' => $this->formatTeacherForResponse($teacher)]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Không thể cập nhật giáo viên.', 'detail' => $e->getMessage()], 500);
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
        $teacherCode = trim((string)($_GET['teacher_code'] ?? ''));
        if ($teacherCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã giáo viên.'], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Teacher::ensureSchema($pdo);
            Admin::ensureSchema($pdo);

            $stmt = $pdo->prepare('SELECT 1 FROM GiangVien WHERE MaGV = :code LIMIT 1');
            $stmt->execute([':code' => $teacherCode]);
            if (!$stmt->fetch()) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy giáo viên.'], 404);
                return;
            }

            $stmt = $pdo->prepare('SELECT COUNT(1) AS c FROM LopHocPhan WHERE MaGV = :code');
            $stmt->execute([':code' => $teacherCode]);
            $countCourse = (int)($stmt->fetch()['c'] ?? 0);
            if ($countCourse > 0) {
                jsonResponse(['status' => 'error', 'message' => 'Giáo viên đang được phân công môn học, không thể xóa.'], 409);
                return;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM GiangVien WHERE MaGV = :code');
            $stmt->execute([':code' => $teacherCode]);
            $stmt = $pdo->prepare('DELETE FROM TaiKhoan WHERE lower(LoginId)=lower(:code) AND LoaiTaiKhoan = "teacher"');
            $stmt->execute([':code' => $teacherCode]);
            $pdo->commit();

            jsonResponse(['status' => 'success', 'message' => 'Đã xóa giáo viên và tài khoản liên quan.']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa giáo viên.', 'detail' => $e->getMessage()], 500);
        }
    }
}
