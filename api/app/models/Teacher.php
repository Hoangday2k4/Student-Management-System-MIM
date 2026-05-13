<?php

require_once __DIR__ . '/Faculty.php';

class Teacher
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

        Faculty::ensureSchema($pdo);

        $columns = $pdo->query('PRAGMA table_info(GiangVien)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        foreach ($columns as $c) {
            $names[(string)$c['name']] = true;
        }
        
        // FIX KIẾN TRÚC: Chuyển MaNganh thành MaKhoa
        if (!isset($names['MaKhoa'])) {
            $pdo->exec('ALTER TABLE GiangVien ADD COLUMN MaKhoa TEXT');
            // Nếu hệ thống cũ có cột MaNganh, đẩy dữ liệu sang MaKhoa
            if (isset($names['MaNganh'])) {
                $pdo->exec('UPDATE GiangVien SET MaKhoa = MaNganh WHERE MaKhoa IS NULL');
            }
        }
      
        if (!isset($names['Avatar'])) {
            $pdo->exec('ALTER TABLE GiangVien ADD COLUMN Avatar TEXT');
        }
        if (!isset($names['CreatedAt'])) {
            // SQLite khong cho ADD COLUMN voi DEFAULT CURRENT_TIMESTAMP
            $pdo->exec('ALTER TABLE GiangVien ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE GiangVien SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }
    }

    private static function resolveKhoaIdByName(PDO $pdo, string $department): ?string
    {
        return Faculty::resolveIdByName($pdo, $department);
    }

    private static function mapRow(array $row): array
    {
        return [
            'id' => null,
            'teacher_code' => $row['MaGV'] ?? '',
            'full_name' => $row['HoTen'] ?? '',
            'date_of_birth' => $row['NgaySinh'] ?? '',
            'gender' => $row['GioiTinh'] ?? '',
            'academic_title' => $row['HocHamHocVi'] ?? '',
            'department_code' => $row['MaKhoa'] ?? '', // FIX: Lấy MaKhoa
            'department' => $row['TenKhoa'] ?? '',
            'email' => $row['Email'] ?? '',
            'phone' => $row['SoDienThoai'] ?? '',
            'avatar' => $row['Avatar'] ?? '',
            'status' => $row['TrangThai'] ?? '',
            'created_at' => $row['CreatedAt'] ?? '',
        ];
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        self::ensureSchema($pdo);
        $maKhoa = self::resolveKhoaIdByName($pdo, (string)($data['department'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO GiangVien (
                MaGV, HoTen, NgaySinh, GioiTinh, Email, SoDienThoai, HocHamHocVi, TrangThai, MaKhoa, Avatar, CreatedAt
            ) VALUES (
                :ma_gv, :ho_ten, :ngay_sinh, :gioi_tinh, :email, :so_dien_thoai, :hoc_ham_hoc_vi, :trang_thai, :ma_khoa, :avatar, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_gv' => $data['teacher_code'],
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':email' => $data['email'] ?: (strtolower((string)$data['teacher_code']) . '@local'),
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':hoc_ham_hoc_vi' => trim((string)($data['academic_title'] ?? '')) ?: null,
            ':trang_thai' => $data['status'] ?: 'Đang công tác',
            ':ma_khoa' => $maKhoa,
            ':avatar' => $data['avatar'] ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function findById(int $id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT g.*, COALESCE(k.TenKhoa, g.MaKhoa) AS TenKhoa
             FROM GiangVien g
             LEFT JOIN Khoa k ON k.MaKhoa = g.MaKhoa
             WHERE g.rowid = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapRow($row) : false;
    }

    public static function findByTeacherCode(string $teacherCode)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT g.*, COALESCE(k.TenKhoa, g.MaKhoa) AS TenKhoa
             FROM GiangVien g
             LEFT JOIN Khoa k ON k.MaKhoa = g.MaKhoa
             WHERE lower(g.MaGV) = lower(:code)
             LIMIT 1'
        );
        $stmt->execute([':code' => $teacherCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapRow($row) : false;
    }

    public static function search(array $filters = [])
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $sql = 'SELECT g.*, COALESCE(k.TenKhoa, g.MaKhoa) AS TenKhoa
                FROM GiangVien g
                LEFT JOIN Khoa k ON k.MaKhoa = g.MaKhoa
                WHERE 1=1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(g.MaGV) LIKE :keyword
                OR lower(g.HoTen) LIKE :keyword
                OR lower(IFNULL(k.TenKhoa,"")) LIKE :keyword
                OR lower(IFNULL(g.Email,"")) LIKE :keyword
                OR lower(IFNULL(g.SoDienThoai,"")) LIKE :keyword
            )';
            $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }
        if (!empty($filters['department'])) {
            $sql .= ' AND lower(IFNULL(k.TenKhoa,"")) LIKE :department';
            $params[':department'] = '%' . self::lowerText((string)$filters['department']) . '%';
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND g.TrangThai = :status';
            $params[':status'] = $filters['status'];
        }
        $sql .= ' ORDER BY lower(IFNULL(k.TenKhoa,"")) ASC, g.MaGV ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'mapRow'], $rows);
    }

    // THÊM MỚI: Hàm Update dùng chung PDO (Để bọc Transaction bên Controller)
    public static function updateProfileWithPdo(PDO $pdo, string $teacherCode, array $data): bool
    {
        $maKhoa = self::resolveKhoaIdByName($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'UPDATE GiangVien
             SET HoTen = :ho_ten,
                 NgaySinh = :ngay_sinh,
                 GioiTinh = :gioi_tinh,
                 HocHamHocVi = :hoc_ham_hoc_vi,
                 MaKhoa = :ma_khoa,
                 Email = :email,
                 SoDienThoai = :so_dien_thoai,
                 Avatar = :avatar,
                 TrangThai = :trang_thai
             WHERE lower(MaGV) = lower(:ma_gv)'
        );
       
        return $stmt->execute([
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':hoc_ham_hoc_vi' => trim((string)($data['academic_title'] ?? '')) ?: null,
            ':ma_khoa' => $maKhoa,
            ':email' => $data['email'] ?: null,
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':avatar' => $data['avatar'] ?: null,
            ':trang_thai' => $data['status'] ?: 'Đang công tác',
            ':ma_gv' => $teacherCode,
        ]);
    }

    public static function updateProfileByTeacherCode(string $teacherCode, array $data): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        return self::updateProfileWithPdo($pdo, $teacherCode, $data);
    }

    public static function listDepartments(): array
    {
        self::ensureSchema();
        return Faculty::listAll();
    }
}
