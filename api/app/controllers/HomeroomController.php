<?php
require_once __DIR__ . '/../models/HomeroomClass.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../helpers/response.php';

class HomeroomController
{
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
            'ma_lop' => ['malop', 'classcode', 'homeroomcode'],
            'ten_lop' => ['tenlop', 'classname', 'homeroomname'],
            'khoa' => ['khoa', 'manganh', 'tennganh', 'major', 'faculty'],
            'ma_gv_co_van' => ['magvcovan', 'magv', 'covan', 'advisor'],
            'nien_khoa' => ['nienkhoa', 'academicyear'],
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
                'ma_lop' => 0,
                'ten_lop' => 1,
                'khoa' => 2,
                'ma_gv_co_van' => 3,
                'nien_khoa' => 4,
            ];
            foreach ($defaultMap as $field => $position) {
                if (!isset($headerMap[$field])) {
                    $headerMap[$field] = $position;
                }
            }
        }

        foreach (['ma_lop', 'ten_lop'] as $requiredField) {
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
                'ma_lop' => trim((string)($source[$headerMap['ma_lop']] ?? '')),
                'ten_lop' => trim((string)($source[$headerMap['ten_lop']] ?? '')),
                'khoa' => trim((string)($source[$headerMap['khoa'] ?? -1] ?? '')),
                'ma_gv_co_van' => trim((string)($source[$headerMap['ma_gv_co_van'] ?? -1] ?? '')),
                'nien_khoa' => trim((string)($source[$headerMap['nien_khoa'] ?? -1] ?? '')),
            ];

            if ($row['ma_lop'] === '' && $row['ten_lop'] === '') {
                continue;
            }
            if ($row['ma_lop'] === '' || $row['ten_lop'] === '') {
                $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Thieu truong bat buoc'];
                continue;
            }
            $key = strtolower($row['ma_lop']);
            if (isset($seenCodes[$key])) {
                $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Trung trong file'];
                continue;
            }
            $seenCodes[$key] = true;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function normalizeImportRow(array $row): array
    {
        return [
            'ma_lop' => trim((string)($row['ma_lop'] ?? '')),
            'ten_lop' => trim((string)($row['ten_lop'] ?? '')),
            'khoa' => trim((string)($row['khoa'] ?? '')),
            'ma_gv_co_van' => trim((string)($row['ma_gv_co_van'] ?? '')),
            'nien_khoa' => trim((string)($row['nien_khoa'] ?? '')),
        ];
    }

    private function resolveMajorCode(array $majorsByCode, array $majorsByName, string $khoa): ?string
    {
        $value = trim($khoa);
        if ($value === '') return '';
        $key = strtolower($value);
        if (isset($majorsByCode[$key])) {
            return (string)$majorsByCode[$key];
        }
        if (isset($majorsByName[$key])) {
            return (string)$majorsByName[$key];
        }
        return null;
    }

    private function buildMajorCodeBase(string $khoa): string
    {
        $base = $this->normalizeHeader($khoa);
        if ($base === '') {
            return 'NGANH';
        }
        if (strlen($base) > 8) {
            $base = substr($base, 0, 8);
        }
        return strtoupper($base);
    }

    private function ensureMajorFromKhoa(PDO $pdo, array &$majorsByCode, array &$majorsByName, string $khoa): string
    {
        $value = trim($khoa);
        if ($value === '') {
            return '';
        }

        $resolved = $this->resolveMajorCode($majorsByCode, $majorsByName, $value);
        if ($resolved !== null) {
            return (string)$resolved;
        }

        $base = $this->buildMajorCodeBase($value);
        $candidate = $base;
        $index = 1;
        while (isset($majorsByCode[strtolower($candidate)])) {
            $candidate = $base . str_pad((string)$index, 2, '0', STR_PAD_LEFT);
            $index++;
        }

        $stmt = $pdo->prepare('INSERT INTO Nganh (MaNganh, TenNganh, MoTa) VALUES (:ma_nganh, :ten_nganh, NULL)');
        $stmt->execute([
            ':ma_nganh' => $candidate,
            ':ten_nganh' => $value,
        ]);

        $majorsByCode[strtolower($candidate)] = $candidate;
        $majorsByName[strtolower($value)] = $candidate;
        return $candidate;
    }

    private function existsInTable(PDO $pdo, string $table, string $codeColumn, string $code): bool
    {
        if ($code === '') {
            return false;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE lower({$codeColumn}) = lower(:code) LIMIT 1");
        $stmt->execute([':code' => $code]);
        return (bool)$stmt->fetchColumn();
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

    private function parseJsonPayload(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return null;
        }
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : null;
    }

    private function validatePayload(array $payload, bool $isCreate = true): array
    {
        $maLop = trim((string)($payload['ma_lop'] ?? ''));
        $tenLop = trim((string)($payload['ten_lop'] ?? ''));
        $maNganh = trim((string)($payload['ma_nganh'] ?? ''));
        $maGvCv = trim((string)($payload['ma_gv_co_van'] ?? ''));
        $nienKhoa = trim((string)($payload['nien_khoa'] ?? ''));

        $errors = [];
        if ($isCreate && $maLop === '') $errors['ma_lop'] = 'Hay nhap ma lop.';
        if ($tenLop === '') $errors['ten_lop'] = 'Hay nhap ten lop.';
        if ($maNganh === '') $errors['ma_nganh'] = 'Hay chon ma nganh.';
        if ($nienKhoa === '') $errors['nien_khoa'] = 'Hay nhap nien khoa.';

        if ($nienKhoa !== '' && !preg_match('/^\d{4}-\d{4}$/', $nienKhoa)) {
            $errors['nien_khoa'] = 'Nien khoa phai theo dinh dang YYYY-YYYY.';
        }

        return [[
            'ma_lop' => $maLop,
            'ten_lop' => $tenLop,
            'ma_nganh' => $maNganh,
            'ma_gv_co_van' => $maGvCv,
            'nien_khoa' => $nienKhoa,
        ], $errors];
    }

    public function index()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        HomeroomClass::ensureSchema();
        $rows = HomeroomClass::list([
            'keyword' => isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '',
            'ma_nganh' => isset($_GET['ma_nganh']) ? trim((string)$_GET['ma_nganh']) : '',
            'ma_gv_co_van' => isset($_GET['ma_gv_co_van']) ? trim((string)$_GET['ma_gv_co_van']) : '',
        ]);
        jsonResponse($rows);
    }

    public function options()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        HomeroomClass::ensureSchema();
        jsonResponse(['status' => 'success', 'data' => HomeroomClass::options()]);
    }

    public function detail()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }

        HomeroomClass::ensureSchema();
        $maLop = trim((string)($_GET['ma_lop'] ?? ''));
        if ($maLop === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop.'], 422);
            return;
        }

        $row = HomeroomClass::findByCode($maLop);
        if (!$row) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay lop sinh hoat.'], 404);
            return;
        }

        jsonResponse(['status' => 'success', 'data' => $row]);
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

        HomeroomClass::ensureSchema();
        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        [$data, $errors] = $this->validatePayload($payload, true);
        $pdo = null;
        $pdo = get_db_connection();
        if ($data['ma_nganh'] !== '' && !$this->existsInTable($pdo, 'Nganh', 'MaNganh', $data['ma_nganh'])) {
            $errors['ma_nganh'] = 'Ma nganh khong ton tai.';
        }
        if ($data['ma_gv_co_van'] !== '' && !$this->existsInTable($pdo, 'GiangVien', 'MaGV', $data['ma_gv_co_van'])) {
            $data['ma_gv_co_van'] = '';
        }
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.', 'fields' => $errors], 422);
            return;
        }

        try {
            HomeroomClass::ensureSchema($pdo);
            $pdo->beginTransaction();
            $created = HomeroomClass::createWithPdo($pdo, $data);
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => $created], 201);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $msg = (string)$e->getMessage();
            if (strpos($msg, 'UNIQUE constraint failed: LopSinhHoat.MaLop') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Ma lop da ton tai.',
                    'fields' => ['ma_lop' => 'Ma lop da ton tai.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the tao lop sinh hoat.', 'detail' => $msg], 500);
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

        HomeroomClass::ensureSchema();
        $maLop = trim((string)($_GET['ma_lop'] ?? ''));
        if ($maLop === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop.'], 422);
            return;
        }

        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        [$data, $errors] = $this->validatePayload($payload, false);
        $pdo = null;
        $pdo = get_db_connection();
        if ($data['ma_nganh'] !== '' && !$this->existsInTable($pdo, 'Nganh', 'MaNganh', $data['ma_nganh'])) {
            $errors['ma_nganh'] = 'Ma nganh khong ton tai.';
        }
        if ($data['ma_gv_co_van'] !== '' && !$this->existsInTable($pdo, 'GiangVien', 'MaGV', $data['ma_gv_co_van'])) {
            $data['ma_gv_co_van'] = '';
        }
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.', 'fields' => $errors], 422);
            return;
        }

        try {
            HomeroomClass::ensureSchema($pdo);
            $pdo->beginTransaction();
            $updated = HomeroomClass::updateByCodeWithPdo($pdo, $maLop, $data);
            $pdo->commit();
            if (!$updated) {
                jsonResponse(['status' => 'error', 'message' => 'Khong tim thay lop sinh hoat.'], 404);
                return;
            }
            jsonResponse(['status' => 'success', 'data' => $updated]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the cap nhat lop sinh hoat.', 'detail' => $e->getMessage()], 500);
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

        HomeroomClass::ensureSchema();
        $maLop = trim((string)($_GET['ma_lop'] ?? ''));
        if ($maLop === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma lop.'], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            HomeroomClass::ensureSchema($pdo);
            $pdo->beginTransaction();
            $ok = HomeroomClass::deleteByCodeWithPdo($pdo, $maLop);
            if (!$ok) {
                $pdo->rollBack();
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Khong the xoa lop sinh hoat vi lop da co sinh vien hoac khong ton tai.',
                ], 409);
                return;
            }
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => ['ma_lop' => $maLop]]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the xoa lop sinh hoat.', 'detail' => $e->getMessage()], 500);
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

        HomeroomClass::ensureSchema();
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
            HomeroomClass::ensureSchema($pdo);
            $pdo->beginTransaction();

            $existingHomerooms = [];
            $homeroomCodes = $pdo->query('SELECT MaLop FROM LopSinhHoat')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($homeroomCodes as $code) {
                $existingHomerooms[strtolower(trim((string)$code))] = true;
            }

            $majorsByCode = [];
            $majorsByName = [];
            $majorRows = $pdo->query('SELECT MaNganh, TenNganh FROM Nganh')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($majorRows as $major) {
                $code = trim((string)($major['MaNganh'] ?? ''));
                $name = trim((string)($major['TenNganh'] ?? ''));
                if ($code !== '') {
                    $majorsByCode[strtolower($code)] = $code;
                }
                if ($name !== '' && $code !== '') {
                    $majorsByName[strtolower($name)] = $code;
                }
            }

            $existingTeachers = [];
            $teacherCodes = $pdo->query('SELECT MaGV FROM GiangVien')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($teacherCodes as $code) {
                $existingTeachers[strtolower(trim((string)$code))] = true;
            }

            $inserted = 0;
            $skipped = [];

            foreach ($rows as $idx => $raw) {
                $line = $idx + 2;
                $row = $this->normalizeImportRow(is_array($raw) ? $raw : []);
                $maLopKey = strtolower($row['ma_lop']);
                if ($row['ma_lop'] === '' || $row['ten_lop'] === '') {
                    $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Thieu truong bat buoc'];
                    continue;
                }
                if (trim((string)$row['khoa']) === '') {
                    $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Thieu cot Khoa'];
                    continue;
                }
                if (trim((string)$row['nien_khoa']) === '') {
                    $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Thieu nien khoa'];
                    continue;
                }
                if ($row['nien_khoa'] !== '' && !preg_match('/^\d{4}-\d{4}$/', $row['nien_khoa'])) {
                    $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Nien khoa sai dinh dang YYYY-YYYY'];
                    continue;
                }
                if (isset($existingHomerooms[$maLopKey])) {
                    $skipped[] = ['line' => $line, 'ma_lop' => $row['ma_lop'], 'reason' => 'Ma lop da ton tai'];
                    continue;
                }

                $resolvedMaNganh = $this->ensureMajorFromKhoa($pdo, $majorsByCode, $majorsByName, $row['khoa']);

                $maGv = $row['ma_gv_co_van'];
                if ($maGv !== '' && !isset($existingTeachers[strtolower($maGv)])) {
                    $maGv = '';
                }

                HomeroomClass::createWithPdo($pdo, [
                    'ma_lop' => $row['ma_lop'],
                    'ten_lop' => $row['ten_lop'],
                    'ma_nganh' => (string)$resolvedMaNganh,
                    'ma_gv_co_van' => $maGv,
                    'nien_khoa' => $row['nien_khoa'],
                ]);
                $existingHomerooms[$maLopKey] = true;
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
            $message = (string)$e->getMessage();
            if (strpos($message, 'database is locked') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'He thong dang ban, vui long thu lai sau it giay.'], 503);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the import lop sinh hoat.', 'detail' => $message], 500);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Loi he thong.', 'detail' => $e->getMessage()], 500);
        }
    }
}
