<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    return (bool)$stmt->fetchColumn();
}

$dbPath = DB_PATH;
if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$backupPath = dirname($dbPath) . '/ltweb_backup_before_semester_3nf_hardening_' . date('Ymd_His') . '.sqlite';
if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Cannot create backup: {$backupPath}" . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 15000');

try {
    $pdo->beginTransaction();
    $pdo->exec('PRAGMA foreign_keys = OFF');

    // Seed required placeholders for hardening.
    $pdo->exec("INSERT OR IGNORE INTO Nganh (MaNganh, TenNganh, MoTa) VALUES ('UNKNOWN', 'Unknown', 'Auto placeholder')");

    if (!tableExists($pdo, 'GiangVien')) {
        throw new RuntimeException('Required table GiangVien is missing.');
    }
    if (!tableExists($pdo, 'LopSinhHoat')) {
        throw new RuntimeException('Required table LopSinhHoat is missing.');
    }
    if (!tableExists($pdo, 'SinhVien')) {
        throw new RuntimeException('Required table SinhVien is missing.');
    }
    if (!tableExists($pdo, 'MonHoc')) {
        throw new RuntimeException('Required table MonHoc is missing.');
    }

    // Normalize GiangVien data to satisfy NOT NULL targets.
    $pdo->exec("UPDATE GiangVien SET GioiTinh = 'Khac' WHERE GioiTinh IS NULL OR trim(GioiTinh) = ''");
    $pdo->exec("UPDATE GiangVien SET TrangThai = 'ACTIVE' WHERE TrangThai IS NULL OR trim(TrangThai) = ''");
    $pdo->exec(
        "UPDATE GiangVien
         SET Email = lower(MaGV) || '@placeholder.local'
         WHERE Email IS NULL OR trim(Email) = ''"
    );

    $pdo->exec('DROP TABLE IF EXISTS GiangVien_new');
    $pdo->exec(
        'CREATE TABLE GiangVien_new (
            MaGV TEXT PRIMARY KEY,
            HoTen TEXT NOT NULL,
            GioiTinh TEXT NOT NULL,
            NgaySinh TEXT,
            Email TEXT NOT NULL UNIQUE,
            SoDienThoai TEXT,
            HocHamHocVi TEXT,
            TrangThai TEXT NOT NULL,
            MaNganh TEXT,
            LopPhuTrach TEXT,
            Avatar TEXT,
            CreatedAt TEXT
        )'
    );

    $pdo->exec(
        "INSERT INTO GiangVien_new (
            MaGV, HoTen, GioiTinh, NgaySinh, Email, SoDienThoai, HocHamHocVi, TrangThai,
            MaNganh, LopPhuTrach, Avatar, CreatedAt
        )
        SELECT
            MaGV,
            COALESCE(NULLIF(trim(HoTen), ''), MaGV),
            COALESCE(NULLIF(trim(GioiTinh), ''), 'Khac'),
            NgaySinh,
            COALESCE(NULLIF(trim(Email), ''), lower(MaGV) || '@placeholder.local'),
            SoDienThoai,
            HocHamHocVi,
            COALESCE(NULLIF(trim(TrangThai), ''), 'ACTIVE'),
            MaNganh,
            LopPhuTrach,
            Avatar,
            CreatedAt
        FROM GiangVien"
    );

    $pdo->exec('DROP TABLE GiangVien');
    $pdo->exec('ALTER TABLE GiangVien_new RENAME TO GiangVien');

    // Normalize MonHoc department references.
    $pdo->exec("UPDATE MonHoc SET MaNganh = 'UNKNOWN' WHERE MaNganh IS NULL OR trim(MaNganh) = ''");
    $pdo->exec(
        "UPDATE MonHoc
         SET MaNganh = 'UNKNOWN'
         WHERE NOT EXISTS (
             SELECT 1 FROM Nganh n WHERE lower(n.MaNganh) = lower(MonHoc.MaNganh)
         )"
    );

    $pdo->exec('DROP TABLE IF EXISTS MonHoc_new');
    $pdo->exec(
        'CREATE TABLE MonHoc_new (
            MaMon TEXT PRIMARY KEY,
            TenMon TEXT NOT NULL,
            SoTinChi INTEGER NOT NULL,
            LoaiMon TEXT,
            MaNganh TEXT NOT NULL,
            FOREIGN KEY(MaNganh) REFERENCES Nganh(MaNganh)
        )'
    );

    $pdo->exec(
        "INSERT INTO MonHoc_new (MaMon, TenMon, SoTinChi, LoaiMon, MaNganh)
         SELECT
            MaMon,
            COALESCE(NULLIF(trim(TenMon), ''), MaMon),
            COALESCE(SoTinChi, 0),
            LoaiMon,
            COALESCE(NULLIF(trim(MaNganh), ''), 'UNKNOWN')
         FROM MonHoc"
    );

    $pdo->exec('DROP TABLE MonHoc');
    $pdo->exec('ALTER TABLE MonHoc_new RENAME TO MonHoc');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monhoc_manganh ON MonHoc(MaNganh)');

    // Normalize LopSinhHoat data for NOT NULL + FK.
    $pdo->exec("UPDATE LopSinhHoat SET MaNganh = 'UNKNOWN' WHERE MaNganh IS NULL OR trim(MaNganh) = ''");
    $pdo->exec(
        "UPDATE LopSinhHoat
         SET MaNganh = 'UNKNOWN'
         WHERE NOT EXISTS (
             SELECT 1 FROM Nganh n WHERE lower(n.MaNganh) = lower(LopSinhHoat.MaNganh)
         )"
    );
    $pdo->exec("UPDATE LopSinhHoat SET NienKhoa = '2024-2028' WHERE NienKhoa IS NULL OR trim(NienKhoa) = ''");
    $pdo->exec(
        "UPDATE LopSinhHoat
         SET MaGV_CoVan = NULL
         WHERE MaGV_CoVan IS NOT NULL
           AND trim(MaGV_CoVan) <> ''
           AND NOT EXISTS (
               SELECT 1 FROM GiangVien g WHERE lower(g.MaGV) = lower(LopSinhHoat.MaGV_CoVan)
           )"
    );

    $pdo->exec(
        "INSERT OR IGNORE INTO LopSinhHoat (MaLop, TenLop, MaNganh, MaGV_CoVan, NienKhoa)
         VALUES ('LOP_UNKNOWN', 'Unknown Class', 'UNKNOWN', NULL, '2024-2028')"
    );

    $pdo->exec('DROP TABLE IF EXISTS LopSinhHoat_new');
    $pdo->exec(
        'CREATE TABLE LopSinhHoat_new (
            MaLop TEXT PRIMARY KEY,
            TenLop TEXT NOT NULL,
            MaNganh TEXT NOT NULL,
            MaGV_CoVan TEXT,
            NienKhoa TEXT NOT NULL,
            FOREIGN KEY(MaNganh) REFERENCES Nganh(MaNganh),
            FOREIGN KEY(MaGV_CoVan) REFERENCES GiangVien(MaGV)
        )'
    );

    $pdo->exec(
        "INSERT INTO LopSinhHoat_new (MaLop, TenLop, MaNganh, MaGV_CoVan, NienKhoa)
         SELECT
            MaLop,
            COALESCE(NULLIF(trim(TenLop), ''), MaLop),
            COALESCE(NULLIF(trim(MaNganh), ''), 'UNKNOWN'),
            CASE
                WHEN MaGV_CoVan IS NULL OR trim(MaGV_CoVan) = '' THEN NULL
                ELSE MaGV_CoVan
            END,
            COALESCE(NULLIF(trim(NienKhoa), ''), '2024-2028')
         FROM LopSinhHoat"
    );

    $pdo->exec('DROP TABLE LopSinhHoat');
    $pdo->exec('ALTER TABLE LopSinhHoat_new RENAME TO LopSinhHoat');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lopsh_ma_nganh ON LopSinhHoat(MaNganh)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lopsh_ma_gv_cv ON LopSinhHoat(MaGV_CoVan)');

    // Normalize SinhVien data for NOT NULL + FK + composite index.
    $pdo->exec("UPDATE SinhVien SET GioiTinh = 'Khac' WHERE GioiTinh IS NULL OR trim(GioiTinh) = ''");
    $pdo->exec(
        "UPDATE SinhVien
         SET CCCD = 'CCCD_' || MaSV
         WHERE CCCD IS NULL OR trim(CCCD) = ''"
    );
    $pdo->exec(
        "UPDATE SinhVien
         SET Email = lower(MaSV) || '@placeholder.local'
         WHERE Email IS NULL OR trim(Email) = ''"
    );
    $pdo->exec(
        "UPDATE SinhVien
         SET MaLop = 'LOP_UNKNOWN'
         WHERE MaLop IS NULL
            OR trim(MaLop) = ''
            OR NOT EXISTS (
                SELECT 1 FROM LopSinhHoat l WHERE lower(l.MaLop) = lower(SinhVien.MaLop)
            )"
    );
    $pdo->exec("UPDATE SinhVien SET TrangThai = 'ACTIVE' WHERE TrangThai IS NULL OR trim(TrangThai) = ''");

    $pdo->exec('DROP TABLE IF EXISTS SinhVien_new');
    $pdo->exec(
        'CREATE TABLE SinhVien_new (
            MaSV TEXT PRIMARY KEY,
            HoTen TEXT NOT NULL,
            NgaySinh TEXT,
            GioiTinh TEXT NOT NULL,
            CCCD TEXT NOT NULL UNIQUE,
            DiaChi TEXT,
            SoDienThoai TEXT,
            Email TEXT NOT NULL UNIQUE,
            MaLop TEXT NOT NULL,
            NgayNhapHoc TEXT,
            TrangThai TEXT NOT NULL,
            Avatar TEXT,
            CreatedAt TEXT,
            FOREIGN KEY(MaLop) REFERENCES LopSinhHoat(MaLop)
        )'
    );

    $pdo->exec(
        "INSERT INTO SinhVien_new (
            MaSV, HoTen, NgaySinh, GioiTinh, CCCD, DiaChi, SoDienThoai, Email,
            MaLop, NgayNhapHoc, TrangThai, Avatar, CreatedAt
        )
        SELECT
            MaSV,
            COALESCE(NULLIF(trim(HoTen), ''), MaSV),
            NgaySinh,
            COALESCE(NULLIF(trim(GioiTinh), ''), 'Khac'),
            COALESCE(NULLIF(trim(CCCD), ''), 'CCCD_' || MaSV),
            DiaChi,
            SoDienThoai,
            COALESCE(NULLIF(trim(Email), ''), lower(MaSV) || '@placeholder.local'),
            COALESCE(NULLIF(trim(MaLop), ''), 'LOP_UNKNOWN'),
            NgayNhapHoc,
            COALESCE(NULLIF(trim(TrangThai), ''), 'ACTIVE'),
            Avatar,
            CreatedAt
        FROM SinhVien"
    );

    $pdo->exec('DROP TABLE SinhVien');
    $pdo->exec('ALTER TABLE SinhVien_new RENAME TO SinhVien');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sinhvien_malop_trangthai ON SinhVien(MaLop, TrangThai)');

    // Ensure missing non-unique index for LopHocPhan semester lookup.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lophocphan_mahocky ON LopHocPhan(MaHocKy)');

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->commit();

    echo 'Hardening migration finished.' . PHP_EOL;
    echo 'Backup: ' . $backupPath . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    fwrite(STDERR, 'Hardening migration failed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Backup kept at: ' . $backupPath . PHP_EOL);
    exit(1);
}
