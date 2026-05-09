<?php

require_once __DIR__ . '/Faculty.php';

class Major
{
    private const STATUS_TRAINING = 'Đang đào tạo';
    private const STATUS_PAUSED = 'Tạm ngưng đào tạo';
    private const STATUS_STOPPED = 'Dừng đào tạo';

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

    public static function validStatuses(): array
    {
        return [self::STATUS_TRAINING, self::STATUS_PAUSED, self::STATUS_STOPPED];
    }

    public static function normalizeStatus(?string $status): string
    {
        $raw = trim((string)$status);
        if ($raw === '') {
            return self::STATUS_TRAINING;
        }

        $key = preg_replace('/\s+/', ' ', self::lowerText($raw));
        $map = [
            'đang đào tạo' => self::STATUS_TRAINING,
            'dang dao tao' => self::STATUS_TRAINING,
            'tạm ngưng đào tạo' => self::STATUS_PAUSED,
            'tam ngung dao tao' => self::STATUS_PAUSED,
            'dừng đào tạo' => self::STATUS_STOPPED,
            'dung dao tao' => self::STATUS_STOPPED,
        ];
        return $map[$key] ?? self::STATUS_TRAINING;
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        Faculty::ensureSchema($pdo);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Nganh (
                MaNganh TEXT PRIMARY KEY,
                TenNganh TEXT NOT NULL,
                MaKhoa TEXT,
                MoTa TEXT,
                TrangThai TEXT NOT NULL DEFAULT "Đang đào tạo"
            )'
        );

        self::ensureColumn($pdo, 'Nganh', 'MaKhoa', 'TEXT');
        self::ensureColumn($pdo, 'Nganh', 'MoTa', 'TEXT');
        self::ensureColumn($pdo, 'Nganh', 'TrangThai', 'TEXT');

