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
        foreach ($columns as $c) $names[(string)$c['name']] = true;
        if (!isset($names['TrongSoCC'])) $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoCC REAL DEFAULT 0');
        if (!isset($names['TrongSoGK'])) $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoGK REAL DEFAULT 0');
        if (!isset($names['TrongSoCK'])) $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN TrongSoCK REAL DEFAULT 0');
        if (!isset($names['CreatedAt'])) {
            // SQLite khong cho ADD COLUMN voi default khong hang so (CURRENT_TIMESTAMP)
            $pdo->exec('ALTER TABLE LopHocPhan ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE LopHocPhan SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }

        $rows = $pdo->query('SELECT MaLHP FROM LopHocPhan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $maLHP) {
            self::ensureMapForLhp($pdo, (string)$maLHP);
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
                MaLHP, MaMon, MaGV, HocKy, NamHoc, SoLuongToiDa, TrongSoCC, TrongSoGK, TrongSoCK, CreatedAt
            ) VALUES (
                :ma_lhp, :ma_mon, :ma_gv, :hoc_ky, :nam_hoc, :max, 0, 0, 0, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_lhp' => $maLHP,
            ':ma_mon' => $maMon,
            ':ma_gv' => $data['teacher_code'] ?: null,
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
                 HocKy = :hoc_ky,
                 NamHoc = :nam_hoc,
                 SoLuongToiDa = :max
             WHERE MaLHP = :ma_lhp'
        );
        $stmt->execute([
            ':ma_mon' => $newMaMon,
            ':ma_gv' => $data['teacher_code'] ?: null,
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
            'SELECT l.MaLHP, l.MaMon, l.MaGV, l.HocKy, l.NamHoc, l.SoLuongToiDa, l.TrongSoCC, l.TrongSoGK, l.TrongSoCK, l.CreatedAt,
                    m.TenMon, m.SoTinChi, ng.TenNganh, n.TenKhoa, g.HoTen AS teacher_name
             FROM LopHocPhan l
             LEFT JOIN MonHoc m ON m.MaMon = l.MaMon
             LEFT JOIN Nganh ng ON ng.MaNganh = m.MaNganh
             LEFT JOIN Khoa n ON n.MaKhoa = ng.MaKhoa
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
        return [
            'id' => $id,
            'section_code' => $row['MaLHP'] ?? '',
            'course_code' => $row['MaMon'] ?? '',
            'course_name' => $row['TenMon'] ?? '',
            'credits' => $row['SoTinChi'] !== null ? (int)$row['SoTinChi'] : null,
            'teacher_code' => $row['MaGV'] ?? '',
            'semester' => $row['HocKy'] !== null ? (int)$row['HocKy'] : null,
            'academic_year' => $row['NamHoc'] ?? '',
            'department' => (($row['TenKhoa'] ?? '') !== '' ? (string)$row['TenKhoa'] : (string)($row['TenNganh'] ?? '')),
            'schedule' => $scheduleInfo['schedule'],
            'classroom' => $scheduleInfo['classroom'],
            'max_students' => $row['SoLuongToiDa'] !== null ? (int)$row['SoLuongToiDa'] : null,
            'weight_cc' => (float)($row['TrongSoCC'] ?? 0),
            'weight_gk' => (float)($row['TrongSoGK'] ?? 0),
            'weight_ck' => (float)($row['TrongSoCK'] ?? 0),
            'created_at' => $row['CreatedAt'] ?? '',
            'teacher_name' => $row['teacher_name'] ?? '',
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
            'SELECT s.MaSV AS student_code, s.HoTen AS full_name, s.MaLop AS class_name, n.TenKhoa AS faculty, s.Email AS email, s.SoDienThoai AS phone
             FROM KetQuaHocTap k
             INNER JOIN SinhVien s ON s.MaSV = k.MaSV
             LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
             LEFT JOIN Khoa n ON n.MaKhoa = l.MaNganh
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
        $sql = 'SELECT m.MaMon, m.TenMon, m.SoTinChi, ng.TenNganh, n.TenKhoa, l.MaLHP, l.MaGV, l.HocKy, l.NamHoc, l.SoLuongToiDa, l.CreatedAt, g.HoTen AS teacher_name
                FROM LopHocPhan l
                INNER JOIN MonHoc m ON m.MaMon = l.MaMon
                LEFT JOIN Nganh ng ON ng.MaNganh = m.MaNganh
                LEFT JOIN Khoa n ON n.MaKhoa = ng.MaKhoa
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
                'department' => (($row['TenKhoa'] ?? '') !== '' ? (string)$row['TenKhoa'] : (string)($row['TenNganh'] ?? '')),
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

        $courseCode = trim((string)($data['course_code'] ?? ''));
        $courseName = trim((string)($data['course_name'] ?? ''));
        $creditsRaw = trim((string)($data['credits'] ?? ''));
        $departmentRaw = trim((string)($data['department'] ?? ''));
        $providedCourseType = trim((string)($data['course_type'] ?? ''));

        // Validate provided course_type strictly when present
        if ($providedCourseType !== '') {
            $upper = function_exists('mb_strtoupper') ? mb_strtoupper($providedCourseType, 'UTF-8') : strtoupper($providedCourseType);
            $allowed = ['BAT_BUOC', 'TU_CHON', 'TUY_CHON', 'TỰ_CHỌN'];
            if (!in_array($upper, $allowed, true)) {
                throw new RuntimeException('Loại môn học không hợp lệ.');
            }
        }

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
        $courseCode = trim($courseCode);
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

    public static function findSubjectByCode(string $courseCode, ?PDO $pdo = null)
    {
        if (!$pdo) {
            self::ensureSchema();
            $pdo = get_db_connection();
        }
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

        $current = self::findSubjectByCode($originalCode, $pdo);
        if (!$current) {
            throw new RuntimeException('Không tìm thấy môn học.');
        }

        $newCode = trim((string)($data['course_code'] ?? ''));
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

        $updated = self::findSubjectByCode($newCode, $pdo);
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
}



