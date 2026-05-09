<?php

require_once __DIR__ . '/Faculty.php';
require_once __DIR__ . '/Major.php';

class HomeroomClass
{
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

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        Faculty::ensureSchema($pdo);
        Major::ensureSchema($pdo);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS LopSinhHoat (
                MaLop TEXT PRIMARY KEY,
                TenLop TEXT NOT NULL,
                MaNganh TEXT,
                MaGV_CoVan TEXT,
                NienKhoa TEXT
            )'
        );
    }

    public static function majorExists(PDO $pdo, string $majorCode): bool
    {
        $majorCode = trim($majorCode);
        if ($majorCode === '') {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM Nganh WHERE lower(MaNganh) = lower(:code) LIMIT 1');
        $stmt->execute([':code' => $majorCode]);
        return (bool)$stmt->fetchColumn();
    }

    public static function teacherExists(PDO $pdo, string $teacherCode): bool
    {
        $teacherCode = trim($teacherCode);
        if ($teacherCode === '') {
            return true;
        }
        if (!self::tableExists($pdo, 'GiangVien')) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM GiangVien WHERE lower(MaGV) = lower(:code) LIMIT 1');
        $stmt->execute([':code' => $teacherCode]);
        return (bool)$stmt->fetchColumn();
    }

    public static function listMajorOptions(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        $rows = $pdo->query(
            'SELECT n.MaNganh, n.TenNganh, n.MaKhoa, k.TenKhoa
             FROM Nganh n
             LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
             ORDER BY lower(IFNULL(k.TenKhoa, "")) ASC, lower(n.TenNganh) ASC, n.MaNganh ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'code' => trim((string)($row['MaNganh'] ?? '')),
                'name' => trim((string)($row['TenNganh'] ?? '')),
                'faculty_code' => trim((string)($row['MaKhoa'] ?? '')),
                'faculty_name' => trim((string)($row['TenKhoa'] ?? '')),
            ];
        }, $rows);
    }

    public static function listTeacherOptions(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        if (!self::tableExists($pdo, 'GiangVien')) {
            return [];
        }
        $rows = $pdo->query(
            'SELECT MaGV, HoTen
             FROM GiangVien
             ORDER BY MaGV ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'code' => trim((string)($row['MaGV'] ?? '')),
                'name' => trim((string)($row['HoTen'] ?? '')),
            ];
        }, $rows);
    }

    public static function summary(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        $total = (int)$pdo->query('SELECT COUNT(1) FROM LopSinhHoat')->fetchColumn();
        return ['total' => $total];
    }

    public static function search(string $keyword, PDO $pdo): array
    {
        self::ensureSchema($pdo);
        $params = [];

        $sql = 'SELECT
                    l.MaLop,
                    l.TenLop,
                    l.MaNganh,
                    n.TenNganh,
                    n.MaKhoa,
                    k.TenKhoa,
                    l.MaGV_CoVan,
                    g.HoTen AS TenGVCN,
                    l.NienKhoa,
                    COUNT(s.MaSV) AS student_count
                FROM LopSinhHoat l
                LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
                LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
                LEFT JOIN GiangVien g ON lower(g.MaGV) = lower(l.MaGV_CoVan)
                LEFT JOIN SinhVien s ON s.MaLop = l.MaLop
                WHERE 1=1';

        if (trim($keyword) !== '') {
            $sql .= ' AND (
                lower(l.MaLop) LIKE :kw
                OR lower(l.TenLop) LIKE :kw
                OR lower(IFNULL(l.MaNganh, "")) LIKE :kw
                OR lower(IFNULL(n.TenNganh, "")) LIKE :kw
                OR lower(IFNULL(k.TenKhoa, "")) LIKE :kw
                OR lower(IFNULL(l.MaGV_CoVan, "")) LIKE :kw
                OR lower(IFNULL(g.HoTen, "")) LIKE :kw
                OR lower(IFNULL(l.NienKhoa, "")) LIKE :kw
            )';
            $params[':kw'] = '%' . self::lowerText($keyword) . '%';
        }

        $sql .= ' GROUP BY
                    l.MaLop, l.TenLop, l.MaNganh, n.TenNganh, n.MaKhoa, k.TenKhoa, l.MaGV_CoVan, g.HoTen, l.NienKhoa
                  ORDER BY lower(l.TenLop) ASC, l.MaLop ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'code' => trim((string)($row['MaLop'] ?? '')),
                'name' => trim((string)($row['TenLop'] ?? '')),
                'major_code' => trim((string)($row['MaNganh'] ?? '')),
                'major_name' => trim((string)($row['TenNganh'] ?? '')),
                'faculty_code' => trim((string)($row['MaKhoa'] ?? '')),
                'faculty_name' => trim((string)($row['TenKhoa'] ?? '')),
                'head_teacher_code' => trim((string)($row['MaGV_CoVan'] ?? '')),
                'head_teacher_name' => trim((string)($row['TenGVCN'] ?? '')),
                'school_year' => trim((string)($row['NienKhoa'] ?? '')),
                'student_count' => (int)($row['student_count'] ?? 0),
            ];
        }, $rows);
    }

    public static function findByCode(string $classCode, PDO $pdo): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            'SELECT
                l.MaLop,
                l.TenLop,
                l.MaNganh,
                n.TenNganh,
                n.MaKhoa,
                k.TenKhoa,
                l.MaGV_CoVan,
                g.HoTen AS TenGVCN,
                l.NienKhoa,
                COUNT(s.MaSV) AS student_count
             FROM LopSinhHoat l
             LEFT JOIN Nganh n ON n.MaNganh = l.MaNganh
             LEFT JOIN Khoa k ON k.MaKhoa = n.MaKhoa
             LEFT JOIN GiangVien g ON lower(g.MaGV) = lower(l.MaGV_CoVan)
             LEFT JOIN SinhVien s ON s.MaLop = l.MaLop
             WHERE lower(l.MaLop) = lower(:code)
             GROUP BY l.MaLop, l.TenLop, l.MaNganh, n.TenNganh, n.MaKhoa, k.TenKhoa, l.MaGV_CoVan, g.HoTen, l.NienKhoa
             LIMIT 1'
        );
        $stmt->execute([':code' => trim($classCode)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $result = [
            'code' => trim((string)($row['MaLop'] ?? '')),
            'name' => trim((string)($row['TenLop'] ?? '')),
            'major_code' => trim((string)($row['MaNganh'] ?? '')),
            'major_name' => trim((string)($row['TenNganh'] ?? '')),
            'faculty_code' => trim((string)($row['MaKhoa'] ?? '')),
            'faculty_name' => trim((string)($row['TenKhoa'] ?? '')),
            'head_teacher_code' => trim((string)($row['MaGV_CoVan'] ?? '')),
            'head_teacher_name' => trim((string)($row['TenGVCN'] ?? '')),
            'school_year' => trim((string)($row['NienKhoa'] ?? '')),
            'student_count' => (int)($row['student_count'] ?? 0),
        ];

        if (self::tableExists($pdo, 'SinhVien')) {
            $studentStmt = $pdo->prepare(
                'SELECT
                    MaSV,
                    HoTen,
                    GioiTinh,
                    MaLop,
                    Email,
                    SoDienThoai,
                    TrangThai
                 FROM SinhVien
                 WHERE lower(IFNULL(MaLop, "")) = lower(:class_code)
                 ORDER BY MaSV ASC'
            );
            $studentStmt->execute([':class_code' => $result['code']]);
            $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $result['students'] = array_map(static function (array $student): array {
                return [
                    'student_code' => trim((string)($student['MaSV'] ?? '')),
                    'full_name' => trim((string)($student['HoTen'] ?? '')),
                    'gender' => trim((string)($student['GioiTinh'] ?? '')),
                    'class_name' => trim((string)($student['MaLop'] ?? '')),
                    'email' => trim((string)($student['Email'] ?? '')),
                    'phone' => trim((string)($student['SoDienThoai'] ?? '')),
                    'status' => trim((string)($student['TrangThai'] ?? '')),
                ];
            }, $studentRows);
        } else {
            $result['students'] = [];
        }

        return $result;
    }

    public static function createWithPdo(PDO $pdo, array $data): array
    {
        self::ensureSchema($pdo);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Tên lớp không được để trống.');
        }
        $code = trim((string)($data['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('Mã lớp không được để trống.');
        }

        $majorCode = trim((string)($data['major_code'] ?? ''));
        if (!self::majorExists($pdo, $majorCode)) {
            throw new RuntimeException('Mã ngành không hợp lệ.');
        }

        $headTeacherCode = trim((string)($data['head_teacher_code'] ?? ''));
        if (!self::teacherExists($pdo, $headTeacherCode)) {
            throw new RuntimeException('Mã GVCN không tồn tại.');
        }

        $schoolYear = trim((string)($data['school_year'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO LopSinhHoat (MaLop, TenLop, MaNganh, MaGV_CoVan, NienKhoa)
             VALUES (:code, :name, :major_code, :head_teacher_code, :school_year)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':major_code' => $majorCode,
            ':head_teacher_code' => $headTeacherCode !== '' ? $headTeacherCode : null,
            ':school_year' => $schoolYear !== '' ? $schoolYear : null,
        ]);

        $result = self::findByCode($code, $pdo);
        if (!$result) {
            throw new RuntimeException('Không thể tạo lớp.');
        }
        return $result;
    }

    public static function updateWithPdo(PDO $pdo, string $oldCode, array $data): array
    {
        self::ensureSchema($pdo);
        $oldCode = trim($oldCode);
        if ($oldCode === '') {
            throw new RuntimeException('Thiếu mã lớp cần cập nhật.');
        }

        $current = self::findByCode($oldCode, $pdo);
        if (!$current) {
            throw new RuntimeException('Không tìm thấy lớp để cập nhật.');
        }

        $newCode = trim((string)($data['code'] ?? $oldCode));
        if ($newCode === '') {
            $newCode = $oldCode;
        }
        $name = trim((string)($data['name'] ?? $current['name']));
        if ($name === '') {
            throw new RuntimeException('Tên lớp không được để trống.');
        }
        $majorCode = trim((string)($data['major_code'] ?? $current['major_code']));
        if (!self::majorExists($pdo, $majorCode)) {
            throw new RuntimeException('Mã ngành không hợp lệ.');
        }
        $headTeacherCode = trim((string)($data['head_teacher_code'] ?? $current['head_teacher_code']));
        if (!self::teacherExists($pdo, $headTeacherCode)) {
            throw new RuntimeException('Mã GVCN không tồn tại.');
        }
        $schoolYear = trim((string)($data['school_year'] ?? $current['school_year']));

        $stmt = $pdo->prepare(
            'UPDATE LopSinhHoat
             SET MaLop = :new_code,
                 TenLop = :name,
                 MaNganh = :major_code,
                 MaGV_CoVan = :head_teacher_code,
                 NienKhoa = :school_year
             WHERE lower(MaLop) = lower(:old_code)'
        );
        $stmt->execute([
            ':new_code' => $newCode,
            ':name' => $name,
            ':major_code' => $majorCode,
            ':head_teacher_code' => $headTeacherCode !== '' ? $headTeacherCode : null,
            ':school_year' => $schoolYear !== '' ? $schoolYear : null,
            ':old_code' => $oldCode,
        ]);

        if (strtolower($newCode) !== strtolower($oldCode)) {
            if (self::tableExists($pdo, 'SinhVien')) {
                $up = $pdo->prepare('UPDATE SinhVien SET MaLop = :new_code WHERE lower(MaLop) = lower(:old_code)');
                $up->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
            }
            if (self::tableExists($pdo, 'GiangVien')) {
                $cols = $pdo->query('PRAGMA table_info(GiangVien)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $hasHomeroom = false;
                foreach ($cols as $col) {
                    if (strcasecmp((string)($col['name'] ?? ''), 'LopPhuTrach') === 0) {
                        $hasHomeroom = true;
                        break;
                    }
                }
                if ($hasHomeroom) {
                    $up = $pdo->prepare('UPDATE GiangVien SET LopPhuTrach = :new_code WHERE lower(LopPhuTrach) = lower(:old_code)');
                    $up->execute([':new_code' => $newCode, ':old_code' => $oldCode]);
                }
            }
        }

        $result = self::findByCode($newCode, $pdo);
        if (!$result) {
            throw new RuntimeException('Không thể cập nhật lớp.');
        }
        return $result;
    }

    public static function deleteByCodeWithPdo(PDO $pdo, string $classCode): void
    {
        self::ensureSchema($pdo);
        $classCode = trim($classCode);
        if ($classCode === '') {
            throw new RuntimeException('Thiếu mã lớp cần xóa.');
        }

        if (self::tableExists($pdo, 'SinhVien')) {
            $stmt = $pdo->prepare('SELECT COUNT(1) FROM SinhVien WHERE lower(IFNULL(MaLop, "")) = lower(:code)');
            $stmt->execute([':code' => $classCode]);
            $studentCount = (int)$stmt->fetchColumn();
            if ($studentCount > 0) {
                throw new RuntimeException('Không thể xóa lớp vì vẫn còn sinh viên trong lớp.');
            }
        }

        $stmt = $pdo->prepare('DELETE FROM LopSinhHoat WHERE lower(MaLop) = lower(:code)');
        $stmt->execute([':code' => $classCode]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Không tìm thấy lớp để xóa.');
        }
    }
}
