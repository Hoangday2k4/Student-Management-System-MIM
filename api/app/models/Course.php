<?php

require_once __DIR__ . '/Faculty.php';

class Course
{
    private static function normalizeCourseType(?string $value): string
    {
        $text = strtoupper(trim((string)$value));
        if ($text === 'TU_CHON' || $text === 'TỰ_CHỌN' || $text === 'TUY_CHON') {
            return 'TU_CHON';
        }
        return 'BAT_BUOC';
    }

    private static function lowerText(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private static function splitValues(string $raw): array
    {
        $parts = explode(',', $raw);
        $out = [];
        foreach ($parts as $part) {
            $v = trim($part);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function parseScheduleItem(string $item): ?array
    {
        $raw = strtoupper(trim($item));
        if (!preg_match('/^T([2-8])-\((\d{1,2})-(\d{1,2})\)$/', $raw, $m)) {
            return null;
        }
        $start = (int)$m[2];
        $end = (int)$m[3];
        if ($start <= 0 || $end <= 0 || $start > $end) {
            return null;
        }
        return ['thu' => (int)$m[1], 'ca' => $start . '-' . $end];
    }

    private static function ensureMapForLhp(PDO $pdo, string $maLHP): int
    {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO LopHocPhanMap (MaLHP) VALUES (:ma)');
        $stmt->execute([':ma' => $maLHP]);
        $stmt = $pdo->prepare('SELECT LegacyId FROM LopHocPhanMap WHERE MaLHP = :ma LIMIT 1');
        $stmt->execute([':ma' => $maLHP]);
        return (int)$stmt->fetchColumn();
    }

    private static function getMaLhpByLegacyId(PDO $pdo, int $id): ?string
    {
        $stmt = $pdo->prepare('SELECT MaLHP FROM LopHocPhanMap WHERE LegacyId = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $ma = $stmt->fetchColumn();
        return $ma !== false ? (string)$ma : null;
    }

    private static function resolveNganh(PDO $pdo, ?string $department): ?string
    {
        return Faculty::resolveIdByName($pdo, (string)$department);
    }

    /**
     * Resolve Faculty (Khoa) code từ tên Khoa hoặc Ngành
     * Logic: Input có thể là tên Khoa, tên Ngành, hoặc Mã Khoa
     * Output: MaKhoa để lưu vào LopHocPhan
     */
    private static function resolveKhoaCode(PDO $pdo, ?string $input): ?string
    {
        $value = trim((string)$input);
        if ($value === '') {
            return null;
        }

        // 1. Try matching Khoa code (e.g., "KHOA_CS")
        $stmt = $pdo->prepare('SELECT MaKhoa FROM Khoa WHERE lower(MaKhoa) = lower(:v) LIMIT 1');
        $stmt->execute([':v' => $value]);
        $code = $stmt->fetchColumn();
        if ($code !== false && trim((string)$code) !== '') {
            return trim((string)$code);
        }

        // 2. Try matching Khoa name (e.g., "Khoa Công nghệ Thông tin")
        $stmt = $pdo->prepare('SELECT MaKhoa FROM Khoa WHERE lower(TenKhoa) = lower(:v) LIMIT 1');
        $stmt->execute([':v' => $value]);
        $code = $stmt->fetchColumn();
        if ($code !== false && trim((string)$code) !== '') {
            return trim((string)$code);
        }

        // 3. Try matching Nganh name to get its Khoa (e.g., "Công nghệ Phần mềm" -> Khoa CNTT)
        $stmt = $pdo->prepare('SELECT MaKhoa FROM Nganh WHERE lower(TenNganh) = lower(:v) LIMIT 1');
        $stmt->execute([':v' => $value]);
        $code = $stmt->fetchColumn();
        if ($code !== false && trim((string)$code) !== '') {
            return trim((string)$code);
        }

        // 4. Try matching Nganh code to get its Khoa
        $stmt = $pdo->prepare('SELECT MaKhoa FROM Nganh WHERE lower(MaNganh) = lower(:v) LIMIT 1');
        $stmt->execute([':v' => $value]);
        $code = $stmt->fetchColumn();
        if ($code !== false && trim((string)$code) !== '') {
            return trim((string)$code);
        }

        return null;
    }

     private static function resolveNganhCode(PDO $pdo, ?string $department): ?string
    {
        $value = trim((string)$department);
        if ($value === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT MaNganh FROM Nganh WHERE lower(MaNganh) = lower(:v) LIMIT 1');
        $stmt->execute([':v' => $value]);
        $code = $stmt->fetchColumn();
        if ($code !== false && trim((string)$code) !== '') {
            return trim((string)$code);
        }

        $stmt = $pdo->prepare('SELECT MaNganh FROM Nganh WHERE lower(TenNganh) = lower(:v) LIMIT 1');
        $stmt->execute([':v' => $value]);
        $code = $stmt->fetchColumn();
        if ($code !== false && trim((string)$code) !== '') {
            return trim((string)$code);
        }

        $khoaCode = Faculty::resolveIdByName($pdo, $value);
        if (is_string($khoaCode) && trim($khoaCode) !== '') {
            $stmt = $pdo->prepare('SELECT MaNganh FROM Nganh WHERE lower(MaKhoa) = lower(:ma_khoa) ORDER BY MaNganh ASC LIMIT 1');
            $stmt->execute([':ma_khoa' => trim($khoaCode)]);
            $code = $stmt->fetchColumn();
            if ($code !== false && trim((string)$code) !== '') {
                return trim((string)$code);
            }
        }

        return null;
    }


    private static function buildScheduleClassroom(PDO $pdo, string $maLHP): array
    {
        $stmt = $pdo->prepare('SELECT Thu, CaHoc, PhongHoc FROM ThoiKhoaBieu WHERE MaLHP = :ma ORDER BY Id ASC');
        $stmt->execute([':ma' => $maLHP]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $schedule = [];
        $classroom = [];
        foreach ($rows as $r) {
            $thu = (int)($r['Thu'] ?? 0);
            $caRaw = trim((string)($r['CaHoc'] ?? ''));
            if ($thu > 0 && $caRaw !== '') {
                if (preg_match('/^\d+$/', $caRaw)) {
                    $caRaw = $caRaw . '-' . $caRaw;
                }
                $schedule[] = 'T' . $thu . '-(' . $caRaw . ')';
            }
            $ph = trim((string)($r['PhongHoc'] ?? ''));
            if ($ph !== '') {
                $classroom[] = $ph;
            }
        }
        return [
            'schedule' => implode(', ', $schedule),
            'classroom' => implode(', ', array_values(array_unique($classroom))),
        ];
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        Faculty::ensureSchema($pdo);

        // 1. TẠO BẢNG MỚI (Dành cho Database trống)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS MonHoc (
                MaMon TEXT PRIMARY KEY,
                TenMon TEXT NOT NULL,
                SoTinChi INTEGER NOT NULL,
                LoaiMon TEXT,
                MaNganh TEXT
            )'
        );
        $pdo->exec('CREATE TABLE IF NOT EXISTS Nganh (
            MaNganh TEXT PRIMARY KEY, 
            TenNganh TEXT NOT NULL,
            MaKhoa TEXT
        )');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS GiangVien (
                MaGV TEXT PRIMARY KEY,
                HoTen TEXT NOT NULL,
                GioiTinh TEXT,
                NgaySinh TEXT,
                Email TEXT UNIQUE NOT NULL,
                SoDienThoai TEXT,
                HocHamHocVi TEXT,
                TrangThai TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS LopHocPhan (
                MaLHP TEXT PRIMARY KEY,
                MaMon TEXT,
                MaGV TEXT,
                MaKhoa TEXT,
                HocKy INTEGER,
                NamHoc TEXT,
                SoLuongToiDa INTEGER
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ThoiKhoaBieu (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                MaLHP TEXT,
                Thu INTEGER,
                CaHoc TEXT,
                PhongHoc TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS KetQuaHocTap (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                MaSV TEXT,
                MaLHP TEXT,
                DiemChuyenCan REAL,
                DiemGiuaKy REAL,
                DiemCuoiKy REAL,
                UNIQUE(MaSV, MaLHP)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS SinhVien (
                MaSV TEXT PRIMARY KEY,
                HoTen TEXT NOT NULL,
                NgaySinh TEXT,
                GioiTinh TEXT,
                CCCD TEXT UNIQUE,
                DiaChi TEXT,
                SoDienThoai TEXT,
                Email TEXT UNIQUE,
                MaLop TEXT,
                NgayNhapHoc TEXT,
                TrangThai TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS LopHocPhanMap (
                LegacyId INTEGER PRIMARY KEY AUTOINCREMENT,
                MaLHP TEXT NOT NULL UNIQUE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lhpmap_ma ON LopHocPhanMap(MaLHP)');

        // ---------------------------------------------------------
        // 2. MIGRATION ĐỂ VÁ LỖI CHO DATABASE CŨ (QUAN TRỌNG)
        // ---------------------------------------------------------

        // A. Cập nhật bảng MonHoc
        $mhCols = $pdo->query('PRAGMA table_info(MonHoc)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mhNames = [];
        foreach ($mhCols as $c) $mhNames[(string)$c['name']] = true;
        
        if (!isset($mhNames['LoaiMon'])) {
            $pdo->exec('ALTER TABLE MonHoc ADD COLUMN LoaiMon TEXT DEFAULT "BAT_BUOC"');
        }
        if (!isset($mhNames['MaNganh'])) {
            $pdo->exec('ALTER TABLE MonHoc ADD COLUMN MaNganh TEXT');
        }

        // B. Cập nhật bảng LopHocPhan
        $columns = $pdo->query('PRAGMA table_info(LopHocPhan)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        foreach ($columns as $c) $names[(string)$c['name']] = true;
        
        if (!isset($names['MaKhoa'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN MaKhoa TEXT');
        }
        if (!isset($names['TrongSoCC'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoCC REAL DEFAULT 0');
        }
        if (!isset($names['TrongSoGK'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoGK REAL DEFAULT 0');
        }
        if (!isset($names['TrongSoCK'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoCK REAL DEFAULT 0');
        }
        if (!isset($names['CreatedAt'])) {
            // SQLite khong cho ADD COLUMN voi default khong hang so (CURRENT_TIMESTAMP)
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE LopHocPhan SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }
        if (!isset($names['TrangThai'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrangThai TEXT DEFAULT "ACTIVE"');
        }

        // C. Cập nhật bảng ThoiKhoaBieu (Chuyen CaHoc tu INTEGER sang TEXT)
        $tkbCols = $pdo->query('PRAGMA table_info(ThoiKhoaBieu)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $caHocType = '';
        foreach ($tkbCols as $col) {
            if (strcasecmp((string)($col['name'] ?? ''), 'CaHoc') === 0) {
                $caHocType = strtoupper(trim((string)($col['type'] ?? '')));
                break;
            }
        }
        if ($caHocType !== '' && strpos($caHocType, 'TEXT') === false && strpos($caHocType, 'CHAR') === false && strpos($caHocType, 'VARCHAR') === false) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS ThoiKhoaBieu_new (
                    Id INTEGER PRIMARY KEY AUTOINCREMENT,
                    MaLHP TEXT,
                    Thu INTEGER,
                    CaHoc TEXT,
                    PhongHoc TEXT
                )'
            );
            $pdo->exec(
                "INSERT INTO ThoiKhoaBieu_new (Id, MaLHP, Thu, CaHoc, PhongHoc)
                 SELECT Id, MaLHP, Thu,
                        CASE
                          WHEN CaHoc IS NULL OR trim(CaHoc) = '' THEN ''
                          WHEN trim(CaHoc) GLOB '[0-9]*' THEN trim(CaHoc) || '-' || trim(CaHoc)
                          ELSE trim(CaHoc)
                        END,
                        PhongHoc
                 FROM ThoiKhoaBieu"
            );
            $pdo->exec('DROP TABLE ThoiKhoaBieu');
            $pdo->exec('ALTER TABLE ThoiKhoaBieu_new RENAME TO ThoiKhoaBieu');
        }

        $rows = $pdo->query('SELECT MaLHP FROM LopHocPhan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $maLHP) {
            self::ensureMapForLhp($pdo, (string)$maLHP);
        }


        // Phần điểm danh
        // Thêm các cột phục vụ tính năng Điểm danh (Tương đương Entity Enrollment của bạn)
        $kqCols = $pdo->query('PRAGMA table_info(KetQuaHocTap)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $kqNames = [];
        foreach ($kqCols as $c) $kqNames[(string)$c['name']] = true;

        if (!isset($kqNames['LichSuDiemDanh'])) {
            $pdo->exec('ALTER TABLE KetQuaHocTap ADD COLUMN LichSuDiemDanh TEXT DEFAULT ""');
        }
        if (!isset($kqNames['SoBuoiVang'])) {
            $pdo->exec('ALTER TABLE KetQuaHocTap ADD COLUMN SoBuoiVang INTEGER DEFAULT 0');
        }
        if (!isset($kqNames['DiemTongKet'])) {
            $pdo->exec('ALTER TABLE KetQuaHocTap ADD COLUMN DiemTongKet REAL');
        }
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        self::ensureSchema($pdo);
        $maMon = trim((string)$data['course_code']);
        $maLHP = trim((string)($data['section_code'] ?? ''));
        if ($maLHP === '') {
            $maLHP = $maMon . '-01';
            $i = 1;
            while (true) {
                $stmt = $pdo->prepare('SELECT 1 FROM LopHocPhan WHERE MaLHP = :ma LIMIT 1');
                $stmt->execute([':ma' => $maLHP]);
                if (!$stmt->fetchColumn()) break;
                $i++;
                $maLHP = $maMon . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            }
        }

       $maNganh = self::resolveNganhCode($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'INSERT INTO MonHoc (MaMon, TenMon, SoTinChi, LoaiMon, MaNganh)
             VALUES (:ma_mon,:ten_mon,:so_tin_chi,"BAT_BUOC",:ma_nganh)
             ON CONFLICT(MaMon) DO UPDATE SET
                TenMon = excluded.TenMon,
                SoTinChi = excluded.SoTinChi,
                MaNganh = COALESCE(excluded.MaNganh, MonHoc.MaNganh)'
        );
        $stmt->execute([
            ':ma_mon' => $maMon,
            ':ten_mon' => $data['course_name'],
            ':so_tin_chi' => (int)($data['credits'] ?? 0),
            ':ma_nganh' => $maNganh,
        ]);

        // Resolve Khoa (Faculty) code từ input department
        $maKhoa = self::resolveKhoaCode($pdo, (string)($data['department'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO LopHocPhan (
                MaLHP, MaMon, MaGV, MaKhoa, HocKy, NamHoc, SoLuongToiDa, TrongSoCC, TrongSoGK, TrongSoCK, CreatedAt
            ) VALUES (
                :ma_lhp, :ma_mon, :ma_gv, :ma_khoa, :hoc_ky, :nam_hoc, :max, 0, 0, 0, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_lhp' => $maLHP,
            ':ma_mon' => $maMon,
            ':ma_gv' => $data['teacher_code'] ?: null,
            ':ma_khoa' => $maKhoa,
            ':hoc_ky' => $data['semester'] !== null ? (int)$data['semester'] : null,
            ':nam_hoc' => trim((string)($data['academic_year'] ?? '')) !== '' ? trim((string)$data['academic_year']) : null,
            ':max' => $data['max_students'] !== null ? (int)$data['max_students'] : null,
        ]);

        $schedules = self::splitValues((string)($data['schedule'] ?? ''));
        $rooms = self::splitValues((string)($data['classroom'] ?? ''));
        $insTkb = $pdo->prepare('INSERT INTO ThoiKhoaBieu (MaLHP, Thu, CaHoc, PhongHoc) VALUES (:ma_lhp,:thu,:ca,:phong)');
        foreach ($schedules as $idx => $raw) {
            $slot = self::parseScheduleItem($raw);
            if (!$slot) continue;
            $room = $rooms[$idx] ?? ($rooms[0] ?? null);
            $insTkb->execute([
                ':ma_lhp' => $maLHP,
                ':thu' => $slot['thu'],
                ':ca' => $slot['ca'],
                ':phong' => $room,
            ]);
        }

        return self::ensureMapForLhp($pdo, $maLHP);
    }

    public static function updateByIdWithPdo(PDO $pdo, int $courseId, array $data): bool
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return false;

        $stmt = $pdo->prepare('SELECT MaMon FROM LopHocPhan WHERE MaLHP = :ma_lhp LIMIT 1');
        $stmt->execute([':ma_lhp' => $maLHP]);
        $oldMaMon = (string)$stmt->fetchColumn();
        $newMaMon = trim((string)$data['course_code']);

        $maNganh = self::resolveNganhCode($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'INSERT INTO MonHoc (MaMon, TenMon, SoTinChi, LoaiMon, MaNganh)
             VALUES (:ma_mon,:ten_mon,:so_tin_chi,"BAT_BUOC",:ma_nganh)
             ON CONFLICT(MaMon) DO UPDATE SET
                TenMon = excluded.TenMon,
                SoTinChi = excluded.SoTinChi,
                MaNganh = COALESCE(excluded.MaNganh, MonHoc.MaNganh)'
        );
        $stmt->execute([
            ':ma_mon' => $newMaMon,
            ':ten_mon' => $data['course_name'],
            ':so_tin_chi' => (int)($data['credits'] ?? 0),
            ':ma_nganh' => $maNganh,
        ]);

        // Resolve Khoa (Faculty) code từ input department
        $maKhoa = self::resolveKhoaCode($pdo, (string)($data['department'] ?? ''));

        $stmt = $pdo->prepare(
            'UPDATE LopHocPhan
             SET MaMon = :ma_mon,
                 MaGV = :ma_gv,
                 MaKhoa = :ma_khoa,
                 HocKy = :hoc_ky,
                 NamHoc = :nam_hoc,
                 SoLuongToiDa = :max
             WHERE MaLHP = :ma_lhp'
        );
        $stmt->execute([
            ':ma_mon' => $newMaMon,
            ':ma_gv' => $data['teacher_code'] ?: null,
            ':ma_khoa' => $maKhoa,
            ':hoc_ky' => $data['semester'] !== null ? (int)$data['semester'] : null,
            ':nam_hoc' => trim((string)($data['academic_year'] ?? '')) !== '' ? trim((string)$data['academic_year']) : null,
            ':max' => $data['max_students'] !== null ? (int)$data['max_students'] : null,
            ':ma_lhp' => $maLHP,
        ]);

        $pdo->prepare('DELETE FROM ThoiKhoaBieu WHERE MaLHP = :ma_lhp')->execute([':ma_lhp' => $maLHP]);
        $schedules = self::splitValues((string)($data['schedule'] ?? ''));
        $rooms = self::splitValues((string)($data['classroom'] ?? ''));
        $insTkb = $pdo->prepare('INSERT INTO ThoiKhoaBieu (MaLHP, Thu, CaHoc, PhongHoc) VALUES (:ma_lhp,:thu,:ca,:phong)');
        foreach ($schedules as $idx => $raw) {
            $slot = self::parseScheduleItem($raw);
            if (!$slot) continue;
            $room = $rooms[$idx] ?? ($rooms[0] ?? null);
            $insTkb->execute([
                ':ma_lhp' => $maLHP,
                ':thu' => $slot['thu'],
                ':ca' => $slot['ca'],
                ':phong' => $room,
            ]);
        }

        if ($oldMaMon !== '' && $oldMaMon !== $newMaMon) {
            $stmt = $pdo->prepare('SELECT COUNT(1) FROM LopHocPhan WHERE MaMon = :ma_mon');
            $stmt->execute([':ma_mon' => $oldMaMon]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM MonHoc WHERE MaMon = :ma_mon')->execute([':ma_mon' => $oldMaMon]);
            }
        }
        return true;
    }

    public static function deleteByIdWithPdo(PDO $pdo, int $courseId): void
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return;
        $stmt = $pdo->prepare('SELECT MaMon FROM LopHocPhan WHERE MaLHP = :ma_lhp LIMIT 1');
        $stmt->execute([':ma_lhp' => $maLHP]);
        $maMon = (string)$stmt->fetchColumn();

        $pdo->prepare('DELETE FROM KetQuaHocTap WHERE MaLHP = :ma_lhp')->execute([':ma_lhp' => $maLHP]);
        $pdo->prepare('DELETE FROM ThoiKhoaBieu WHERE MaLHP = :ma_lhp')->execute([':ma_lhp' => $maLHP]);
        $pdo->prepare('DELETE FROM LopHocPhan WHERE MaLHP = :ma_lhp')->execute([':ma_lhp' => $maLHP]);
        $pdo->prepare('DELETE FROM LopHocPhanMap WHERE MaLHP = :ma_lhp')->execute([':ma_lhp' => $maLHP]);

       
    }

    public static function replaceEnrollmentsWithPdo(PDO $pdo, int $courseId, array $studentCodes): void
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return;
        $pdo->prepare('DELETE FROM KetQuaHocTap WHERE MaLHP = :ma_lhp')->execute([':ma_lhp' => $maLHP]);
        if (empty($studentCodes)) return;
        self::appendEnrollmentsWithPdo($pdo, $courseId, $studentCodes);
    }

    public static function appendEnrollmentsWithPdo(PDO $pdo, int $courseId, array $studentCodes): int
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return 0;
        $ins = $pdo->prepare(
            'INSERT INTO KetQuaHocTap (MaSV, MaLHP, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy)
             VALUES (:ma_sv, :ma_lhp, NULL, NULL, NULL)
             ON CONFLICT(MaSV, MaLHP) DO NOTHING'
        );
        $added = 0;
        foreach ($studentCodes as $code) {
            $code = trim((string)$code);
            if ($code === '') continue;
            $ins->execute([':ma_sv' => $code, ':ma_lhp' => $maLHP]);
            if ($ins->rowCount() > 0) $added++;
        }
        return $added;
    }

    public static function findValidStudentCodes(PDO $pdo, array $studentCodes): array
    {
        $studentCodes = array_values(array_unique(array_filter(array_map('trim', $studentCodes))));
        if (empty($studentCodes)) return [];
        $placeholders = implode(',', array_fill(0, count($studentCodes), '?'));
        $stmt = $pdo->prepare("SELECT MaSV FROM SinhVien WHERE MaSV IN ($placeholders)");
        $stmt->execute($studentCodes);
        return array_map(static fn($r) => (string)$r['MaSV'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

   public static function findById(int $id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maLHP = self::getMaLhpByLegacyId($pdo, $id);
        if (!$maLHP) return false;
        
        // Simplify: Lấy Khoa trực tiếp từ LopHocPhan.MaKhoa
        $stmt = $pdo->prepare(
            'SELECT l.MaLHP, l.MaMon, l.MaGV, l.MaKhoa, l.HocKy, l.NamHoc, l.SoLuongToiDa, l.TrongSoCC, l.TrongSoGK, l.TrongSoCK, l.CreatedAt,
                    m.TenMon, m.SoTinChi,
                    k.TenKhoa, g.HoTen AS teacher_name
             FROM LopHocPhan l
             LEFT JOIN MonHoc m ON m.MaMon = l.MaMon
             LEFT JOIN Khoa k ON k.MaKhoa = l.MaKhoa
             LEFT JOIN GiangVien g ON g.MaGV = l.MaGV
             WHERE l.MaLHP = :ma_lhp
             LIMIT 1'
        );
        $stmt->execute([':ma_lhp' => $maLHP]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $scheduleInfo = self::buildScheduleClassroom($pdo, $maLHP);
        $countStmt = $pdo->prepare('SELECT COUNT(1) FROM KetQuaHocTap WHERE MaLHP = :ma_lhp');
        $countStmt->execute([':ma_lhp' => $maLHP]);
        
        // Lấy Tên Khoa từ LopHocPhan.MaKhoa
        $deptName = (string)($row['TenKhoa'] ?? '');

        return [
            'id' => $id,
            'section_code' => $row['MaLHP'] ?? '',
            'course_code' => $row['MaMon'] ?? '',
            'course_name' => $row['TenMon'] ?? '',
            'credits' => $row['SoTinChi'] !== null ? (int)$row['SoTinChi'] : null,
            'teacher_code' => $row['MaGV'] ?? '',
            'semester' => $row['HocKy'] !== null ? (int)$row['HocKy'] : null,
            'academic_year' => $row['NamHoc'] ?? '',
            'department' => $deptName,
            'department_name' => $deptName,
            'schedule' => $scheduleInfo['schedule'],
            'classroom' => $scheduleInfo['classroom'],
            'max_students' => $row['SoLuongToiDa'] !== null ? (int)$row['SoLuongToiDa'] : null,
            'weight_cc' => (float)($row['TrongSoCC'] ?? 0),
            'weight_gk' => (float)($row['TrongSoGK'] ?? 0),
            'weight_ck' => (float)($row['TrongSoCK'] ?? 0),
            'created_at' => $row['CreatedAt'] ?? '',
            'teacher_name' => $row['teacher_name'] ?? '',
            'enrolled_count' => (int)$countStmt->fetchColumn(), // Đây là số SV thực tế hiện có trong lớp
        ];
    }

    public static function isStudentEnrolled(int $courseId, string $studentCode): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return false;
        $stmt = $pdo->prepare('SELECT 1 FROM KetQuaHocTap WHERE lower(MaSV)=lower(:ma_sv) AND MaLHP = :ma_lhp LIMIT 1');
        $stmt->execute([':ma_sv' => $studentCode, ':ma_lhp' => $maLHP]);
        return (bool)$stmt->fetchColumn();
    }

    public static function getStudentsByCourseId(int $courseId): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return [];
        $stmt = $pdo->prepare(
            'SELECT s.MaSV AS student_code, s.HoTen AS full_name, s.MaLop AS class_name,ng.TenNganh AS major, n.TenKhoa AS faculty, s.Email AS email, s.SoDienThoai AS phone
             FROM KetQuaHocTap k
             INNER JOIN SinhVien s ON s.MaSV = k.MaSV
             LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
             LEFT JOIN Nganh ng ON ng.MaNganh = l.MaNganh
             LEFT JOIN Khoa n ON n.MaKhoa = ng.MaKhoa -- SỬA LỖI Ở ĐÂY: JOIN Khoa với Ngành thay vì Lớp Sinh Hoạt
             WHERE k.MaLHP = :ma_lhp
             ORDER BY s.MaSV ASC'
        );
        $stmt->execute([':ma_lhp' => $maLHP]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getScoresByCourseId(int $courseId): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return [];
        $stmt = $pdo->prepare('SELECT MaSV, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy FROM KetQuaHocTap WHERE MaLHP = :ma_lhp');
        $stmt->execute([':ma_lhp' => $maLHP]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['MaSV']] = [
                'cc' => $r['DiemChuyenCan'] !== null ? (float)$r['DiemChuyenCan'] : null,
                'gk' => $r['DiemGiuaKy'] !== null ? (float)$r['DiemGiuaKy'] : null,
                'ck' => $r['DiemCuoiKy'] !== null ? (float)$r['DiemCuoiKy'] : null,
            ];
        }
        return $map;
    }

    public static function updateWeightsWithPdo(PDO $pdo, int $courseId, float $weightCc, float $weightGk, float $weightCk): bool
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return false;
        $stmt = $pdo->prepare('UPDATE LopHocPhan SET TrongSoCC = :cc, TrongSoGK = :gk, TrongSoCK = :ck WHERE MaLHP = :ma_lhp');
        return $stmt->execute([
            ':cc' => $weightCc,
            ':gk' => $weightGk,
            ':ck' => $weightCk,
            ':ma_lhp' => $maLHP,
        ]);
    }

    public static function upsertScoresWithPdo(PDO $pdo, int $courseId, array $scores): void
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return;
        $stmt = $pdo->prepare(
            'INSERT INTO KetQuaHocTap (MaSV, MaLHP, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy)
             VALUES (:ma_sv, :ma_lhp, :cc, :gk, :ck)
             ON CONFLICT(MaSV, MaLHP)
             DO UPDATE SET DiemChuyenCan = excluded.DiemChuyenCan, DiemGiuaKy = excluded.DiemGiuaKy, DiemCuoiKy = excluded.DiemCuoiKy'
        );
        foreach ($scores as $row) {
            $stmt->execute([
                ':ma_sv' => (string)$row['student_code'],
                ':ma_lhp' => $maLHP,
                ':cc' => $row['cc'],
                ':gk' => $row['gk'],
                ':ck' => $row['ck'],
            ]);
        }
    }

    public static function searchForStaff(array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql = 'SELECT m.MaMon, m.TenMon, m.SoTinChi, n.TenKhoa, l.MaLHP, l.MaGV, l.MaKhoa, l.HocKy, l.NamHoc, l.SoLuongToiDa, l.CreatedAt, g.HoTen AS teacher_name
                FROM LopHocPhan l
                INNER JOIN MonHoc m ON m.MaMon = l.MaMon
                LEFT JOIN Khoa n ON n.MaKhoa = l.MaKhoa
                LEFT JOIN GiangVien g ON g.MaGV = l.MaGV
                WHERE 1=1';
        $params = [];
        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(m.MaMon) LIKE :keyword
                OR lower(m.TenMon) LIKE :keyword
                OR lower(IFNULL(l.MaGV,"")) LIKE :keyword
                OR lower(IFNULL(g.HoTen,"")) LIKE :keyword
            )';
            $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }
        if (!empty($filters['department'])) {
            $sql .= ' AND lower(IFNULL(n.TenKhoa,"")) LIKE :department';
            $params[':department'] = '%' . self::lowerText((string)$filters['department']) . '%';
        }
        if (!empty($filters['teacher_code'])) {
            $sql .= ' AND lower(IFNULL(l.MaGV,"")) LIKE :teacher_code';
            $params[':teacher_code'] = '%' . self::lowerText((string)$filters['teacher_code']) . '%';
        }
        $sql .= ' ORDER BY lower(IFNULL(n.TenKhoa,"")) ASC, CAST(substr(m.MaMon,4) AS INTEGER) ASC, m.MaMon ASC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $id = self::ensureMapForLhp($pdo, (string)$row['MaLHP']);
            $sch = self::buildScheduleClassroom($pdo, (string)$row['MaLHP']);
            $cst = $pdo->prepare('SELECT COUNT(1) FROM KetQuaHocTap WHERE MaLHP = :ma');
            $cst->execute([':ma' => $row['MaLHP']]);
            $out[] = [
                'id' => $id,
                'section_code' => $row['MaLHP'] ?? '',
                'course_code' => $row['MaMon'] ?? '',
                'course_name' => $row['TenMon'] ?? '',
                'credits' => $row['SoTinChi'] !== null ? (int)$row['SoTinChi'] : null,
                'teacher_code' => $row['MaGV'] ?? '',
                'semester' => $row['HocKy'] !== null ? (int)$row['HocKy'] : null,
                'academic_year' => $row['NamHoc'] ?? '',
                'department' => (string)($row['TenKhoa'] ?? ''),
                'schedule' => $sch['schedule'],
                'classroom' => $sch['classroom'],
                'max_students' => $row['SoLuongToiDa'] !== null ? (int)$row['SoLuongToiDa'] : null,
                'created_at' => $row['CreatedAt'] ?? '',
                'teacher_name' => $row['teacher_name'] ?? '',
                'enrolled_count' => (int)$cst->fetchColumn(),
            ];
        }
        return $out;
    }

    public static function searchSubjectsForStaff(array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $sql = 'SELECT
                    m.MaMon,
                    m.TenMon,
                    m.SoTinChi,
                    m.LoaiMon,
                    m.MaNganh,
                    ng.TenNganh,
                    k.MaKhoa,
                    k.TenKhoa,
                    COUNT(DISTINCT l.MaLHP) AS section_count
                FROM MonHoc m
                LEFT JOIN Nganh ng ON ng.MaNganh = m.MaNganh
                LEFT JOIN Khoa k ON k.MaKhoa = ng.MaKhoa
                LEFT JOIN LopHocPhan l ON l.MaMon = m.MaMon
                WHERE 1=1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(m.MaMon) LIKE :keyword
                OR lower(m.TenMon) LIKE :keyword
            )';
            $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }

        if (!empty($filters['department'])) {
            $dept = self::lowerText((string)$filters['department']);
            $sql .= ' AND (
                lower(IFNULL(k.MaKhoa, "")) = :department_exact
                OR lower(IFNULL(k.TenKhoa, "")) LIKE :department_like
                OR lower(IFNULL(ng.MaNganh, "")) = :department_exact
                OR lower(IFNULL(ng.TenNganh, "")) LIKE :department_like
            )';
            $params[':department_exact'] = $dept;
            $params[':department_like'] = '%' . $dept . '%';
        }

        $sql .= ' GROUP BY m.MaMon, m.TenMon, m.SoTinChi, m.LoaiMon, m.MaNganh, ng.TenNganh, k.MaKhoa, k.TenKhoa
                  ORDER BY lower(IFNULL(k.TenKhoa,"")) ASC, CAST(substr(m.MaMon,4) AS INTEGER) ASC, m.MaMon ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'course_code' => trim((string)($row['MaMon'] ?? '')),
                'course_name' => trim((string)($row['TenMon'] ?? '')),
                'credits' => isset($row['SoTinChi']) ? (int)$row['SoTinChi'] : null,
                'course_type' => self::normalizeCourseType((string)($row['LoaiMon'] ?? 'BAT_BUOC')),
                'department_code' => trim((string)($row['MaNganh'] ?? '')),
                'department_name' => trim((string)(($row['TenKhoa'] ?? '') !== '' ? $row['TenKhoa'] : ($row['TenNganh'] ?? ''))),
                'section_count' => (int)($row['section_count'] ?? 0),
            ];
        }, $rows);
    }

    public static function insertSubjectWithPdo(PDO $pdo, array $data): array
    {
        self::ensureSchema($pdo);

        $courseCode = strtoupper(trim((string)($data['course_code'] ?? '')));
        $courseName = trim((string)($data['course_name'] ?? ''));
        $creditsRaw = trim((string)($data['credits'] ?? ''));
        $departmentRaw = trim((string)($data['department'] ?? ''));
        $courseType = self::normalizeCourseType((string)($data['course_type'] ?? 'BAT_BUOC'));

        if ($courseCode === '') {
            throw new RuntimeException('Hãy nhập mã môn học.');
        }
        if ($courseName === '') {
            throw new RuntimeException('Hãy nhập tên môn học.');
        }
        if ($creditsRaw === '' || !preg_match('/^\d+$/', $creditsRaw) || (int)$creditsRaw <= 0) {
            throw new RuntimeException('Số tín chỉ phải là số nguyên dương.');
        }

        $departmentCode = self::resolveNganhCode($pdo, $departmentRaw);
        if ($departmentRaw !== '' && !$departmentCode) {
            throw new RuntimeException('Không tìm thấy ngành/khoa quản lý môn học.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO MonHoc (MaMon, TenMon, SoTinChi, LoaiMon, MaNganh)
             VALUES (:ma_mon, :ten_mon, :so_tin_chi, :loai_mon, :ma_nganh)'
        );
        $stmt->execute([
            ':ma_mon' => $courseCode,
            ':ten_mon' => $courseName,
            ':so_tin_chi' => (int)$creditsRaw,
            ':loai_mon' => $courseType,
            ':ma_nganh' => $departmentCode,
        ]);

        $q = $pdo->prepare(
            'SELECT m.MaMon, m.TenMon, m.SoTinChi, m.LoaiMon, m.MaNganh, ng.TenNganh, k.TenKhoa
             FROM MonHoc m
             LEFT JOIN Nganh ng ON ng.MaNganh = m.MaNganh
             LEFT JOIN Khoa k ON k.MaKhoa = ng.MaKhoa
             WHERE m.MaMon = :ma_mon
             LIMIT 1'
        );
        $q->execute([':ma_mon' => $courseCode]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'course_code' => trim((string)($row['MaMon'] ?? $courseCode)),
            'course_name' => trim((string)($row['TenMon'] ?? $courseName)),
            'credits' => isset($row['SoTinChi']) ? (int)$row['SoTinChi'] : (int)$creditsRaw,
            'course_type' => self::normalizeCourseType((string)($row['LoaiMon'] ?? $courseType)),
            'department_code' => trim((string)($row['MaNganh'] ?? $departmentCode)),
            'department_name' => trim((string)(($row['TenKhoa'] ?? '') !== '' ? $row['TenKhoa'] : ($row['TenNganh'] ?? ''))),
        ];
    }

    public static function deleteSubjectByCodeWithPdo(PDO $pdo, string $courseCode): void
    {
        self::ensureSchema($pdo);
        $courseCode = strtoupper(trim($courseCode));
        if ($courseCode === '') {
            throw new RuntimeException('Thiếu mã môn học.');
        }

        $stmt = $pdo->prepare('SELECT COUNT(1) FROM LopHocPhan WHERE MaMon = :ma_mon');
        $stmt->execute([':ma_mon' => $courseCode]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Không thể xóa môn học vì đã có lớp học phần.');
        }

        $del = $pdo->prepare('DELETE FROM MonHoc WHERE MaMon = :ma_mon');
        $del->execute([':ma_mon' => $courseCode]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Không tìm thấy môn học.');
        }
    }

    public static function findSubjectByCode(string $courseCode)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $courseCode = strtoupper(trim($courseCode));
        if ($courseCode === '') return false;

        $stmt = $pdo->prepare(
            'SELECT
                m.MaMon,
                m.TenMon,
                m.SoTinChi,
                m.LoaiMon,
                m.MaNganh,
                ng.TenNganh,
                ng.MaKhoa,
                k.TenKhoa,
                COUNT(DISTINCT l.MaLHP) AS section_count,
                COUNT(DISTINCT kq.MaSV) AS student_count
             FROM MonHoc m
             LEFT JOIN Nganh ng ON ng.MaNganh = m.MaNganh
             LEFT JOIN Khoa k ON k.MaKhoa = ng.MaKhoa
             LEFT JOIN LopHocPhan l ON l.MaMon = m.MaMon
             LEFT JOIN KetQuaHocTap kq ON kq.MaLHP = l.MaLHP
             WHERE upper(m.MaMon) = :ma_mon
             GROUP BY m.MaMon, m.TenMon, m.SoTinChi, m.LoaiMon, m.MaNganh, ng.TenNganh, ng.MaKhoa, k.TenKhoa
             LIMIT 1'
        );
        $stmt->execute([':ma_mon' => $courseCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        return [
            'course_code' => trim((string)($row['MaMon'] ?? '')),
            'course_name' => trim((string)($row['TenMon'] ?? '')),
            'credits' => isset($row['SoTinChi']) ? (int)$row['SoTinChi'] : null,
            'course_type' => self::normalizeCourseType((string)($row['LoaiMon'] ?? 'BAT_BUOC')),
            'department_code' => trim((string)($row['MaNganh'] ?? '')),
            'department_name' => trim((string)(($row['TenKhoa'] ?? '') !== '' ? $row['TenKhoa'] : ($row['TenNganh'] ?? ''))),
            'faculty_code' => trim((string)($row['MaKhoa'] ?? '')),
            'faculty_name' => trim((string)($row['TenKhoa'] ?? '')),
            'section_count' => (int)($row['section_count'] ?? 0),
            'student_count' => (int)($row['student_count'] ?? 0),
        ];
    }

    public static function updateSubjectByCodeWithPdo(PDO $pdo, string $originalCode, array $data): array
    {
        self::ensureSchema($pdo);
        $originalCode = strtoupper(trim($originalCode));
        if ($originalCode === '') {
            throw new RuntimeException('Thiếu mã môn học gốc.');
        }

        $current = self::findSubjectByCode($originalCode);
        if (!$current) {
            throw new RuntimeException('Không tìm thấy môn học.');
        }

        $newCode = strtoupper(trim((string)($data['course_code'] ?? '')));
        $newName = trim((string)($data['course_name'] ?? ''));
        $creditsRaw = trim((string)($data['credits'] ?? ''));
        $departmentRaw = trim((string)($data['department'] ?? ''));
        $courseType = self::normalizeCourseType((string)($data['course_type'] ?? ($current['course_type'] ?? 'BAT_BUOC')));

        if ($newCode === '') {
            throw new RuntimeException('Hãy nhập mã môn học.');
        }
        if ($newName === '') {
            throw new RuntimeException('Hãy nhập tên môn học.');
        }
        if ($creditsRaw === '' || !preg_match('/^\d+$/', $creditsRaw) || (int)$creditsRaw <= 0) {
            throw new RuntimeException('Số tín chỉ phải là số nguyên dương.');
        }

        $departmentCode = self::resolveNganhCode($pdo, $departmentRaw);
        if ($departmentRaw !== '' && !$departmentCode) {
            throw new RuntimeException('Không tìm thấy ngành/khoa quản lý môn học.');
        }

        if (strcasecmp($originalCode, $newCode) !== 0) {
            $check = $pdo->prepare('SELECT 1 FROM MonHoc WHERE upper(MaMon) = :ma_mon LIMIT 1');
            $check->execute([':ma_mon' => $newCode]);
            if ($check->fetchColumn()) {
                throw new RuntimeException('Mã môn học mới đã tồn tại.');
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE MonHoc
             SET MaMon = :new_code,
                 TenMon = :new_name,
                 SoTinChi = :credits,
                 LoaiMon = :loai_mon,
                 MaNganh = :ma_nganh
             WHERE upper(MaMon) = :original_code'
        );
        $stmt->execute([
            ':new_code' => $newCode,
            ':new_name' => $newName,
            ':credits' => (int)$creditsRaw,
            ':loai_mon' => $courseType,
            ':ma_nganh' => $departmentCode,
            ':original_code' => $originalCode,
        ]);

        if (strcasecmp($originalCode, $newCode) !== 0) {
            $moveLhp = $pdo->prepare('UPDATE LopHocPhan SET MaMon = :new_code WHERE upper(MaMon) = :original_code');
            $moveLhp->execute([
                ':new_code' => $newCode,
                ':original_code' => $originalCode,
            ]);
        }

        $updated = self::findSubjectByCode($newCode);
        if (!$updated) {
            throw new RuntimeException('Không thể đọc lại dữ liệu môn học sau cập nhật.');
        }
        return $updated;
    }

    public static function listByTeacherCode(string $teacherCode): array
    {
        return self::searchForStaff(['teacher_code' => $teacherCode]);
    }

    public static function listByStudentCode(string $studentCode): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql = 'SELECT DISTINCT l.MaLHP
                FROM KetQuaHocTap k
                INNER JOIN LopHocPhan l ON l.MaLHP = k.MaLHP
                WHERE lower(k.MaSV) = lower(:ma_sv)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':ma_sv' => $studentCode]);
        $lhps = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $items = [];
        foreach ($lhps as $maLHP) {
            $id = self::ensureMapForLhp($pdo, (string)$maLHP);
            $course = self::findById($id);
            if ($course) $items[] = $course;
        }
        return $items;
    }

    public static function listScoresByStudentCode(string $studentCode): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT k.MaLHP, k.DiemChuyenCan, k.DiemGiuaKy, k.DiemCuoiKy
             FROM KetQuaHocTap k
             WHERE lower(k.MaSV) = lower(:ma_sv)'
        );
        $stmt->execute([':ma_sv' => $studentCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $id = self::ensureMapForLhp($pdo, (string)$row['MaLHP']);
            $c = self::findById($id);
            if (!$c) continue;
            $c['cc'] = $row['DiemChuyenCan'] !== null ? (float)$row['DiemChuyenCan'] : null;
            $c['gk'] = $row['DiemGiuaKy'] !== null ? (float)$row['DiemGiuaKy'] : null;
            $c['ck'] = $row['DiemCuoiKy'] !== null ? (float)$row['DiemCuoiKy'] : null;
            $out[] = $c;
        }
        return $out;
    }

    public static function getScoreDetailForStudent(int $courseId, string $studentCode)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return false;
        $course = self::findById($courseId);
        if (!$course) return false;
        $stmt = $pdo->prepare(
            'SELECT k.DiemChuyenCan, k.DiemGiuaKy, k.DiemCuoiKy, s.MaSV, s.HoTen, s.MaLop
             FROM KetQuaHocTap k
             INNER JOIN SinhVien s ON s.MaSV = k.MaSV
             WHERE k.MaLHP = :ma_lhp AND lower(k.MaSV)=lower(:ma_sv)
             LIMIT 1'
        );
        $stmt->execute([':ma_lhp' => $maLHP, ':ma_sv' => $studentCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return [
            'id' => $courseId,
            'course_code' => $course['course_code'],
            'course_name' => $course['course_name'],
            'teacher_code' => $course['teacher_code'],
            'teacher_name' => $course['teacher_name'],
            'credits' => $course['credits'],
            'weight_cc' => $course['weight_cc'] ?? 0,
            'weight_gk' => $course['weight_gk'] ?? 0,
            'weight_ck' => $course['weight_ck'] ?? 0,
            'student_code' => $row['MaSV'],
            'student_name' => $row['HoTen'],
            'class_name' => $row['MaLop'],
            'cc' => $row['DiemChuyenCan'] !== null ? (float)$row['DiemChuyenCan'] : null,
            'gk' => $row['DiemGiuaKy'] !== null ? (float)$row['DiemGiuaKy'] : null,
            'ck' => $row['DiemCuoiKy'] !== null ? (float)$row['DiemCuoiKy'] : null,
        ];
    }
    


    // Phần điểm danh
    public static function createNewLessonWithPdo(PDO $pdo, string $maLHP): int
    {
        // Nối thêm số '0' vào cuối chuỗi lịch sử điểm danh của tất cả SV trong lớp
        $stmt = $pdo->prepare("UPDATE KetQuaHocTap SET LichSuDiemDanh = IFNULL(LichSuDiemDanh, '') || '0' WHERE MaLHP = :ma_lhp");
        $stmt->execute([':ma_lhp' => $maLHP]);

        // Cập nhật trạng thái lớp thành ACTIVE (Đang diễn ra) nếu chưa cập nhật
        $upLhp = $pdo->prepare("UPDATE LopHocPhan SET TrangThai = 'ACTIVE' WHERE MaLHP = :ma_lhp AND (TrangThai IS NULL OR TrangThai = 'PENDING')");
        $upLhp->execute([':ma_lhp' => $maLHP]);

        // Lấy số thứ tự buổi học vừa tạo
        $check = $pdo->prepare("SELECT length(LichSuDiemDanh) FROM KetQuaHocTap WHERE MaLHP = :ma_lhp LIMIT 1");
        $check->execute([':ma_lhp' => $maLHP]);
        return (int)$check->fetchColumn();
    }

    //Phần điểm danh
    public static function updateAttendanceWithPdo(PDO $pdo, string $maLHP, string $maSV, int $weekNumber, bool $isAbsent): void
    {
        $stmt = $pdo->prepare('SELECT LichSuDiemDanh FROM KetQuaHocTap WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv LIMIT 1');
        $stmt->execute([':ma_lhp' => $maLHP, ':ma_sv' => $maSV]);
        $history = (string)$stmt->fetchColumn();

        if ($weekNumber < 1 || $weekNumber > strlen($history)) {
            throw new RuntimeException("Buổi học số $weekNumber chưa được tạo!");
        }

        // Cập nhật ký tự tại vị trí weekNumber - 1
        $statusChar = $isAbsent ? '1' : '0';
        $history[$weekNumber - 1] = $statusChar;

       $up = $pdo->prepare('UPDATE KetQuaHocTap SET LichSuDiemDanh = :history WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv');
        $up->execute([':history' => $history, ':ma_lhp' => $maLHP, ':ma_sv' => $maSV]);

        // ĐỔI TÊN HÀM Ở ĐÂY:
        self::updateAbsenceCountWithPdo($pdo, $maLHP, $maSV);
    }


    //Phần điểm danh
    public static function deleteLessonWithPdo(PDO $pdo, string $maLHP, int $lessonNumber): void
    {
        // 1. Kiểm tra số buổi hiện tại của lớp
        $stmt = $pdo->prepare('SELECT LichSuDiemDanh FROM KetQuaHocTap WHERE MaLHP = :ma_lhp LIMIT 1');
        $stmt->execute([':ma_lhp' => $maLHP]);
        $history = (string)$stmt->fetchColumn();
        $totalLessons = strlen($history);

        if ($totalLessons === 0) {
            throw new RuntimeException("Lớp học chưa có buổi học nào.");
        }
        if ($lessonNumber < 1 || $lessonNumber > $totalLessons) {
            throw new RuntimeException("Buổi học số $lessonNumber không tồn tại!");
        }

        // 2. Cắt bỏ ký tự tại vị trí buổi học bị xóa cho toàn bộ sinh viên
        // Lưu ý: substr trong SQLite đánh index từ 1 (không phải 0 như PHP)
        $up = $pdo->prepare(
            'UPDATE KetQuaHocTap 
             SET LichSuDiemDanh = substr(LichSuDiemDanh, 1, :pos - 1) || substr(LichSuDiemDanh, :pos + 1) 
             WHERE MaLHP = :ma_lhp'
        );
        $up->execute([
            ':pos' => $lessonNumber, 
            ':ma_lhp' => $maLHP
        ]);

        // 3. Lấy danh sách sinh viên để tính toán lại điểm (vì số buổi vắng có thể bị thay đổi)
        $svStmt = $pdo->prepare('SELECT MaSV FROM KetQuaHocTap WHERE MaLHP = :ma_lhp');
        $svStmt->execute([':ma_lhp' => $maLHP]);
        $svList = $svStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($svList as $maSV) {
            // ĐỔI TÊN HÀM Ở ĐÂY:
            self::updateAbsenceCountWithPdo($pdo, $maLHP, (string)$maSV);
        }
    }

    //Điểm danh 
    public static function updateAbsenceCountWithPdo(PDO $pdo, string $maLHP, string $maSV): void
    {
        $stmtKq = $pdo->prepare('SELECT LichSuDiemDanh FROM KetQuaHocTap WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv LIMIT 1');
        $stmtKq->execute([':ma_lhp' => $maLHP, ':ma_sv' => $maSV]);
        $kq = $stmtKq->fetch(PDO::FETCH_ASSOC);
        
        if (!$kq) return;

        $history = (string)($kq['LichSuDiemDanh'] ?? '');
        $absences = substr_count($history, '1'); // Đếm số ký tự '1' (vắng)

        $up = $pdo->prepare('UPDATE KetQuaHocTap SET SoBuoiVang = :absences WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv');
        $up->execute([
            ':absences' => $absences,
            ':ma_lhp' => $maLHP,
            ':ma_sv' => $maSV
        ]);
    }

    /**
     * TÍNH TOÁN VÀ CẬP NHẬT ĐIỂM TỔNG KẾT
     * Hàm này lấy điểm CC, GK, CK và nhân với trọng số tương ứng của Lớp học phần
     */
    public static function updateFinalGradeWithPdo(PDO $pdo, string $maLHP, string $maSV): void
    {
        // 1. Lấy điểm thành phần và trọng số của lớp
        $stmt = $pdo->prepare(
            'SELECT k.DiemChuyenCan, k.DiemGiuaKy, k.DiemCuoiKy, 
                    l.TrongSoCC, l.TrongSoGK, l.TrongSoCK
             FROM KetQuaHocTap k
             JOIN LopHocPhan l ON k.MaLHP = l.MaLHP
             WHERE k.MaLHP = :ma_lhp AND k.MaSV = :ma_sv LIMIT 1'
        );
        $stmt->execute([':ma_lhp' => $maLHP, ':ma_sv' => $maSV]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return;

        // 2. Ép kiểu dữ liệu
        $cc = (float)($row['DiemChuyenCan'] ?? 0);
        $gk = (float)($row['DiemGiuaKy'] ?? 0);
        $ck = (float)($row['DiemCuoiKy'] ?? 0);
        
        $wCc = (float)($row['TrongSoCC'] ?? 0);
        $wGk = (float)($row['TrongSoGK'] ?? 0);
        $wCk = (float)($row['TrongSoCK'] ?? 0);

        $total = 0;
        $sumW = $wCc + $wGk + $wCk;
        
        // 3. Tính toán (Chỉ tính nếu tổng trọng số > 0, thường là 1.0 hoặc 100)
        if ($sumW > 0) {
            $total = ($cc * $wCc + $gk * $wGk + $ck * $wCk) / $sumW;
            $total = round($total, 2); // Làm tròn 2 chữ số thập phân
        }

        // 4. Cập nhật vào Database (Cột DiemTongKet đã được thêm ở ensureSchema)
        $up = $pdo->prepare('UPDATE KetQuaHocTap SET DiemTongKet = :total WHERE MaLHP = :ma_lhp AND MaSV = :ma_sv');
        $up->execute([
            ':total' => $total,
            ':ma_lhp' => $maLHP,
            ':ma_sv' => $maSV
        ]);
    }
}