        $pdo->exec(
            'UPDATE Nganh
             SET MaKhoa = MaNganh
             WHERE trim(IFNULL(MaKhoa, "")) = ""
               AND MaNganh IN (SELECT MaKhoa FROM Khoa)'
        );
        $pdo->exec(
            "UPDATE Nganh
             SET TrangThai = '" . self::STATUS_TRAINING . "'
             WHERE trim(IFNULL(TrangThai, '')) = ''"
        );
    }

    private static function generateCode(string $name): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'NGANH');
        if (strlen($code) > 20) {
            $code = substr($code, 0, 20);
        }
        return $code;
    }

    
    public static function listFacultyOptions(PDO $pdo): array
    {
        Faculty::ensureSchema($pdo);
        $rows = $pdo->query(
            'SELECT MaKhoa, TenKhoa
             FROM Khoa
             ORDER BY lower(TenKhoa) ASC, MaKhoa ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'code' => trim((string)($row['MaKhoa'] ?? '')),
                'name' => trim((string)($row['TenKhoa'] ?? '')),
            ];
        }, $rows);
    }

    public static function summary(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $total = (int)$pdo->query('SELECT COUNT(1) FROM Nganh')->fetchColumn();
        $training = (int)$pdo->query(
            "SELECT COUNT(1) FROM Nganh
             WHERE lower(IFNULL(TrangThai, '')) = lower('" . self::STATUS_TRAINING . "')"
        )->fetchColumn();

        return [
            'total' => $total,
            'training' => $training,
        ];
    }

    public static function search(string $keyword, PDO $pdo): array
    {
        self::ensureSchema($pdo);
        $params = [];

        $sql = 'SELECT
                    n.MaNganh,
                    n.TenNganh,
                    n.MaKhoa,
                    n.MoTa,
                    n.TrangThai,
                    k.TenKhoa,
                    COUNT(s.MaSV) AS student_count
                FROM Nganh n
                LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
                LEFT JOIN LopSinhHoat l ON l.MaNganh = n.MaNganh
                LEFT JOIN SinhVien s ON s.MaLop = l.MaLop
                WHERE 1=1';

        if (trim($keyword) !== '') {
            $sql .= ' AND (
                lower(n.MaNganh) LIKE :kw
                OR lower(n.TenNganh) LIKE :kw
                OR lower(IFNULL(n.MaKhoa, "")) LIKE :kw
                OR lower(IFNULL(k.TenKhoa, "")) LIKE :kw
                OR lower(IFNULL(n.MoTa, "")) LIKE :kw
                OR lower(IFNULL(n.TrangThai, "")) LIKE :kw
            )';
            $params[':kw'] = '%' . self::lowerText($keyword) . '%';
        }

        $sql .= ' GROUP BY n.MaNganh, n.TenNganh, n.MaKhoa, n.MoTa, n.TrangThai, k.TenKhoa
                  ORDER BY lower(n.TenNganh) ASC, n.MaNganh ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'code' => trim((string)($row['MaNganh'] ?? '')),
                'name' => trim((string)($row['TenNganh'] ?? '')),
                'faculty_code' => trim((string)($row['MaKhoa'] ?? '')),
                'faculty_name' => trim((string)($row['TenKhoa'] ?? '')),
                'description' => trim((string)($row['MoTa'] ?? '')),
                'status' => self::normalizeStatus((string)($row['TrangThai'] ?? '')),
                'student_count' => (int)($row['student_count'] ?? 0),
            ];
        }, $rows);
    }

    public static function findByCode(string $code, PDO $pdo): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            'SELECT
                n.MaNganh, n.TenNganh, n.MaKhoa, n.MoTa, n.TrangThai, k.TenKhoa,
                COUNT(s.MaSV) AS student_count
             FROM Nganh n
             LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
             LEFT JOIN LopSinhHoat l ON l.MaNganh = n.MaNganh
             LEFT JOIN SinhVien s ON s.MaLop = l.MaLop
             WHERE lower(n.MaNganh) = lower(:code)
             GROUP BY n.MaNganh, n.TenNganh, n.MaKhoa, n.MoTa, n.TrangThai, k.TenKhoa
             LIMIT 1'
        );
        $stmt->execute([':code' => trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'code' => trim((string)($row['MaNganh'] ?? '')),
            'name' => trim((string)($row['TenNganh'] ?? '')),
            'faculty_code' => trim((string)($row['MaKhoa'] ?? '')),
            'faculty_name' => trim((string)($row['TenKhoa'] ?? '')),
            'description' => trim((string)($row['MoTa'] ?? '')),
            'status' => self::normalizeStatus((string)($row['TrangThai'] ?? '')),
            'student_count' => (int)($row['student_count'] ?? 0),
        ];
    }

    public static function facultyExists(PDO $pdo, string $facultyCode): bool
    {
        if ($facultyCode === '') {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM Khoa WHERE lower(MaKhoa) = lower(:code) LIMIT 1');
        $stmt->execute([':code' => $facultyCode]);
        return (bool)$stmt->fetchColumn();
    }

    public static function createWithPdo(PDO $pdo, array $data): array
    {
        self::ensureSchema($pdo);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Tên ngành không được để trống.');
        }
        $facultyCode = trim((string)($data['faculty_code'] ?? ''));
        if (!self::facultyExists($pdo, $facultyCode)) {
            throw new RuntimeException('Mã khoa không hợp lệ.');
        }

        $code = trim((string)($data['code'] ?? ''));
        if ($code === '') {
            $code = self::generateCode($name);
        }
        $description = trim((string)($data['description'] ?? ''));
        $status = self::normalizeStatus((string)($data['status'] ?? self::STATUS_TRAINING));

        $stmt = $pdo->prepare(
            'INSERT INTO Nganh (MaNganh, TenNganh, MaKhoa, MoTa, TrangThai)
             VALUES (:code, :name, :faculty_code, :description, :status)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':faculty_code' => $facultyCode,
            ':description' => $description !== '' ? $description : null,
            ':status' => $status,
        ]);

        $result = self::findByCode($code, $pdo);
        if (!$result) {
            throw new RuntimeException('Không thể tạo ngành.');
        }
        return $result;
    }

    public static function updateWithPdo(PDO $pdo, string $oldCode, array $data): array
    {
        self::ensureSchema($pdo);
        $oldCode = trim($oldCode);
        if ($oldCode === '') {
            throw new RuntimeException('Thiếu mã ngành cần cập nhật.');
        }

        $current = self::findByCode($oldCode, $pdo);
        if (!$current) {
            throw new RuntimeException('Không tìm thấy ngành để cập nhật.');
        }

        $newCode = trim((string)($data['code'] ?? $oldCode));
        if ($newCode === '') {
            $newCode = $oldCode;
        }
        $name = trim((string)($data['name'] ?? $current['name']));
        if ($name === '') {
            throw new RuntimeException('Tên ngành không được để trống.');
        }
        $facultyCode = trim((string)($data['faculty_code'] ?? $current['faculty_code']));
        if (!self::facultyExists($pdo, $facultyCode)) {
            throw new RuntimeException('Mã khoa không hợp lệ.');
        }
        $description = trim((string)($data['description'] ?? $current['description']));
        $status = self::normalizeStatus((string)($data['status'] ?? $current['status']));

        $stmt = $pdo->prepare(
            'UPDATE Nganh
             SET MaNganh = :new_code,
                 TenNganh = :name,
                 MaKhoa = :faculty_code,
                 MoTa = :description,
                 TrangThai = :status
             WHERE lower(MaNganh) = lower(:old_code)'
        );
        $stmt->execute([
            ':new_code' => $newCode,
            ':name' => $name,
            ':faculty_code' => $facultyCode,
            ':description' => $description !== '' ? $description : null,
            ':status' => $status,
            ':old_code' => $oldCode,
        ]);

        if (strtolower($newCode) !== strtolower($oldCode)) {
            if (self::tableExists($pdo, 'LopSinhHoat')) {
                $up = $pdo->prepare('UPDATE LopSinhHoat SET MaNganh = :new_code WHERE lower(MaNganh) = lower(:old_code)');
                $up->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
            }
            if (self::tableExists($pdo, 'MonHoc')) {
                $up = $pdo->prepare('UPDATE MonHoc SET MaNganh = :new_code WHERE lower(MaNganh) = lower(:old_code)');
                $up->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
            }
            if (self::tableExists($pdo, 'GiangVien')) {
                $cols = $pdo->query('PRAGMA table_info(GiangVien)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $hasMaNganh = false;
                foreach ($cols as $col) {
                    if (strcasecmp((string)($col['name'] ?? ''), 'MaNganh') === 0) {
                        $hasMaNganh = true;
                        break;
                    }
                }
                if ($hasMaNganh) {
                    $up = $pdo->prepare('UPDATE GiangVien SET MaNganh = :new_code WHERE lower(MaNganh) = lower(:old_code)');
                    $up->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
                }
            }
        }

        $result = self::findByCode($newCode, $pdo);
        if (!$result) {
            throw new RuntimeException('Không thể cập nhật ngành.');
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
            throw new RuntimeException('Thiếu mã ngành cần xóa.');
        }

        $classCount = self::countRefs($pdo, 'LopSinhHoat', 'MaNganh', $code);
        $courseCount = self::countRefs($pdo, 'MonHoc', 'MaNganh', $code);
        if ($classCount > 0 || $courseCount > 0) {
            throw new RuntimeException('Không thể xóa ngành vì vẫn còn lớp hoặc môn học liên kết.');
        }

        $stmt = $pdo->prepare('DELETE FROM Nganh WHERE lower(MaNganh) = lower(:code)');
        $stmt->execute([':code' => $code]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Không tìm thấy ngành để xóa.');
        }
    }
    public static function isLinkedToFaculty(PDO $pdo, string $majorCode): bool
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM Nganh n
        INNER JOIN Khoa k ON n.MaKhoa = k.MaKhoa
        WHERE n.MaNganh = :code LIMIT 1
    ');
    $stmt->execute([':code' => $majorCode]);
    return (bool)$stmt->fetchColumn();
}
}
