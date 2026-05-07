<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    if (!tableExists($pdo, $table)) {
        return false;
    }

    $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (strcasecmp((string)($row['name'] ?? ''), $column) === 0) {
            return true;
        }
    }
    return false;
}

function addColumnIfMissing(PDO $pdo, string $table, string $columnDef): void
{
    $parts = preg_split('/\s+/', trim($columnDef));
    $column = (string)($parts[0] ?? '');
    if ($column === '' || !tableExists($pdo, $table) || columnExists($pdo, $table, $column)) {
        return;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $columnDef");
}

function sqlExpr(PDO $pdo, string $table, string $column, string $fallback): string
{
    return columnExists($pdo, $table, $column) ? "$table.$column" : $fallback;
}

function ensureBaseTables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS Nganh (
            MaNganh TEXT PRIMARY KEY,
            TenNganh TEXT NOT NULL,
            MoTa TEXT
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
        'CREATE TABLE IF NOT EXISTS MonHoc (
            MaMon TEXT PRIMARY KEY,
            TenMon TEXT NOT NULL,
            SoTinChi INTEGER NOT NULL,
            MaNganh TEXT,
            FOREIGN KEY(MaNganh) REFERENCES Nganh(MaNganh)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS HocKy (
            MaHocKy TEXT PRIMARY KEY,
            TenHocKy TEXT NOT NULL,
            NamHoc TEXT NOT NULL,
            Ky INTEGER NOT NULL,
            TrangThai TEXT NOT NULL DEFAULT "ACTIVE",
            IsCurrent INTEGER NOT NULL DEFAULT 0,
            GhiChu TEXT,
            CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TEXT,
            DeletedAt TEXT
        )'
    );

    if (!tableExists($pdo, 'LopHocPhan')) {
        $pdo->exec(
            'CREATE TABLE LopHocPhan (
                MaLHP TEXT PRIMARY KEY,
                MaMon TEXT,
                MaGV TEXT,
                MaHocKy TEXT,
                SoLuongToiDa INTEGER,
                TrongSoCC REAL DEFAULT 0,
                TrongSoGK REAL DEFAULT 0,
                TrongSoCK REAL DEFAULT 0,
                TrangThaiDangKy TEXT DEFAULT "DRAFT",
                CreatedAt TEXT,
                UpdatedAt TEXT
            )'
        );
    }

    if (!tableExists($pdo, 'SinhVien')) {
        $pdo->exec(
            'CREATE TABLE SinhVien (
                MaSV TEXT PRIMARY KEY,
                HoTen TEXT NOT NULL,
                GioiTinh TEXT,
                Email TEXT
            )'
        );
    }

    if (!tableExists($pdo, 'ThoiKhoaBieu')) {
        $pdo->exec(
            'CREATE TABLE ThoiKhoaBieu (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                MaLHP TEXT,
                Thu INTEGER,
                CaHoc TEXT,
                PhongHoc TEXT
            )'
        );
    }

    if (!tableExists($pdo, 'KetQuaHocTap')) {
        $pdo->exec(
            'CREATE TABLE KetQuaHocTap (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                MaSV TEXT,
                MaLHP TEXT,
                DiemChuyenCan REAL,
                DiemGiuaKy REAL,
                DiemCuoiKy REAL
            )'
        );
    }
}

$dbPath = DB_PATH;
if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: $dbPath" . PHP_EOL);
    exit(1);
}

$backupPath = dirname($dbPath) . '/ltweb_backup_before_semester_3nf_strict_' . date('Ymd_His') . '.sqlite';
if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, 'Could not create backup file.' . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 10000');

