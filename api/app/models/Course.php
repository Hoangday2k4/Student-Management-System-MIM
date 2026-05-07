<?php

class Course
{
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
        $name = trim((string)$department);
        if ($name === '') {
            $fallbackCode = 'NGANH00';
            $pdo->prepare('INSERT OR IGNORE INTO Nganh (MaNganh, TenNganh, MoTa) VALUES (:code, :name, NULL)')->execute([
                ':code' => $fallbackCode,
                ':name' => 'Chua xac dinh',
            ]);
            return $fallbackCode;
        }
        $stmt = $pdo->prepare('SELECT MaNganh FROM Nganh WHERE lower(TenNganh)=lower(:name) OR lower(MaNganh)=lower(:name) LIMIT 1');
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string)$id;
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'NGANH');
        if (strlen($code) > 12) $code = substr($code, 0, 12);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO Nganh (MaNganh, TenNganh, MoTa) VALUES (:code,:name,NULL)');
        $ins->execute([':code' => $code, ':name' => $name]);
        return $code;
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
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS HocKy (
                MaHocKy TEXT PRIMARY KEY,
                TenHocKy TEXT NOT NULL,
                NamHoc TEXT NOT NULL,
                Ky INTEGER NOT NULL,
                TrangThai TEXT NOT NULL DEFAULT "ACTIVE",
                IsCurrent INTEGER NOT NULL DEFAULT 0,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TEXT,
                DeletedAt TEXT
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_hocky_namhoc_ky ON HocKy(NamHoc, Ky)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hocky_trangthai ON HocKy(TrangThai)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Nganh (
                MaNganh TEXT PRIMARY KEY,
                TenNganh TEXT NOT NULL,
                MoTa TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS MonHoc (
                MaMon TEXT PRIMARY KEY,
                TenMon TEXT NOT NULL,
                SoTinChi INTEGER NOT NULL,
                LoaiMon TEXT,
                MaNganh TEXT
            )'
        );
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
                MaHocKy TEXT,
                SoLuongToiDa INTEGER,
                TrangThaiDangKy TEXT DEFAULT "DRAFT"
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
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_ketquahoctap_masv_malhp ON KetQuaHocTap(MaSV, MaLHP)');
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

        // Chuyen CaHoc tu INTEGER sang TEXT (luu dang "start-end")
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

        $columns = $pdo->query('PRAGMA table_info(LopHocPhan)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        $needsRelaxMaGv = false;
        foreach ($columns as $c) {
            $colName = (string)($c['name'] ?? '');
            $names[$colName] = true;
            if (strcasecmp($colName, 'MaGV') === 0 && (int)($c['notnull'] ?? 0) === 1) {
                $needsRelaxMaGv = true;
            }
        }

        if ($needsRelaxMaGv) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS LopHocPhan_new (
                    MaLHP TEXT PRIMARY KEY,
                    MaMon TEXT,
                    MaGV TEXT,
                    MaHocKy TEXT,
                    SoLuongToiDa INTEGER,
                    TrangThaiDangKy TEXT DEFAULT "DRAFT",
                    TrongSoCC REAL DEFAULT 0,
                    TrongSoGK REAL DEFAULT 0,
                    TrongSoCK REAL DEFAULT 0,
                    CreatedAt TEXT
                )'
            );

            $targetCols = ['MaLHP', 'MaMon', 'MaGV', 'MaHocKy', 'SoLuongToiDa', 'TrangThaiDangKy', 'TrongSoCC', 'TrongSoGK', 'TrongSoCK', 'CreatedAt'];
            $selectParts = [];
            foreach ($targetCols as $col) {
                if (isset($names[$col])) {
                    $selectParts[] = $col;
                    continue;
                }
                if ($col === 'TrangThaiDangKy') {
                    $selectParts[] = '"DRAFT"';
                } elseif (in_array($col, ['TrongSoCC', 'TrongSoGK', 'TrongSoCK'], true)) {
                    $selectParts[] = '0';
                } elseif ($col === 'CreatedAt') {
                    $selectParts[] = "datetime('now', 'localtime')";
                } else {
                    $selectParts[] = 'NULL';
                }
            }

            $pdo->exec(
                'INSERT INTO LopHocPhan_new (' . implode(', ', $targetCols) . ')
                 SELECT ' . implode(', ', $selectParts) . ' FROM LopHocPhan'
            );
            $pdo->exec('DROP TABLE LopHocPhan');
            $pdo->exec('ALTER TABLE LopHocPhan_new RENAME TO LopHocPhan');

            $columns = $pdo->query('PRAGMA table_info(LopHocPhan)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $names = [];
            foreach ($columns as $c) {
                $names[(string)($c['name'] ?? '')] = true;
            }
        }
        if (!isset($names['MaHocKy'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN MaHocKy TEXT');
        }
        if (!isset($names['TrangThaiDangKy'])) {
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrangThaiDangKy TEXT DEFAULT "DRAFT"');
        }
        if (!isset($names['TrongSoCC'])) $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoCC REAL DEFAULT 0');
        if (!isset($names['TrongSoGK'])) $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoGK REAL DEFAULT 0');
        if (!isset($names['TrongSoCK'])) $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoCK REAL DEFAULT 0');
        if (!isset($names['CreatedAt'])) {
            // SQLite khong cho ADD COLUMN voi default khong hang so (CURRENT_TIMESTAMP)
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE LopHocPhan SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }

        if (isset($names['HocKy']) && isset($names['NamHoc'])) {
            $pdo->exec(
                "INSERT OR IGNORE INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, CreatedAt)
                 SELECT
                    CASE
                        WHEN NamHoc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9]' AND HocKy IN (1,2,3)
                            THEN substr(NamHoc, 3, 2) || CAST(HocKy AS TEXT)
                        ELSE 'TMP001'
                    END AS MaHocKy,
                    'HK' || CASE WHEN HocKy IN (1,2,3) THEN CAST(HocKy AS TEXT) ELSE '1' END AS TenHocKy,
                    COALESCE(NULLIF(trim(NamHoc), ''), '2024-2025') AS NamHoc,
                    CASE WHEN HocKy IN (1,2,3) THEN HocKy ELSE 1 END AS Ky,
                    'ACTIVE',
                    0,
                    datetime('now', 'localtime')
                 FROM LopHocPhan"
            );

            $pdo->exec(
                "UPDATE LopHocPhan
                 SET MaHocKy = (
                    CASE
                        WHEN NamHoc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9]' AND HocKy IN (1,2,3)
                            THEN substr(NamHoc, 3, 2) || CAST(HocKy AS TEXT)
                        ELSE 'TMP001'
                    END
                 )
                 WHERE MaHocKy IS NULL OR trim(MaHocKy) = ''"
            );
        }

        $pdo->exec(
            "INSERT OR IGNORE INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, CreatedAt)
             VALUES ('TMP001', 'HK1', '2024-2025', 1, 'ACTIVE', 1, datetime('now', 'localtime'))"
        );
        $pdo->exec("UPDATE LopHocPhan SET MaHocKy = 'TMP001' WHERE MaHocKy IS NULL OR trim(MaHocKy) = ''");
        $pdo->exec("UPDATE LopHocPhan SET TrangThaiDangKy = 'DRAFT' WHERE TrangThaiDangKy IS NULL OR trim(TrangThaiDangKy) = ''");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_mahocky ON LopHocPhan(MaHocKy)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_trangthai_dk ON LopHocPhan(TrangThaiDangKy)');

        $pdo->exec(
            'INSERT OR IGNORE INTO LopHocPhanMap (MaLHP)
             SELECT MaLHP FROM LopHocPhan'
        );
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        self::ensureSchema($pdo);
        $maMon = trim((string)$data['course_code']);
        $maLHP = $maMon . '-01';
        $i = 1;
        while (true) {
            $stmt = $pdo->prepare('SELECT 1 FROM LopHocPhan WHERE MaLHP = :ma LIMIT 1');
            $stmt->execute([':ma' => $maLHP]);
            if (!$stmt->fetchColumn()) break;
            $i++;
            $maLHP = $maMon . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        }

        $maNganh = self::resolveNganh($pdo, (string)($data['department'] ?? ''));
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

        $stmt = $pdo->prepare(
            'INSERT INTO LopHocPhan (
                MaLHP, MaMon, MaGV, MaHocKy, SoLuongToiDa, TrangThaiDangKy, TrongSoCC, TrongSoGK, TrongSoCK, CreatedAt
            ) VALUES (
                :ma_lhp, :ma_mon, :ma_gv, :ma_hoc_ky, :max, :trang_thai_dk, 0, 0, 0, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_lhp' => $maLHP,
            ':ma_mon' => $maMon,
            ':ma_gv' => $data['teacher_code'] ?: null,
            ':ma_hoc_ky' => trim((string)($data['ma_hoc_ky'] ?? '')),
            ':max' => $data['max_students'] !== null ? (int)$data['max_students'] : null,
            ':trang_thai_dk' => trim((string)($data['enrollment_status'] ?? 'DRAFT')),
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

        $maNganh = self::resolveNganh($pdo, (string)($data['department'] ?? ''));
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

        $stmt = $pdo->prepare(
            'UPDATE LopHocPhan
             SET MaMon = :ma_mon,
                 MaGV = :ma_gv,
                 MaHocKy = :ma_hoc_ky,
                 SoLuongToiDa = :max,
                 TrangThaiDangKy = :trang_thai_dk
             WHERE MaLHP = :ma_lhp'
        );
        $stmt->execute([
            ':ma_mon' => $newMaMon,
            ':ma_gv' => $data['teacher_code'] ?: null,
            ':ma_hoc_ky' => trim((string)($data['ma_hoc_ky'] ?? '')),
            ':max' => $data['max_students'] !== null ? (int)$data['max_students'] : null,
            ':trang_thai_dk' => trim((string)($data['enrollment_status'] ?? 'DRAFT')),
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

        if ($maMon !== '') {
            $stmt = $pdo->prepare('SELECT COUNT(1) FROM LopHocPhan WHERE MaMon = :ma_mon');
            $stmt->execute([':ma_mon' => $maMon]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM MonHoc WHERE MaMon = :ma_mon')->execute([':ma_mon' => $maMon]);
            }
        }
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
        $stmt = $pdo->prepare(
            'SELECT l.MaLHP, l.MaMon, l.MaGV, l.MaHocKy, l.SoLuongToiDa, l.TrangThaiDangKy, l.TrongSoCC, l.TrongSoGK, l.TrongSoCK, l.CreatedAt,
                m.TenMon, m.SoTinChi, n.TenNganh, g.HoTen AS teacher_name,
                h.TenHocKy, h.NamHoc, h.Ky
             FROM LopHocPhan l
             LEFT JOIN MonHoc m ON m.MaMon = l.MaMon
             LEFT JOIN Nganh n ON n.MaNganh = m.MaNganh
             LEFT JOIN GiangVien g ON g.MaGV = l.MaGV
             LEFT JOIN HocKy h ON h.MaHocKy = l.MaHocKy
             WHERE l.MaLHP = :ma_lhp
             LIMIT 1'
        );
        $stmt->execute([':ma_lhp' => $maLHP]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $scheduleInfo = self::buildScheduleClassroom($pdo, $maLHP);
        $countStmt = $pdo->prepare('SELECT COUNT(1) FROM KetQuaHocTap WHERE MaLHP = :ma_lhp');
        $countStmt->execute([':ma_lhp' => $maLHP]);
        return [
            'id' => $id,
            'course_code' => $row['MaMon'] ?? '',
            'course_name' => $row['TenMon'] ?? '',
            'credits' => $row['SoTinChi'] !== null ? (int)$row['SoTinChi'] : null,
            'teacher_code' => $row['MaGV'] ?? '',
            'department' => $row['TenNganh'] ?? '',
            'schedule' => $scheduleInfo['schedule'],
            'classroom' => $scheduleInfo['classroom'],
            'max_students' => $row['SoLuongToiDa'] !== null ? (int)$row['SoLuongToiDa'] : null,
            'weight_cc' => (float)($row['TrongSoCC'] ?? 0),
            'weight_gk' => (float)($row['TrongSoGK'] ?? 0),
            'weight_ck' => (float)($row['TrongSoCK'] ?? 0),
            'created_at' => $row['CreatedAt'] ?? '',
            'teacher_name' => $row['teacher_name'] ?? '',
            'ma_hoc_ky' => $row['MaHocKy'] ?? '',
            'semester' => [
                'ma_hoc_ky' => $row['MaHocKy'] ?? '',
                'ten_hoc_ky' => $row['TenHocKy'] ?? '',
                'nam_hoc' => $row['NamHoc'] ?? '',
                'ky' => isset($row['Ky']) ? (int)$row['Ky'] : null,
            ],
            'enrollment_status' => $row['TrangThaiDangKy'] ?? 'DRAFT',
            'enrolled_count' => (int)$countStmt->fetchColumn(),
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
            'SELECT s.MaSV AS student_code, s.HoTen AS full_name, s.MaLop AS class_name, n.TenNganh AS faculty, s.Email AS email, s.SoDienThoai AS phone
             FROM KetQuaHocTap k
             INNER JOIN SinhVien s ON s.MaSV = k.MaSV
             LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
             LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
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

    public static function searchForStaff(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo) {
            self::ensureSchema($pdo);
        } else {
            self::ensureSchema();
            $pdo = get_db_connection();
        }
        $sql = 'SELECT m.MaMon, m.TenMon, m.SoTinChi, n.TenNganh, l.MaLHP, l.MaGV, l.MaHocKy, l.SoLuongToiDa, l.TrangThaiDangKy, l.CreatedAt, g.HoTen AS teacher_name,
                   h.TenHocKy, h.NamHoc, h.Ky
                FROM LopHocPhan l
                INNER JOIN MonHoc m ON m.MaMon = l.MaMon
                LEFT JOIN Nganh n ON n.MaNganh = m.MaNganh
                LEFT JOIN GiangVien g ON g.MaGV = l.MaGV
            LEFT JOIN HocKy h ON h.MaHocKy = l.MaHocKy
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
            $sql .= ' AND lower(IFNULL(n.TenNganh,"")) LIKE :department';
            $params[':department'] = '%' . self::lowerText((string)$filters['department']) . '%';
        }
        if (!empty($filters['teacher_code'])) {
            $sql .= ' AND lower(IFNULL(l.MaGV,"")) LIKE :teacher_code';
            $params[':teacher_code'] = '%' . self::lowerText((string)$filters['teacher_code']) . '%';
        }
        if (!empty($filters['ma_hoc_ky'])) {
            $sql .= ' AND lower(IFNULL(l.MaHocKy,"")) = lower(:ma_hoc_ky)';
            $params[':ma_hoc_ky'] = trim((string)$filters['ma_hoc_ky']);
        }
        $sql .= ' ORDER BY lower(IFNULL(n.TenNganh,"")) ASC, CAST(substr(m.MaMon,4) AS INTEGER) ASC, m.MaMon ASC LIMIT 500';
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
                'course_code' => $row['MaMon'] ?? '',
                'course_name' => $row['TenMon'] ?? '',
                'credits' => $row['SoTinChi'] !== null ? (int)$row['SoTinChi'] : null,
                'teacher_code' => $row['MaGV'] ?? '',
                'department' => $row['TenNganh'] ?? '',
                'schedule' => $sch['schedule'],
                'classroom' => $sch['classroom'],
                'max_students' => $row['SoLuongToiDa'] !== null ? (int)$row['SoLuongToiDa'] : null,
                'created_at' => $row['CreatedAt'] ?? '',
                'teacher_name' => $row['teacher_name'] ?? '',
                'ma_hoc_ky' => $row['MaHocKy'] ?? '',
                'semester' => [
                    'ma_hoc_ky' => $row['MaHocKy'] ?? '',
                    'ten_hoc_ky' => $row['TenHocKy'] ?? '',
                    'nam_hoc' => $row['NamHoc'] ?? '',
                    'ky' => isset($row['Ky']) ? (int)$row['Ky'] : null,
                ],
                'enrollment_status' => $row['TrangThaiDangKy'] ?? 'DRAFT',
                'enrolled_count' => (int)$cst->fetchColumn(),
            ];
        }
        return $out;
    }

    public static function listByTeacherCode(string $teacherCode, array $filters = []): array
    {
        return self::searchForStaff([
            'teacher_code' => $teacherCode,
            'ma_hoc_ky' => $filters['ma_hoc_ky'] ?? '',
        ]);
    }

    public static function listByStudentCode(string $studentCode, array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql = 'SELECT DISTINCT l.MaLHP
                FROM KetQuaHocTap k
                INNER JOIN LopHocPhan l ON l.MaLHP = k.MaLHP
                WHERE lower(k.MaSV) = lower(:ma_sv)';
        $params = [':ma_sv' => $studentCode];
        if (!empty($filters['ma_hoc_ky'])) {
            $sql .= ' AND lower(IFNULL(l.MaHocKy,"")) = lower(:ma_hoc_ky)';
            $params[':ma_hoc_ky'] = trim((string)$filters['ma_hoc_ky']);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lhps = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $items = [];
        foreach ($lhps as $maLHP) {
            $id = self::ensureMapForLhp($pdo, (string)$maLHP);
            $course = self::findById($id);
            if ($course) $items[] = $course;
        }
        return $items;
    }

    public static function updateEnrollmentStatusWithPdo(PDO $pdo, int $courseId, string $status): bool
    {
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return false;
        $stmt = $pdo->prepare('UPDATE LopHocPhan SET TrangThaiDangKy = :st WHERE MaLHP = :ma_lhp');
        $stmt->execute([':st' => $status, ':ma_lhp' => $maLHP]);
        return $stmt->rowCount() > 0;
    }

    public static function hasEnrollmentData(int $courseId, ?PDO $pdo = null): bool
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        self::ensureSchema($pdo);
        $maLHP = self::getMaLhpByLegacyId($pdo, $courseId);
        if (!$maLHP) return false;

        $stmt = $pdo->prepare('SELECT COUNT(1) FROM KetQuaHocTap WHERE MaLHP = :ma_lhp');
        $stmt->execute([':ma_lhp' => $maLHP]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }

        $tableCheck = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name='DangKyHoc' LIMIT 1");
        $tableCheck->execute();
        if ($tableCheck->fetchColumn()) {
            $stmt = $pdo->prepare('SELECT COUNT(1) FROM DangKyHoc WHERE MaLHP = :ma_lhp');
            $stmt->execute([':ma_lhp' => $maLHP]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }

        return false;
    }

    public static function listScoresByStudentCode(string $studentCode, array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql = 'SELECT k.MaLHP, k.DiemChuyenCan, k.DiemGiuaKy, k.DiemCuoiKy
                FROM KetQuaHocTap k
                INNER JOIN LopHocPhan l ON l.MaLHP = k.MaLHP
                WHERE lower(k.MaSV) = lower(:ma_sv)';
        $params = [':ma_sv' => $studentCode];
        if (!empty($filters['ma_hoc_ky'])) {
            $sql .= ' AND lower(IFNULL(l.MaHocKy, "")) = lower(:ma_hoc_ky)';
            $params[':ma_hoc_ky'] = trim((string)$filters['ma_hoc_ky']);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
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

    public static function reportFailRateBySemester(string $maHocKy, float $threshold = 4.0): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql =
            'SELECT l.MaLHP, l.MaMon, m.TenMon,
                    l.TrongSoCC, l.TrongSoGK, l.TrongSoCK,
                    k.DiemChuyenCan, k.DiemGiuaKy, k.DiemCuoiKy
             FROM LopHocPhan l
             INNER JOIN MonHoc m ON m.MaMon = l.MaMon
             LEFT JOIN KetQuaHocTap k ON k.MaLHP = l.MaLHP
             WHERE lower(IFNULL(l.MaHocKy, "")) = lower(:ma_hoc_ky)
             ORDER BY l.MaLHP ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':ma_hoc_ky' => $maHocKy]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $maLHP = (string)($row['MaLHP'] ?? '');
            if ($maLHP === '') continue;

            if (!isset($map[$maLHP])) {
                $map[$maLHP] = [
                    'ma_lhp' => $maLHP,
                    'ma_mon' => (string)($row['MaMon'] ?? ''),
                    'ten_mon' => (string)($row['TenMon'] ?? ''),
                    'so_sv' => 0,
                    'so_rot' => 0,
                ];
            }

            $cc = $row['DiemChuyenCan'];
            $gk = $row['DiemGiuaKy'];
            $ck = $row['DiemCuoiKy'];
            if ($cc === null && $gk === null && $ck === null) {
                continue;
            }

            $wCc = (float)($row['TrongSoCC'] ?? 0);
            $wGk = (float)($row['TrongSoGK'] ?? 0);
            $wCk = (float)($row['TrongSoCK'] ?? 0);
            $sumW = $wCc + $wGk + $wCk;
            if ($sumW <= 0) {
                $wCc = 1;
                $wGk = 1;
                $wCk = 1;
                $sumW = 3;
            }

            $ccVal = $cc === null ? 0.0 : (float)$cc;
            $gkVal = $gk === null ? 0.0 : (float)$gk;
            $ckVal = $ck === null ? 0.0 : (float)$ck;
            $total = ($ccVal * $wCc + $gkVal * $wGk + $ckVal * $wCk) / $sumW;

            $map[$maLHP]['so_sv']++;
            if ($total < $threshold) {
                $map[$maLHP]['so_rot']++;
            }
        }

        $items = array_values($map);
        foreach ($items as &$item) {
            $soSv = (int)$item['so_sv'];
            $soRot = (int)$item['so_rot'];
            $item['ty_le_rot'] = $soSv > 0 ? round(($soRot / $soSv) * 100, 2) : 0.0;
        }
        unset($item);

        usort($items, static function (array $a, array $b): int {
            $rateCompare = ((float)$b['ty_le_rot']) <=> ((float)$a['ty_le_rot']);
            if ($rateCompare !== 0) return $rateCompare;
            return strcmp((string)$a['ma_lhp'], (string)$b['ma_lhp']);
        });

        return $items;
    }
}
