<?php
require_once __DIR__ . '/../models/Course.php';
require_once __DIR__ . '/../models/Teacher.php';
require_once __DIR__ . '/../models/Student.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../helpers/response.php';

class CourseController
{
    private function createMeta(PDO $pdo): array
    {
        $departments = [];
        $deptRows = $pdo->query('SELECT MaKhoa, TenKhoa FROM Khoa ORDER BY MaKhoa ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($deptRows as $row) {
            $departments[] = [
                'code' => trim((string)($row['MaKhoa'] ?? '')),
                'name' => trim((string)($row['TenKhoa'] ?? '')),
            ];
        }

        $teachers = [];
        $teacherRows = $pdo->query(
            'SELECT g.MaGV, g.HoTen, g.MaNganh, k.TenKhoa, g.TrangThai
             FROM GiangVien g
             LEFT JOIN Khoa k ON k.MaKhoa = g.MaNganh
             ORDER BY g.MaGV ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($teacherRows as $row) {
            $teachers[] = [
                'teacher_code' => trim((string)($row['MaGV'] ?? '')),
                'full_name' => trim((string)($row['HoTen'] ?? '')),
                'department_code' => trim((string)($row['MaNganh'] ?? '')),
                'department_name' => trim((string)($row['TenKhoa'] ?? '')),
                'status' => trim((string)($row['TrangThai'] ?? '')),
            ];
        }

        return [
            'departments' => $departments,
            'teachers' => $teachers,
        ];
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

    private function splitMultiValues(string $raw): array
    {
        $items = array_map('trim', explode(',', $raw));
        return array_values(array_filter($items, static fn($v) => $v !== ''));
    }

    private function parseScheduleItem(string $item): ?array
    {
        $raw = strtoupper(trim($item));
        if (!preg_match('/^T([2-8])-\((\d{1,2})-(\d{1,2})\)$/u', $raw, $m)) {
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

        $rows = Course::searchForStaff([]);
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
            return [[], 'KhĂ´ng thá»ƒ Ä‘á»c file danh sĂ¡ch sinh viĂªn.', []];
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            return [[], 'File .xls chua ho tro. Hay luu duoi dang .xlsx hoac .csv roi tai len.', []];
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return [[], 'KhĂ´ng tĂ¬m tháº¥y file táº£i lĂªn.', []];
        }

        try {
            if ($ext === 'xlsx') {
                $rawRows = $this->parseXlsxRows($tmp);
            } else {
                $rawRows = $this->parseCsvRows($tmp);
            }
            return $this->extractStudentCodesFromTableRows($rawRows);
        } catch (Throwable $e) {
            return [[], 'KhĂ´ng Ä‘á»c Ä‘Æ°á»£c dá»¯ liá»‡u file danh sĂ¡ch sinh viĂªn.', []];
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
            throw new RuntimeException('KhĂ´ng má»Ÿ Ä‘Æ°á»£c file xlsx.');
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
            throw new RuntimeException('KhĂ´ng Ä‘á»c Ä‘Æ°á»£c dá»¯ liá»‡u worksheet.');
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
            'department' => ['khoa', 'vien', 'khoavien', 'bomon', 'khoabomon', 'department'],
            'schedule' => ['lichhoc', 'schedule'],
            'classroom' => ['phonghoc', 'classroom'],
            'max_students' => ['soluongtoida', 'maxstudents'],
            'semester' => ['hocky', 'semester'],
            'academic_year' => ['namhoc', 'academicyear'],
            'section_code' => ['malophocphan', 'malhp', 'sectioncode'],
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

        if (count($headers) >= 8) {
            $defaultMap = [
                'course_code' => 0,
                'course_name' => 1,
                'credits' => 2,
                'teacher_code' => 3,
                'department' => 4,
                'schedule' => 5,
                'classroom' => 6,
                'max_students' => 7,
                'semester' => 8,
                'academic_year' => 9,
                'section_code' => 10,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['course_code', 'course_name', 'teacher_code'] as $requiredField) {
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
                'course_code' => trim((string)($source[$headerMap['course_code']] ?? '')),
                'course_name' => trim((string)($source[$headerMap['course_name']] ?? '')),
                'credits' => trim((string)($source[$headerMap['credits'] ?? -1] ?? '')),
                'teacher_code' => trim((string)($source[$headerMap['teacher_code']] ?? '')),
                'department' => trim((string)($source[$headerMap['department'] ?? -1] ?? '')),
                'schedule' => trim((string)($source[$headerMap['schedule'] ?? -1] ?? '')),
                'classroom' => trim((string)($source[$headerMap['classroom'] ?? -1] ?? '')),
                'max_students' => trim((string)($source[$headerMap['max_students'] ?? -1] ?? '')),
                'semester' => trim((string)($source[$headerMap['semester'] ?? -1] ?? '')),
                'academic_year' => trim((string)($source[$headerMap['academic_year'] ?? -1] ?? '')),
                'section_code' => trim((string)($source[$headerMap['section_code'] ?? -1] ?? '')),
            ];

            if ($row['course_code'] === '' && $row['course_name'] === '' && $row['teacher_code'] === '') {
                continue;
            }
            if ($row['course_code'] === '' || $row['course_name'] === '' || $row['teacher_code'] === '') {
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

    private function mapSubjectImportRows(array $rawRows): array
    {
        if (count($rawRows) < 2) {
            return ['rows' => [], 'skipped' => []];
        }

        $headers = $rawRows[0];
        $headerMap = [];
        $headerAlias = [
            'course_code' => ['mamonhoc', 'mamon', 'coursecode'],
            'course_name' => ['tenmonhoc', 'tenmon', 'coursename'],
            'credits' => ['sotinchi', 'credits'],
            'course_type' => ['loaimon', 'coursetype'],
            'department' => ['manganh', 'nganh', 'department', 'departmentcode'],
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

        if (count($headers) >= 5) {
            $defaultMap = [
                'course_code' => 0,
                'course_name' => 1,
                'credits' => 2,
                'course_type' => 3,
                'department' => 4,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['course_code', 'course_name', 'credits', 'course_type', 'department'] as $requiredField) {
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
                'course_code' => strtoupper(trim((string)($source[$headerMap['course_code']] ?? ''))),
                'course_name' => trim((string)($source[$headerMap['course_name']] ?? '')),
                'credits' => trim((string)($source[$headerMap['credits']] ?? '')),
                'course_type' => trim((string)($source[$headerMap['course_type']] ?? '')),
                'department' => trim((string)($source[$headerMap['department']] ?? '')),
            ];

            if (
                $row['course_code'] === '' &&
                $row['course_name'] === '' &&
                $row['credits'] === '' &&
                $row['course_type'] === '' &&
                $row['department'] === ''
            ) {
                continue;
            }

            if (
                $row['course_code'] === '' ||
                $row['course_name'] === '' ||
                $row['credits'] === '' ||
                $row['course_type'] === '' ||
                $row['department'] === ''
            ) {
                $skipped[] = ['line' => $line, 'course_code' => $row['course_code'], 'reason' => 'Thieu truong bat buoc'];
                continue;
            }

            $typeRaw = strtoupper($row['course_type']);
            if (in_array($typeRaw, ['BAT_BUOC', 'BATBUOC', 'Báº®TBUá»˜C', 'Báº®T BUá»˜C'], true)) {
                $row['course_type'] = 'BAT_BUOC';
            } elseif (in_array($typeRaw, ['TU_CHON', 'TUCHON', 'Tá»°CHá»ŒN', 'Tá»° CHá»ŒN'], true)) {
                $row['course_type'] = 'TU_CHON';
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

    private function normalizeSemesterTerm(string $value): ?int
    {
        $raw = strtoupper(trim($value));
        if ($raw === '') return null;
        $ascii = $raw;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
            if ($converted !== false) {
                $ascii = $converted;
            }
        }
        $ascii = strtoupper($ascii);
        $ascii = preg_replace('/[^A-Z0-9 ]+/', ' ', $ascii);
        $ascii = preg_replace('/\s+/', ' ', trim((string)$ascii));

        if (in_array($ascii, ['1', 'I', 'KI I', 'KY I'], true)) return 1;
        if (in_array($ascii, ['2', 'II', 'KI II', 'KY II'], true)) return 2;
        if (in_array($ascii, ['3', 'KI HE', 'KY HE', 'HE'], true)) return 3;
        return null;
    }

    private function semesterLabelFromInt(?int $semester): string
    {
        if ($semester === 1) return 'I';
        if ($semester === 2) return 'II';
        if ($semester === 3) return 'Ká»³ hĂ¨';
        return '-';
    }

    private function mapSectionLiteImportRows(array $rawRows): array
    {
        if (count($rawRows) < 2) {
            return ['rows' => [], 'skipped' => []];
        }

        $headers = $rawRows[0];
        $headerMap = [];
        $headerAlias = [
            'section_code' => ['mahocphan', 'malhp', 'malophocphan', 'sectioncode'],
            'course_code' => ['mamon', 'mamonhoc', 'coursecode'],
            'teacher_code' => ['msgv', 'magiangvien', 'giangvien', 'teachercode'],
            'semester' => ['hocky', 'semester'],
            'academic_year' => ['namhoc', 'academicyear'],
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
            $defaultMap = [
                'section_code' => 0,
                'course_code' => 1,
                'teacher_code' => 2,
                'semester' => 3,
                'academic_year' => 4,
                'max_students' => 5,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['section_code', 'course_code', 'teacher_code', 'semester', 'academic_year', 'max_students'] as $requiredField) {
            if (!isset($headerMap[$requiredField])) {
                throw new RuntimeException('File thieu cot bat buoc: ' . $requiredField);
            }
        }

        $rows = [];
        $skipped = [];
        $seenSection = [];
        for ($i = 1; $i < count($rawRows); $i++) {
            $line = $i + 1;
            $source = $rawRows[$i];
            $row = [
                'section_code' => strtoupper(trim((string)($source[$headerMap['section_code']] ?? ''))),
                'course_code' => strtoupper(trim((string)($source[$headerMap['course_code']] ?? ''))),
                'teacher_code' => trim((string)($source[$headerMap['teacher_code']] ?? '')),
                'semester_term' => trim((string)($source[$headerMap['semester']] ?? '')),
                'academic_year' => trim((string)($source[$headerMap['academic_year']] ?? '')),
                'max_students' => trim((string)($source[$headerMap['max_students']] ?? '')),
            ];

            if (
                $row['section_code'] === '' &&
                $row['course_code'] === '' &&
                $row['teacher_code'] === '' &&
                $row['semester_term'] === '' &&
                $row['academic_year'] === '' &&
                $row['max_students'] === ''
            ) {
                continue;
            }

            if (
                $row['section_code'] === '' ||
                $row['course_code'] === '' ||
                $row['teacher_code'] === '' ||
                $row['semester_term'] === '' ||
                $row['academic_year'] === '' ||
                $row['max_students'] === ''
            ) {
                $skipped[] = ['line' => $line, 'section_code' => $row['section_code'], 'reason' => 'Thieu truong bat buoc'];
                continue;
            }

            $key = strtolower($row['section_code']);
            if (isset($seenSection[$key])) {
                $skipped[] = ['line' => $line, 'section_code' => $row['section_code'], 'reason' => 'Trung trong file'];
                continue;
            }
            $seenSection[$key] = true;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function createSectionLiteWithPdo(PDO $pdo, array $payload): array
    {
        $sectionCode = strtoupper(trim((string)($payload['section_code'] ?? '')));
        $courseCode = strtoupper(trim((string)($payload['course_code'] ?? '')));
        $teacherCode = trim((string)($payload['teacher_code'] ?? ''));
        $semesterTerm = trim((string)($payload['semester_term'] ?? ''));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $maxStudentsRaw = trim((string)($payload['max_students'] ?? ''));

        if ($sectionCode === '') throw new RuntimeException('Thiáº¿u mĂ£ há»c pháº§n.');
        if (!preg_match('/^[A-Z0-9._-]{3,30}$/', $sectionCode)) throw new RuntimeException('MĂ£ há»c pháº§n khĂ´ng há»£p lá»‡.');
        if ($courseCode === '') throw new RuntimeException('Thiáº¿u mĂ£ mĂ´n.');
        if ($teacherCode === '') throw new RuntimeException('Thiáº¿u mĂ£ giáº£ng viĂªn.');
        if ($academicYear === '' || !preg_match('/^\d{4}\s*-\s*\d{4}$/', $academicYear)) throw new RuntimeException('NÄƒm há»c khĂ´ng há»£p lá»‡.');
        if ($maxStudentsRaw === '' || !ctype_digit($maxStudentsRaw) || (int)$maxStudentsRaw <= 0) throw new RuntimeException('Sá»‘ lÆ°á»£ng tá»‘i Ä‘a pháº£i lĂ  sá»‘ nguyĂªn dÆ°Æ¡ng.');

        $semester = $this->normalizeSemesterTerm($semesterTerm);
        if ($semester === null) throw new RuntimeException('Há»c ká»³ chá»‰ nháº­n I, II hoáº·c Ká»³ hĂ¨.');

        $existsSection = $pdo->prepare('SELECT 1 FROM LopHocPhan WHERE upper(MaLHP) = :ma_lhp LIMIT 1');
        $existsSection->execute([':ma_lhp' => $sectionCode]);
        if ($existsSection->fetchColumn()) throw new RuntimeException('MĂ£ há»c pháº§n Ä‘Ă£ tá»“n táº¡i.');

        $subject = Course::findSubjectByCode($courseCode);
        if (!$subject) throw new RuntimeException('KhĂ´ng tĂ¬m tháº¥y mĂ£ mĂ´n há»c.');

        $teacher = Teacher::findByTeacherCode($teacherCode);
        if (!$teacher) throw new RuntimeException('KhĂ´ng tĂ¬m tháº¥y mĂ£ giáº£ng viĂªn.');

        $ins = $pdo->prepare(
            'INSERT INTO LopHocPhan (MaLHP, MaMon, MaGV, HocKy, NamHoc, SoLuongToiDa, TrongSoCC, TrongSoGK, TrongSoCK, CreatedAt)
             VALUES (:ma_lhp, :ma_mon, :ma_gv, :hoc_ky, :nam_hoc, :max, 0, 0, 0, CURRENT_TIMESTAMP)'
        );
        $ins->execute([
            ':ma_lhp' => $sectionCode,
            ':ma_mon' => $courseCode,
            ':ma_gv' => $teacherCode,
            ':hoc_ky' => $semester,
            ':nam_hoc' => $academicYear,
            ':max' => (int)$maxStudentsRaw,
        ]);

        $stmtMap = $pdo->prepare('INSERT OR IGNORE INTO LopHocPhanMap (MaLHP) VALUES (:ma)');
        $stmtMap->execute([':ma' => $sectionCode]);
        $stmtMap = $pdo->prepare('SELECT LegacyId FROM LopHocPhanMap WHERE MaLHP = :ma LIMIT 1');
        $stmtMap->execute([':ma' => $sectionCode]);
        $id = (int)$stmtMap->fetchColumn();

        return [
            'id' => $id,
            'section_code' => $sectionCode,
            'course_code' => $courseCode,
            'course_name' => (string)($subject['course_name'] ?? ''),
            'credits' => (int)($subject['credits'] ?? 0),
            'teacher_code' => $teacherCode,
            'teacher_name' => (string)($teacher['full_name'] ?? ''),
            'semester' => $semester,
            'semester_label' => $this->semesterLabelFromInt($semester),
            'academic_year' => $academicYear,
            'max_students' => (int)$maxStudentsRaw,
        ];
    }

    private function updateSectionLiteWithPdo(PDO $pdo, int $id, array $payload): array
    {
        if ($id <= 0) throw new RuntimeException('Thiáº¿u mĂ£ há»c pháº§n.');

        $sectionCode = trim((string)($payload['section_code'] ?? ''));
        $courseCode = strtoupper(trim((string)($payload['course_code'] ?? '')));
        $teacherCode = trim((string)($payload['teacher_code'] ?? ''));
        $semesterTerm = trim((string)($payload['semester_term'] ?? ''));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $maxStudentsRaw = trim((string)($payload['max_students'] ?? ''));

        if ($sectionCode === '') throw new RuntimeException('Thiáº¿u mĂ£ há»c pháº§n.');
        if ($courseCode === '') throw new RuntimeException('Thiáº¿u mĂ£ mĂ´n.');
        if ($teacherCode === '') throw new RuntimeException('Thiáº¿u mĂ£ giáº£ng viĂªn.');
        if ($academicYear === '' || !preg_match('/^\d{4}\s*-\s*\d{4}$/', $academicYear)) throw new RuntimeException('NÄƒm há»c khĂ´ng há»£p lá»‡.');
        if ($maxStudentsRaw === '' || !ctype_digit($maxStudentsRaw) || (int)$maxStudentsRaw <= 0) throw new RuntimeException('Sá»‘ lÆ°á»£ng tá»‘i Ä‘a pháº£i lĂ  sá»‘ nguyĂªn dÆ°Æ¡ng.');

        $semester = $this->normalizeSemesterTerm($semesterTerm);
        if ($semester === null) throw new RuntimeException('Há»c ká»³ chá»‰ nháº­n I, II hoáº·c Ká»³ hĂ¨.');

        $subject = Course::findSubjectByCode($courseCode);
        if (!$subject) throw new RuntimeException('KhĂ´ng tĂ¬m tháº¥y mĂ£ mĂ´n há»c.');

        $teacher = Teacher::findByTeacherCode($teacherCode);
        if (!$teacher) throw new RuntimeException('KhĂ´ng tĂ¬m tháº¥y mĂ£ giáº£ng viĂªn.');

        $stmtMap = $pdo->prepare('SELECT MaLHP FROM LopHocPhanMap WHERE LegacyId = :id LIMIT 1');
        $stmtMap->execute([':id' => $id]);
        $maLhp = (string)$stmtMap->fetchColumn();
        if ($maLhp === '') throw new RuntimeException('KhĂ´ng tĂ¬m tháº¥y lá»›p há»c pháº§n.');

        $up = $pdo->prepare(
            'UPDATE LopHocPhan
             SET MaMon = :ma_mon,
                 MaGV = :ma_gv,
                 HocKy = :hoc_ky,
                 NamHoc = :nam_hoc,
                 SoLuongToiDa = :max
             WHERE MaLHP = :ma_lhp'
        );
        $up->execute([
            ':ma_mon' => $courseCode,
            ':ma_gv' => $teacherCode,
            ':hoc_ky' => $semester,
            ':nam_hoc' => $academicYear,
            ':max' => (int)$maxStudentsRaw,
            ':ma_lhp' => $maLhp,
        ]);

        return [
            'id' => $id,
            'section_code' => $maLhp,
            'course_code' => $courseCode,
            'course_name' => (string)($subject['course_name'] ?? ''),
            'credits' => (int)($subject['credits'] ?? 0),
            'teacher_code' => $teacherCode,
            'teacher_name' => (string)($teacher['full_name'] ?? ''),
            'semester' => $semester,
            'semester_label' => $this->semesterLabelFromInt($semester),
            'academic_year' => $academicYear,
            'max_students' => (int)$maxStudentsRaw,
        ];
    }

    private function validatePayload(array $payload, ?PDO $pdo = null, string $ignoreSectionCode = ''): array
    {
        $courseCode = trim((string)($payload['course_code'] ?? ''));
        $courseName = trim((string)($payload['course_name'] ?? ''));
        $teacherCode = trim((string)($payload['teacher_code'] ?? ''));
        $department = trim((string)($payload['department'] ?? ''));
        $schedule = strtoupper(trim((string)($payload['schedule'] ?? '')));
        $classroom = strtoupper(trim((string)($payload['classroom'] ?? '')));
        $semesterRaw = trim((string)($payload['semester'] ?? ''));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $sectionCode = strtoupper(trim((string)($payload['section_code'] ?? '')));

        $creditsRaw = trim((string)($payload['credits'] ?? ''));
        $maxStudentsRaw = trim((string)($payload['max_students'] ?? ''));

        $errors = [];
        if ($courseCode === '') $errors['course_code'] = 'HÄ‚Â£y nhĂ¡ÂºÂ­p mÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc.';
        if ($courseName === '') $errors['course_name'] = 'HÄ‚Â£y nhĂ¡ÂºÂ­p tÄ‚Âªn mÄ‚Â´n hĂ¡Â»Âc.';
        if ($teacherCode === '') $errors['teacher_code'] = 'HÄ‚Â£y nhĂ¡ÂºÂ­p mÄ‚Â£ giÄ‚Â¡o viÄ‚Âªn.';

        $credits = null;
        if ($creditsRaw !== '') {
            if (!ctype_digit($creditsRaw)) {
                $errors['credits'] = 'SĂ¡Â»â€˜ tÄ‚Â­n chĂ¡Â»â€° phĂ¡ÂºÂ£i lÄ‚Â  sĂ¡Â»â€˜ nguyÄ‚Âªn dĂ†Â°Ă†Â¡ng.';
            } else {
                $credits = (int)$creditsRaw;
                if ($credits <= 0) {
                    $errors['credits'] = 'SĂ¡Â»â€˜ tÄ‚Â­n chĂ¡Â»â€° phĂ¡ÂºÂ£i lĂ¡Â»â€ºn hĂ†Â¡n 0.';
                }
            }
        }

        $maxStudents = null;
        if ($maxStudentsRaw !== '') {
            if (!ctype_digit($maxStudentsRaw)) {
                $errors['max_students'] = 'SĂ¡Â»â€˜ lĂ†Â°Ă¡Â»Â£ng tĂ¡Â»â€˜i Ă„â€˜a phĂ¡ÂºÂ£i lÄ‚Â  sĂ¡Â»â€˜ nguyÄ‚Âªn dĂ†Â°Ă†Â¡ng.';
            } else {
                $maxStudents = (int)$maxStudentsRaw;
                if ($maxStudents <= 0) {
                    $errors['max_students'] = 'SĂ¡Â»â€˜ lĂ†Â°Ă¡Â»Â£ng tĂ¡Â»â€˜i Ă„â€˜a phĂ¡ÂºÂ£i lĂ¡Â»â€ºn hĂ†Â¡n 0.';
                }
            }
        }

        $semester = null;
        if ($semesterRaw !== '') {
            $semester = $this->normalizeSemesterTerm($semesterRaw);
            if ($semester === null) {
                $errors['semester'] = 'Học kỳ chỉ nhận Kì I, Kì II hoặc Kì hè.';
            }
        }

        if ($academicYear !== '' && !preg_match('/^\d{4}\s*-\s*\d{4}$/', $academicYear)) {
            $errors['academic_year'] = 'NÄƒm há»c pháº£i Ä‘Ăºng dáº¡ng 2026-2027.';
        }

        if ($sectionCode !== '' && !preg_match('/^[A-Z0-9._-]{3,30}$/', $sectionCode)) {
            $errors['section_code'] = 'MĂ£ lá»›p há»c pháº§n chá»‰ gá»“m chá»¯ hoa, sá»‘, ".", "_" hoáº·c "-".';
        }
        if ($sectionCode !== '' && $pdo && ($ignoreSectionCode === '' || strcasecmp($ignoreSectionCode, $sectionCode) !== 0)) {
            $stmt = $pdo->prepare('SELECT 1 FROM LopHocPhan WHERE upper(MaLHP) = :ma_lhp LIMIT 1');
            $stmt->execute([':ma_lhp' => strtoupper($sectionCode)]);
            if ($stmt->fetchColumn()) {
                $errors['section_code'] = 'MĂ£ lá»›p há»c pháº§n Ä‘Ă£ tá»“n táº¡i.';
            }
        }

        if ($schedule !== '') {
            $scheduleItems = $this->splitMultiValues($schedule);
            foreach ($scheduleItems as $scheduleItem) {
                if (!preg_match('/^T([2-8])-\((\d{1,2})-(\d{1,2})\)$/u', strtoupper($scheduleItem), $m)) {
                    $errors['schedule'] = 'LĂ¡Â»â€¹ch hĂ¡Â»Âc phĂ¡ÂºÂ£i Ă„â€˜Ä‚Âºng dĂ¡ÂºÂ¡ng T2-(1-3), cÄ‚Â³ thĂ¡Â»Æ’ nhiĂ¡Â»Âu giÄ‚Â¡ trĂ¡Â»â€¹ ngĂ„Æ’n cÄ‚Â¡ch bĂ¡Â»Å¸i dĂ¡ÂºÂ¥u phĂ¡ÂºÂ©y.';
                    break;
                }
                $start = (int)$m[2];
                $end = (int)$m[3];
                if ($start <= 0 || $end <= 0 || $start > $end) {
                    $errors['schedule'] = 'TiĂ¡ÂºÂ¿t hĂ¡Â»Âc khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡. VÄ‚Â­ dĂ¡Â»Â¥ Ă„â€˜Ä‚Âºng: T2-(1-3).';
                    break;
                }
            }
        }

        if ($classroom !== '') {
            $classroomItems = $this->splitMultiValues($classroom);
            foreach ($classroomItems as $classroomItem) {
                if (!preg_match('/^\d{3}T\d{1,2}$/', strtoupper($classroomItem))) {
                    $errors['classroom'] = 'PhÄ‚Â²ng hĂ¡Â»Âc phĂ¡ÂºÂ£i Ă„â€˜Ä‚Âºng dĂ¡ÂºÂ¡ng 502T5, cÄ‚Â³ thĂ¡Â»Æ’ nhiĂ¡Â»Âu giÄ‚Â¡ trĂ¡Â»â€¹ ngĂ„Æ’n cÄ‚Â¡ch bĂ¡Â»Å¸i dĂ¡ÂºÂ¥u phĂ¡ÂºÂ©y.';
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
                $errors['classroom'] = 'Sá»‘ phĂ²ng há»c pháº£i báº±ng 1 hoáº·c báº±ng sá»‘ lá»‹ch há»c.';
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
                    $errors['teacher_code'] = 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y giÄ‚Â¡o viÄ‚Âªn theo mÄ‚Â£ Ă„â€˜Ä‚Â£ nhĂ¡ÂºÂ­p.';
                }
            } catch (Throwable $e) {
                $errors['teacher_code'] = 'KhÄ‚Â´ng kiĂ¡Â»Æ’m tra Ă„â€˜Ă†Â°Ă¡Â»Â£c mÄ‚Â£ giÄ‚Â¡o viÄ‚Âªn lÄ‚Âºc nÄ‚Â y. Vui lÄ‚Â²ng thĂ¡Â»Â­ lĂ¡ÂºÂ¡i.';
            }
        }

        return [[
            'course_code' => $courseCode,
            'course_name' => $courseName,
            'credits' => $credits,
            'teacher_code' => $teacherCode,
            'department' => $department,
            'schedule' => $schedule,
            'classroom' => $classroom,
            'max_students' => $maxStudents,
            'semester' => $semester,
            'academic_year' => $academicYear,
            'section_code' => $sectionCode,
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

        if ((string)($_GET['action'] ?? '') === 'meta') {
            if (!$this->isStaff($identity)) {
                jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
                return;
            }
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                Teacher::ensureSchema($pdo);
                jsonResponse(['status' => 'success', 'data' => $this->createMeta($pdo)]);
            } catch (Throwable $e) {
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng táº£i Ä‘Æ°á»£c dá»¯ liá»‡u táº¡o lá»›p há»c.', 'detail' => $e->getMessage()], 500);
            }
            return;
        }

        if ($this->isStaff($identity)) {
            $mode = strtolower(trim((string)($_GET['mode'] ?? '')));
            if ($mode === 'subject') {
                $code = strtoupper(trim((string)($_GET['code'] ?? '')));
                if ($code !== '') {
                    $subject = Course::findSubjectByCode($code);
                    if (!$subject) {
                        jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y mĂ´n há»c.'], 404);
                        return;
                    }
                    jsonResponse(['status' => 'success', 'data' => $subject]);
                    return;
                }
                $rows = Course::searchSubjectsForStaff([
                    'keyword' => isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '',
                    'department' => isset($_GET['department']) ? trim((string)$_GET['department']) : '',
                ]);
                jsonResponse($rows);
                return;
            }

            $rows = Course::searchForStaff([
                'keyword' => isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '',
                'department' => isset($_GET['department']) ? trim((string)$_GET['department']) : '',
                'teacher_code' => isset($_GET['teacher_code']) ? trim((string)$_GET['teacher_code']) : '',
            ]);
            jsonResponse($rows);
            return;
        }

        if ($this->isTeacher($identity)) {
            jsonResponse(Course::listByTeacherCode($loginId));
            return;
        }

        if ($this->isStudent($identity)) {
            jsonResponse(Course::listByStudentCode($loginId));
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
            jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.'], 400);
            return;
        }

        $mode = strtolower(trim((string)($_GET['mode'] ?? ($payload['mode'] ?? ''))));
        if ($mode === 'subject-update') {
            $originalCode = strtoupper(trim((string)($payload['original_code'] ?? '')));
            if ($originalCode === '') {
                $originalCode = strtoupper(trim((string)($payload['course_code'] ?? '')));
            }
            if ($originalCode === '') {
                return;
            }
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                $pdo->beginTransaction();
                $saved = Course::updateSubjectByCodeWithPdo($pdo, $originalCode, $payload);
                $pdo->commit();
                jsonResponse(['status' => 'success', 'data' => $saved]);
                return;
            } catch (RuntimeException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ cáº­p nháº­t mĂ´n há»c.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        if ($mode === 'subject') {
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                $pdo->beginTransaction();
                $saved = Course::insertSubjectWithPdo($pdo, $payload);
                $pdo->commit();
                jsonResponse(['status' => 'success', 'data' => $saved], 201);
                return;
            } catch (RuntimeException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
                return;
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
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ thĂªm mĂ´n há»c.', 'detail' => $msg], 500);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Lá»—i há»‡ thá»‘ng.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        if ($mode === 'section-lite') {
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                Teacher::ensureSchema($pdo);
                $pdo->beginTransaction();
                $saved = $this->createSectionLiteWithPdo($pdo, $payload);
                $pdo->commit();
                jsonResponse(['status' => 'success', 'data' => $saved], 201);
                return;
            } catch (RuntimeException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ táº¡o lá»›p há»c pháº§n.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        if ($mode === 'section-lite-update') {
            $id = (int)($payload['id'] ?? 0);
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                Teacher::ensureSchema($pdo);
                $pdo->beginTransaction();
                $saved = $this->updateSectionLiteWithPdo($pdo, $id, $payload);
                $pdo->commit();
                jsonResponse(['status' => 'success', 'data' => $saved]);
                return;
            } catch (RuntimeException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ cáº­p nháº­t lá»›p há»c pháº§n.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            Teacher::ensureSchema($pdo);
            Student::ensureSchema($pdo);
            [$data, $errors] = $this->validatePayload($payload, $pdo, '');
            if (!empty($errors)) {
                jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.', 'fields' => $errors], 422);
                return;
            }

            $roomConflict = $this->findClassroomConflict($pdo, (string)($data['schedule'] ?? ''), (string)($data['classroom'] ?? ''), null);
            if ($roomConflict) {
                $msg = sprintf(
                    'TrĂ¹ng phĂ²ng há»c vá»›i lá»›p %s (T%s-(%s-%s), phĂ²ng %s).',
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


            // Check for duplicate course code before inserting
            $dupCodeRaw = (string)($data['course_code'] ?? '');
            if ($dupCodeRaw !== '') {
                $dupStmt = $pdo->prepare('SELECT 1 FROM MonHoc WHERE upper(MaMon) = upper(:code) LIMIT 1');
                $dupStmt->execute([':code' => $dupCodeRaw]);
                if ($dupStmt->fetchColumn()) {
                    jsonResponse([
                        'status' => 'error',
                        'message' => 'Ma mon hoc da ton tai.',
                        'fields' => ['course_code' => 'Ma mon hoc da ton tai.'],
                    ], 409);
                    return;
                }
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
                    'message' => 'MÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.',
                    'fields' => ['course_code' => 'MÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng thĂ¡Â»Æ’ tĂ¡ÂºÂ¡o lĂ¡Â»â€ºp hĂ¡Â»Âc.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'LĂ¡Â»â€”i hĂ¡Â»â€¡ thĂ¡Â»â€˜ng.', 'detail' => $e->getMessage()], 500);
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

        $mode = strtolower(trim((string)($_GET['mode'] ?? '')));
        if ($mode === 'subject') {
            $courseCode = trim((string)($_GET['code'] ?? ''));
            if ($courseCode === '') {
                jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ mĂ´n há»c.'], 422);
                return;
            }
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                $pdo->beginTransaction();
                Course::deleteSubjectByCodeWithPdo($pdo, $courseCode);
                $pdo->commit();
                jsonResponse([
                    'status' => 'success',
                    'message' => 'ÄĂ£ xĂ³a mĂ´n há»c thĂ nh cĂ´ng.',
                    'data' => ['course_code' => strtoupper($courseCode)],
                ]);
                return;
            } catch (RuntimeException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ xĂ³a mĂ´n há»c.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ mĂ´n há»c.'], 422);
            return;
        }

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng tĂ¬m tháº¥y mĂ´n há»c.'], 404);
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
                'message' => 'ÄĂ£ xĂ³a mĂ´n há»c thĂ nh cĂ´ng.',
                'data' => ['id' => $courseId, 'course_code' => (string)($course['course_code'] ?? '')],
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ xĂ³a mĂ´n há»c.', 'detail' => $e->getMessage()], 500);
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
        $mode = strtolower(trim((string)($_GET['mode'] ?? '')));
        if ($action === 'preview') {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                jsonResponse(['status' => 'error', 'message' => 'Chua co file du lieu.'], 400);
                return;
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng Ä‘á»c Ä‘Æ°á»£c file táº£i lĂªn.'], 400);
                return;
            }
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            try {
                if ($ext === 'csv') {
                    $rawRows = $this->parseCsvRows((string)$file['tmp_name']);
                } else {
                    $rawRows = $this->parseXlsxRows((string)$file['tmp_name']);
                }
                if ($mode === 'subject') {
                    $mapped = $this->mapSubjectImportRows($rawRows);
                } elseif ($mode === 'section-lite') {
                    $mapped = $this->mapSectionLiteImportRows($rawRows);
                } else {
                    $mapped = $this->mapCourseImportRows($rawRows);
                }
                jsonResponse([
                    'status' => 'success',
                    'rows' => $mapped['rows'],
                    'skipped_in_file' => $mapped['skipped'],
                ]);
            } catch (Throwable $e) {
                jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ Ä‘á»c file import.', 'detail' => $e->getMessage()], 422);
            }
            return;
        }

        $payload = $this->parseJsonPayload();
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        if (empty($rows)) {
            jsonResponse(['status' => 'error', 'message' => 'Không có dữ liệu để import.'], 400);
            return;
        }

        if ($mode === 'section-lite') {
            try {
                $pdo = get_db_connection();
                Course::ensureSchema($pdo);
                Teacher::ensureSchema($pdo);
                $pdo->beginTransaction();

                $inserted = 0;
                $skipped = [];
                foreach ($rows as $idx => $row) {
                    $line = $idx + 2;
                    $data = is_array($row) ? $row : [];
                    try {
                        $this->createSectionLiteWithPdo($pdo, $data);
                        $inserted++;
                    } catch (RuntimeException $e) {
                        $skipped[] = [
                            'line' => $line,
                            'section_code' => trim((string)($data['section_code'] ?? '')),
                            'reason' => $e->getMessage(),
                        ];
                    }
                }

                $pdo->commit();
                jsonResponse([
                    'status' => 'success',
                    'inserted_count' => $inserted,
                    'skipped_count' => count($skipped),
                    'skipped' => $skipped,
                ]);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(['status' => 'error', 'message' => 'Không thể import lớp học phần.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            Teacher::ensureSchema($pdo);
            Student::ensureSchema($pdo);
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
                    $skipped[] = ['line' => $line, 'course_code' => $code, 'reason' => 'MĂ£ mĂ´n há»c Ä‘Ă£ tá»“n táº¡i'];
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
                            'TrĂ¹ng phĂ²ng há»c vá»›i lá»›p %s (T%s-(%s-%s), phĂ²ng %s)',
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
            jsonResponse([
                'status' => 'success',
                'inserted_count' => $inserted,
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
            ]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $msg = (string)$e->getMessage();
            jsonResponse(['status' => 'error', 'message' => 'KhĂ´ng thá»ƒ import lá»›p há»c.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
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
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ lĂ¡Â»â€ºp hĂ¡Â»Âc.'], 422);
            return;
        }

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y mÄ‚Â´n hĂ¡Â»Âc.'], 404);
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
            jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ lĂ¡Â»â€ºp hĂ¡Â»Âc.'], 422);
            return;
        }

        $current = Course::findById($courseId);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y mÄ‚Â´n hĂ¡Â»Âc.'], 404);
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
                jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.', 'fields' => $errors], 422);
                return;
            }

            $roomConflict = $this->findClassroomConflict(
                $pdo,
                (string)($data['schedule'] ?? ''),
                (string)($data['classroom'] ?? ''),
                $courseId
            );
            if ($roomConflict) {
                $msg = sprintf(
                    'TrĂ¹ng phĂ²ng há»c vá»›i lá»›p %s (T%s-(%s-%s), phĂ²ng %s).',
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

            $teacherCode = (string)($data['teacher_code'] ?? '');
            if ($teacherCode !== '') {
                $newSlots = $this->buildScheduleSlots((string)($data['schedule'] ?? ''), '');
                if (!empty($newSlots)) {
                    $teacherCourses = Course::searchForStaff(['teacher_code' => $teacherCode]);
                    foreach ($teacherCourses as $row) {
                        if ((int)($row['id'] ?? 0) === $courseId) {
                            continue;
                        }
                        $existingSlots = $this->buildScheduleSlots((string)($row['schedule'] ?? ''), '');
                        foreach ($newSlots as $newSlot) {
                            foreach ($existingSlots as $oldSlot) {
                                if ((int)$newSlot['day'] !== (int)$oldSlot['day']) {
                                    continue;
                                }
                                if ($this->intervalsOverlap((int)$newSlot['start'], (int)$newSlot['end'], (int)$oldSlot['start'], (int)$oldSlot['end'])) {
                                    $msg = sprintf(
                                        'Giang vien %s da co lich day lop %s vao T%s-(%s-%s).',
                                        $teacherCode,
                                        (string)($row['course_code'] ?? ''),
                                        $oldSlot['day'],
                                        $oldSlot['start'],
                                        $oldSlot['end']
                                    );
                                    jsonResponse([
                                        'status' => 'error',
                                        'message' => $msg,
                                        'fields' => ['teacher_code' => $msg, 'schedule' => $msg],
                                    ], 409);
                                    return;
                                }
                            }
                        }
                    }
                }
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
                        'message' => 'Danh sÄ‚Â¡ch sinh viÄ‚Âªn vĂ†Â°Ă¡Â»Â£t quÄ‚Â¡ sĂ¡Â»â€˜ lĂ†Â°Ă¡Â»Â£ng tĂ¡Â»â€˜i Ă„â€˜a cĂ¡Â»Â§a lĂ¡Â»â€ºp.',
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
                    'message' => 'MÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.',
                    'fields' => ['course_code' => 'MÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng thĂ¡Â»Æ’ cĂ¡ÂºÂ­p nhĂ¡ÂºÂ­t mÄ‚Â´n hĂ¡Â»Âc.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'LĂ¡Â»â€”i hĂ¡Â»â€¡ thĂ¡Â»â€˜ng.', 'detail' => $e->getMessage()], 500);
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
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y mÄ‚Â´n hĂ¡Â»Âc.'], 404);
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
            jsonResponse(['status' => 'error', 'message' => 'DĂ¡Â»Â¯ liĂ¡Â»â€¡u khÄ‚Â´ng hĂ¡Â»Â£p lĂ¡Â»â€¡.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y mÄ‚Â´n hĂ¡Â»Âc.'], 404);
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
            jsonResponse(['status' => 'error', 'message' => 'TĂ¡Â»Â· lĂ¡Â»â€¡ Ă„â€˜iĂ¡Â»Æ’m khÄ‚Â´ng Ă„â€˜Ă†Â°Ă¡Â»Â£c Ä‚Â¢m.'], 422);
            return;
        }
        if (($wCc + $wGk + $wCk) <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'TĂ¡Â»â€¢ng tĂ¡Â»Â· lĂ¡Â»â€¡ Ă„â€˜iĂ¡Â»Æ’m phĂ¡ÂºÂ£i lĂ¡Â»â€ºn hĂ†Â¡n 0.'], 422);
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
                    jsonResponse(['status' => 'error', 'message' => "Ă„ÂiĂ¡Â»Æ’m $k cĂ¡Â»Â§a $code phĂ¡ÂºÂ£i trong khoĂ¡ÂºÂ£ng 0-10."], 422);
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

            jsonResponse(['status' => 'success', 'message' => 'LĂ†Â°u Ă„â€˜iĂ¡Â»Æ’m thÄ‚Â nh cÄ‚Â´ng.']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng thĂ¡Â»Æ’ lĂ†Â°u Ă„â€˜iĂ¡Â»Æ’m.', 'detail' => $e->getMessage()], 500);
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
        $rows = Course::listScoresByStudentCode($studentCode);
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
            jsonResponse(['status' => 'error', 'message' => 'ThiĂ¡ÂºÂ¿u mÄ‚Â£ mÄ‚Â´n hĂ¡Â»Âc.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $studentCode = (string)$identity['login_id'];
        $row = Course::getScoreDetailForStudent($courseId, $studentCode);
        if (!$row) {
            jsonResponse(['status' => 'error', 'message' => 'KhÄ‚Â´ng tÄ‚Â¬m thĂ¡ÂºÂ¥y dĂ¡Â»Â¯ liĂ¡Â»â€¡u Ă„â€˜iĂ¡Â»Æ’m.'], 404);
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
}



