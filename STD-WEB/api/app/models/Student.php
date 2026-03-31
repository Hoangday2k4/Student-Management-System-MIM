<?php

class Student
{
    private static function lowerText(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

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
            'CREATE TABLE IF NOT EXISTS LopSinhHoat (
                MaLop TEXT PRIMARY KEY,
                TenLop TEXT NOT NULL,
                MaNganh TEXT,
                MaGV_CoVan TEXT,
                NienKhoa TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Nganh (
                MaNganh TEXT PRIMARY KEY,
                TenNganh TEXT NOT NULL,
                MoTa TEXT
            )'
        );

        $columns = $pdo->query('PRAGMA table_info(SinhVien)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        foreach ($columns as $c) {
            $names[(string)$c['name']] = true;
        }
        if (!isset($names['Avatar'])) {
            $pdo->exec('ALTER TABLE SinhVien ADD COLUMN Avatar TEXT');
        }
        if (!isset($names['CreatedAt'])) {
            // SQLite khong cho ADD COLUMN voi DEFAULT CURRENT_TIMESTAMP
            $pdo->exec('ALTER TABLE SinhVien ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE SinhVien SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }
    }

    private static function resolveNganhIdByName(PDO $pdo, string $faculty): ?string
    {
        $name = trim($faculty);
        if ($name === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT MaNganh FROM Nganh WHERE lower(TenNganh)=lower(:name) OR lower(MaNganh)=lower(:name) LIMIT 1');
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string)$id;
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'NGANH');
        if (strlen($code) > 12) {
            $code = substr($code, 0, 12);
        }
        $ins = $pdo->prepare('INSERT OR IGNORE INTO Nganh (MaNganh, TenNganh, MoTa) VALUES (:code, :name, NULL)');
        $ins->execute([':code' => $code, ':name' => $name]);
        return $code;
    }

    private static function ensureLop(PDO $pdo, string $className, string $faculty): void
    {
        $maLop = trim($className);
        if ($maLop === '') {
            return;
        }
        $maNganh = self::resolveNganhIdByName($pdo, $faculty);
        $stmt = $pdo->prepare(
            'INSERT INTO LopSinhHoat (MaLop, TenLop, MaNganh, MaGV_CoVan, NienKhoa)
             VALUES (:ma_lop, :ten_lop, :ma_nganh, NULL, NULL)
             ON CONFLICT(MaLop) DO UPDATE SET
                TenLop = excluded.TenLop,
                MaNganh = COALESCE(excluded.MaNganh, LopSinhHoat.MaNganh)'
        );
        $stmt->execute([
            ':ma_lop' => $maLop,
            ':ten_lop' => $maLop,
            ':ma_nganh' => $maNganh,
        ]);
    }

    public static function create(array $data)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        self::insertWithPdo($pdo, $data);
        return self::findByStudentCode((string)$data['student_code']);
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        self::ensureSchema($pdo);
        self::ensureLop($pdo, (string)($data['class_name'] ?? ''), (string)($data['faculty'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO SinhVien (
                MaSV, HoTen, NgaySinh, GioiTinh, DiaChi, SoDienThoai, Email, MaLop, NgayNhapHoc, TrangThai, Avatar, CreatedAt
            ) VALUES (
                :ma_sv, :ho_ten, :ngay_sinh, :gioi_tinh, :dia_chi, :so_dien_thoai, :email, :ma_lop, :ngay_nhap_hoc, :trang_thai, :avatar, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_sv' => $data['student_code'],
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':dia_chi' => null,
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':email' => $data['email'] ?: null,
            ':ma_lop' => $data['class_name'] ?: null,
            ':ngay_nhap_hoc' => null,
            ':trang_thai' => $data['status'] ?: 'Đang học',
            ':avatar' => $data['avatar'] ?: null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    private static function mapRow(array $row): array
    {
        return [
            'id' => null,
            'student_code' => $row['MaSV'] ?? '',
            'full_name' => $row['HoTen'] ?? '',
            'date_of_birth' => $row['NgaySinh'] ?? '',
            'gender' => $row['GioiTinh'] ?? '',
            'class_name' => $row['MaLop'] ?? '',
            'faculty' => $row['TenNganh'] ?? '',
            'email' => $row['Email'] ?? '',
            'phone' => $row['SoDienThoai'] ?? '',
            'avatar' => $row['Avatar'] ?? '',
            'status' => $row['TrangThai'] ?? '',
            'created_at' => $row['CreatedAt'] ?? '',
        ];
    }

    public static function findById(int $id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT s.*, n.TenNganh
             FROM SinhVien s
             LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
             LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
             WHERE rowid = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapRow($row) : false;
    }

    public static function search(array $filters = [])
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql = 'SELECT s.*, n.TenNganh
                FROM SinhVien s
                LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
                LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
                WHERE 1=1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(s.MaSV) LIKE :keyword
                OR lower(s.HoTen) LIKE :keyword
                OR lower(s.MaLop) LIKE :keyword
                OR lower(IFNULL(n.TenNganh,"")) LIKE :keyword
                OR lower(IFNULL(s.Email,"")) LIKE :keyword
                OR lower(IFNULL(s.SoDienThoai,"")) LIKE :keyword
            )';
            $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }
        if (!empty($filters['class_name'])) {
            $sql .= ' AND lower(IFNULL(s.MaLop,"")) LIKE :class_name';
            $params[':class_name'] = '%' . self::lowerText((string)$filters['class_name']) . '%';
        }
        if (!empty($filters['faculty'])) {
            $sql .= ' AND lower(IFNULL(n.TenNganh,"")) LIKE :faculty';
            $params[':faculty'] = '%' . self::lowerText((string)$filters['faculty']) . '%';
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND s.TrangThai = :status';
            $params[':status'] = $filters['status'];
        }
        $sql .= ' ORDER BY lower(IFNULL(n.TenNganh,"")) ASC, s.MaLop ASC, s.MaSV ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'mapRow'], $rows);
    }

    public static function findByStudentCode(string $studentCode)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT s.*, n.TenNganh
             FROM SinhVien s
             LEFT JOIN LopSinhHoat l ON l.MaLop = s.MaLop
             LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
             WHERE lower(s.MaSV) = lower(:code)
             LIMIT 1'
        );
        $stmt->execute([':code' => $studentCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapRow($row) : false;
    }

    public static function updateProfileByStudentCode(string $studentCode, array $data): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        self::ensureLop($pdo, (string)($data['class_name'] ?? ''), (string)($data['faculty'] ?? ''));
        $stmt = $pdo->prepare(
            'UPDATE SinhVien
             SET HoTen = :ho_ten,
                 NgaySinh = :ngay_sinh,
                 GioiTinh = :gioi_tinh,
                 MaLop = :ma_lop,
                 Email = :email,
                 SoDienThoai = :so_dien_thoai,
                 Avatar = :avatar,
                 TrangThai = :trang_thai
             WHERE lower(MaSV) = lower(:ma_sv)'
        );
        $stmt->execute([
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':ma_lop' => $data['class_name'] ?: null,
            ':email' => $data['email'] ?: null,
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':avatar' => $data['avatar'] ?: null,
            ':trang_thai' => $data['status'] ?: 'Đang học',
            ':ma_sv' => $studentCode,
        ]);
        return $stmt->rowCount() > 0;
    }
}
