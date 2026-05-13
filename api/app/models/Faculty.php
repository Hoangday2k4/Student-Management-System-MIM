<?php

class Faculty
{
    private const STATUS_ACTIVE = 'Hoạt động';
    private const STATUS_PAUSED = 'Tạm nghỉ';
    private const STATUS_STOPPED = 'Dừng hoạt động';

    private static function lowerText(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($columns as $col) {
            if (strcasecmp((string)($col['name'] ?? ''), $column) === 0) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }

    private static function needsKhoaColumnReorder(PDO $pdo): bool
    {
        $columns = $pdo->query('PRAGMA table_info(Khoa)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_map(static fn(array $col): string => (string)($col['name'] ?? ''), $columns);
        $expected = ['MaKhoa', 'TenKhoa', 'TruongKhoa', 'MoTa', 'TrangThai'];
        if (count($names) < count($expected)) {
            return false;
        }
        for ($i = 0; $i < count($expected); $i++) {
            if (strcasecmp($names[$i] ?? '', $expected[$i]) !== 0) {
                return true;
            }
        }
        return false;
    }

    private static function reorderKhoaColumns(PDO $pdo): void
    {
        if (!self::needsKhoaColumnReorder($pdo)) {
            return;
        }

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE Khoa__new (
                    MaKhoa TEXT PRIMARY KEY,
                    TenKhoa TEXT NOT NULL,
                    TruongKhoa TEXT,
                    MoTa TEXT,
                    TrangThai TEXT NOT NULL DEFAULT "Hoáº¡t Ä‘á»™ng"
                )'
            );
            $pdo->exec(
                "INSERT INTO Khoa__new (MaKhoa, TenKhoa, TruongKhoa, MoTa, TrangThai)
                 SELECT MaKhoa, TenKhoa, TruongKhoa, MoTa, IFNULL(NULLIF(trim(TrangThai), ''), '" . self::STATUS_ACTIVE . "')
                 FROM Khoa"
            );
            $pdo->exec('DROP TABLE Khoa');
            $pdo->exec('ALTER TABLE Khoa__new RENAME TO Khoa');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function validStatuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_STOPPED];
    }

