<?php

require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/HomeroomClass.php';
require_once __DIR__ . '/../models/Teacher.php';
require_once __DIR__ . '/../helpers/response.php';

class HomeroomClassController
{
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

    private function requireStaff(): ?array
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return null;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return null;
        }
        return $identity;
    }

    public function index(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $code = trim((string)($_GET['code'] ?? ''));
        $pdo = get_db_connection();
        HomeroomClass::ensureSchema($pdo);

        if ($code !== '') {
            $item = HomeroomClass::findByCode($code, $pdo);
            if (!$item) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy lớp.'], 404);
                return;
            }
            jsonResponse(['status' => 'success', 'data' => $item]);
            return;
        }

        jsonResponse([
            'status' => 'success',
            'data' => HomeroomClass::search($keyword, $pdo),
            'summary' => HomeroomClass::summary($pdo),
            'major_options' => HomeroomClass::listMajorOptions($pdo),
            'teacher_options' => HomeroomClass::listTeacherOptions($pdo),
        ]);
    }

    public function save(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
            return;
        }

        $action = strtolower(trim((string)($payload['action'] ?? 'create')));
        $oldCode = trim((string)($payload['old_code'] ?? ''));
        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $majorCode = trim((string)($payload['major_code'] ?? ''));
        $headTeacherCode = trim((string)($payload['head_teacher_code'] ?? ''));
        $schoolYear = trim((string)($payload['school_year'] ?? ''));

        if ($action === 'update' && $oldCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp cần cập nhật.'], 422);
            return;
        }
        if ($name === '') {
            jsonResponse(['status' => 'error', 'message' => 'Tên lớp không được để trống.'], 422);
            return;
        }
        if ($majorCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Vui lòng chọn ngành cho lớp.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        HomeroomClass::ensureSchema($pdo);

        if ($action === 'create' && $code !== '') {
            $dup = $pdo->prepare('SELECT 1 FROM LopSinhHoat WHERE lower(MaLop) = lower(:code) LIMIT 1');
            $dup->execute([':code' => $code]);
            if ($dup->fetchColumn()) {
                jsonResponse(['status' => 'error', 'message' => 'Mã lớp đã tồn tại.'], 409);
                return;
            }
        }

        try {
            $pdo->beginTransaction();
            if ($action === 'update') {
                $saved = HomeroomClass::updateWithPdo($pdo, $oldCode, [
                    'code' => $code,
                    'name' => $name,
                    'major_code' => $majorCode,
                    'head_teacher_code' => $headTeacherCode,
                    'school_year' => $schoolYear,
                ]);
            } else {
                $saved = HomeroomClass::createWithPdo($pdo, [
                    'code' => $code,
                    'name' => $name,
                    'major_code' => $majorCode,
                    'head_teacher_code' => $headTeacherCode,
                    'school_year' => $schoolYear,
                ]);
            }
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => $saved]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = (string)$e->getMessage();
            if (strpos($message, 'UNIQUE constraint failed: LopSinhHoat.MaLop') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'Mã lớp đã tồn tại.'], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể lưu lớp. (' . $message . ')'], 500);
        }
    }

    public function delete(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp cần xóa.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        HomeroomClass::ensureSchema($pdo);

        $existing = $pdo->prepare('SELECT 1 FROM LopSinhHoat WHERE lower(MaLop) = lower(:code) LIMIT 1');
        $existing->execute([':code' => $code]);
        if (!$existing->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'Lop khong ton tai.'], 404);
            return;
        }

        try {
            $pdo->beginTransaction();
            HomeroomClass::deleteByCodeWithPdo($pdo, $code);
            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Đã xóa lớp thành công.']);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa lớp. (' . $e->getMessage() . ')'], 500);
        }
    }

    // =========================================================================
    // CÁC HÀM XỬ LÝ ĐỌC FILE EXCEL & IMPORT BULK
    // =========================================================================

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

    

    private function mapImportRows(array $rawRows, PDO $pdo): array
    {
        if (count($rawRows) < 2) {
            return ['rows' => [], 'skipped' => []];
        }

        // Đã cập nhật từ khóa 'magiangvien'
        $headerAlias = [
            'code'              => ['malop', 'classcode', 'code', 'lop'],
            'name'              => ['tenlop', 'classname', 'name'],
            'major_code'        => ['manganh', 'makhoa', 'majorcode', 'major', 'nganh'],
            'head_teacher_code' => ['magvcn', 'gvcn', 'magv', 'magiangvien', 'giangvien', 'covanhoctap', 'teacher', 'headteacher', 'gv'],
            'school_year'       => ['nienkhoa', 'khoa', 'schoolyear', 'year'],
        ];

        $headerMap = [];
        $headerRowIndex = -1;

        // 1. QUÉT TÌM DÒNG TIÊU ĐỀ
        for ($i = 0; $i < min(10, count($rawRows)); $i++) {
            $tempMap = [];
            foreach ($rawRows[$i] as $index => $label) {
                $normalized = $this->normalizeHeader((string)$label);
                if ($normalized === '') continue;
                foreach ($headerAlias as $field => $aliases) {
                    if (in_array($normalized, $aliases, true) && !isset($tempMap[$field])) {
                        $tempMap[$field] = (int)$index;
                        break;
                    }
                }
            }
            if (isset($tempMap['code']) && isset($tempMap['name'])) {
                $headerMap = $tempMap;
                $headerRowIndex = $i;
                break;
            }
        }

        // 2. NẾU KHÔNG TÌM THẤY TIÊU ĐỀ, GIẢ ĐỊNH DÒNG 0 LÀ HEADER VÀ BẮT ĐẦU TỪ DÒNG 1
        if ($headerRowIndex === -1) {
            $headerMap = ['code' => 0, 'name' => 1, 'major_code' => 2, 'head_teacher_code' => 3, 'school_year' => 4];
            $startDataRow = 1; // Skip dòng 0 (assume là header)
        } else {
            $startDataRow = $headerRowIndex + 1; // Bắt đầu đọc từ dòng bên dưới dòng tiêu đề được detect
        }

        // Bắt lỗi nếu thiếu cột
        $requiredFields = ['code', 'name', 'major_code'];
        foreach ($requiredFields as $requiredField) {
            if (!isset($headerMap[$requiredField])) {
                throw new RuntimeException('Không nhận diện được dòng tiêu đề. Hãy đảm bảo có đủ cột: Mã lớp, Tên lớp, Mã ngành.');
            }
        }

        $tempRows = [];
        $skipped = [];
        $seenCodes = [];
        $teacherCodesToFetch = [];
        $majorCodesToFetch = [];

        // 3. ĐỌC DỮ LIỆU
        for ($i = $startDataRow; $i < count($rawRows); $i++) {
            $line = $i + 1;
            $source = $rawRows[$i];

            $row = [
                'line'              => $line,
                'code'              => trim((string)($source[$headerMap['code']] ?? '')),
                'name'              => trim((string)($source[$headerMap['name']] ?? '')),
                'major_code'        => trim((string)($source[$headerMap['major_code']] ?? '')),
                'head_teacher_code' => trim((string)($source[$headerMap['head_teacher_code'] ?? -1] ?? '')),
                'school_year'       => trim((string)($source[$headerMap['school_year'] ?? -1] ?? '')),
            ];

            if ($row['code'] === '' && $row['name'] === '' && $row['major_code'] === '') continue;
            
            if ($row['code'] === '' || $row['name'] === '' || $row['major_code'] === '') {
                $skipped[] = ['line' => $line, 'code' => $row['code'], 'reason' => 'Thiếu trường bắt buộc (Mã lớp/Tên lớp/Mã ngành)'];
                continue;
            }

            if (isset($seenCodes[$row['code']])) {
                $skipped[] = ['line' => $line, 'code' => $row['code'], 'reason' => 'Mã lớp trùng lặp trong file'];
                continue;
            }

            $seenCodes[$row['code']] = true;
            $tempRows[] = $row;

            if ($row['head_teacher_code'] !== '') $teacherCodesToFetch[] = strtolower($row['head_teacher_code']);
            if ($row['major_code'] !== '') $majorCodesToFetch[] = strtolower($row['major_code']);
        }

        // BATCH QUERY 1: Tải hàng loạt Tên Giảng Viên
        $teacherDict = [];
        if (!empty($teacherCodesToFetch)) {
            $uniqueTeachers = array_unique($teacherCodesToFetch);
            $placeholders = implode(',', array_fill(0, count($uniqueTeachers), '?'));
            $stmt = $pdo->prepare("SELECT lower(MaGV) AS MaGV, HoTen FROM GiangVien WHERE lower(MaGV) IN ($placeholders)");
            $stmt->execute(array_values($uniqueTeachers));
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $teacherDict[$r['MaGV']] = $r['HoTen'];
            }
        }

        // BATCH QUERY 2: Tải hàng loạt Tên Ngành
        $majorDict = [];
        if (!empty($majorCodesToFetch)) {
            $uniqueMajors = array_unique($majorCodesToFetch);
            $placeholders = implode(',', array_fill(0, count($uniqueMajors), '?'));
            $stmt = $pdo->prepare("SELECT lower(MaNganh) AS MaNganh, TenNganh FROM Nganh WHERE lower(MaNganh) IN ($placeholders)");
            $stmt->execute(array_values($uniqueMajors));
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $majorDict[$r['MaNganh']] = $r['TenNganh'];
            }
        }

        $finalRows = [];

        // LẦN LẶP 2: Lắp ráp Tên vào Mã
        foreach ($tempRows as $row) {
            $mCode = strtolower($row['major_code']);
            if (!isset($majorDict[$mCode])) {
                $skipped[] = ['line' => $row['line'], 'code' => $row['code'], 'reason' => "Mã ngành '{$row['major_code']}' không tồn tại"];
                continue;
            }

            $tCode = strtolower($row['head_teacher_code']);
            if ($tCode !== '') {
                if (!isset($teacherDict[$tCode])) {
                    $skipped[] = ['line' => $row['line'], 'code' => $row['code'], 'reason' => "Mã GVCN '{$row['head_teacher_code']}' không tồn tại"];
                    continue;
                }
                $row['head_teacher_name'] = $teacherDict[$tCode];
            } else {
                $row['head_teacher_name'] = '';
            }

            unset($row['line']);
            $finalRows[] = $row;
        }

        return ['rows' => $finalRows, 'skipped' => $skipped];
    }

    public function importBulk(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $action = strtolower(trim((string)($_GET['action'] ?? 'save')));

        // 1. CHỨC NĂNG PREVIEW (ĐỌC FILE VÀ HIỂN THỊ XEM TRƯỚC)
        if ($action === 'preview') {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                jsonResponse(['status' => 'error', 'message' => 'Chưa có file dữ liệu.'], 400);
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
                } elseif ($ext === 'xlsx') {
                    $rawRows = $this->parseXlsxRows((string)$file['tmp_name']);
                } else {
                    throw new RuntimeException('File phải là CSV hoặc XLSX.');
                }
                
                // MỚI: Mở PDO truyền vào để nó chạy Batch Query
                $pdo = get_db_connection();
                HomeroomClass::ensureSchema($pdo);
                $mapped = $this->mapImportRows($rawRows, $pdo);
                
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

        // 2. CHỨC NĂNG SAVE (LƯU DỮ LIỆU TỪ MẢNG JSON VÀO DATABASE)
        $payload = json_decode(file_get_contents('php://input'), true);
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        if (empty($rows)) {
            jsonResponse(['status' => 'error', 'message' => 'Không có dữ liệu để import.'], 400);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        HomeroomClass::ensureSchema($pdo);

        try {
            $pdo->beginTransaction();
            $inserted = 0;
            $skipped = [];

            foreach ($rows as $idx => $row) {
                $line = $idx + 2;
                $code = trim((string)($row['code'] ?? ''));
                $name = trim((string)($row['name'] ?? ''));
                $majorCode = trim((string)($row['major_code'] ?? ''));
                // Lấy Mã GVCN từ JSON đã được mapping lúc preview
                $headTeacherCode = trim((string)($row['head_teacher_code'] ?? '')); 
                $schoolYear = trim((string)($row['school_year'] ?? ''));

                // Kiểm tra mã lớp đã tồn tại trong DB chưa
                $dup = $pdo->prepare('SELECT 1 FROM LopSinhHoat WHERE lower(MaLop) = lower(:code) LIMIT 1');
                $dup->execute([':code' => $code]);
                if ($dup->fetchColumn()) {
                    $skipped[] = ['line' => $line, 'code' => $code, 'reason' => 'Mã lớp đã tồn tại trên hệ thống'];
                    continue;
                }

                // Chèn vào Database bằng hàm chuẩn có sẵn của bạn
                try {
                    HomeroomClass::createWithPdo($pdo, [
                        'code' => $code,
                        'name' => $name,
                        'major_code' => $majorCode,
                        'head_teacher_code' => $headTeacherCode,
                        'school_year' => $schoolYear
                    ]);
                    $inserted++;
                } catch (Exception $e) {
                    $skipped[] = ['line' => $line, 'code' => $code, 'reason' => $e->getMessage()];
                }
            }

            $pdo->commit();
            jsonResponse([
                'status' => 'success',
                'inserted_count' => $inserted,
                'skipped_count' => count($skipped),
                'skipped' => $skipped
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Lỗi khi import.', 'detail' => $e->getMessage()], 500);
        }
    }
}
