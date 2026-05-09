<?php

require_once __DIR__ . '/Faculty.php';

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
                MaNganh TEXT,
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
        Faculty::ensureSchema($pdo);

        $columns = $pdo->query('PRAGMA table_info(SinhVien)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        foreach ($columns as $c) {
            $names[(string)$c['name']] = true;
        }
        if (!isset($names['Avatar'])) {
            $pdo->exec('ALTER TABLE SinhVien ADD COLUMN Avatar TEXT');
        }
        if (!isset($names['MaNganh'])) {
            $pdo->exec('ALTER TABLE SinhVien ADD COLUMN MaNganh TEXT');
        }
        if (!isset($names['CreatedAt'])) {
            // SQLite khong cho ADD COLUMN voi DEFAULT CURRENT_TIMESTAMP
            $pdo->exec('ALTER TABLE SinhVien ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE SinhVien SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }
    }

    private static function resolveKhoaIdByName(PDO $pdo, string $faculty): ?string
    {
        return Faculty::resolveIdByName($pdo, $faculty);
    }

    /**
     * Verify that class exists in database
     * @throws RuntimeException if class does not exist
     */
    private static function verifyLopExists(PDO $pdo, string $className): void
    {
        $maLop = trim($className);
        if ($maLop === '') {
            throw new RuntimeException('Lớp không được để trống.');
        }
        $stmt = $pdo->prepare('SELECT 1 FROM LopSinhHoat WHERE MaLop = :ma_lop LIMIT 1');
        $stmt->execute([':ma_lop' => $maLop]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException("Lớp '$maLop' không tồn tại. Vui lòng tạo lớp trước khi thêm sinh viên.");
        }
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
        // Verify class exists before inserting student
        self::verifyLopExists($pdo, (string)($data['class_name'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO SinhVien (
                MaSV, HoTen, NgaySinh, GioiTinh, CCCD, DiaChi, SoDienThoai, Email, MaLop, MaNganh, NgayNhapHoc, TrangThai, Avatar, CreatedAt
            ) VALUES (
                :ma_sv, :ho_ten, :ngay_sinh, :gioi_tinh, :cccd, :dia_chi, :so_dien_thoai, :email, :ma_lop, :ma_nganh, :ngay_nhap_hoc, :trang_thai, :avatar, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_sv' => $data['student_code'],
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':cccd' => trim((string)($data['cccd'] ?? '')) ?: null,
            ':dia_chi' => trim((string)($data['address'] ?? '')) ?: null,
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':email' => $data['email'] ?: null,
            ':ma_lop' => $data['class_name'] ?: null,
            ':ma_nganh' => trim((string)($data['major'] ?? '')) ?: null,
            ':ngay_nhap_hoc' => trim((string)($data['admission_date'] ?? '')) ?: null,
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
            'cccd' => $row['CCCD'] ?? '',
            'address' => $row['DiaChi'] ?? '',
            'class_name' => $row['MaLop'] ?? '',
            'major' => $row['MaNganh'] ?? '',
            'major_code' => $row['MaNganh'] ?? '',
            'major_name' => $row['TenNganh'] ?? '-',
            'faculty_name' => $row['TenKhoa'] ?? '-',
            'admission_date' => $row['NgayNhapHoc'] ?? '',
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
        'SELECT 
            s.*, 
            n.TenNganh, 
            k.TenKhoa
         FROM SinhVien s
         LEFT JOIN Nganh n ON n.MaNganh = s.MaNganh
         LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
         WHERE lower(s.MaSV) = lower(:code)
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

    $sql = 'SELECT s.*, 
                   n.TenNganh, 
                   k.TenKhoa
            FROM SinhVien s
            LEFT JOIN Nganh n ON n.MaNganh = s.MaNganh
            LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
            WHERE 1=1';
    
    $params = [];

    if (!empty($filters['keyword'])) {
        $sql .= ' AND (
            lower(s.MaSV) LIKE :keyword
            OR lower(s.HoTen) LIKE :keyword
            OR lower(s.MaLop) LIKE :keyword
            OR lower(IFNULL(n.TenNganh,"")) LIKE :keyword
            OR lower(IFNULL(k.TenKhoa,"")) LIKE :keyword
            OR lower(IFNULL(s.Email,"")) LIKE :keyword
        )';
        $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
    }

    if (!empty($filters['class_name'])) {
        $sql .= ' AND lower(IFNULL(s.MaLop,"")) LIKE :class_name';
        $params[':class_name'] = '%' . self::lowerText((string)$filters['class_name']) . '%';
    }

    if (!empty($filters['faculty'])) {
        // Tìm kiếm theo tên khoa hoặc tên ngành
        $sql .= ' AND (lower(IFNULL(k.TenKhoa,"")) LIKE :faculty OR lower(IFNULL(n.TenNganh,"")) LIKE :faculty)';
        $params[':faculty'] = '%' . self::lowerText((string)$filters['faculty']) . '%';
    }

    if (!empty($filters['status'])) {
        $sql .= ' AND s.TrangThai = :status';
        $params[':status'] = $filters['status'];
    }

   // Thay bằng dòng này: Sắp xếp theo ngày tạo mới nhất (DESC)
        $sql .= ' ORDER BY s.CreatedAt DESC, s.MaSV DESC LIMIT 300';
    
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
        'SELECT 
            s.*, 
            n.TenNganh, 
            k.TenKhoa
         FROM SinhVien s
         LEFT JOIN Nganh n ON n.MaNganh = s.MaNganh
         LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
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
        // Verify class exists before updating student
        self::verifyLopExists($pdo, (string)($data['class_name'] ?? ''));
        $stmt = $pdo->prepare(
            'UPDATE SinhVien
             SET HoTen = :ho_ten,
                 NgaySinh = :ngay_sinh,
                 GioiTinh = :gioi_tinh,
                 MaLop = :ma_lop,
                 MaNganh = :ma_nganh, -- ĐÃ THÊM DÒNG NÀY ĐỂ LƯU NGÀNH
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
            ':ma_nganh' => trim((string)($data['major'] ?? '')) ?: null, // ĐÃ THÊM DÒNG NÀY LẤY TỪ CONTROLLER
            ':email' => $data['email'] ?: null,
            ':so_dien_thoai' => $data['phone'] ?: null,
            ':avatar' => $data['avatar'] ?: null,
            ':trang_thai' => $data['status'] ?: 'Đang học',
            ':ma_sv' => $studentCode,
        ]);
        return $stmt->rowCount() > 0;
    }
}
