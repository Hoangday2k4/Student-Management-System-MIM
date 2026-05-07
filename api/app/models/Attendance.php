<?php

class Attendance
{
    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS BuoiHoc (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                MaLHP TEXT NOT NULL,
                NgayHoc TEXT NOT NULL,
                GhiChu TEXT,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_buoihoc_malhp ON BuoiHoc(MaLHP)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_buoihoc_malhp_ngay ON BuoiHoc(MaLHP, NgayHoc)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS DiemDanh (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                BuoiHocId INTEGER NOT NULL,
                MaSV TEXT NOT NULL,
                TrangThai TEXT NOT NULL,
                UpdatedAt TEXT,
                UNIQUE(BuoiHocId, MaSV)
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_diemdanh_buoi_masv ON DiemDanh(BuoiHocId, MaSV)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diemdanh_buoi ON DiemDanh(BuoiHocId)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diemdanh_masv ON DiemDanh(MaSV)');
    }

    public static function resolveMaLhpByCourseId(PDO $pdo, int $courseId): ?string
    {
        $stmt = $pdo->prepare('SELECT MaLHP FROM LopHocPhanMap WHERE LegacyId = :id LIMIT 1');
        $stmt->execute([':id' => $courseId]);
        $ma = $stmt->fetchColumn();
        return $ma !== false ? (string)$ma : null;
    }

    public static function listSessionsByCourse(PDO $pdo, string $maLHP): array
    {
        $stmt = $pdo->prepare(
            'SELECT b.Id, b.NgayHoc, b.GhiChu,
                    COUNT(d.Id) AS total,
                    SUM(CASE WHEN d.TrangThai = "PRESENT" THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN d.TrangThai = "ABSENT" THEN 1 ELSE 0 END) AS absent
             FROM BuoiHoc b
             LEFT JOIN DiemDanh d ON d.BuoiHocId = b.Id
             WHERE b.MaLHP = :ma
             GROUP BY b.Id
             ORDER BY b.NgayHoc DESC, b.Id DESC'
        );
        $stmt->execute([':ma' => $maLHP]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)($row['Id'] ?? 0),
                'date' => (string)($row['NgayHoc'] ?? ''),
                'note' => (string)($row['GhiChu'] ?? ''),
                'total' => (int)($row['total'] ?? 0),
                'present' => (int)($row['present'] ?? 0),
                'absent' => (int)($row['absent'] ?? 0),
            ];
        }
        return $items;
    }

    public static function getSessionById(PDO $pdo, int $sessionId): ?array
    {
        $stmt = $pdo->prepare('SELECT Id, MaLHP, NgayHoc, GhiChu FROM BuoiHoc WHERE Id = :id LIMIT 1');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => (int)($row['Id'] ?? 0),
            'ma_lhp' => (string)($row['MaLHP'] ?? ''),
            'session_date' => (string)($row['NgayHoc'] ?? ''),
            'note' => (string)($row['GhiChu'] ?? ''),
        ];
    }

    public static function getSessionByDate(PDO $pdo, string $maLHP, string $date): ?array
    {
        $stmt = $pdo->prepare('SELECT Id, MaLHP, NgayHoc, GhiChu FROM BuoiHoc WHERE MaLHP = :ma AND NgayHoc = :date LIMIT 1');
        $stmt->execute([':ma' => $maLHP, ':date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => (int)($row['Id'] ?? 0),
            'ma_lhp' => (string)($row['MaLHP'] ?? ''),
            'session_date' => (string)($row['NgayHoc'] ?? ''),
            'note' => (string)($row['GhiChu'] ?? ''),
        ];
    }

    public static function createSession(PDO $pdo, string $maLHP, string $date, string $note): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO BuoiHoc (MaLHP, NgayHoc, GhiChu, CreatedAt)
             VALUES (:ma, :date, :note, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':ma' => $maLHP,
            ':date' => $date,
            ':note' => $note !== '' ? $note : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateSession(PDO $pdo, int $sessionId, string $date, string $note): void
    {
        $stmt = $pdo->prepare(
            'UPDATE BuoiHoc
             SET NgayHoc = :date, GhiChu = :note, UpdatedAt = CURRENT_TIMESTAMP
             WHERE Id = :id'
        );
        $stmt->execute([
            ':id' => $sessionId,
            ':date' => $date,
            ':note' => $note !== '' ? $note : null,
        ]);
    }

    public static function getAttendanceMap(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare('SELECT MaSV, TrangThai FROM DiemDanh WHERE BuoiHocId = :id');
        $stmt->execute([':id' => $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $code = (string)($row['MaSV'] ?? '');
            if ($code !== '') {
                $map[$code] = (string)($row['TrangThai'] ?? '');
            }
        }
        return $map;
    }

    public static function upsertAttendance(PDO $pdo, int $sessionId, array $rows): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO DiemDanh (BuoiHocId, MaSV, TrangThai, UpdatedAt)
             VALUES (:buoi, :ma_sv, :status, CURRENT_TIMESTAMP)
             ON CONFLICT(BuoiHocId, MaSV)
             DO UPDATE SET TrangThai = excluded.TrangThai, UpdatedAt = CURRENT_TIMESTAMP'
        );
        foreach ($rows as $row) {
            $stmt->execute([
                ':buoi' => $sessionId,
                ':ma_sv' => $row['student_code'],
                ':status' => $row['status'],
            ]);
        }
    }
}
