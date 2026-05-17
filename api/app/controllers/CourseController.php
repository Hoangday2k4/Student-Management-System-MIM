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
            'SELECT g.MaGV, g.HoTen, g.MaKhoa, k.TenKhoa, g.TrangThai
             FROM GiangVien g
             LEFT JOIN Khoa k ON k.MaKhoa = g.MaKhoa
             ORDER BY g.MaGV ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($teacherRows as $row) {
            $teachers[] = [
                'teacher_code' => trim((string)($row['MaGV'] ?? '')),
                'full_name' => trim((string)($row['HoTen'] ?? '')),
                'department_code' => trim((string)($row['MaKhoa'] ?? '')),
                'department_name' => trim((string)($row['TenKhoa'] ?? '')),
                'status' => trim((string)($row['TrangThai'] ?? '')),
            ];
        }

        $courses = [];
        $courseRows = $pdo->query('SELECT MaMon, TenMon, SoTinChi FROM MonHoc ORDER BY MaMon ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($courseRows as $row) {
            $courses[] = [
                'course_code' => trim((string)($row['MaMon'] ?? '')),
                'course_name' => trim((string)($row['TenMon'] ?? '')),
                'credits' => trim((string)($row['SoTinChi'] ?? '')),
            ];
        }

        return [
            'departments' => $departments,
            'teachers' => $teachers,
            'courses' => $courses,
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

    private function findTeacherConflict(PDO $pdo, string $scheduleRaw, string $teacherCode, ?int $excludeCourseId = null): ?array
    {
        if ($teacherCode === '' || $scheduleRaw === '') return null;
        $newSlots = $this->buildScheduleSlots($scheduleRaw, '');
        if (empty($newSlots)) return null;

        $rows = Course::searchForStaff(['teacher_code' => $teacherCode]);
        if ($excludeCourseId !== null && $excludeCourseId > 0) {
            $rows = array_values(array_filter($rows, static function ($row) use ($excludeCourseId) {
                return (int)($row['id'] ?? 0) !== $excludeCourseId;
            }));
        }
        foreach ($rows as $row) {
            $existingSlots = $this->buildScheduleSlots((string)($row['schedule'] ?? ''), '');
            foreach ($newSlots as $newSlot) {
                foreach ($existingSlots as $oldSlot) {
                    if ((int)$newSlot['day'] !== (int)$oldSlot['day']) continue;
                    if ($this->intervalsOverlap((int)$newSlot['start'], (int)$newSlot['end'], (int)$oldSlot['start'], (int)$oldSlot['end'])) {
                        return ['course_code' => (string)($row['course_code'] ?? ''), 'day' => (int)$newSlot['day'], 'start' => (int)$newSlot['start'], 'end' => (int)$newSlot['end']];
                    }
                }
            }
        }
        return null;
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
            return [[], 'File danh sách sinh viên không có dữ liệu.', []];
        }

        $headers = $rows[0];
        $headerMap = [];
        
        $headerAlias = [
            'student_code' => ['mssv', 'studentcode', 'student_code', 'masosinhvien', 'masv', 'ma'],
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

        // CHỈ KIỂM TRA MỖI CỘT MSSV
        if (!isset($headerMap['student_code'])) {
            return [[], 'File bắt buộc phải có cột Mã số sinh viên (MSSV). Vui lòng kiểm tra lại.', []];
        }

        $codes = [];
        $seen = [];
        $totalRows = 0;
        $invalidRows = 0;
        $duplicateRows = 0;
        
        for ($i = 1; $i < count($rows); $i++) {
            $source = $rows[$i];
            $studentCode = trim((string)($source[$headerMap['student_code']] ?? ''));

            if (empty(array_filter($source, 'trim'))) {
                continue;
            }
            $totalRows++;
            
            if ($studentCode === '') {
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
            return [[], 'File không có mã sinh viên nào hợp lệ để thêm vào lớp.', [
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
            return [[], 'File .xls chưa hỗ trợ. Hay lưu dưới dạng .xlsx hoặc .csv rồi tải lên.', []];
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
            return [[], 'Không thể đọc được dữ liệu file danh sách sinh viên.', []];
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
        $content = file_get_contents($filePath);
        if ($content === false) return [];
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        $rows = [];
        if (empty($lines)) return $rows;
        $firstLine = $lines[0];
        $delimiter = ',';
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $row = str_getcsv($line, $delimiter, '"', '');
            $rows[] = array_map('trim', $row);
        }
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
            throw new RuntimeException('Không thể mở file xlsx.');
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
            throw new RuntimeException('Không thể đọc được dữ liệu worksheet.');
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
        
        // Check directly for Vietnamese semester terms
        $lowerValue = mb_strtolower(trim($value), 'UTF-8');
        if (in_array($lowerValue, ['kì i', 'ki i', 'học kỳ i', 'hoc ky i', '1', 'i'], true)) return 1;
        if (in_array($lowerValue, ['kì ii', 'ki ii', 'học kỳ ii', 'hoc ky ii', '2', 'ii'], true)) return 2;
        if (in_array($lowerValue, ['kì hè', 'ki he', 'kỳ hè', 'ky he', 'học kỳ hè', 'hoc ky he', '3', 'he', 'hè'], true)) return 3;
        
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
        $semesterTerm = trim((string)($payload['semester_term'] ?? ($payload['semester'] ?? '')));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $departmentCode = trim((string)($payload['department'] ?? ''));
        $maxStudentsRaw = trim((string)($payload['max_students'] ?? ''));

        if ($sectionCode === '') throw new RuntimeException('Thiếu mã học phần.');
        if (!preg_match('/^[A-Z0-9._-]{3,30}$/', $sectionCode)) throw new RuntimeException('Mã học phần không hợp lệ.');
        if ($courseCode === '') throw new RuntimeException('Thiếu mã môn.');
        if ($teacherCode === '') throw new RuntimeException('Thiếu mã giảng viên.');
        if ($academicYear === '' || !preg_match('/^\d{4}\s*-\s*\d{4}$/', $academicYear)) throw new RuntimeException('Năm học không hợp lệ.');
        if ($maxStudentsRaw === '' || !ctype_digit($maxStudentsRaw) || (int)$maxStudentsRaw <= 0) throw new RuntimeException('Số lượng tối đa phải là số nguyên dương.');

        $semester = $this->normalizeSemesterTerm($semesterTerm);
        if ($semester === null) throw new RuntimeException('Học kỳ chỉ nhận I, II hoặc Kỳ hè.');

        $existsSection = $pdo->prepare('SELECT 1 FROM LopHocPhan WHERE upper(MaLHP) = :ma_lhp LIMIT 1');
        $existsSection->execute([':ma_lhp' => $sectionCode]);
        if ($existsSection->fetchColumn()) throw new RuntimeException('Mã học phần đã tồn tại.');

        // SỬA Ở ĐÂY: Truy vấn trực tiếp bằng $pdo đang có sẵn thay vì gọi hàm tạo kết nối mới
        $stmtSubj = $pdo->prepare('SELECT TenMon, SoTinChi FROM MonHoc WHERE upper(MaMon) = :ma LIMIT 1');
        $stmtSubj->execute([':ma' => $courseCode]);
        $subject = $stmtSubj->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new RuntimeException('Không tìm thấy mã môn học.');

        // SỬA Ở ĐÂY: Truy vấn trực tiếp bằng $pdo
        $stmtTeacher = $pdo->prepare('SELECT HoTen FROM GiangVien WHERE MaGV = :ma LIMIT 1');
        $stmtTeacher->execute([':ma' => $teacherCode]);
        $teacher = $stmtTeacher->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) throw new RuntimeException('Không tìm thấy mã giảng viên.');

        $ins = $pdo->prepare(
            'INSERT INTO LopHocPhan (MaLHP, MaMon, MaGV, MaKhoa, HocKy, NamHoc, SoLuongToiDa, TrongSoCC, TrongSoGK, TrongSoCK, CreatedAt)
             VALUES (:ma_lhp, :ma_mon, :ma_gv, :ma_khoa, :hoc_ky, :nam_hoc, :max, 0, 0, 0, CURRENT_TIMESTAMP)'
        );
        $ins->execute([
            ':ma_lhp' => $sectionCode,
            ':ma_mon' => $courseCode,
            ':ma_gv' => $teacherCode,
            ':ma_khoa' => $departmentCode ?: null,
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
            'course_name' => (string)($subject['TenMon'] ?? ''), // Đổi key cho khớp CSDL
            'credits' => (int)($subject['SoTinChi'] ?? 0),       // Đổi key cho khớp CSDL
            'teacher_code' => $teacherCode,
            'teacher_name' => (string)($teacher['HoTen'] ?? ''), // Đổi key cho khớp CSDL
            'semester' => $semester,
            'semester_label' => $this->semesterLabelFromInt($semester),
            'academic_year' => $academicYear,
            'max_students' => (int)$maxStudentsRaw,
        ];
    }

    private function updateSectionLiteWithPdo(PDO $pdo, int $id, array $payload): array
    {
        if ($id <= 0) throw new RuntimeException('Thiếu mã học phần.');

        $sectionCode = trim((string)($payload['section_code'] ?? ''));
        $courseCode = strtoupper(trim((string)($payload['course_code'] ?? '')));
        $teacherCode = trim((string)($payload['teacher_code'] ?? ''));
        $semesterTerm = trim((string)($payload['semester_term'] ?? ''));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $departmentCode = trim((string)($payload['department'] ?? ''));
        $maxStudentsRaw = trim((string)($payload['max_students'] ?? ''));

        if ($sectionCode === '') throw new RuntimeException('Thiếu mã học phần.');
        if ($courseCode === '') throw new RuntimeException('Thiếu mã môn.');
        if ($teacherCode === '') throw new RuntimeException('Thiếu mã giảng viên.');
        if ($academicYear === '' || !preg_match('/^\d{4}\s*-\s*\d{4}$/', $academicYear)) throw new RuntimeException('Năm học không hợp lệ.');
        if ($maxStudentsRaw === '' || !ctype_digit($maxStudentsRaw) || (int)$maxStudentsRaw <= 0) throw new RuntimeException('Số lượng tối đa phải là số nguyên dương.');

        $semester = $this->normalizeSemesterTerm($semesterTerm);
        if ($semester === null) throw new RuntimeException('Học kỳ chỉ nhận I, II hoặc Kỳ hè.');

        // FIX: Truy vấn trực tiếp bằng $pdo đang có sẵn
        $stmtSubj = $pdo->prepare('SELECT TenMon, SoTinChi FROM MonHoc WHERE upper(MaMon) = :ma LIMIT 1');
        $stmtSubj->execute([':ma' => $courseCode]);
        $subject = $stmtSubj->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new RuntimeException('Không tìm thấy mã môn học.');

        // FIX: Truy vấn trực tiếp bằng $pdo đang có sẵn
        $stmtTeacher = $pdo->prepare('SELECT HoTen FROM GiangVien WHERE MaGV = :ma LIMIT 1');
        $stmtTeacher->execute([':ma' => $teacherCode]);
        $teacher = $stmtTeacher->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) throw new RuntimeException('Không tìm thấy mã giảng viên.');

        $stmtMap = $pdo->prepare('SELECT MaLHP FROM LopHocPhanMap WHERE LegacyId = :id LIMIT 1');
        $stmtMap->execute([':id' => $id]);
        $maLhp = (string)$stmtMap->fetchColumn();
        if ($maLhp === '') throw new RuntimeException('Không tìm thấy lớp học phần.');

        $up = $pdo->prepare(
            'UPDATE LopHocPhan
             SET MaMon = :ma_mon,
                 MaGV = :ma_gv,
                 MaKhoa = :ma_khoa,
                 HocKy = :hoc_ky,
                 NamHoc = :nam_hoc,
                 SoLuongToiDa = :max
             WHERE MaLHP = :ma_lhp'
        );
        $up->execute([
            ':ma_mon' => $courseCode,
            ':ma_gv' => $teacherCode,
            ':ma_khoa' => $departmentCode ?: null,
            ':hoc_ky' => $semester,
            ':nam_hoc' => $academicYear,
            ':max' => (int)$maxStudentsRaw,
            ':ma_lhp' => $maLhp,
        ]);

        return [
            'id' => $id,
            'section_code' => $maLhp,
            'course_code' => $courseCode,
            'course_name' => (string)($subject['TenMon'] ?? ''),
            'credits' => (int)($subject['SoTinChi'] ?? 0),
            'teacher_code' => $teacherCode,
            'teacher_name' => (string)($teacher['HoTen'] ?? ''),
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
                    $errors['credits'] = 'Số tín chỉ phải lớn hơn 0.';
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
                    $errors['max_students'] = 'Số lượng tối đa phải lớn hơn 0.';
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
                    $errors['teacher_code'] = 'Không tìm thấy giáo viên theo mã được nhập.';
                }
            } catch (Throwable $e) {
                $errors['teacher_code'] = 'Không thể kiểm tra mã giáo viên lúc này. Vui lòng thử lại.';
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
                jsonResponse(['status' => 'error', 'message' => 'Không thể tải được dữ liệu tạo lớp học.', 'detail' => $e->getMessage()], 500);
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
                        jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy môn học.'], 404);
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
            $originalCode = strtoupper(trim((string)($payload['original_code'] ?? $payload['course_code'] ?? '')));
            if ($originalCode === '') {
                jsonResponse(['status' => 'error', 'message' => 'Thiáº¿u mĂ£ mĂ´n há»c gá»‘c.'], 422);
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
                jsonResponse(['status' => 'error', 'message' => 'Không thể cập nhật môn học.', 'detail' => $e->getMessage()], 500);
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

           

            $courseCodeToCheck = strtoupper(trim((string)($data['course_code'] ?? '')));
            if ($courseCodeToCheck !== '') {
                $dupCheck = $pdo->prepare('SELECT 1 FROM LopHocPhan WHERE upper(MaMon) = :mc LIMIT 1');
                $dupCheck->execute([':mc' => $courseCodeToCheck]);
                if ($dupCheck->fetchColumn()) {
                    jsonResponse([
                        'status' => 'error',
                        'message' => 'Mã môn học đã được dùng trong một lớp học phần. Mỗi môn học chỉ có một lớp học phần tại một thời điểm.',
                        'fields' => ['course_code' => 'Mã môn học đã tồn tại trong hệ thống.'],
                    ], 409);
                    return;
                }
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
            jsonResponse(['status' => 'error', 'message' => 'Không thể tạo lớp học.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Lỗi hệ thống.', 'detail' => $e->getMessage()], 500);
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
                jsonResponse(['status' => 'error', 'message' => 'Thiếu mã môn học.'], 422);
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
                    'message' => 'Đã xóa môn học thành công.',
                    'data' => ['course_code' => strtoupper($courseCode)],
                ]);
                return;
            } catch (RuntimeException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
                return;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Không thể xóa môn học.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        // Hỗ trợ nhận ID từ cả URL ($_GET) và JSON Payload Body cho Axios
        $payload = $this->parseJsonPayload();
        $courseId = (int)($_GET['id'] ?? $payload['id'] ?? 0);

        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp học phần.'], 422);
            return;
        }

        try {
            $course = Course::findById($courseId);
            if (!$course) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy lớp học phần.'], 404);
                return;
            }

            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            $pdo->beginTransaction();
            Course::deleteByIdWithPdo($pdo, $courseId);
            $pdo->commit();

            jsonResponse([
                'status' => 'success',
                'message' => 'Đã xóa lớp học phần thành công.',
                'data' => ['id' => $courseId, 'course_code' => (string)($course['course_code'] ?? '')],
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Lỗi hệ thống khi xóa.', 'detail' => $e->getMessage()], 500);
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
                jsonResponse(['status' => 'error', 'message' => 'Chưa có file dữ liệu.'], 400);
                return;
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                jsonResponse(['status' => 'error', 'message' => 'Không thể đọc được file tải lên.'], 400);
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

        if ($mode === 'subject') {
            try {
                $pdo = get_db_connection();
                $pdo->exec('PRAGMA busy_timeout = 5000');
                Course::ensureSchema($pdo);
                $pdo->beginTransaction();
                $inserted = 0;
                $skipped = [];
                foreach ($rows as $idx => $row) {
                    $line = $idx + 2;
                    $data = is_array($row) ? $row : [];
                    try {
                        Course::insertSubjectWithPdo($pdo, $data);
                        $inserted++;
                    } catch (RuntimeException $e) {
                        $skipped[] = [
                            'line' => $line,
                            'course_code' => trim((string)($data['course_code'] ?? '')),
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
                jsonResponse(['status' => 'error', 'message' => 'Không thể import môn học.', 'detail' => $e->getMessage()], 500);
                return;
            }
        }

        if ($mode === 'section-lite') {
            try {
                $pdo = get_db_connection();
                
                // THÊM DÒNG NÀY VÀO:
                $pdo->exec('PRAGMA busy_timeout = 5000');
                
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
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp học phần.'], 422);
            return;
        }

        try {
            $course = Course::findById($courseId);
            if (!$course) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy lớp học phần.'], 404);
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

            // 1. Lấy danh sách sinh viên
            $students = Course::getStudentsByCourseId($courseId);

            // 2. Lấy bảng điểm và trọng số
            $scoreMap = Course::getScoresByCourseId($courseId);
            $wCc = (float)($course['weight_cc'] ?? 0);
            $wGk = (float)($course['weight_gk'] ?? 0);
            $wCk = (float)($course['weight_ck'] ?? 0);

            // 3. Ghép điểm vào từng sinh viên
            foreach ($students as &$student) {
                $code = (string)$student['student_code'];
                $score = $scoreMap[$code] ?? ['cc' => null, 'gk' => null, 'ck' => null];
                
                $student['cc'] = $score['cc'];
                $student['gk'] = $score['gk'];
                $student['ck'] = $score['ck'];
                
                $total = $this->calculateTotal($score['cc'], $score['gk'], $score['ck'], $wCc, $wGk, $wCk);
                $student['total'] = $total;
                $student['letter'] = $this->letterGrade($total);
            }
            unset($student); // Hủy tham chiếu

            jsonResponse([
                'status' => 'success',
                'data' => $course,
                'students' => $students,
            ]);
        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => 'Lỗi hệ thống khi tải chi tiết.', 'detail' => $e->getMessage()], 500);
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
            // When FormData is sent, use $_POST which contains multipart/form-data
            $payload = array_merge($_GET, $_POST);
        }
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
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
            $sectionCodeToIgnore = strtoupper(trim((string)($payload['section_code'] ?? '')));
            [$data, $errors] = $this->validatePayload($payload, $pdo, $sectionCodeToIgnore);
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

                        $teacherConflict2 = $this->findTeacherConflict($pdo, (string)($data['schedule'] ?? ''), (string)($data['teacher_code'] ?? ''), $courseId);
            if ($teacherConflict2) {
                $msg = sprintf('Giảng viên đã có lịch dạy lớp %s vào T%s-(%s-%s).',
                    $teacherConflict2['course_code'], $teacherConflict2['day'], $teacherConflict2['start'], $teacherConflict2['end']);
                jsonResponse(['status' => 'error', 'message' => $msg, 'fields' => ['schedule' => $msg]], 422);
                return;
            }

            // Load student data BEFORE transaction to avoid database lock
            $currentStudents = Course::getStudentsByCourseId($courseId);
            $currentMap = [];
            foreach ($currentStudents as $student) {
                $code = strtolower(trim((string)($student['student_code'] ?? '')));
                if ($code !== '') {
                    $currentMap[$code] = true;
                }
            }

            $validCodes = [];
            $newCodes = [];
            $alreadyEnrolledRows = 0;
            $scheduleConflictDetails = [];
            $scheduleConflictRows = 0;

            // Pre-process enrollment data BEFORE transaction
            if (is_array($studentCodesFromFile)) {
                $validCodes = Course::findValidStudentCodes($pdo, $studentCodesFromFile);
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
                    jsonResponse([
                        'status' => 'error',
                        'message' => 'Danh sách sinh viên vượt quá số lượng tối đa của lớp.',
                    ], 422);
                    return;
                }
            }

            // Now begin transaction with minimal operations inside
            $pdo->beginTransaction();

            Course::updateByIdWithPdo($pdo, $courseId, $data);

            $addedCount = 0;
            if (!empty($newCodes)) {
                $addedCount = Course::appendEnrollmentsWithPdo($pdo, $courseId, $newCodes);
            }

            $pdo->commit();

            // Calculate stats after transaction
            $enrollmentImport = null;
            if (is_array($studentCodesFromFile)) {
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
                    'fields' => ['course_code' => 'MÄ‚Â mÄ‚Â´n hĂ¡Â»Âc Ă„â€˜Ä‚Â£ tĂ¡Â»â€œn tĂ¡ÂºÂ¡i.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the cap nhat mon hoc.', 'detail' => $msg], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Loi he thong.', 'detail' => $e->getMessage()], 500);
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
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã môn học.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy môn học.'], 404);
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
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
            return;
        }

        $courseId = (int)($payload['id'] ?? 0);
        if ($courseId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã môn học.'], 422);
            return;
        }

        Teacher::ensureSchema();
        Student::ensureSchema();
        Course::ensureSchema();

        $course = Course::findById($courseId);
        if (!$course) {
            jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy môn học.'], 404);
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
            jsonResponse(['status' => 'error', 'message' => 'Tỷ lệ điểm không thể âm.'], 422);
            return;
        }
        if (($wCc + $wGk + $wCk) <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Tổng tỷ lệ điểm phải lớn hơn 0.'], 422);
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
                    jsonResponse(['status' => 'error', 'message' => "Điểm $k của $code phải trong khoảng 0-10."], 422);
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
            jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy dữ liệu điểm.'], 404);
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


    // Phần điểm danh
    private function verifyClassAccess(PDO $pdo, string $maLHP, array $identity, bool $requireTeacher = true)
    {
        $stmt = $pdo->prepare('SELECT MaGV, TrangThai, NgayHetHan, IsLocked FROM LopHocPhan WHERE MaLHP = :ma LIMIT 1');
        $stmt->execute([':ma' => $maLHP]);
        $lhp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lhp) throw new RuntimeException("Không tìm thấy lớp học phần.");

        $role = $identity['account_type'] ?? '';
        $loginId = $identity['login_id'] ?? '';

        // 1. Admin/Staff được toàn quyền (Bỏ qua mọi rào cản)
        if ($role === 'staff') return true;

        if ($requireTeacher) {
            // Kiểm tra đúng giảng viên phụ trách không
            if ($role !== 'teacher' || (string)$lhp['MaGV'] !== $loginId) {
                throw new RuntimeException("Truy cập bị từ chối: Bạn không phải giảng viên phụ trách lớp này!");
            }

            // KIỂM TRA LỚP BỊ ADMIN KHÓA CHỦ ĐỘNG
            if ((int)($lhp['IsLocked'] ?? 0) === 1) {
                throw new RuntimeException("Lớp học đã bị Phòng Đào tạo khóa. Không thể thay đổi dữ liệu.");
            }

            // KIỂM TRA QUÁ HẠN (4 tháng)
            if (!empty($lhp['NgayHetHan'])) {
                $deadline = strtotime($lhp['NgayHetHan']);
                if (time() > $deadline) {
                    throw new RuntimeException("Đã quá thời hạn cập nhật dữ liệu (" . date('d/m/Y H:i', $deadline) . ").");
                }
            }

            // Kiểm tra trạng thái đã kết thúc
            if (($lhp['TrangThai'] ?? '') === 'COMPLETED') {
                throw new RuntimeException("Lớp học đã kết thúc. Không thể thay đổi dữ liệu!");
            }
        } else {
            // Dành cho Sinh viên (Chỉ được xem)
            if ($role === 'student') {
                $check = $pdo->prepare('SELECT 1 FROM KetQuaHocTap WHERE MaLHP = :ma AND MaSV = :sv LIMIT 1');
                $check->execute([':ma' => $maLHP, ':sv' => $loginId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException("Truy cập bị từ chối: Bạn không có tên trong lớp này!");
                }
            }
        }
        return true;
    }

    // Mark course as started (IsStarted = 1) when first data is entered
    // Also set NgayHetHan if not already set (4 months from now)
    private function markCourseAsStarted(PDO $pdo, string $maLHP): void
    {
        $stmt = $pdo->prepare(
            'UPDATE LopHocPhan SET 
              IsStarted = 1, 
              StartedAt = datetime("now", "localtime"),
              NgayHetHan = COALESCE(NgayHetHan, datetime("now", "localtime", "+4 months"))
             WHERE MaLHP = :ma AND IsStarted = 0'
        );
        $stmt->execute([':ma' => $maLHP]);
    }


    // Phần điểm danh
    public function createLesson()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        $payload = $this->parseJsonPayload();
        $maLHP = trim((string)($payload['section_code'] ?? ''));

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            
            // Tương đương verifyTeacherOwnership()
            $this->verifyClassAccess($pdo, $maLHP, $identity, true);

            $pdo->beginTransaction();
            
            // Mark course as started when first lesson is created
            $this->markCourseAsStarted($pdo, $maLHP);
            
            $lessonNum = Course::createNewLessonWithPdo($pdo, $maLHP);
            $pdo->commit();

            jsonResponse(['status' => 'success', 'message' => "Tạo thành công buổi học thứ $lessonNum"]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // Phần điểm danh
    public function submitAttendance()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        $payload = $this->parseJsonPayload();
        $maLHP = trim((string)($payload['section_code'] ?? ''));
        $weekNumber = (int)($payload['week_number'] ?? 0);
        $attendanceList = $payload['attendance_list'] ?? []; // mảng: [['student_code' => '...', 'is_absent' => true/false]]

        if ($maLHP === '' || $weekNumber <= 0 || !is_array($attendanceList)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            
            // Xác minh quyền Giảng viên (phải là GV dạy lớp này và lớp chưa đóng)
            $this->verifyClassAccess($pdo, $maLHP, $identity, true);

            $pdo->beginTransaction();
            
            // Mark course as started when first attendance is submitted
            $this->markCourseAsStarted($pdo, $maLHP);
            
            foreach ($attendanceList as $st) {
                $maSV = trim((string)($st['student_code'] ?? ''));
                $isAbsent = (bool)($st['is_absent'] ?? false);
                
                if ($maSV !== '') {
                    // Gọi hàm xử lý chuỗi "0100" đã viết ở Model
                    Course::updateAttendanceWithPdo($pdo, $maLHP, $maSV, $weekNumber, $isAbsent);
                }
            }
            $pdo->commit();

            jsonResponse(['status' => 'success', 'message' => 'Đã lưu điểm danh thành công!']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //Phần điểm danh 
    public function getAttendance()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        // Lấy param từ URL (GET Request)
        $maLHP = trim((string)($_GET['section_code'] ?? ''));
        $weekNumber = (int)($_GET['week_number'] ?? 0);

        if ($maLHP === '' || $weekNumber <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu thông tin lớp hoặc buổi học.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            
            // requireTeacher = false => Admin, Giảng viên lớp này, Sinh viên lớp này đều được xem
            $this->verifyClassAccess($pdo, $maLHP, $identity, false);

            // Lấy danh sách lớp kèm chuỗi lịch sử điểm danh
            $stmt = $pdo->prepare(
                'SELECT k.MaSV, s.HoTen, k.LichSuDiemDanh, k.SoBuoiVang
                 FROM KetQuaHocTap k
                 INNER JOIN SinhVien s ON k.MaSV = s.MaSV
                 WHERE k.MaLHP = :ma_lhp
                 ORDER BY k.MaSV ASC'
            );
            $stmt->execute([':ma_lhp' => $maLHP]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                throw new RuntimeException("Lớp học chưa có sinh viên.");
            }

            // Lấy độ dài chuỗi điểm danh (số buổi đã tạo) từ sinh viên đầu tiên
            $totalLessons = strlen((string)($rows[0]['LichSuDiemDanh'] ?? ''));
            if ($weekNumber > $totalLessons) {
                throw new RuntimeException("Buổi học số $weekNumber chưa được tạo!");
            }

            $students = [];
            foreach ($rows as $row) {
                $history = (string)($row['LichSuDiemDanh'] ?? '');
                
                // Kiểm tra ký tự tại vị trí tuần hiện tại (index bắt đầu từ 0 nên phải trừ 1)
                $statusChar = $history[$weekNumber - 1] ?? '0';
                
                $students[] = [
                    'student_code' => $row['MaSV'],
                    'full_name' => $row['HoTen'],
                    'is_absent_this_week' => ($statusChar === '1'),
                    'total_absences' => (int)$row['SoBuoiVang'],
                    
                ];
            }

            jsonResponse([
                'status' => 'success',
                'data' => [
                    'section_code' => $maLHP,
                    'week_number' => $weekNumber,
                    'total_lessons' => $totalLessons,
                    'students' => $students
                ]
            ]);

        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    //Phần điểm danh 
    public function deleteLesson()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        // Đọc dữ liệu từ Frontend gửi lên
        $payload = $this->parseJsonPayload();
        $maLHP = trim((string)($payload['section_code'] ?? ''));
        $lessonNumber = (int)($payload['week_number'] ?? $payload['lesson_number'] ?? 0);

        if ($maLHP === '' || $lessonNumber <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            
            // Xác minh quyền: Chỉ Giảng viên phụ trách lớp và lớp chưa đóng mới được xóa
            $this->verifyClassAccess($pdo, $maLHP, $identity, true);

            // Chạy Transaction để đảm bảo an toàn dữ liệu
            $pdo->beginTransaction();
            Course::deleteLessonWithPdo($pdo, $maLHP, $lessonNumber);
            $pdo->commit();

            jsonResponse([
                'status' => 'success', 
                'message' => "Đã xóa thành công buổi học số $lessonNumber."
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

   
    // Nhập điểm cho từng sinh viên và tự động tính tổng kết
   // CHỈ CẬP NHẬT ĐIỂM CHO 1 SINH VIÊN
    public function submitScore()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        $payload = $this->parseJsonPayload();
        $maLHP = trim((string)($payload['section_code'] ?? ''));
        $maSV = trim((string)($payload['student_code'] ?? ''));
        
        $cc = isset($payload['cc']) && $payload['cc'] !== '' ? (float)$payload['cc'] : null;
        $gk = isset($payload['gk']) && $payload['gk'] !== '' ? (float)$payload['gk'] : null;
        $ck = isset($payload['ck']) && $payload['ck'] !== '' ? (float)$payload['ck'] : null;

        if ($maLHP === '' || $maSV === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp hoặc mã sinh viên.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            $this->verifyClassAccess($pdo, $maLHP, $identity, true);

            $pdo->beginTransaction();
            
            // Mark course as started when first score is entered
            $this->markCourseAsStarted($pdo, $maLHP);
            
            // Cập nhật điểm thành phần
            $stmt = $pdo->prepare(
                'UPDATE KetQuaHocTap 
                 SET DiemChuyenCan = :cc, DiemGiuaKy = :gk, DiemCuoiKy = :ck 
                 WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv'
            );
            $stmt->execute([':cc' => $cc, ':gk' => $gk, ':ck' => $ck, ':ma_lhp' => $maLHP, ':ma_sv' => $maSV]);

            // Tính điểm tổng kết (Model tự lấy Trọng số từ DB)
            Course::updateFinalGradeWithPdo($pdo, $maLHP, $maSV);

            $pdo->commit();

            // Lấy điểm tổng kết mới trả về FE
            $stmtResult = $pdo->prepare('SELECT DiemTongKet FROM KetQuaHocTap WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv LIMIT 1');
            $stmtResult->execute([':ma_lhp' => $maLHP, ':ma_sv' => $maSV]);
            $newTotal = $stmtResult->fetchColumn();

            jsonResponse([
                'status' => 'success', 
                'message' => "Cập nhật điểm cho sinh viên $maSV thành công!",
                'data' => [
                    'student_code' => $maSV,
                    'total_score' => $newTotal !== false ? (float)$newTotal : null,
                    'letter_grade' => $this->letterGrade($newTotal)
                ]
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
    // HÀM MỚI: CÀI ĐẶT TRỌNG SỐ CHO CẢ LỚP
    public function updateWeights()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        $payload = $this->parseJsonPayload();
        $maLHP = trim((string)($payload['section_code'] ?? ''));
        $wCc = (float)($payload['weight_cc'] ?? 0);
        $wGk = (float)($payload['weight_gk'] ?? 0);
        $wCk = (float)($payload['weight_ck'] ?? 0);

        if ($maLHP === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp học phần.'], 400);
            return;
        }
        
        // Validate tổng trọng số bằng 1.0 (hoặc 100 tùy nghiệp vụ)
        if (abs(($wCc + $wGk + $wCk) - 1.0) > 0.01) {
            jsonResponse(['status' => 'error', 'message' => 'Tổng các trọng số phải bằng 1.0'], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Course::ensureSchema($pdo);
            $this->verifyClassAccess($pdo, $maLHP, $identity, true);

            $pdo->beginTransaction();

            // 1. Lưu trọng số mới vào bảng Lớp học phần
            $stmt = $pdo->prepare('UPDATE LopHocPhan SET TrongSoCC = :cc, TrongSoGK = :gk, TrongSoCK = :ck WHERE MaLHP = :ma_lhp');
            $stmt->execute([':cc' => $wCc, ':gk' => $wGk, ':ck' => $wCk, ':ma_lhp' => $maLHP]);

            // 2. Tính lại điểm cho TOÀN BỘ sinh viên trong lớp
            $stmtAllSv = $pdo->prepare('SELECT MaSV FROM KetQuaHocTap WHERE MaLHP = :ma_lhp');
            $stmtAllSv->execute([':ma_lhp' => $maLHP]);
            $allSv = $stmtAllSv->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($allSv as $svCode) {
                Course::updateFinalGradeWithPdo($pdo, $maLHP, (string)$svCode);
            }

            $pdo->commit();

            // 3. Lấy danh sách sinh viên cập nhật với điểm mới
            $scoreMap = Course::getScoresByCourseId(0); // Get scores using legacy approach
            // Query directly for this section's students
            $stmtStudents = $pdo->prepare(
                'SELECT s.MaSV AS student_code, s.HoTen AS full_name, s.MaLop AS class_name, ng.TenNganh AS major, s.Email AS email
                 FROM KetQuaHocTap k
                 INNER JOIN SinhVien s ON s.MaSV = k.MaSV
                 LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
                 LEFT JOIN Nganh ng ON ng.MaNganh = l.MaNganh
                 WHERE k.MaLHP = :ma_lhp
                 ORDER BY s.MaSV ASC'
            );
            $stmtStudents->execute([':ma_lhp' => $maLHP]);
            $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

            // Get updated scores for all students
            $stmtScores = $pdo->prepare(
                'SELECT MaSV, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy, DiemTongKet FROM KetQuaHocTap WHERE MaLHP = :ma_lhp'
            );
            $stmtScores->execute([':ma_lhp' => $maLHP]);
            $scoreData = $stmtScores->fetchAll(PDO::FETCH_ASSOC);
            $scoreMap = [];
            foreach ($scoreData as $row) {
                $scoreMap[(string)$row['MaSV']] = [
                    'cc' => $row['DiemChuyenCan'] !== null ? (float)$row['DiemChuyenCan'] : null,
                    'gk' => $row['DiemGiuaKy'] !== null ? (float)$row['DiemGiuaKy'] : null,
                    'ck' => $row['DiemCuoiKy'] !== null ? (float)$row['DiemCuoiKy'] : null,
                    'total' => $row['DiemTongKet'] !== null ? (float)$row['DiemTongKet'] : null
                ];
            }

            // Add scores to students
            foreach ($students as &$student) {
                $code = (string)$student['student_code'];
                $score = $scoreMap[$code] ?? ['cc' => null, 'gk' => null, 'ck' => null, 'total' => null];
                $student['cc'] = $score['cc'];
                $student['gk'] = $score['gk'];
                $student['ck'] = $score['ck'];
                $student['total'] = $score['total'];
                $student['letter'] = $this->letterGrade($score['total']);
            }
            unset($student);

            jsonResponse([
                'status' => 'success', 
                'message' => 'Đã cập nhật trọng số và tính lại điểm cho toàn lớp!',
                'students' => $students
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }


    // HÀM MỚI: Dành riêng cho Admin Khóa/Mở lớp hoặc gia hạn
    public function toggleLock()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Chỉ Phòng Đào tạo mới có quyền này.'], 403);
            return;
        }

        $payload = $this->parseJsonPayload();
        $maLHP = trim((string)($payload['section_code'] ?? ''));
        $isLocked = isset($payload['is_locked']) ? (int)$payload['is_locked'] : 0;
        $ngayHetHan = trim((string)($payload['ngay_het_han'] ?? ''));

        if ($maLHP === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp học phần.'], 400);
            return;
        }

        try {
            $pdo = get_db_connection();
            $pdo->beginTransaction();

            $sql = 'UPDATE LopHocPhan SET IsLocked = :is_locked';
            $params = [':is_locked' => $isLocked, ':ma_lhp' => $maLHP];

            // Nếu Admin có nhập ngày mới để gia hạn
            if ($ngayHetHan !== '') {
                $sql .= ', NgayHetHan = :ngay_het_han';
                $params[':ngay_het_han'] = $ngayHetHan;
            }

            $sql .= ' WHERE MaLHP = :ma_lhp';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Đã cập nhật trạng thái khóa lớp thành công!']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Không thể cập nhật.', 'detail' => $e->getMessage()], 500);
        }
    }
}



