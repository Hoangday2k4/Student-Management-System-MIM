<?php
require_once __DIR__ . '/../models/Course.php';
require_once __DIR__ . '/../models/Semester.php';
require_once __DIR__ . '/../models/Teacher.php';
require_once __DIR__ . '/../models/Student.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/Attendance.php';
require_once __DIR__ . '/../helpers/response.php';

class CourseController
{
    private function resolveCurrentSemesterCode(?PDO $pdo = null): string
    {
        try {
            if (!$pdo) {
                $pdo = get_db_connection();
            }
            $stmt = $pdo->query('SELECT MaHocKy FROM HocKy WHERE IsCurrent = 1 AND TrangThai = "ACTIVE" ORDER BY UpdatedAt DESC, CreatedAt DESC LIMIT 1');
            $code = $stmt ? $stmt->fetchColumn() : false;
            if ($code !== false && trim((string)$code) !== '') {
                return trim((string)$code);
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
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

    private function isTeacher(array $identity): bool
    {
        return ($identity['account_type'] ?? '') === 'teacher';
    }

    private function isStudent(array $identity): bool
    {
        return ($identity['account_type'] ?? '') === 'student';
    }

    private function parseJsonPayload(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return null;
        }
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : null;
    }

    private function normalizeSessionDate(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') return '';
        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$dt) return '';
        if ($dt->format('Y-m-d') !== $raw) return '';
        return $raw;
    }

    private function normalizeAttendanceStatus(string $value): string
    {
        $raw = strtoupper(trim($value));
        if ($raw === 'PRESENT' || $raw === 'ABSENT') {
            return $raw;
        }
        return '';
    }

    private function splitMultiValues(string $raw): array
    {
        $items = array_map('trim', explode(',', $raw));
        return array_values(array_filter($items, static fn($v) => $v !== ''));
    }

    private function parseScheduleItem(string $item): ?array
    {
        $raw = strtoupper(trim($item));
        if (!preg_match('/^T([2-7])-\((\d{1,2})-(\d{1,2})\)$/u', $raw, $m)) {
            return null;
        }
        $day = (int)$m[1];
        $start = (int)$m[2];
        $end = (int)$m[3];
        if ($start <= 0 || $end <= 0 || $start > $end) {
            return null;
        }
        return [
            'raw' => $raw,
            'day' => $day,
            'start' => $start,
            'end' => $end,
        ];
    }

    private function intervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return max($aStart, $bStart) <= min($aEnd, $bEnd);
    }

    private function buildScheduleSlots(string $scheduleRaw, string $classroomRaw): array
    {
        $scheduleItems = $this->splitMultiValues($scheduleRaw);
        $classroomItems = array_map('strtoupper', $this->splitMultiValues($classroomRaw));
        $slots = [];
        if (empty($scheduleItems)) {
            return $slots;
        }

        $getRoomByIndex = function (int $idx) use ($classroomItems): string {
            if (empty($classroomItems)) return '';
            if (count($classroomItems) === 1) return (string)$classroomItems[0];
            return (string)($classroomItems[$idx] ?? '');
        };

        foreach ($scheduleItems as $idx => $item) {
            $slot = $this->parseScheduleItem($item);
            if (!$slot) {
                continue;
            }
            $slot['classroom'] = $getRoomByIndex((int)$idx);
            $slots[] = $slot;
        }

        return $slots;
    }

    private function findClassroomConflict(PDO $pdo, string $scheduleRaw, string $classroomRaw, ?int $excludeCourseId = null): ?array
    {
        $newSlots = $this->buildScheduleSlots($scheduleRaw, $classroomRaw);
        if (empty($newSlots)) {
            return null;
        }

        $rows = Course::searchForStaff([], $pdo);
        if ($excludeCourseId !== null && $excludeCourseId > 0) {
            $rows = array_values(array_filter($rows, static function ($row) use ($excludeCourseId) {
                return (int)($row['id'] ?? 0) !== $excludeCourseId;
            }));
        }

        foreach ($rows as $row) {
            $existingSlots = $this->buildScheduleSlots((string)($row['schedule'] ?? ''), (string)($row['classroom'] ?? ''));
            foreach ($newSlots as $newSlot) {
                $newRoom = strtoupper(trim((string)($newSlot['classroom'] ?? '')));
                if ($newRoom === '') continue;
                foreach ($existingSlots as $oldSlot) {
                    $oldRoom = strtoupper(trim((string)($oldSlot['classroom'] ?? '')));
                    if ($oldRoom === '' || $newRoom !== $oldRoom) continue;
                    if ((int)$newSlot['day'] !== (int)$oldSlot['day']) continue;
                    if ($this->intervalsOverlap((int)$newSlot['start'], (int)$newSlot['end'], (int)$oldSlot['start'], (int)$oldSlot['end'])) {
                        return [
                            'course_code' => (string)($row['course_code'] ?? ''),
                            'classroom' => $newRoom,
                            'day' => (int)$newSlot['day'],
                            'start' => (int)$newSlot['start'],
                            'end' => (int)$newSlot['end'],
                        ];
                    }
                }
            }
        }

        return null;
    }

    private function splitStudentsByScheduleConflict(PDO $pdo, int $courseId, array $studentCodes, string $targetScheduleRaw): array
    {
        $targetSlots = $this->buildScheduleSlots($targetScheduleRaw, '');
        if (empty($targetSlots) || empty($studentCodes)) {
            return [$studentCodes, [], 0];
        }

        $studentCodes = array_values(array_unique(array_filter(array_map('trim', $studentCodes))));
        if (empty($studentCodes)) {
            return [[], [], 0];
        }

        $conflictMap = [];
        foreach ($studentCodes as $studentCode) {
            $studentCode = trim((string)$studentCode);
            if ($studentCode === '') continue;
            $courses = Course::listByStudentCode($studentCode);
            foreach ($courses as $row) {
                if ((int)($row['id'] ?? 0) === $courseId) {
                    continue;
                }
                $existingSlots = $this->buildScheduleSlots((string)($row['schedule'] ?? ''), '');
                if (empty($existingSlots)) continue;

                foreach ($targetSlots as $target) {
                    foreach ($existingSlots as $old) {
                        if ((int)$target['day'] !== (int)$old['day']) continue;
                        if ($this->intervalsOverlap((int)$target['start'], (int)$target['end'], (int)$old['start'], (int)$old['end'])) {
                            $conflictMap[$studentCode] = [
                                'student_code' => $studentCode,
                                'course_code' => (string)($row['course_code'] ?? ''),
                                'schedule' => (string)($row['schedule'] ?? ''),
                            ];
                            break 3;
                        }
                    }
                }
            }
        }

        $allowed = [];
        foreach ($studentCodes as $code) {
            if (!isset($conflictMap[$code])) {
                $allowed[] = $code;
            }
        }

        return [$allowed, array_values($conflictMap), count($conflictMap)];
    }