    public static function normalizeStatus(?string $status): string
    {
        $raw = trim((string)$status);
        if ($raw === '') {
            return self::STATUS_ACTIVE;
        }

        $key = preg_replace('/\s+/', ' ', self::lowerText($raw));
        $map = [
            'hoạt động' => self::STATUS_ACTIVE,
            'hoat dong' => self::STATUS_ACTIVE,
            'tạm nghỉ' => self::STATUS_PAUSED,
            'tam nghi' => self::STATUS_PAUSED,
            'dừng hoạt động' => self::STATUS_STOPPED,
            'dung hoat dong' => self::STATUS_STOPPED,
        ];
        return $map[$key] ?? self::STATUS_ACTIVE;
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Khoa (
                MaKhoa TEXT PRIMARY KEY,
                TenKhoa TEXT NOT NULL,
                TruongKhoa TEXT,
                MoTa TEXT,
                TrangThai TEXT NOT NULL DEFAULT "Hoạt động"
            )'
        );
        // GiangVien and Nganh are referenced by findByCode/searchWithStats; ensure they exist
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
                MaKhoa TEXT,
                MoTa TEXT,
                TrangThai TEXT NOT NULL DEFAULT "Đang đào tạo"
            )'
        );

        self::ensureColumn($pdo, 'Khoa', 'MoTa', 'TEXT');
        self::ensureColumn($pdo, 'Khoa', 'TruongKhoa', 'TEXT');
        self::ensureColumn($pdo, 'Khoa', 'TrangThai', 'TEXT');
        self::reorderKhoaColumns($pdo);

        $pdo->exec(
            "UPDATE Khoa
             SET TrangThai = '" . self::STATUS_ACTIVE . "'
             WHERE trim(IFNULL(TrangThai, '')) = ''"
        );

        if (self::tableExists($pdo, 'Nganh')) {
            $nganhCols = $pdo->query('PRAGMA table_info(Nganh)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $hasMaKhoa = false;
            foreach ($nganhCols as $col) {
                if (strcasecmp((string)($col['name'] ?? ''), 'MaKhoa') === 0) {
                    $hasMaKhoa = true;
                    break;
                }
            }

            // Chỉ migrate dữ liệu từ Nganh sang Khoa với schema cũ (Nganh chưa có MaKhoa).
            if (!$hasMaKhoa) {
                $pdo->exec(
                    "INSERT OR IGNORE INTO Khoa (MaKhoa, TenKhoa, TruongKhoa, MoTa, TrangThai)
                     SELECT MaNganh, TenNganh, NULL, MoTa, '" . self::STATUS_ACTIVE . "'
                     FROM Nganh"
                );
            }
        }
    }

    private static function generateCode(string $name): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'KHOA');
        if (strlen($code) > 12) {
            $code = substr($code, 0, 12);
        }
        return $code;
    }

    public static function resolveIdByName(PDO $pdo, string $input): ?string
    {
        self::ensureSchema($pdo);
        $name = trim($input);
        if ($name === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT MaKhoa
             FROM Khoa
             WHERE lower(TenKhoa) = lower(:name)
                OR lower(MaKhoa) = lower(:name)
             LIMIT 1'
        );
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string)$id;
        }

        $baseCode = self::generateCode($name);
        $code = $baseCode;
        $suffix = 1;
        while (true) {
            $check = $pdo->prepare('SELECT 1 FROM Khoa WHERE MaKhoa = :code LIMIT 1');
            $check->execute([':code' => $code]);
            if (!$check->fetchColumn()) {
                break;
            }
            $suffix++;
            $code = substr($baseCode, 0, max(1, 12 - strlen((string)$suffix))) . $suffix;
        }

        $ins = $pdo->prepare(
            'INSERT OR IGNORE INTO Khoa (MaKhoa, TenKhoa, TruongKhoa, MoTa, TrangThai)
             VALUES (:code, :name, NULL, NULL, :status)'
        );
        $ins->execute([
            ':code' => $code,
            ':name' => $name,
            ':status' => self::STATUS_ACTIVE,
        ]);
        return $code;
    }

    public static function listAll(?PDO $pdo = null): array
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        self::ensureSchema($pdo);

        $rows = $pdo->query(
            'SELECT k.MaKhoa, k.TenKhoa, k.MoTa, k.TruongKhoa, k.TrangThai, g.HoTen AS TenGiangVien
             FROM Khoa k
             LEFT JOIN GiangVien g ON lower(g.MaGV) = lower(k.TruongKhoa)
             ORDER BY lower(k.TenKhoa) ASC, k.MaKhoa ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'code' => trim((string)($row['MaKhoa'] ?? '')),
                'name' => trim((string)($row['TenKhoa'] ?? '')),
                'head_teacher_code' => trim((string)($row['TruongKhoa'] ?? '')),
                'head_teacher_name' => trim((string)($row['TenGiangVien'] ?? '')),
                'description' => trim((string)($row['MoTa'] ?? '')),
                'status' => self::normalizeStatus((string)($row['TrangThai'] ?? '')),
            ];
        }, $rows);
    }

    private static function aggregateCount(PDO $pdo, string $sql): int
    {
        try {
            $value = $pdo->query($sql)->fetchColumn();
            return (int)$value;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function summary(?PDO $pdo = null): array
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        self::ensureSchema($pdo);

        $total = self::aggregateCount($pdo, 'SELECT COUNT(1) FROM Khoa');
        $totalClasses = self::aggregateCount($pdo, 'SELECT COUNT(1) FROM LopSinhHoat WHERE trim(IFNULL(MaNganh, "")) <> ""');
        $totalStudents = self::aggregateCount(
            $pdo,
            'SELECT COUNT(1)
             FROM SinhVien s
             INNER JOIN LopSinhHoat l ON l.MaLop = s.MaLop
             WHERE trim(IFNULL(l.MaNganh, "")) <> ""'
        );
        $active = self::aggregateCount(
            $pdo,
            "SELECT COUNT(1)
             FROM Khoa
             WHERE lower(IFNULL(TrangThai, '')) = lower('" . self::STATUS_ACTIVE . "')"
        );

        return [
            'total' => $total,
            'active' => $active,
            'total_classes' => $totalClasses,
            'total_students' => $totalStudents,
        ];
    }

    public static function searchWithStats(string $keyword = '', ?PDO $pdo = null): array
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        self::ensureSchema($pdo);

        $sql = 'SELECT
                    k.MaKhoa,
                    k.TenKhoa,
                    k.MoTa,
                    k.TruongKhoa,
                    k.TrangThai,
                    g.HoTen AS TenGiangVien,
                    COUNT(DISTINCT l.MaLop) AS class_count,
                    COUNT(s.MaSV) AS student_count
                FROM Khoa k
                LEFT JOIN GiangVien g ON lower(g.MaGV) = lower(k.TruongKhoa)
                LEFT JOIN Nganh n ON n.MaKhoa = k.MaKhoa
                LEFT JOIN LopSinhHoat l ON l.MaNganh = n.MaNganh
                LEFT JOIN SinhVien s ON s.MaLop = l.MaLop
                WHERE 1=1';
        $params = [];
        if (trim($keyword) !== '') {
            $sql .= ' AND (
                lower(k.MaKhoa) LIKE :kw
                OR lower(k.TenKhoa) LIKE :kw
                OR lower(IFNULL(k.MoTa, "")) LIKE :kw
                OR lower(IFNULL(k.TruongKhoa, "")) LIKE :kw
                OR lower(IFNULL(k.TrangThai, "")) LIKE :kw
                OR lower(IFNULL(g.HoTen, "")) LIKE :kw
            )';
            $params[':kw'] = '%' . self::lowerText($keyword) . '%';
        }
        $sql .= '
            GROUP BY k.MaKhoa, k.TenKhoa, k.MoTa, k.TruongKhoa, k.TrangThai, g.HoTen
            ORDER BY lower(k.TenKhoa) ASC, k.MaKhoa ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $classCount = (int)($row['class_count'] ?? 0);
            $studentCount = (int)($row['student_count'] ?? 0);
            return [
                'code' => trim((string)($row['MaKhoa'] ?? '')),
                'name' => trim((string)($row['TenKhoa'] ?? '')),
                'head_teacher_code' => trim((string)($row['TruongKhoa'] ?? '')),
                'head_teacher_name' => trim((string)($row['TenGiangVien'] ?? '')),
                'description' => trim((string)($row['MoTa'] ?? '')),
                'class_count' => $classCount,
                'student_count' => $studentCount,
                'status' => self::normalizeStatus((string)($row['TrangThai'] ?? '')),
            ];
        }, $rows);
    }

    public static function findByCode(string $code, ?PDO $pdo = null): ?array
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT k.MaKhoa, k.TenKhoa, k.MoTa, k.TruongKhoa, k.TrangThai, g.HoTen AS TenGiangVien
             FROM Khoa k
             LEFT JOIN GiangVien g ON lower(g.MaGV) = lower(k.TruongKhoa)
             WHERE lower(k.MaKhoa) = lower(:code)
             LIMIT 1'
        );
        $stmt->execute([':code' => trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $countStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT l.MaLop) AS class_count, COUNT(s.MaSV) AS student_count
             FROM Khoa k
             LEFT JOIN Nganh n ON n.MaKhoa = k.MaKhoa
             LEFT JOIN LopSinhHoat l ON l.MaNganh = n.MaNganh
             LEFT JOIN SinhVien s ON s.MaLop = l.MaLop
             WHERE lower(k.MaKhoa) = lower(:code)'
        );
        $countStmt->execute([':code' => trim($code)]);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['class_count' => 0, 'student_count' => 0];

        return [
            'code' => trim((string)($row['MaKhoa'] ?? '')),
            'name' => trim((string)($row['TenKhoa'] ?? '')),
            'head_teacher_code' => trim((string)($row['TruongKhoa'] ?? '')),
            'head_teacher_name' => trim((string)($row['TenGiangVien'] ?? '')),
            'description' => trim((string)($row['MoTa'] ?? '')),
            'status' => self::normalizeStatus((string)($row['TrangThai'] ?? '')),
            'class_count' => (int)($countRow['class_count'] ?? 0),
            'student_count' => (int)($countRow['student_count'] ?? 0),
        ];
    }

    public static function createWithPdo(PDO $pdo, array $data): array
    {
        self::ensureSchema($pdo);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Tên khoa không được để trống.');
        }

        $code = trim((string)($data['code'] ?? ''));
        if ($code === '') {
            $code = self::generateCode($name);
        }
        $description = trim((string)($data['description'] ?? ''));
        $headTeacherCode = trim((string)($data['head_teacher_code'] ?? ''));
        $status = self::normalizeStatus((string)($data['status'] ?? self::STATUS_ACTIVE));

        $stmt = $pdo->prepare(
            'INSERT INTO Khoa (MaKhoa, TenKhoa, TruongKhoa, MoTa, TrangThai)
             VALUES (:code, :name, :head_teacher_code, :description, :status)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':head_teacher_code' => $headTeacherCode !== '' ? $headTeacherCode : null,
            ':status' => $status,
        ]);

        $result = self::findByCode($code, $pdo);
        if (!$result) {
            throw new RuntimeException('Không thể tạo khoa.');
        }
        return $result;
    }

    public static function updateWithPdo(PDO $pdo, string $oldCode, array $data): array
    {
        self::ensureSchema($pdo);
        $oldCode = trim($oldCode);
        if ($oldCode === '') {
            throw new RuntimeException('Thiếu mã khoa cần cập nhật.');
        }

        $current = self::findByCode($oldCode, $pdo);
        if (!$current) {
            throw new RuntimeException('Không tìm thấy khoa để cập nhật.');
        }

        $newCode = trim((string)($data['code'] ?? $oldCode));
        if ($newCode === '') {
            $newCode = $oldCode;
        }
        $name = trim((string)($data['name'] ?? $current['name']));
        if ($name === '') {
            throw new RuntimeException('Tên khoa không được để trống.');
        }
        $description = trim((string)($data['description'] ?? $current['description']));
        $headTeacherCode = trim((string)($data['head_teacher_code'] ?? $current['head_teacher_code']));
        $status = self::normalizeStatus((string)($data['status'] ?? $current['status']));

        $stmt = $pdo->prepare(
            'UPDATE Khoa
             SET MaKhoa = :new_code,
                 TenKhoa = :name,
                 TruongKhoa = :head_teacher_code,
                 MoTa = :description,
                 TrangThai = :status
             WHERE lower(MaKhoa) = lower(:old_code)'
        );
        $stmt->execute([
            ':new_code' => $newCode,
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':head_teacher_code' => $headTeacherCode !== '' ? $headTeacherCode : null,
            ':status' => $status,
            ':old_code' => $oldCode,
        ]);

        if (strtolower($newCode) !== strtolower($oldCode)) {
            $updLop = $pdo->prepare('UPDATE LopSinhHoat SET MaNganh = :new_code WHERE lower(MaNganh) = lower(:old_code)');
            $updLop->execute([':new_code' => $newCode, ':old_code' => $oldCode]);

            $updGv = $pdo->prepare('UPDATE GiangVien SET MaNganh = :new_code WHERE lower(MaNganh) = lower(:old_code)');
            $updGv->execute([':new_code' => $newCode, ':old_code' => $oldCode]);

            $updMon = $pdo->prepare('UPDATE MonHoc SET MaNganh = :new_code WHERE lower(MaNganh) = lower(:old_code)');
            $updMon->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
        }

        $result = self::findByCode($newCode, $pdo);
        if (!$result) {
            throw new RuntimeException('Không thể cập nhật khoa.');
        }
        return $result;
    }

    private static function countRefs(PDO $pdo, string $table, string $column, string $code): int
    {
        if (!self::tableExists($pdo, $table)) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(1) FROM $table WHERE lower(IFNULL($column, '')) = lower(:code)");
        $stmt->execute([':code' => $code]);
        return (int)$stmt->fetchColumn();
    }

    public static function deleteByCodeWithPdo(PDO $pdo, string $code): void
    {
        self::ensureSchema($pdo);
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Thiếu mã khoa cần xóa.');
        }

        $majorCount  = self::countRefs($pdo, 'Nganh', 'MaKhoa', $code);
        $classCount  = self::countRefs($pdo, 'LopSinhHoat', 'MaNganh', $code);
        $teacherCount = self::countRefs($pdo, 'GiangVien', 'MaNganh', $code);
        $courseCount = self::countRefs($pdo, 'MonHoc', 'MaNganh', $code);
        if ($majorCount > 0 || $classCount > 0 || $teacherCount > 0 || $courseCount > 0) {
            throw new RuntimeException('Không thể xóa khoa vì vẫn còn ngành, lớp, giáo viên hoặc môn học đang liên kết.');
        }

        $stmt = $pdo->prepare('DELETE FROM Khoa WHERE lower(MaKhoa) = lower(:code)');
        $stmt->execute([':code' => $code]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Không tìm thấy khoa để xóa.');
        }
    }
}
