<?php

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
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Nganh (
                MaNganh TEXT PRIMARY KEY,
                TenNganh TEXT NOT NULL,
                MoTa TEXT
            )'
        );

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

    private static function resolveNganhIdByName(PDO $pdo, string $department): ?string
    {
        $name = trim($department);
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

    private static function mapRow(array $row): array
    {
        return [
            'id' => null,
            'teacher_code' => $row['MaGV'] ?? '',
            'full_name' => $row['HoTen'] ?? '',
            'date_of_birth' => $row['NgaySinh'] ?? '',
            'gender' => $row['GioiTinh'] ?? '',
            'department' => $row['TenNganh'] ?? '',
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
        $maNganh = self::resolveNganhIdByName($pdo, (string)($data['department'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO GiangVien (
                MaGV, HoTen, NgaySinh, GioiTinh, Email, SoDienThoai, HocHamHocVi, TrangThai, MaNganh, LopPhuTrach, Avatar, CreatedAt
            ) VALUES (
                :ma_gv, :ho_ten, :ngay_sinh, :gioi_tinh, :email, :so_dien_thoai, NULL, :trang_thai, :ma_nganh, :lop_phu_trach, :avatar, CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            ':ma_gv' => $data['teacher_code'],
            ':ho_ten' => $data['full_name'],
            ':ngay_sinh' => $data['date_of_birth'] ?: null,
            ':gioi_tinh' => $data['gender'] ?: null,
            ':email' => $data['email'] ?: (strtolower((string)$data['teacher_code']) . '@local'),
            ':so_dien_thoai' => $data['phone'] ?: null,
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
            'SELECT g.*, n.TenNganh
             FROM GiangVien g
             LEFT JOIN Nganh n ON n.MaNganh = g.MaNganh
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
            'SELECT g.*, n.TenNganh
             FROM GiangVien g
             LEFT JOIN Nganh n ON n.MaNganh = g.MaNganh
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
        $sql = 'SELECT g.*, n.TenNganh
                FROM GiangVien g
                LEFT JOIN Nganh n ON n.MaNganh = g.MaNganh
                WHERE 1=1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(g.MaGV) LIKE :keyword
                OR lower(g.HoTen) LIKE :keyword
                OR lower(IFNULL(n.TenNganh,"")) LIKE :keyword
                OR lower(IFNULL(g.LopPhuTrach,"")) LIKE :keyword
                OR lower(IFNULL(g.Email,"")) LIKE :keyword
                OR lower(IFNULL(g.SoDienThoai,"")) LIKE :keyword
            )';
            $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }
        if (!empty($filters['department'])) {
            $sql .= ' AND lower(IFNULL(n.TenNganh,"")) LIKE :department';
            $params[':department'] = '%' . self::lowerText((string)$filters['department']) . '%';
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND g.TrangThai = :status';
            $params[':status'] = $filters['status'];
        }
        $sql .= ' ORDER BY lower(IFNULL(n.TenNganh,"")) ASC, g.MaGV ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'mapRow'], $rows);
    }

    public static function updateProfileByTeacherCode(string $teacherCode, array $data): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $maNganh = self::resolveNganhIdByName($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'UPDATE GiangVien
             SET HoTen = :ho_ten,
                 NgaySinh = :ngay_sinh,
                 GioiTinh = :gioi_tinh,
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
}