try {
    $step = 'begin';
    $pdo->beginTransaction();
    $pdo->exec('PRAGMA foreign_keys = OFF');

    $step = 'ensure_base_tables';
    ensureBaseTables($pdo);

    $step = 'ensure_hocky_columns_and_indexes';
    addColumnIfMissing($pdo, 'HocKy', 'TrangThai TEXT NOT NULL DEFAULT "ACTIVE"');
    addColumnIfMissing($pdo, 'HocKy', 'IsCurrent INTEGER NOT NULL DEFAULT 0');
    addColumnIfMissing($pdo, 'HocKy', 'GhiChu TEXT');
    addColumnIfMissing($pdo, 'HocKy', 'CreatedAt TEXT');
    addColumnIfMissing($pdo, 'HocKy', 'UpdatedAt TEXT');
    addColumnIfMissing($pdo, 'HocKy', 'DeletedAt TEXT');
    $pdo->exec("UPDATE HocKy SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");

    $dupStmt = $pdo->query('SELECT COUNT(1) FROM (SELECT NamHoc, Ky FROM HocKy GROUP BY NamHoc, Ky HAVING COUNT(1) > 1) t');
    $hasDup = ((int)$dupStmt->fetchColumn()) > 0;
    if ($hasDup) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hocky_namhoc_ky ON HocKy(NamHoc, Ky)');
    } else {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_hocky_namhoc_ky ON HocKy(NamHoc, Ky)');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hocky_trangthai ON HocKy(TrangThai)');

    $pdo->exec(
        "INSERT OR IGNORE INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, CreatedAt)
         VALUES ('TMP001', 'HK1', '2024-2025', 1, 'ACTIVE', 1, datetime('now', 'localtime'))"
    );

    $step = 'normalize_monhoc_nganh';
    addColumnIfMissing($pdo, 'MonHoc', 'MaNganh TEXT');
    $pdo->exec("INSERT OR IGNORE INTO Nganh (MaNganh, TenNganh, MoTa) VALUES ('UNKNOWN', 'Unknown', 'Auto-created by migration')");
    $pdo->exec("UPDATE MonHoc SET MaNganh = 'UNKNOWN' WHERE MaNganh IS NULL OR trim(MaNganh) = ''");

    addColumnIfMissing($pdo, 'LopHocPhan', 'MaHocKy TEXT');
    addColumnIfMissing($pdo, 'LopHocPhan', 'TrangThaiDangKy TEXT DEFAULT "DRAFT"');
    addColumnIfMissing($pdo, 'LopHocPhan', 'TrongSoCC REAL DEFAULT 0');
    addColumnIfMissing($pdo, 'LopHocPhan', 'TrongSoGK REAL DEFAULT 0');
    addColumnIfMissing($pdo, 'LopHocPhan', 'TrongSoCK REAL DEFAULT 0');
    addColumnIfMissing($pdo, 'LopHocPhan', 'CreatedAt TEXT');
    addColumnIfMissing($pdo, 'LopHocPhan', 'UpdatedAt TEXT');

    $step = 'backfill_lophocphan_hocky';
    $hasHocKyOld = columnExists($pdo, 'LopHocPhan', 'HocKy');
    $hasNamHocOld = columnExists($pdo, 'LopHocPhan', 'NamHoc');
    if ($hasHocKyOld && $hasNamHocOld) {
        $pdo->exec(
            "INSERT OR IGNORE INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, CreatedAt)
             SELECT
                CASE
                    WHEN NamHoc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9]' AND HocKy IN (1,2,3)
                        THEN substr(NamHoc, 3, 2) || CAST(HocKy AS TEXT)
                    ELSE 'TMP001'
                END,
                'HK' || CASE WHEN HocKy IN (1,2,3) THEN CAST(HocKy AS TEXT) ELSE '1' END,
                COALESCE(NULLIF(trim(NamHoc), ''), '2024-2025'),
                CASE WHEN HocKy IN (1,2,3) THEN HocKy ELSE 1 END,
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

    $pdo->exec("UPDATE LopHocPhan SET MaHocKy = 'TMP001' WHERE MaHocKy IS NULL OR trim(MaHocKy) = ''");
    $pdo->exec("UPDATE LopHocPhan SET TrangThaiDangKy = 'DRAFT' WHERE TrangThaiDangKy IS NULL OR trim(TrangThaiDangKy) = ''");
    $pdo->exec("UPDATE LopHocPhan SET SoLuongToiDa = 0 WHERE SoLuongToiDa IS NULL");

    $pdo->exec(
        "INSERT OR IGNORE INTO MonHoc (MaMon, TenMon, SoTinChi, MaNganh)
         VALUES ('MON_UNKNOWN', 'Unknown Course', 0, 'UNKNOWN')"
    );

    $pdo->exec(
        "INSERT OR IGNORE INTO GiangVien (MaGV, HoTen, GioiTinh, NgaySinh, Email, SoDienThoai, HocHamHocVi, TrangThai)
         VALUES ('GV_UNKNOWN', 'Unknown Teacher', 'Khac', NULL, 'gv_unknown@placeholder.local', NULL, NULL, 'ACTIVE')"
    );

    $pdo->exec("UPDATE LopHocPhan SET MaMon = 'MON_UNKNOWN' WHERE MaMon IS NULL OR trim(MaMon) = ''");
    $pdo->exec("UPDATE LopHocPhan SET MaGV = 'GV_UNKNOWN' WHERE MaGV IS NULL OR trim(MaGV) = ''");

    $pdo->exec(
        "INSERT OR IGNORE INTO MonHoc (MaMon, TenMon, SoTinChi, MaNganh)
         SELECT DISTINCT l.MaMon, l.MaMon, 0, 'UNKNOWN'
         FROM LopHocPhan l
         LEFT JOIN MonHoc m ON lower(m.MaMon) = lower(l.MaMon)
         WHERE trim(COALESCE(l.MaMon, '')) <> ''
           AND m.MaMon IS NULL"
    );

    $pdo->exec(
        "INSERT OR IGNORE INTO GiangVien (MaGV, HoTen, GioiTinh, NgaySinh, Email, SoDienThoai, HocHamHocVi, TrangThai)
         SELECT DISTINCT l.MaGV,
                l.MaGV,
                'Khac',
                NULL,
                lower(l.MaGV) || '@placeholder.local',
                NULL,
                NULL,
                'ACTIVE'
         FROM LopHocPhan l
         LEFT JOIN GiangVien g ON lower(g.MaGV) = lower(l.MaGV)
         WHERE trim(COALESCE(l.MaGV, '')) <> ''
           AND g.MaGV IS NULL"
    );

        $step = 'normalize_monhoc_indexes';
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monhoc_manganh ON MonHoc(MaNganh)');

    $step = 'rebuild_lophocphan';
    $lhpSoCCExpr = sqlExpr($pdo, 'l', 'TrongSoCC', '0');
    $lhpSoGKExpr = sqlExpr($pdo, 'l', 'TrongSoGK', '0');
    $lhpSoCKExpr = sqlExpr($pdo, 'l', 'TrongSoCK', '0');
    $lhpStatusExpr = sqlExpr($pdo, 'l', 'TrangThaiDangKy', "'DRAFT'");
    $lhpCreatedExpr = sqlExpr($pdo, 'l', 'CreatedAt', "datetime('now', 'localtime')");
    $lhpUpdatedExpr = sqlExpr($pdo, 'l', 'UpdatedAt', "NULL");

    $pdo->exec('DROP TABLE IF EXISTS LopHocPhan_new');
    $pdo->exec(
        'CREATE TABLE LopHocPhan_new (
            MaLHP TEXT PRIMARY KEY,
            MaMon TEXT NOT NULL,
            MaGV TEXT NOT NULL,
            MaHocKy TEXT NOT NULL,
            SoLuongToiDa INTEGER NOT NULL,
            TrongSoCC REAL DEFAULT 0,
            TrongSoGK REAL DEFAULT 0,
            TrongSoCK REAL DEFAULT 0,
            TrangThaiDangKy TEXT NOT NULL DEFAULT "DRAFT",
            CreatedAt TEXT,
            UpdatedAt TEXT,
            FOREIGN KEY(MaMon) REFERENCES MonHoc(MaMon),
            FOREIGN KEY(MaGV) REFERENCES GiangVien(MaGV),
            FOREIGN KEY(MaHocKy) REFERENCES HocKy(MaHocKy)
        )'
    );
    $pdo->exec(
        "INSERT INTO LopHocPhan_new (
            MaLHP, MaMon, MaGV, MaHocKy, SoLuongToiDa,
            TrongSoCC, TrongSoGK, TrongSoCK, TrangThaiDangKy, CreatedAt, UpdatedAt
        )
        SELECT
            l.MaLHP,
            COALESCE(NULLIF(trim(l.MaMon), ''), 'MON_UNKNOWN'),
            COALESCE(NULLIF(trim(l.MaGV), ''), 'GV_UNKNOWN'),
            COALESCE(NULLIF(trim(l.MaHocKy), ''), 'TMP001'),
            COALESCE(l.SoLuongToiDa, 0),
            COALESCE($lhpSoCCExpr, 0),
            COALESCE($lhpSoGKExpr, 0),
            COALESCE($lhpSoCKExpr, 0),
            COALESCE(NULLIF(trim($lhpStatusExpr), ''), 'DRAFT'),
            $lhpCreatedExpr,
            $lhpUpdatedExpr
        FROM LopHocPhan l"
    );
    $legacyLhpTable = 'LopHocPhan_legacy_3nf_' . date('Ymd_His');
    $pdo->exec("ALTER TABLE LopHocPhan RENAME TO $legacyLhpTable");
    $pdo->exec('ALTER TABLE LopHocPhan_new RENAME TO LopHocPhan');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_mamon ON LopHocPhan(MaMon)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_magv ON LopHocPhan(MaGV)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_mahocky ON LopHocPhan(MaHocKy)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_trangthai_dk ON LopHocPhan(TrangThaiDangKy)');

    $step = 'rebuild_thoikhoabieu';
    $tkbCaExpr = sqlExpr($pdo, 't', 'CaHoc', "'1-1'");
    $tkbRoomExpr = sqlExpr($pdo, 't', 'PhongHoc', "'TBD'");

    $pdo->exec('DROP TABLE IF EXISTS ThoiKhoaBieu_new');
    $pdo->exec(
        'CREATE TABLE ThoiKhoaBieu_new (
            Id INTEGER PRIMARY KEY AUTOINCREMENT,
            MaLHP TEXT NOT NULL,
            Thu INTEGER NOT NULL,
            CaHoc TEXT NOT NULL,
            PhongHoc TEXT NOT NULL,
            FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP)
        )'
    );

    $pdo->exec(
        "INSERT INTO ThoiKhoaBieu_new (MaLHP, Thu, CaHoc, PhongHoc)
         WITH normalized AS (
            SELECT
                t.Id,
                COALESCE(NULLIF(trim(t.MaLHP), ''), '') AS MaLHP,
                CASE
                    WHEN t.Thu IS NULL OR CAST(t.Thu AS INTEGER) < 2 OR CAST(t.Thu AS INTEGER) > 8 THEN 2
                    ELSE CAST(t.Thu AS INTEGER)
                END AS Thu,
                CASE
                    WHEN trim(COALESCE($tkbCaExpr, '')) = '' THEN '1-1'
                    WHEN trim(COALESCE($tkbCaExpr, '')) GLOB '[0-9]*' THEN trim(COALESCE($tkbCaExpr, '')) || '-' || trim(COALESCE($tkbCaExpr, ''))
                    ELSE trim(COALESCE($tkbCaExpr, ''))
                END AS CaHoc,
                COALESCE(NULLIF(trim(COALESCE($tkbRoomExpr, '')), ''), 'TBD') AS PhongHoc
            FROM ThoiKhoaBieu t
         ),
         filtered AS (
            SELECT n.*
            FROM normalized n
            JOIN LopHocPhan l ON lower(l.MaLHP) = lower(n.MaLHP)
            WHERE n.MaLHP <> ''
         ),
         ranked AS (
            SELECT
                f.*,
                ROW_NUMBER() OVER (PARTITION BY f.MaLHP, f.Thu, f.CaHoc ORDER BY f.Id) AS rn_lhp,
                ROW_NUMBER() OVER (PARTITION BY f.PhongHoc, f.Thu, f.CaHoc ORDER BY f.Id) AS rn_room
            FROM filtered f
         )
         SELECT MaLHP, Thu, CaHoc, PhongHoc
         FROM ranked
         WHERE rn_lhp = 1 AND rn_room = 1"
    );

    $legacyTkbTable = 'ThoiKhoaBieu_legacy_3nf_' . date('Ymd_His');
    $pdo->exec("ALTER TABLE ThoiKhoaBieu RENAME TO $legacyTkbTable");
    $pdo->exec('ALTER TABLE ThoiKhoaBieu_new RENAME TO ThoiKhoaBieu');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_tkb_lhp_thu_cahoc ON ThoiKhoaBieu(MaLHP, Thu, CaHoc)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_tkb_phong_thu_cahoc ON ThoiKhoaBieu(PhongHoc, Thu, CaHoc)');

    $step = 'rebuild_dangkyhoc';
    $hasDangKyOld = tableExists($pdo, 'DangKyHoc');
    if ($hasDangKyOld) {
        addColumnIfMissing($pdo, 'DangKyHoc', 'NgayDangKy TEXT');
        addColumnIfMissing($pdo, 'DangKyHoc', 'TrangThai TEXT');
        addColumnIfMissing($pdo, 'DangKyHoc', 'CreatedAt TEXT');
        addColumnIfMissing($pdo, 'DangKyHoc', 'UpdatedAt TEXT');
    }

    $dangKyNgayExpr = $hasDangKyOld ? sqlExpr($pdo, 'd', 'NgayDangKy', 'NULL') : 'NULL';
    $dangKyStatusExpr = $hasDangKyOld ? sqlExpr($pdo, 'd', 'TrangThai', "'APPROVED'") : "'APPROVED'";
    $dangKyCreatedExpr = $hasDangKyOld ? sqlExpr($pdo, 'd', 'CreatedAt', "datetime('now', 'localtime')") : "datetime('now', 'localtime')";
    $dangKyUpdatedExpr = $hasDangKyOld ? sqlExpr($pdo, 'd', 'UpdatedAt', 'NULL') : 'NULL';

    $pdo->exec('DROP TABLE IF EXISTS DangKyHoc_new');
    $pdo->exec(
        'CREATE TABLE DangKyHoc_new (
            Id INTEGER PRIMARY KEY AUTOINCREMENT,
            MaSV TEXT NOT NULL,
            MaLHP TEXT NOT NULL,
            NgayDangKy TEXT,
            TrangThai TEXT NOT NULL,
            CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TEXT,
            UNIQUE(MaSV, MaLHP),
            FOREIGN KEY(MaSV) REFERENCES SinhVien(MaSV),
            FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP)
        )'
    );

    if ($hasDangKyOld) {
        $pdo->exec(
            "INSERT OR IGNORE INTO DangKyHoc_new (MaSV, MaLHP, NgayDangKy, TrangThai, CreatedAt, UpdatedAt)
             SELECT
                d.MaSV,
                d.MaLHP,
                $dangKyNgayExpr,
                COALESCE(NULLIF(trim($dangKyStatusExpr), ''), 'APPROVED'),
                COALESCE($dangKyCreatedExpr, datetime('now', 'localtime')),
                $dangKyUpdatedExpr
             FROM DangKyHoc d
             JOIN SinhVien s ON lower(s.MaSV) = lower(d.MaSV)
             JOIN LopHocPhan l ON lower(l.MaLHP) = lower(d.MaLHP)
             WHERE trim(COALESCE(d.MaSV, '')) <> ''
               AND trim(COALESCE(d.MaLHP, '')) <> ''"
        );
    }

    $pdo->exec(
        "INSERT OR IGNORE INTO DangKyHoc_new (MaSV, MaLHP, NgayDangKy, TrangThai)
         SELECT DISTINCT
            k.MaSV,
            k.MaLHP,
            NULL,
            'APPROVED'
         FROM KetQuaHocTap k
         JOIN SinhVien s ON lower(s.MaSV) = lower(k.MaSV)
         JOIN LopHocPhan l ON lower(l.MaLHP) = lower(k.MaLHP)
         WHERE trim(COALESCE(k.MaSV, '')) <> ''
           AND trim(COALESCE(k.MaLHP, '')) <> ''"
    );

    if ($hasDangKyOld) {
        $legacyDangKyTable = 'DangKyHoc_legacy_3nf_' . date('Ymd_His');
        $pdo->exec("ALTER TABLE DangKyHoc RENAME TO $legacyDangKyTable");
    }
    $pdo->exec('ALTER TABLE DangKyHoc_new RENAME TO DangKyHoc');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dangkyhoc_lhp_status ON DangKyHoc(MaLHP, TrangThai)');

    $step = 'rebuild_ketquahoctap';
    $hasLanHocOld = columnExists($pdo, 'KetQuaHocTap', 'LanHoc');
    $lanHocExpr = $hasLanHocOld ? 'COALESCE(k.LanHoc, 1)' : '1';

    $pdo->exec('DROP TABLE IF EXISTS KetQuaHocTap_new');
    $pdo->exec(
        'CREATE TABLE KetQuaHocTap_new (
            Id INTEGER PRIMARY KEY AUTOINCREMENT,
            MaSV TEXT NOT NULL,
            MaLHP TEXT NOT NULL,
            LanHoc INTEGER NOT NULL DEFAULT 1,
            DiemChuyenCan REAL,
            DiemGiuaKy REAL,
            DiemCuoiKy REAL,
            FOREIGN KEY(MaSV) REFERENCES SinhVien(MaSV),
            FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP),
            UNIQUE(MaSV, MaLHP, LanHoc)
        )'
    );

    $pdo->exec(
        "INSERT INTO KetQuaHocTap_new (MaSV, MaLHP, LanHoc, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy)
         WITH ranked AS (
            SELECT
                k.MaSV,
                k.MaLHP,
                $lanHocExpr AS LanHoc,
                k.DiemChuyenCan,
                k.DiemGiuaKy,
                k.DiemCuoiKy,
                ROW_NUMBER() OVER (
                    PARTITION BY lower(k.MaSV), lower(k.MaLHP), CAST($lanHocExpr AS INTEGER)
                    ORDER BY k.Id
                ) AS rn
            FROM KetQuaHocTap k
            JOIN SinhVien s ON lower(s.MaSV) = lower(k.MaSV)
            JOIN LopHocPhan l ON lower(l.MaLHP) = lower(k.MaLHP)
            WHERE trim(COALESCE(k.MaSV, '')) <> ''
              AND trim(COALESCE(k.MaLHP, '')) <> ''
         )
         SELECT
            MaSV,
            MaLHP,
            CASE WHEN CAST(LanHoc AS INTEGER) <= 0 THEN 1 ELSE CAST(LanHoc AS INTEGER) END,
            DiemChuyenCan,
            DiemGiuaKy,
            DiemCuoiKy
         FROM ranked
         WHERE rn = 1"
    );

    $legacyKetQuaTable = 'KetQuaHocTap_legacy_3nf_' . date('Ymd_His');
    $pdo->exec("ALTER TABLE KetQuaHocTap RENAME TO $legacyKetQuaTable");
    $pdo->exec('ALTER TABLE KetQuaHocTap_new RENAME TO KetQuaHocTap');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ketquahoctap_lhp ON KetQuaHocTap(MaLHP)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ketquahoctap_masv ON KetQuaHocTap(MaSV)');

    $step = 'commit';
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->commit();

    $checks = [
        'missing_semester' => 'SELECT COUNT(1) FROM LopHocPhan WHERE MaHocKy IS NULL OR trim(MaHocKy) = ""',
        'orphan_semester_fk' => 'SELECT COUNT(1) FROM LopHocPhan l LEFT JOIN HocKy h ON h.MaHocKy = l.MaHocKy WHERE h.MaHocKy IS NULL',
        'dang_ky_duplicates' => 'SELECT COUNT(1) FROM (SELECT MaSV, MaLHP FROM DangKyHoc GROUP BY MaSV, MaLHP HAVING COUNT(*) > 1) t',
        'ket_qua_duplicates' => 'SELECT COUNT(1) FROM (SELECT MaSV, MaLHP, LanHoc FROM KetQuaHocTap GROUP BY MaSV, MaLHP, LanHoc HAVING COUNT(*) > 1) t',
    ];

    echo 'Migration finished.' . PHP_EOL;
    echo 'Backup: ' . $backupPath . PHP_EOL;
    foreach ($checks as $label => $sql) {
        $value = (int)$pdo->query($sql)->fetchColumn();
        echo $label . ': ' . $value . PHP_EOL;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    fwrite(STDERR, 'Migration failed at step [' . ($step ?? 'unknown') . ']: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Backup kept at: ' . $backupPath . PHP_EOL);
    exit(1);
}