    private function runWithDbRetry(callable $handler, int $maxAttempts = 3, int $baseDelayMs = 200)
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $handler();
            } catch (PDOException $e) {
                $msg = (string)$e->getMessage();
                $isLocked = stripos($msg, 'database is locked') !== false;
                if ($isLocked && $attempt < $maxAttempts) {
                    usleep($baseDelayMs * 1000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    private function composeAttendancePayload(PDO $pdo, int $courseId, array $course, int $sessionId): array
    {
        $maLHP = Attendance::resolveMaLhpByCourseId($pdo, $courseId);
        $sessions = $maLHP ? Attendance::listSessionsByCourse($pdo, $maLHP) : [];

        $selected = null;
        if ($sessionId > 0) {
            $selected = Attendance::getSessionById($pdo, $sessionId);
        }
        if (!$selected && !empty($sessions)) {
            $selected = Attendance::getSessionById($pdo, (int)($sessions[0]['id'] ?? 0));
        }
        if ($selected && $maLHP && (string)($selected['ma_lhp'] ?? '') !== $maLHP) {
            $selected = null;
        }

        $attendance = $selected ? Attendance::getAttendanceMap($pdo, (int)$selected['id']) : [];
        $students = Course::getStudentsByCourseId($courseId);

        return [
            'status' => 'success',
            'data' => [
                'id' => (int)($course['id'] ?? $courseId),
                'course_code' => $course['course_code'] ?? '',
                'course_name' => $course['course_name'] ?? '',
                'teacher_name' => $course['teacher_name'] ?? '',
                'teacher_code' => $course['teacher_code'] ?? '',
                'credits' => $course['credits'] ?? null,
                'schedule' => $course['schedule'] ?? '',
                'classroom' => $course['classroom'] ?? '',
                'ma_hoc_ky' => $course['ma_hoc_ky'] ?? '',
            ],
            'session' => $selected ? [
                'id' => (int)$selected['id'],
                'date' => (string)($selected['session_date'] ?? ''),
                'note' => (string)($selected['note'] ?? ''),
            ] : null,
            'sessions' => $sessions,
            'students' => $students,
            'attendance' => $attendance,
        ];
    }
    private function extractStudentCodesFromTableRows(array $rows): array
    {
        if (count($rows) < 2) {
            return [[], 'File danh sach sinh vien khong co du lieu.', []];
        }

        $headers = $rows[0];
        $headerMap = [];
        $headerAlias = [
            'student_code' => ['mssv', 'studentcode', 'student_code', 'masosinhvien', 'masv'],
            'full_name' => ['hoten', 'hten', 'fullname', 'tensinhvien'],
            'date_of_birth' => ['ngaysinh', 'ngysinh', 'dateofbirth', 'dob'],
            'gender' => ['gioitinh', 'gitinh', 'gender'],
            'class_name' => ['lop', 'lp', 'classname'],
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

        // Fallback by fixed column order to tolerate mojibake/accent stripping in CSV headers.
        if (count($headers) >= 5) {
            $defaultMap = [
                'student_code' => 0,
                'full_name' => 1,
                'date_of_birth' => 2,
                'gender' => 3,
                'class_name' => 4,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['student_code', 'full_name', 'date_of_birth', 'gender', 'class_name'] as $requiredField) {
            if (!isset($headerMap[$requiredField])) {
                return [[], 'File danh sach sinh vien phai co du 5 cot: MSSV, Ho ten, Ngay sinh, Gioi tinh, Lop.', []];
            }
        }

        $codes = [];
        $seen = [];
        $totalRows = 0;
        $invalidRows = 0;
        $duplicateRows = 0;
        for ($i = 1; $i < count($rows); $i++) {
            $line = $i + 1;
            $source = $rows[$i];
            $studentCode = trim((string)($source[$headerMap['student_code']] ?? ''));
            $fullName = trim((string)($source[$headerMap['full_name']] ?? ''));
            $dateOfBirth = trim((string)($source[$headerMap['date_of_birth']] ?? ''));
            $gender = trim((string)($source[$headerMap['gender']] ?? ''));
            $className = trim((string)($source[$headerMap['class_name']] ?? ''));

            if ($studentCode === '' && $fullName === '' && $dateOfBirth === '' && $gender === '' && $className === '') {
                continue;
            }
            $totalRows++;
            if ($studentCode === '' || $fullName === '' || $dateOfBirth === '' || $gender === '' || $className === '') {
                $invalidRows++;
                continue;
            }

            $key = strtolower($studentCode);
            if (isset($seen[$key])) {
                $duplicateRows++;
                continue;
            }
            $seen[$key] = true;
            $codes[] = $studentCode;
        }

        if (empty($codes)) {
            return [[], 'File danh sach sinh vien khong co dong hop le.', [
                'total_rows' => $totalRows,
                'valid_rows' => 0,
                'invalid_rows' => $invalidRows,
                'duplicate_rows' => $duplicateRows,
            ]];
        }

        return [$codes, '', [
            'total_rows' => $totalRows,
            'valid_rows' => count($codes),
            'invalid_rows' => $invalidRows,
            'duplicate_rows' => $duplicateRows,
        ]];
    }

    private function parseStudentCodesFromFile(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [[], 'Không thể đọc file danh sách sinh viên.', []];
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            return [[], 'File .xls chua ho tro. Hay luu duoi dang .xlsx hoac .csv roi tai len.', []];
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return [[], 'Không tìm thấy file tải lên.', []];
        }

        try {
            if ($ext === 'xlsx') {
                $rawRows = $this->parseXlsxRows($tmp);
            } else {
                $rawRows = $this->parseCsvRows($tmp);
            }
            return $this->extractStudentCodesFromTableRows($rawRows);
        } catch (Throwable $e) {
            return [[], 'Không đọc được dữ liệu file danh sách sinh viên.', []];
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
        while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
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
            throw new RuntimeException('Không mở được file xlsx.');
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
            throw new RuntimeException('Không đọc được dữ liệu worksheet.');
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

    private function mapCourseImportRows(array $rawRows): array
    {
        if (count($rawRows) < 2) {
            return ['rows' => [], 'skipped' => []];
        }

        $headers = $rawRows[0];
        $headerMap = [];
        $headerAlias = [
            'course_code' => ['mamonhoc', 'mamh', 'coursecode'],
            'course_name' => ['tenmonhoc', 'coursename'],
            'credits' => ['sotinchi', 'credits'],
            'teacher_code' => ['msgv', 'magiaovien', 'mgv', 'teachercode'],
            'schedule' => ['lichhoc', 'schedule'],
            'classroom' => ['phonghoc', 'classroom'],
            'max_students' => ['soluongtoida', 'maxstudents'],
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

        if (count($headers) >= 6) {
            $col3 = $this->normalizeHeader((string)($headers[3] ?? ''));
            $col4 = $this->normalizeHeader((string)($headers[4] ?? ''));
            $looksLikeOldDepartmentColumn = in_array($col4, ['khoa', 'vien', 'khoavien', 'bomon', 'khoabomon', 'department'], true);
            $looksLikeTeacherColumn = in_array($col3, ['msgv', 'magiaovien', 'mgv', 'teachercode'], true);
            $looksLikeDepartmentAtCol3 = in_array($col3, ['khoa', 'vien', 'khoavien', 'bomon', 'khoabomon', 'department'], true);

            $defaultMap = ($looksLikeOldDepartmentColumn && count($headers) >= 8)
                ? [
                    'course_code' => 0,
                    'course_name' => 1,
                    'credits' => 2,
                    'teacher_code' => 3,
                    'schedule' => 5,
                    'classroom' => 6,
                    'max_students' => 7,
                ]
                : (($looksLikeDepartmentAtCol3 && count($headers) >= 7)
                    ? [
                        'course_code' => 0,
                        'course_name' => 1,
                        'credits' => 2,
                        'schedule' => 4,
                        'classroom' => 5,
                        'max_students' => 6,
                    ]
                    : ($looksLikeTeacherColumn || count($headers) >= 7
                        ? [
                            'course_code' => 0,
                            'course_name' => 1,
                            'credits' => 2,
                            'teacher_code' => 3,
                            'schedule' => 4,
                            'classroom' => 5,
                            'max_students' => 6,
                        ]
                        : [
                            'course_code' => 0,
                            'course_name' => 1,
                            'credits' => 2,
                            'schedule' => 3,
                            'classroom' => 4,
                            'max_students' => 5,
                        ]));
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['course_code', 'course_name'] as $requiredField) {
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
            $teacherIndex = isset($headerMap['teacher_code']) ? (int)$headerMap['teacher_code'] : -1;
            $row = [
                'course_code' => trim((string)($source[$headerMap['course_code']] ?? '')),
                'course_name' => trim((string)($source[$headerMap['course_name']] ?? '')),
                'credits' => trim((string)($source[$headerMap['credits'] ?? -1] ?? '')),
                'teacher_code' => trim((string)($source[$teacherIndex] ?? '')),
                'schedule' => trim((string)($source[$headerMap['schedule'] ?? -1] ?? '')),
                'classroom' => trim((string)($source[$headerMap['classroom'] ?? -1] ?? '')),
                'max_students' => trim((string)($source[$headerMap['max_students'] ?? -1] ?? '')),
            ];

            if ($row['course_code'] === '' && $row['course_name'] === '') {
                continue;
            }
            if ($row['course_code'] === '' || $row['course_name'] === '') {
                $skipped[] = ['line' => $line, 'course_code' => $row['course_code'], 'reason' => 'Thieu truong bat buoc'];
                continue;
            }
            $key = strtolower($row['course_code']);
            if (isset($seenCodes[$key])) {
                $skipped[] = ['line' => $line, 'course_code' => $row['course_code'], 'reason' => 'Trung trong file'];
                continue;
            }
            $seenCodes[$key] = true;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function validatePayload(array $payload, ?PDO $pdo = null): array
    {
        $courseCode = trim((string)($payload['course_code'] ?? ''));
        $courseName = trim((string)($payload['course_name'] ?? ''));
        $teacherCode = trim((string)($payload['teacher_code'] ?? ''));
        $schedule = strtoupper(trim((string)($payload['schedule'] ?? '')));
        $classroom = strtoupper(trim((string)($payload['classroom'] ?? '')));
        $maHocKy = trim((string)($payload['ma_hoc_ky'] ?? ''));
        if ($maHocKy === '' && $pdo) {
            $maHocKy = $this->resolveCurrentSemesterCode($pdo);
        }
        $enrollmentStatus = strtoupper(trim((string)($payload['enrollment_status'] ?? 'DRAFT')));

        $creditsRaw = trim((string)($payload['credits'] ?? ''));
        $maxStudentsRaw = trim((string)($payload['max_students'] ?? ''));

        $errors = [];
        if ($courseCode === '') $errors['course_code'] = 'HĂ£y nháº­p mĂ£ mĂ´n há»c.';
        if ($courseName === '') $errors['course_name'] = 'HĂ£y nháº­p tĂªn mĂ´n há»c.';
        if ($maHocKy === '') $errors['ma_hoc_ky'] = 'Hay chon hoc ky.';
        if (!in_array($enrollmentStatus, ['DRAFT', 'OPEN', 'CLOSED', 'LOCKED'], true)) {
            $errors['enrollment_status'] = 'Trang thai dang ky khong hop le.';
        }

        $credits = null;
        if ($creditsRaw !== '') {
            if (!ctype_digit($creditsRaw)) {
                $errors['credits'] = 'Sá»‘ tĂ­n chá»‰ pháº£i lĂ  sá»‘ nguyĂªn dÆ°Æ¡ng.';
            } else {
                $credits = (int)$creditsRaw;
                if ($credits <= 0) {
                    $errors['credits'] = 'Sá»‘ tĂ­n chá»‰ pháº£i lá»›n hÆ¡n 0.';
                }
            }
        }

        if ($maHocKy !== '') {
            try {
                if ($pdo) {
                    $stmt = $pdo->prepare('SELECT 1 FROM HocKy WHERE lower(MaHocKy)=lower(:ma) LIMIT 1');
                    $stmt->execute([':ma' => $maHocKy]);
                    if (!$stmt->fetchColumn()) {
                        $errors['ma_hoc_ky'] = 'Khong tim thay hoc ky.';
                    }
                } else {
                    $semester = Semester::findByCode($maHocKy);
                    if (!$semester) {
                        $errors['ma_hoc_ky'] = 'Khong tim thay hoc ky.';
                    }
                }
            } catch (Throwable $e) {
                $errors['ma_hoc_ky'] = 'Khong the kiem tra hoc ky luc nay.';
            }
        }

        $maxStudents = null;
        if ($maxStudentsRaw !== '') {
            if (!ctype_digit($maxStudentsRaw)) {
                $errors['max_students'] = 'Sá»‘ lÆ°á»£ng tá»‘i Ä‘a pháº£i lĂ  sá»‘ nguyĂªn dÆ°Æ¡ng.';
            } else {
                $maxStudents = (int)$maxStudentsRaw;
                if ($maxStudents <= 0) {
                    $errors['max_students'] = 'Sá»‘ lÆ°á»£ng tá»‘i Ä‘a pháº£i lá»›n hÆ¡n 0.';
                }
            }
        }

        if ($schedule !== '') {
            $scheduleItems = $this->splitMultiValues($schedule);
            foreach ($scheduleItems as $scheduleItem) {
                if (!preg_match('/^T([2-7])-\((\d{1,2})-(\d{1,2})\)$/u', strtoupper($scheduleItem), $m)) {
                    $errors['schedule'] = 'Lá»‹ch há»c pháº£i Ä‘Ăºng dáº¡ng T2-(1-3), cĂ³ thá»ƒ nhiá»u giĂ¡ trá»‹ ngÄƒn cĂ¡ch bá»Ÿi dáº¥u pháº©y.';
                    break;
                }
                $start = (int)$m[2];
                $end = (int)$m[3];
                if ($start <= 0 || $end <= 0 || $start > $end) {
                    $errors['schedule'] = 'Tiáº¿t há»c khĂ´ng há»£p lá»‡. VĂ­ dá»¥ Ä‘Ăºng: T2-(1-3).';
                    break;
                }
            }
        }

        if ($classroom !== '') {
            $classroomItems = $this->splitMultiValues($classroom);
            foreach ($classroomItems as $classroomItem) {
                if (!preg_match('/^\d{3}T\d{1,2}$/', strtoupper($classroomItem))) {
                    $errors['classroom'] = 'PhĂ²ng há»c pháº£i Ä‘Ăºng dáº¡ng 502T5, cĂ³ thá»ƒ nhiá»u giĂ¡ trá»‹ ngÄƒn cĂ¡ch bá»Ÿi dáº¥u pháº©y.';
                    break;
                }
            }
        }

        if ($schedule !== '' && $classroom !== '') {
            $scheduleItems = $this->splitMultiValues($schedule);
            $classroomItems = $this->splitMultiValues($classroom);
            $scheduleCount = count($scheduleItems);
            $classroomCount = count($classroomItems);
            if ($scheduleCount > 0 && $classroomCount > 1 && $classroomCount !== $scheduleCount) {
                $errors['classroom'] = 'Số phòng học phải bằng 1 hoặc bằng số lịch học.';
            }
        }

        $teacher = null;
        if ($teacherCode !== '') {
            try {
                if ($pdo) {
                    $stmt = $pdo->prepare('SELECT MaGV AS teacher_code, HoTen AS full_name FROM GiangVien WHERE MaGV = :teacher_code LIMIT 1');
                    $stmt->execute([':teacher_code' => $teacherCode]);
                    $teacher = $stmt->fetch();
                } else {
                    $teacher = Teacher::findByTeacherCode($teacherCode);
                }
                if (!$teacher) {
                    $errors['teacher_code'] = 'KhĂ´ng tĂ¬m tháº¥y giĂ¡o viĂªn theo mĂ£ Ä‘Ă£ nháº­p.';
                }
            } catch (Throwable $e) {
                $errors['teacher_code'] = 'KhĂ´ng kiá»ƒm tra Ä‘Æ°á»£c mĂ£ giĂ¡o viĂªn lĂºc nĂ y. Vui lĂ²ng thá»­ láº¡i.';
            }
        }

        return [[
            'course_code' => $courseCode,
            'course_name' => $courseName,
            'credits' => $credits,
            'teacher_code' => $teacherCode,
            'department' => '',
            'schedule' => $schedule,
            'classroom' => $classroom,
            'max_students' => $maxStudents,
            'ma_hoc_ky' => $maHocKy,
            'enrollment_status' => $enrollmentStatus,
            'teacher_name' => $teacher['full_name'] ?? '',
        ], $errors];
    }

    private function parseWeight($value): float
    {
        $raw = trim((string)$value);
        if ($raw === '') return 0.0;
        return (float)$raw;
    }

    private function parseScore($value)
    {
        $raw = trim((string)$value);
        if ($raw === '') return null;
        return (float)$raw;
    }

    private function calculateTotal($cc, $gk, $ck, float $wCc, float $wGk, float $wCk)
    {
        $sumW = $wCc + $wGk + $wCk;
        if ($sumW <= 0) return null;
        $ccVal = $cc === null ? 0.0 : (float)$cc;
        $gkVal = $gk === null ? 0.0 : (float)$gk;
        $ckVal = $ck === null ? 0.0 : (float)$ck;
        return round(($ccVal * $wCc + $gkVal * $wGk + $ckVal * $wCk) / $sumW, 2);
    }

    private function letterGrade($total): string
    {
        if ($total === null) return '-';
        $v = (float)$total;
        if ($v >= 8.5) return 'A';
        if ($v >= 8.0) return 'B+';
        if ($v >= 7.0) return 'B';
        if ($v >= 6.5) return 'C+';
        if ($v >= 5.5) return 'C';
        if ($v >= 5.0) return 'D+';
        if ($v >= 4.0) return 'D';
        return 'F';
    }

    private function canTransitEnrollmentStatus(string $from, string $to): bool
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === $to) return true;

        $allowed = [
            'DRAFT' => ['OPEN', 'CLOSED', 'LOCKED'],
            'OPEN' => ['CLOSED', 'LOCKED'],
            'CLOSED' => ['OPEN', 'LOCKED'],
            'LOCKED' => [],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }

    public function index()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        $loginId = (string)$identity['login_id'];
        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();
        if ($this->isStaff($identity)) {
            $rows = Course::searchForStaff([
                'keyword' => isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '',
                'teacher_code' => isset($_GET['teacher_code']) ? trim((string)$_GET['teacher_code']) : '',
                'ma_hoc_ky' => isset($_GET['ma_hoc_ky']) ? trim((string)$_GET['ma_hoc_ky']) : '',
            ]);
            jsonResponse($rows);
            return;
        }

        if ($this->isTeacher($identity)) {
            jsonResponse(Course::listByTeacherCode($loginId, [
                'ma_hoc_ky' => isset($_GET['ma_hoc_ky']) ? trim((string)$_GET['ma_hoc_ky']) : '',
            ]));
            return;
        }

        if ($this->isStudent($identity)) {
            jsonResponse(Course::listByStudentCode($loginId, [
                'ma_hoc_ky' => isset($_GET['ma_hoc_ky']) ? trim((string)$_GET['ma_hoc_ky']) : '',
            ]));
            return;
        }

        jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
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

        Teacher::ensureSchema();
        Course::ensureSchema();
        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dá»¯ liá»‡u khĂ´ng há»£p lá»‡.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            Teacher::ensureSchema($pdo);
            Student::ensureSchema($pdo);
            [$data, $errors] = $this->validatePayload($payload, $pdo);
            if (!empty($errors)) {
                jsonResponse(['status' => 'error', 'message' => 'Dá»¯ liá»‡u khĂ´ng há»£p lá»‡.', 'fields' => $errors], 422);
                return;
            }

            $roomConflict = $this->findClassroomConflict($pdo, (string)($data['schedule'] ?? ''), (string)($data['classroom'] ?? ''), null);
            if ($roomConflict) {
                $msg = sprintf(
                    'Trùng phòng học với lớp %s (T%s-(%s-%s), phòng %s).',
                    $roomConflict['course_code'],
                    $roomConflict['day'],
                    $roomConflict['start'],
                    $roomConflict['end'],
                    $roomConflict['classroom']
                );
                jsonResponse([
                    'status' => 'error',
                    'message' => $msg,
                    'fields' => [
                        'schedule' => $msg,
                        'classroom' => $msg,
                    ],
                ], 422);
                return;
            }

            $pdo->beginTransaction();
            $id = Course::insertWithPdo($pdo, $data);
            $pdo->commit();

            $course = Course::findById($id);
            jsonResponse(['status' => 'success', 'data' => $course], 201);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $msg = (string)$e->getMessage();
            if (strpos($msg, 'UNIQUE constraint failed: MonHoc.MaMon') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'MĂ£ mĂ´n há»c Ä‘Ă£ tá»“n táº¡i.',
                    'fields' => ['course_code' => 'MĂ£ mĂ´n há»c Ä‘Ă£ tá»“n táº¡i.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ táº¡o lá»›p há»c.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Lá»—i há»‡ thá»‘ng.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function delete()
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

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã môn học.'], 422);
            return;
        }

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy môn học.'], 404);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            $pdo->beginTransaction();
            Course::deleteByIdWithPdo($pdo, $courseId);
            $pdo->commit();

            jsonResponse([
                'status' => 'success',
                'message' => 'Đã xóa môn học thành công.',
                'data' => ['id' => $courseId, 'course_code' => (string)($course['course_code'] ?? '')],
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa môn học.', 'detail' => $e->getMessage()], 500);
        }
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

        Teacher::ensureSchema();
        Course::ensureSchema();

        $action = strtolower(trim((string)($_GET['action'] ?? 'save')));
        if ($action === 'preview') {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                jsonResponse(['status' => 'error', 'message' => 'Chua co file du lieu.'], 400);
                return;
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                jsonResponse(['status' => 'error', 'message' => 'Không đọc được file tải lên.'], 400);
                return;
            }
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            try {
                if ($ext === 'csv') {
                    $rawRows = $this->parseCsvRows((string)$file['tmp_name']);
                } else {
                    $rawRows = $this->parseXlsxRows((string)$file['tmp_name']);
                }
                $mapped = $this->mapCourseImportRows($rawRows);
                jsonResponse([
                    'status' => 'success',
                    'rows' => $mapped['rows'],
                    'skipped_in_file' => $mapped['skipped'],
                ]);
            } catch (Throwable $e) {
                jsonResponse(['status' => 'error', 'message' => 'Không thể đọc file import.', 'detail' => $e->getMessage()], 422);
            }
            return;
        }

        $payload = $this->parseJsonPayload();
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        if (empty($rows)) {
            jsonResponse(['status' => 'error', 'message' => 'Không có dữ liệu để import.'], 400);
            return;
        }

        try {
            $result = $this->runWithDbRetry(function () use ($rows) {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                Teacher::ensureSchema($pdo);
                Student::ensureSchema($pdo);

                try {
                    $pdo->beginTransaction();

                    $inserted = 0;
                    $skipped = [];
                    $existingCodes = [];
                    $courseCodes = $pdo->query('SELECT MaMon FROM MonHoc')->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($courseCodes as $code) {
                        $existingCodes[strtolower(trim((string)$code))] = true;
                    }

                    foreach ($rows as $idx => $row) {
                        $line = $idx + 2;
                        $data = is_array($row) ? $row : [];
                        [$validated, $errors] = $this->validatePayload($data, $pdo);
                        $code = trim((string)($validated['course_code'] ?? ''));
                        $codeKey = strtolower($code);

                        if (!empty($errors)) {
                            $skipped[] = ['line' => $line, 'course_code' => $code, 'reason' => implode(' | ', array_values($errors))];
                            continue;
                        }
                        if (isset($existingCodes[$codeKey])) {
                            $skipped[] = ['line' => $line, 'course_code' => $code, 'reason' => 'Mã môn học đã tồn tại'];
                            continue;
                        }

                        $roomConflict = $this->findClassroomConflict(
                            $pdo,
                            (string)($validated['schedule'] ?? ''),
                            (string)($validated['classroom'] ?? ''),
                            null
                        );
                        if ($roomConflict) {
                            $skipped[] = [
                                'line' => $line,
                                'course_code' => $code,
                                'reason' => sprintf(
                                    'Trùng phòng học với lớp %s (T%s-(%s-%s), phòng %s)',
                                    $roomConflict['course_code'],
                                    $roomConflict['day'],
                                    $roomConflict['start'],
                                    $roomConflict['end'],
                                    $roomConflict['classroom']
                                ),
                            ];
                            continue;
                        }

                        Course::insertWithPdo($pdo, $validated);
                        $existingCodes[$codeKey] = true;
                        $inserted++;
                    }

                    $pdo->commit();

                    return [
                        'inserted' => $inserted,
                        'skipped' => $skipped,
                    ];
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });

            jsonResponse([
                'status' => 'success',
                'inserted_count' => (int)($result['inserted'] ?? 0),
                'skipped_count' => count($result['skipped'] ?? []),
                'skipped' => $result['skipped'] ?? [],
            ]);
        } catch (PDOException $e) {
            $msg = (string)$e->getMessage();
            jsonResponse(['status' => 'error', 'message' => 'Không thể import lớp học.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => 'Loi he thong.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function detail()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();
        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ lá»›p há»c.'], 422);
            return;
        }

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y mĂ´n há»c.'], 404);
            return;
        }

        $loginId = (string)$identity['login_id'];
        if ($this->isTeacher($identity) && (string)$course['teacher_code'] !== $loginId) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }
        if ($this->isStudent($identity) && !Course::isStudentEnrolled($courseId, $loginId)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }
        if (!$this->isStaff($identity) && !$this->isTeacher($identity) && !$this->isStudent($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $students = Course::getStudentsByCourseId($courseId);
        jsonResponse([
            'status' => 'success',
            'data' => $course,
            'students' => $students,
        ]);
    }

    public function addStudents()
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

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        $studentCodes = is_array($payload['student_codes'] ?? null) ? $payload['student_codes'] : [];
        $studentCodes = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $studentCodes))));

        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop hoc.'], 422);
            return;
        }
        if (empty($studentCodes)) {
            jsonResponse(['status' => 'error', 'message' => 'Hay chon it nhat mot sinh vien.'], 422);
            return;
        }

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay mon hoc.'], 404);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            Student::ensureSchema($pdo);

            $validCodes = Course::findValidStudentCodes($pdo, $studentCodes);
            $requestedMap = array_fill_keys(array_map('strtolower', $studentCodes), true);
            $validMap = array_fill_keys(array_map(static fn($v) => strtolower((string)$v), $validCodes), true);

            $missingCodes = [];
            foreach ($studentCodes as $code) {
                if (!isset($validMap[strtolower($code)])) {
                    $missingCodes[] = $code;
                }
            }

            $currentStudents = Course::getStudentsByCourseId($courseId);
            $currentMap = [];
            foreach ($currentStudents as $student) {
                $code = strtolower(trim((string)($student['student_code'] ?? '')));
                if ($code !== '') {
                    $currentMap[$code] = true;
                }
            }

            $newCodes = [];
            $alreadyEnrolled = [];
            foreach ($validCodes as $code) {
                $key = strtolower(trim((string)$code));
                if (isset($currentMap[$key])) {
                    $alreadyEnrolled[] = $code;
                    continue;
                }
                $newCodes[] = $code;
            }

            [$newCodes, $scheduleConflictDetails, $scheduleConflictRows] = $this->splitStudentsByScheduleConflict(
                $pdo,
                $courseId,
                $newCodes,
                (string)($course['schedule'] ?? '')
            );

            $mergedCount = count($currentMap) + count($newCodes);
            $maxStudents = isset($course['max_students']) ? (int)$course['max_students'] : 0;
            if ($maxStudents > 0 && $mergedCount > $maxStudents) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Vuot qua so luong toi da cua lop hoc phan.',
                ], 422);
                return;
            }

            $addedCount = 0;
            if (!empty($newCodes)) {
                $pdo->beginTransaction();
                $addedCount = Course::appendEnrollmentsWithPdo($pdo, $courseId, $newCodes);
                $pdo->commit();
            }

            $updatedCourse = Course::findById($courseId);
            $updatedStudents = Course::getStudentsByCourseId($courseId);

            jsonResponse([
                'status' => 'success',
                'message' => 'Da cap nhat danh sach sinh vien cho mon hoc.',
                'data' => $updatedCourse,
                'students' => $updatedStudents,
                'summary' => [
                    'requested' => count($studentCodes),
                    'added' => $addedCount,
                    'already_enrolled' => count($alreadyEnrolled),
                    'missing_in_db' => count($missingCodes),
                    'schedule_conflict_rows' => $scheduleConflictRows,
                    'schedule_conflict_students' => array_slice($scheduleConflictDetails, 0, 20),
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the them sinh vien vao mon hoc.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function update()
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

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();
        $payload = null;
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
            $payload = $this->parseJsonPayload();
        } else {
            $payload = $_POST;
        }
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dá»¯ liá»‡u khĂ´ng há»£p lá»‡.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ lá»›p há»c.'], 422);
            return;
        }

        $current = Course::findById($courseId);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y mĂ´n há»c.'], 404);
            return;
        }

        $studentCodesFromFile = null;
        $studentFileStats = [];
        if (isset($_FILES['student_file']) && ($_FILES['student_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$codes, $fileError, $fileStats] = $this->parseStudentCodesFromFile($_FILES['student_file']);
            if ($fileError !== '') {
                jsonResponse(['status' => 'error', 'message' => $fileError], 422);
                return;
            }
            $studentCodesFromFile = $codes;
            $studentFileStats = is_array($fileStats) ? $fileStats : [];
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            Student::ensureSchema($pdo);
            Teacher::ensureSchema($pdo);
            [$data, $errors] = $this->validatePayload($payload, $pdo);
            if (!empty($errors)) {
                jsonResponse(['status' => 'error', 'message' => 'Dá»¯ liá»‡u khĂ´ng há»£p lá»‡.', 'fields' => $errors], 422);
                return;
            }

            $currentSemester = trim((string)($current['ma_hoc_ky'] ?? ''));
            $newSemester = trim((string)($data['ma_hoc_ky'] ?? ''));
            if ($currentSemester !== '' && $newSemester !== '' && strcasecmp($currentSemester, $newSemester) !== 0) {
                if (Course::hasEnrollmentData($courseId, $pdo)) {
                    jsonResponse([
                        'status' => 'error',
                        'message' => 'Khong the doi hoc ky vi lop hoc phan da co du lieu dang ky/diem.',
                        'fields' => [
                            'ma_hoc_ky' => 'Khong the doi hoc ky khi da co du lieu hoc vu.',
                        ],
                    ], 409);
                    return;
                }
            }

            $roomConflict = $this->findClassroomConflict(
                $pdo,
                (string)($data['schedule'] ?? ''),
                (string)($data['classroom'] ?? ''),
                $courseId
            );
            if ($roomConflict) {
                $msg = sprintf(
                    'Trùng phòng học với lớp %s (T%s-(%s-%s), phòng %s).',
                    $roomConflict['course_code'],
                    $roomConflict['day'],
                    $roomConflict['start'],
                    $roomConflict['end'],
                    $roomConflict['classroom']
                );
                jsonResponse([
                    'status' => 'error',
                    'message' => $msg,
                    'fields' => [
                        'schedule' => $msg,
                        'classroom' => $msg,
                    ],
                ], 422);
                return;
            }
            $pdo->beginTransaction();

            Course::updateByIdWithPdo($pdo, $courseId, $data);

            $enrollmentImport = null;
            if (is_array($studentCodesFromFile)) {
                $currentStudents = Course::getStudentsByCourseId($courseId);
                $currentMap = [];
                foreach ($currentStudents as $student) {
                    $code = strtolower(trim((string)($student['student_code'] ?? '')));
                    if ($code !== '') {
                        $currentMap[$code] = true;
                    }
                }

                $validCodes = Course::findValidStudentCodes($pdo, $studentCodesFromFile);
                $newCodes = [];
                $alreadyEnrolledRows = 0;
                foreach ($validCodes as $code) {
                    $key = strtolower(trim((string)$code));
                    if ($key === '') {
                        continue;
                    }
                    if (isset($currentMap[$key])) {
                        $alreadyEnrolledRows++;
                        continue;
                    }
                    $newCodes[] = $code;
                }

                [$newCodes, $scheduleConflictDetails, $scheduleConflictRows] = $this->splitStudentsByScheduleConflict(
                    $pdo,
                    $courseId,
                    $newCodes,
                    (string)($data['schedule'] ?? '')
                );

                $mergedCount = count($currentMap) + count($newCodes);
                if ($data['max_students'] !== null && $mergedCount > (int)$data['max_students']) {
                    $pdo->rollBack();
                    jsonResponse([
                        'status' => 'error',
                        'message' => 'Danh sĂ¡ch sinh viĂªn vÆ°á»£t quĂ¡ sá»‘ lÆ°á»£ng tá»‘i Ä‘a cá»§a lá»›p.',
                    ], 422);
                    return;
                }

                $addedCount = 0;
                if (!empty($newCodes)) {
                    $addedCount = Course::appendEnrollmentsWithPdo($pdo, $courseId, $newCodes);
                }

                $requestedValidRows = (int)($studentFileStats['valid_rows'] ?? count($studentCodesFromFile));
                $invalidRows = (int)($studentFileStats['invalid_rows'] ?? 0);
                $duplicateRows = (int)($studentFileStats['duplicate_rows'] ?? 0);
                $missingInDb = max(0, $requestedValidRows - count($validCodes));
                $duplicateTotal = $duplicateRows + $alreadyEnrolledRows;
                $rejectedCount = $invalidRows + $duplicateTotal + $missingInDb + $scheduleConflictRows;

                $enrollmentImport = [
                    'total_rows' => (int)($studentFileStats['total_rows'] ?? ($requestedValidRows + $invalidRows + $duplicateRows)),
                    'accepted_rows' => count($validCodes),
                    'added_count' => $addedCount,
                    'rejected_count' => $rejectedCount,
                    'invalid_rows' => $invalidRows,
                    'duplicate_rows' => $duplicateTotal,
                    'already_enrolled_rows' => $alreadyEnrolledRows,
                    'missing_in_db' => $missingInDb,
                    'schedule_conflict_rows' => $scheduleConflictRows,
                    'schedule_conflict_students' => array_slice($scheduleConflictDetails, 0, 20),
                ];
            }

            $pdo->commit();
            $course = Course::findById($courseId);
            $students = Course::getStudentsByCourseId($courseId);

            jsonResponse([
                'status' => 'success',
                'data' => $course,
                'students' => $students,
                'enrollment_import' => $enrollmentImport,
            ]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $msg = (string)$e->getMessage();
            if (strpos($msg, 'UNIQUE constraint failed: MonHoc.MaMon') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'MĂ£ mĂ´n há»c Ä‘Ă£ tá»“n táº¡i.',
                    'fields' => ['course_code' => 'MĂ£ mĂ´n há»c Ä‘Ă£ tá»“n táº¡i.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ cáº­p nháº­t mĂ´n há»c.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Lá»—i há»‡ thá»‘ng.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function attendanceSheet()
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

        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop hoc.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay mon hoc.'], 404);
            return;
        }
        if ((string)$course['teacher_code'] !== (string)$identity['login_id']) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            Attendance::ensureSchema($pdo);

            $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
            $payload = $this->composeAttendancePayload($pdo, $courseId, $course, $sessionId);
            jsonResponse($payload);
        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => 'Khong the tai diem danh.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function saveAttendance()
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

        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop hoc.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay mon hoc.'], 404);
            return;
        }
        if ((string)$course['teacher_code'] !== (string)$identity['login_id']) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $sessionId = (int)($payload['session_id'] ?? 0);
        $sessionDate = $this->normalizeSessionDate((string)($payload['session_date'] ?? ''));
        $sessionNote = trim((string)($payload['session_note'] ?? ''));

        if ($sessionId <= 0 && $sessionDate === '') {
            jsonResponse(['status' => 'error', 'message' => 'Hay chon ngay hoc.'], 422);
            return;
        }
        if ($sessionDate === '' && isset($payload['session_date'])) {
            jsonResponse(['status' => 'error', 'message' => 'Ngay hoc khong hop le.'], 422);
            return;
        }

        $rows = isset($payload['attendance']) && is_array($payload['attendance']) ? $payload['attendance'] : [];

        try {
            $payload = $this->runWithDbRetry(function () use ($courseId, $course, $sessionId, $sessionDate, $sessionNote, $rows) {
                $pdo = get_db_connection();
                $pdo->exec('PRAGMA busy_timeout = 5000');
                $pdo->beginTransaction();
                Course::ensureSchema($pdo);
                Attendance::ensureSchema($pdo);

                $maLHP = Attendance::resolveMaLhpByCourseId($pdo, $courseId);
                if (!$maLHP) {
                    $pdo->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Khong tim thay lop hoc phan.'], 404);
                    return null;
                }

                $currentSessionId = $sessionId;
                if ($currentSessionId > 0) {
                    $current = Attendance::getSessionById($pdo, $currentSessionId);
                    if (!$current || (string)($current['ma_lhp'] ?? '') !== $maLHP) {
                        $pdo->rollBack();
                        jsonResponse(['status' => 'error', 'message' => 'Khong tim thay buoi hoc.'], 404);
                        return null;
                    }
                    $targetDate = $sessionDate !== '' ? $sessionDate : (string)($current['session_date'] ?? '');
                    if ($targetDate === '') {
                        $pdo->rollBack();
                        jsonResponse(['status' => 'error', 'message' => 'Ngay hoc khong hop le.'], 422);
                        return null;
                    }
                    Attendance::updateSession($pdo, $currentSessionId, $targetDate, $sessionNote);
                } else {
                    $existing = Attendance::getSessionByDate($pdo, $maLHP, $sessionDate);
                    if ($existing) {
                        $currentSessionId = (int)$existing['id'];
                        Attendance::updateSession($pdo, $currentSessionId, $sessionDate, $sessionNote);
                    } else {
                        $currentSessionId = Attendance::createSession($pdo, $maLHP, $sessionDate, $sessionNote);
                    }
                }

                $enrolled = Course::getStudentsByCourseId($courseId);
                $allowed = [];
                foreach ($enrolled as $student) {
                    $code = trim((string)($student['student_code'] ?? ''));
                    if ($code !== '') {
                        $allowed[$code] = true;
                    }
                }

                $saveRows = [];
                foreach ($rows as $row) {
                    $code = trim((string)($row['student_code'] ?? ''));
                    if ($code === '' || !isset($allowed[$code])) {
                        continue;
                    }
                    $status = $this->normalizeAttendanceStatus((string)($row['status'] ?? ''));
                    if ($status === '') {
                        $pdo->rollBack();
                        jsonResponse(['status' => 'error', 'message' => 'Trang thai diem danh khong hop le.'], 422);
                        return null;
                    }
                    $saveRows[] = [
                        'student_code' => $code,
                        'status' => $status,
                    ];
                }

                if (!empty($saveRows)) {
                    Attendance::upsertAttendance($pdo, $currentSessionId, $saveRows);
                }

                $pdo->commit();

                return $this->composeAttendancePayload($pdo, $courseId, $course, $currentSessionId);
            });

            if ($payload === null) {
                return;
            }
            jsonResponse($payload);
        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => 'Khong the luu diem danh.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function gradeSheet()
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

        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ mĂ´n há»c.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y mĂ´n há»c.'], 404);
            return;
        }
        if ((string)$course['teacher_code'] !== (string)$identity['login_id']) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $students = Course::getStudentsByCourseId($courseId);
        $scoreMap = Course::getScoresByCourseId($courseId);
        $wCc = (float)($course['weight_cc'] ?? 0);
        $wGk = (float)($course['weight_gk'] ?? 0);
        $wCk = (float)($course['weight_ck'] ?? 0);

        $items = [];
        foreach ($students as $student) {
            $code = (string)$student['student_code'];
            $score = $scoreMap[$code] ?? ['cc' => null, 'gk' => null, 'ck' => null];
            $items[] = [
                'student_code' => $code,
                'full_name' => $student['full_name'] ?? '',
                'class_name' => $student['class_name'] ?? '',
                'cc' => $score['cc'],
                'gk' => $score['gk'],
                'ck' => $score['ck'],
                'total' => $this->calculateTotal($score['cc'], $score['gk'], $score['ck'], $wCc, $wGk, $wCk),
            ];
        }

        jsonResponse([
            'status' => 'success',
            'data' => [
                'id' => (int)$course['id'],
                'course_code' => $course['course_code'] ?? '',
                'course_name' => $course['course_name'] ?? '',
                'teacher_name' => $course['teacher_name'] ?? '',
                'credits' => $course['credits'],
                'weight_cc' => $wCc,
                'weight_gk' => $wGk,
                'weight_ck' => $wCk,
            ],
            'students' => $items,
        ]);
    }

    public function saveGrades()
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

        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dá»¯ liá»‡u khĂ´ng há»£p lá»‡.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ mĂ´n há»c.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y mĂ´n há»c.'], 404);
            return;
        }
        if ((string)$course['teacher_code'] !== (string)$identity['login_id']) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        $wCc = $this->parseWeight($payload['weight_cc'] ?? 0);
        $wGk = $this->parseWeight($payload['weight_gk'] ?? 0);
        $wCk = $this->parseWeight($payload['weight_ck'] ?? 0);
        if ($wCc < 0 || $wGk < 0 || $wCk < 0) {
            jsonResponse(['status' => 'error', 'message' => 'Tá»· lá»‡ Ä‘iá»ƒm khĂ´ng Ä‘Æ°á»£c Ă¢m.'], 422);
            return;
        }
        if (($wCc + $wGk + $wCk) <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Tá»•ng tá»· lá»‡ Ä‘iá»ƒm pháº£i lá»›n hÆ¡n 0.'], 422);
            return;
        }

        $rows = isset($payload['scores']) && is_array($payload['scores']) ? $payload['scores'] : [];
        $enrolled = Course::getStudentsByCourseId($courseId);
        $allowed = [];
        foreach ($enrolled as $st) {
            $allowed[(string)$st['student_code']] = true;
        }

        $saveRows = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['student_code'] ?? ''));
            if ($code === '' || !isset($allowed[$code])) {
                continue;
            }
            $cc = $this->parseScore($row['cc'] ?? null);
            $gk = $this->parseScore($row['gk'] ?? null);
            $ck = $this->parseScore($row['ck'] ?? null);
            foreach (['cc' => $cc, 'gk' => $gk, 'ck' => $ck] as $k => $v) {
                if ($v !== null && ($v < 0 || $v > 10)) {
                    jsonResponse(['status' => 'error', 'message' => "Äiá»ƒm $k cá»§a $code pháº£i trong khoáº£ng 0-10."], 422);
                    return;
                }
            }
            $saveRows[] = [
                'student_code' => $code,
                'cc' => $cc,
                'gk' => $gk,
                'ck' => $ck,
            ];
        }

        try {
            $pdo = get_db_connection();
            $pdo->beginTransaction();
            Course::ensureSchema($pdo);
            Course::updateWeightsWithPdo($pdo, $courseId, $wCc, $wGk, $wCk);
            Course::upsertScoresWithPdo($pdo, $courseId, $saveRows);
            $pdo->commit();

            jsonResponse(['status' => 'success', 'message' => 'LÆ°u Ä‘iá»ƒm thĂ nh cĂ´ng.']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ lÆ°u Ä‘iá»ƒm.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function studentScoreList()
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

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $studentCode = (string)$identity['login_id'];
        $rows = Course::listScoresByStudentCode($studentCode, [
            'ma_hoc_ky' => isset($_GET['ma_hoc_ky']) ? trim((string)$_GET['ma_hoc_ky']) : '',
        ]);
        $items = [];
        foreach ($rows as $row) {
            $wCc = (float)($row['weight_cc'] ?? 0);
            $wGk = (float)($row['weight_gk'] ?? 0);
            $wCk = (float)($row['weight_ck'] ?? 0);
            $cc = $row['cc'] !== null ? (float)$row['cc'] : null;
            $gk = $row['gk'] !== null ? (float)$row['gk'] : null;
            $ck = $row['ck'] !== null ? (float)$row['ck'] : null;
            $total = $this->calculateTotal($cc, $gk, $ck, $wCc, $wGk, $wCk);
            $items[] = [
                'id' => (int)$row['id'],
                'course_code' => $row['course_code'] ?? '',
                'course_name' => $row['course_name'] ?? '',
                'credits' => $row['credits'],
                'teacher_name' => $row['teacher_name'] ?? ($row['teacher_code'] ?? ''),
                'score' => $total,
                'letter' => $this->letterGrade($total),
            ];
        }

        jsonResponse($items);
    }

    public function studentScoreDetail()
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

        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ mĂ´n há»c.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $studentCode = (string)$identity['login_id'];
        $row = Course::getScoreDetailForStudent($courseId, $studentCode);
        if (!$row) {
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y dá»¯ liá»‡u Ä‘iá»ƒm.'], 404);
            return;
        }

        $wCc = (float)($row['weight_cc'] ?? 0);
        $wGk = (float)($row['weight_gk'] ?? 0);
        $wCk = (float)($row['weight_ck'] ?? 0);
        $cc = $row['cc'] !== null ? (float)$row['cc'] : null;
        $gk = $row['gk'] !== null ? (float)$row['gk'] : null;
        $ck = $row['ck'] !== null ? (float)$row['ck'] : null;
        $total = $this->calculateTotal($cc, $gk, $ck, $wCc, $wGk, $wCk);

        jsonResponse([
            'status' => 'success',
            'data' => [
                'id' => (int)$row['id'],
                'course_code' => $row['course_code'] ?? '',
                'course_name' => $row['course_name'] ?? '',
                'teacher_name' => $row['teacher_name'] ?? ($row['teacher_code'] ?? ''),
                'credits' => $row['credits'],
                'weight_cc' => $wCc,
                'weight_gk' => $wGk,
                'weight_ck' => $wCk,
                'student_code' => $row['student_code'] ?? '',
                'student_name' => $row['student_name'] ?? '',
                'class_name' => $row['class_name'] ?? '',
                'cc' => $cc,
                'gk' => $gk,
                'ck' => $ck,
                'score' => $total,
                'letter' => $this->letterGrade($total),
            ],
        ]);
    }

    public function updateEnrollmentStatus()
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

        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        $status = strtoupper(trim((string)($payload['enrollment_status'] ?? '')));
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop hoc.'], 422);
            return;
        }
        if (!in_array($status, ['DRAFT', 'OPEN', 'CLOSED', 'LOCKED'], true)) {
            jsonResponse(['status' => 'error', 'message' => 'Trang thai dang ky khong hop le.'], 422);
            return;
        }

        $current = Course::findById($courseId);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay lop hoc.'], 404);
            return;
        }

        $fromStatus = strtoupper(trim((string)($current['enrollment_status'] ?? 'DRAFT')));
        if (!$this->canTransitEnrollmentStatus($fromStatus, $status)) {
            jsonResponse([
                'status' => 'error',
                'message' => sprintf('Khong the chuyen trang thai tu %s sang %s.', $fromStatus, $status),
            ], 409);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            $pdo->beginTransaction();
            $ok = Course::updateEnrollmentStatusWithPdo($pdo, $courseId, $status);
            if (!$ok) {
                $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Khong tim thay lop hoc.'], 404);
                return;
            }
            $pdo->commit();
            $course = Course::findById($courseId);
            jsonResponse(['status' => 'success', 'data' => $course]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the cap nhat trang thai dang ky.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function failRateReport()
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

        $maHocKy = trim((string)($_GET['ma_hoc_ky'] ?? ''));
        if ($maHocKy === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma hoc ky.'], 422);
            return;
        }

        $thresholdRaw = trim((string)($_GET['threshold'] ?? '4'));
        $threshold = is_numeric($thresholdRaw) ? (float)$thresholdRaw : 4.0;
        if ($threshold < 0 || $threshold > 10) {
            jsonResponse(['status' => 'error', 'message' => 'Threshold phai trong khoang 0-10.'], 422);
            return;
        }

        Course::ensureSchema();
        $items = Course::reportFailRateBySemester($maHocKy, $threshold);
        $totalSections = count($items);
        $totalStudents = 0;
        $totalFailed = 0;
        foreach ($items as $item) {
            $totalStudents += (int)($item['so_sv'] ?? 0);
            $totalFailed += (int)($item['so_rot'] ?? 0);
        }
        $avgFailRate = $totalStudents > 0 ? round(($totalFailed / $totalStudents) * 100, 2) : 0.0;

        jsonResponse([
            'status' => 'success',
            'data' => [
                'ma_hoc_ky' => $maHocKy,
                'threshold' => $threshold,
                'summary' => [
                    'total_lop_hoc_phan' => $totalSections,
                    'total_sinh_vien' => $totalStudents,
                    'total_rot' => $totalFailed,
                    'avg_fail_rate' => $avgFailRate,
                ],
                'items' => $items,
            ],
        ]);
    }
}

