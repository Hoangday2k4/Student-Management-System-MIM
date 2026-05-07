<?php

class HomeroomClass
{
    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS LopSinhHoat (
                MaLop TEXT PRIMARY KEY,
                TenLop TEXT NOT NULL,
                MaNganh TEXT NOT NULL,
                MaGV_CoVan TEXT,
                NienKhoa TEXT NOT NULL
            )'
        );

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

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lopsh_ma_nganh ON LopSinhHoat(MaNganh)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lopsh_ma_gv_cv ON LopSinhHoat(MaGV_CoVan)');
    }

    private static function mapRow(array $row): array
    {
        return [
            'ma_lop' => (string)($row['MaLop'] ?? ''),
            'ten_lop' => (string)($row['TenLop'] ?? ''),
            'ma_nganh' => (string)($row['MaNganh'] ?? ''),
            'ten_nganh' => (string)($row['TenNganh'] ?? ''),
            'ma_gv_co_van' => (string)($row['MaGV_CoVan'] ?? ''),
            'ten_gv_co_van' => (string)($row['TenGVCV'] ?? ''),
            'nien_khoa' => (string)($row['NienKhoa'] ?? ''),
            'student_count' => (int)($row['StudentCount'] ?? 0),
        ];
    }

    public static function list(array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $sql =
            'SELECT l.MaLop, l.TenLop, l.MaNganh, n.TenNganh, l.MaGV_CoVan, g.HoTen AS TenGVCV, l.NienKhoa,
                    (SELECT COUNT(1) FROM SinhVien s WHERE s.MaLop = l.MaLop) AS StudentCount
             FROM LopSinhHoat l
             LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
             LEFT JOIN GiangVien g ON g.MaGV = l.MaGV_CoVan
             WHERE 1=1';

        $params = [];

        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $sql .= ' AND (
                lower(IFNULL(l.MaLop, "")) LIKE :kw
                OR lower(IFNULL(l.TenLop, "")) LIKE :kw
                OR lower(IFNULL(l.NienKhoa, "")) LIKE :kw
            )';
            $params[':kw'] = '%' . mb_strtolower($keyword, 'UTF-8') . '%';
        }

        $maNganh = trim((string)($filters['ma_nganh'] ?? ''));
        if ($maNganh !== '') {
            $sql .= ' AND lower(IFNULL(l.MaNganh, "")) = lower(:ma_nganh)';
            $params[':ma_nganh'] = $maNganh;
        }

        $maGVCV = trim((string)($filters['ma_gv_co_van'] ?? ''));
        if ($maGVCV !== '') {
            $sql .= ' AND lower(IFNULL(l.MaGV_CoVan, "")) = lower(:ma_gv_cv)';
            $params[':ma_gv_cv'] = $maGVCV;
        }

        $sql .= ' ORDER BY l.MaLop ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn($r) => self::mapRow($r), $rows);
    }

    public static function findByCode(string $maLop): ?array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $stmt = $pdo->prepare(
            'SELECT l.MaLop, l.TenLop, l.MaNganh, n.TenNganh, l.MaGV_CoVan, g.HoTen AS TenGVCV, l.NienKhoa,
                    (SELECT COUNT(1) FROM SinhVien s WHERE s.MaLop = l.MaLop) AS StudentCount
             FROM LopSinhHoat l
             LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
             LEFT JOIN GiangVien g ON g.MaGV = l.MaGV_CoVan
             WHERE lower(l.MaLop) = lower(:ma_lop)
             LIMIT 1'
        );
        $stmt->execute([':ma_lop' => $maLop]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapRow($row) : null;
    }

    public static function createWithPdo(PDO $pdo, array $data): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO LopSinhHoat (MaLop, TenLop, MaNganh, MaGV_CoVan, NienKhoa)
             VALUES (:ma_lop, :ten_lop, :ma_nganh, :ma_gv_cv, :nien_khoa)'
        );
        $stmt->execute([
            ':ma_lop' => $data['ma_lop'],
            ':ten_lop' => $data['ten_lop'],
            ':ma_nganh' => $data['ma_nganh'],
            ':ma_gv_cv' => $data['ma_gv_co_van'] !== '' ? $data['ma_gv_co_van'] : null,
            ':nien_khoa' => $data['nien_khoa'],
        ]);
        return self::findByCode((string)$data['ma_lop']);
    }

    public static function updateByCodeWithPdo(PDO $pdo, string $maLop, array $data): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            'UPDATE LopSinhHoat
             SET TenLop = :ten_lop,
                 MaNganh = :ma_nganh,
                 MaGV_CoVan = :ma_gv_cv,
                 NienKhoa = :nien_khoa
             WHERE lower(MaLop) = lower(:ma_lop)'
        );
        $stmt->execute([
            ':ten_lop' => $data['ten_lop'],
            ':ma_nganh' => $data['ma_nganh'],
            ':ma_gv_cv' => $data['ma_gv_co_van'] !== '' ? $data['ma_gv_co_van'] : null,
            ':nien_khoa' => $data['nien_khoa'],
            ':ma_lop' => $maLop,
        ]);
        return self::findByCode($maLop);
    }

    public static function deleteByCodeWithPdo(PDO $pdo, string $maLop): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(1) FROM SinhVien WHERE lower(MaLop) = lower(:ma_lop)');
        $stmt->execute([':ma_lop' => $maLop]);
        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }

        $stmt = $pdo->prepare('DELETE FROM LopSinhHoat WHERE lower(MaLop) = lower(:ma_lop)');
        $stmt->execute([':ma_lop' => $maLop]);
        return $stmt->rowCount() > 0;
    }

    public static function options(): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $majors = $pdo->query('SELECT MaNganh, TenNganh FROM Nganh ORDER BY MaNganh ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $teachers = $pdo->query('SELECT MaGV, HoTen FROM GiangVien ORDER BY MaGV ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'majors' => array_map(static fn($r) => [
                'ma_nganh' => (string)($r['MaNganh'] ?? ''),
                'ten_nganh' => (string)($r['TenNganh'] ?? ''),
            ], $majors),
            'teachers' => array_map(static fn($r) => [
                'ma_gv' => (string)($r['MaGV'] ?? ''),
                'ho_ten' => (string)($r['HoTen'] ?? ''),
            ], $teachers),
        ];
    }
}
