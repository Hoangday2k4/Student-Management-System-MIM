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
        if (!isset($names['MaNganh'])) {
            $pdo->exec('ALTER TABLE GiangVien ADD COLUMN MaNganh TEXT');
        }
        if (!isset($names['LopPhuTrach'])) {
            $pdo->exec('ALTER TABLE GiangVien ADD COLUMN LopPhuTrach TEXT');
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
            'department_code' => $row['MaNganh'] ?? '',
            'department' => $row['TenKhoa'] ?? '',
            'homeroom_class' => $row['LopPhuTrach'] ?? '',
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
        $maNganh = self::resolveKhoaIdByName($pdo, (string)($data['department'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO GiangVien (
                MaGV, HoTen, NgaySinh, GioiTinh, Email, SoDienThoai, HocHamHocVi, TrangThai, MaNganh, LopPhuTrach, Avatar, CreatedAt
            ) VALUES (
                :ma_gv, :ho_ten, :ngay_sinh, :gioi_tinh, :email, :so_dien_thoai, :hoc_ham_hoc_vi, :trang_thai, :ma_nganh, :lop_phu_trach, :avatar, CURRENT_TIMESTAMP
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
            ':ma_nganh' => $maNganh,
            ':lop_phu_trach' => $data['homeroom_class'] ?: null,
            ':avatar' => $data['avatar'] ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function findById(int $id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT g.*, k.TenKhoa
             FROM GiangVien g
             LEFT JOIN Khoa k ON k.MaKhoa = g.MaNganh
             WHERE rowid = :id
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
            'SELECT g.*, k.TenKhoa
             FROM GiangVien g
             LEFT JOIN Khoa k ON k.MaKhoa = g.MaNganh
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
        $sql = 'SELECT g.*, k.TenKhoa
                FROM GiangVien g
                LEFT JOIN Khoa k ON k.MaKhoa = g.MaNganh
                WHERE 1=1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(g.MaGV) LIKE :keyword
                OR lower(g.HoTen) LIKE :keyword
                OR lower(IFNULL(k.TenKhoa,"")) LIKE :keyword
                OR lower(IFNULL(g.LopPhuTrach,"")) LIKE :keyword
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

    public static function updateProfileByTeacherCode(string $teacherCode, array $data): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maNganh = self::resolveKhoaIdByName($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'UPDATE GiangVien
             SET HoTen = :ho_ten,
                 NgaySinh = :ngay_sinh,
                 GioiTinh = :gioi_tinh,
                 HocHamHocVi = :hoc_ham_hoc_vi,
                 MaNganh = :ma_nganh,
                 LopPhuTrach = :lop_phu_trach,
                 Email = :email,
                 SoDienThoai = :so_dien_thoai,
                 Avatar = :avatar,
                 TrangThai = :trang_thai
             WHERE lower(MaGV) = lower(:ma_gv)'
        );
        $stmt->execute([
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':hoc_ham_hoc_vi' => trim((string)($data['academic_title'] ?? '')) ?: null,
            ':ma_nganh' => $maNganh,
            ':lop_phu_trach' => $data['homeroom_class'] ?: null,
            ':email' => $data['email'] ?: null,
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':avatar' => $data['avatar'] ?: null,
            ':trang_thai' => $data['status'] ?: 'Đang công tác',
            ':ma_gv' => $teacherCode,
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function listDepartments(): array
    {
        self::ensureSchema();
        return Faculty::listAll();
    }
}
