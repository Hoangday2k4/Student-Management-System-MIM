<?php

class Semester
{
    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS HocKy (
                MaHocKy TEXT PRIMARY KEY,
                TenHocKy TEXT NOT NULL,
                NamHoc TEXT NOT NULL,
                Ky INTEGER NOT NULL,
                TrangThai TEXT NOT NULL DEFAULT "ACTIVE",
                IsCurrent INTEGER NOT NULL DEFAULT 0,
                GhiChu TEXT,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TEXT,
                DeletedAt TEXT
            )'
        );

        $columns = $pdo->query('PRAGMA table_info(HocKy)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        foreach ($columns as $col) {
            $names[(string)$col['name']] = true;
        }

        if (!isset($names['TrangThai'])) {
            $pdo->exec('ALTER TABLE HocKy ADD COLUMN TrangThai TEXT NOT NULL DEFAULT "ACTIVE"');
        }
        if (!isset($names['IsCurrent'])) {
            $pdo->exec('ALTER TABLE HocKy ADD COLUMN IsCurrent INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($names['GhiChu'])) {
            $pdo->exec('ALTER TABLE HocKy ADD COLUMN GhiChu TEXT');
        }
        if (!isset($names['CreatedAt'])) {
            $pdo->exec('ALTER TABLE HocKy ADD COLUMN CreatedAt TEXT');
            $pdo->exec("UPDATE HocKy SET CreatedAt = datetime('now', 'localtime') WHERE CreatedAt IS NULL OR trim(CreatedAt) = ''");
        }
        if (!isset($names['UpdatedAt'])) {
            $pdo->exec('ALTER TABLE HocKy ADD COLUMN UpdatedAt TEXT');
        }
        if (!isset($names['DeletedAt'])) {
            $pdo->exec('ALTER TABLE HocKy ADD COLUMN DeletedAt TEXT');
        }

        $dupStmt = $pdo->query('SELECT NamHoc, Ky, COUNT(1) AS Cnt FROM HocKy GROUP BY NamHoc, Ky HAVING COUNT(1) > 1 LIMIT 1');
        $hasDuplicateNamHocKy = (bool)$dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($hasDuplicateNamHocKy) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hocky_namhoc_ky ON HocKy(NamHoc, Ky)');
        } else {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_hocky_namhoc_ky ON HocKy(NamHoc, Ky)');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hocky_trangthai ON HocKy(TrangThai)');
    }

    private static function rowToDto(array $row): array
    {
        return [
            'ma_hoc_ky' => (string)($row['MaHocKy'] ?? ''),
            'ten_hoc_ky' => (string)($row['TenHocKy'] ?? ''),
            'nam_hoc' => (string)($row['NamHoc'] ?? ''),
            'ky' => (int)($row['Ky'] ?? 0),
            'trang_thai' => (string)($row['TrangThai'] ?? 'ACTIVE'),
            'is_current' => (int)($row['IsCurrent'] ?? 0) === 1,
            'ghi_chu' => (string)($row['GhiChu'] ?? ''),
            'created_at' => (string)($row['CreatedAt'] ?? ''),
            'updated_at' => (string)($row['UpdatedAt'] ?? ''),
            'deleted_at' => (string)($row['DeletedAt'] ?? ''),
        ];
    }

    public static function list(array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(lower(MaHocKy) LIKE lower(:q) OR lower(NamHoc) LIKE lower(:q) OR lower(TenHocKy) LIKE lower(:q))';
            $params[':q'] = '%' . $q . '%';
        }

        $namHoc = trim((string)($filters['nam_hoc'] ?? ''));
        if ($namHoc !== '') {
            $where[] = 'NamHoc = :nam_hoc';
            $params[':nam_hoc'] = $namHoc;
        }

        $ky = (int)($filters['ky'] ?? 0);
        if (in_array($ky, [1, 2], true)) {
            $where[] = 'Ky = :ky';
            $params[':ky'] = $ky;
        }

        $includeInactive = (string)($filters['include_inactive'] ?? 'false');
        if ($includeInactive !== 'true') {
            $where[] = 'TrangThai != "ARCHIVED"';
        }

        $sql = 'SELECT MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, GhiChu, CreatedAt, UpdatedAt, DeletedAt FROM HocKy';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY NamHoc DESC, Ky DESC, MaHocKy DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn($row) => self::rowToDto($row), $rows);
    }

    public static function findByCode(string $maHocKy): ?array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, GhiChu, CreatedAt, UpdatedAt, DeletedAt
             FROM HocKy
             WHERE lower(MaHocKy) = lower(:ma)
             LIMIT 1'
        );
        $stmt->execute([':ma' => $maHocKy]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return self::rowToDto($row);
    }

    public static function createWithPdo(PDO $pdo, array $data): array
    {
        self::ensureSchema($pdo);

        $isCurrent = !empty($data['is_current']);
        if (!$isCurrent && strtoupper((string)($data['trang_thai'] ?? 'ACTIVE')) !== 'ARCHIVED') {
            $checkStmt = $pdo->query('SELECT COUNT(1) FROM HocKy WHERE IsCurrent = 1 AND TrangThai != "ARCHIVED"');
            $hasCurrent = (int)($checkStmt ? $checkStmt->fetchColumn() : 0) > 0;
            if (!$hasCurrent) {
                $isCurrent = true;
            }
        }

        if ($isCurrent) {
            $pdo->exec('UPDATE HocKy SET IsCurrent = 0, UpdatedAt = datetime("now", "localtime") WHERE IsCurrent = 1');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, GhiChu, CreatedAt, UpdatedAt)
             VALUES (:ma, :ten, :nam_hoc, :ky, :trang_thai, :is_current, :ghi_chu, datetime("now", "localtime"), datetime("now", "localtime"))'
        );
        $stmt->execute([
            ':ma' => $data['ma_hoc_ky'],
            ':ten' => $data['ten_hoc_ky'],
            ':nam_hoc' => $data['nam_hoc'],
            ':ky' => $data['ky'],
            ':trang_thai' => $data['trang_thai'],
            ':is_current' => $isCurrent ? 1 : 0,
            ':ghi_chu' => $data['ghi_chu'] !== '' ? $data['ghi_chu'] : null,
        ]);

        return self::findByCode((string)$data['ma_hoc_ky']) ?? [];
    }

    public static function updateByCodeWithPdo(PDO $pdo, string $maHocKy, array $data): ?array
    {
        self::ensureSchema($pdo);

        $current = self::findByCode($maHocKy);
        if (!$current) {
            return null;
        }

        if (!empty($data['is_current'])) {
            $pdo->exec('UPDATE HocKy SET IsCurrent = 0, UpdatedAt = datetime("now", "localtime") WHERE IsCurrent = 1');
        }

        $stmt = $pdo->prepare(
            'UPDATE HocKy
             SET TenHocKy = :ten,
                 NamHoc = :nam_hoc,
                 Ky = :ky,
                 TrangThai = :trang_thai,
                 IsCurrent = :is_current,
                 GhiChu = :ghi_chu,
                 UpdatedAt = datetime("now", "localtime")
             WHERE lower(MaHocKy) = lower(:ma)'
        );
        $stmt->execute([
            ':ten' => $data['ten_hoc_ky'],
            ':nam_hoc' => $data['nam_hoc'],
            ':ky' => $data['ky'],
            ':trang_thai' => $data['trang_thai'],
            ':is_current' => !empty($data['is_current']) ? 1 : 0,
            ':ghi_chu' => $data['ghi_chu'] !== '' ? $data['ghi_chu'] : null,
            ':ma' => $maHocKy,
        ]);

        return self::findByCode($maHocKy);
    }

    public static function softDeleteByCodeWithPdo(PDO $pdo, string $maHocKy): bool
    {
        self::ensureSchema($pdo);

        $beforeStmt = $pdo->prepare('SELECT NamHoc, Ky, IsCurrent FROM HocKy WHERE lower(MaHocKy) = lower(:ma) LIMIT 1');
        $beforeStmt->execute([':ma' => $maHocKy]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare(
            'UPDATE HocKy
             SET TrangThai = "ARCHIVED",
                 IsCurrent = 0,
                 DeletedAt = datetime("now", "localtime"),
                 UpdatedAt = datetime("now", "localtime")
             WHERE lower(MaHocKy) = lower(:ma)
               AND TrangThai != "ARCHIVED"'
        );
        $stmt->execute([':ma' => $maHocKy]);
        if ($stmt->rowCount() <= 0) {
            return false;
        }

        if ($before && (int)($before['IsCurrent'] ?? 0) === 1) {
            $pdo->exec('UPDATE HocKy SET IsCurrent = 0 WHERE TrangThai != "ARCHIVED"');

            $nextStmt = $pdo->prepare(
                'SELECT MaHocKy
                 FROM HocKy
                 WHERE TrangThai != "ARCHIVED"
                   AND (NamHoc > :nam_hoc OR (NamHoc = :nam_hoc AND Ky > :ky))
                 ORDER BY NamHoc ASC, Ky ASC
                 LIMIT 1'
            );
            $nextStmt->execute([
                ':nam_hoc' => (string)($before['NamHoc'] ?? ''),
                ':ky' => (int)($before['Ky'] ?? 0),
            ]);
            $nextCode = $nextStmt->fetchColumn();

            if ($nextCode === false || trim((string)$nextCode) === '') {
                $fallbackStmt = $pdo->query(
                    'SELECT MaHocKy FROM HocKy WHERE TrangThai != "ARCHIVED" ORDER BY NamHoc ASC, Ky ASC LIMIT 1'
                );
                $nextCode = $fallbackStmt ? $fallbackStmt->fetchColumn() : false;
            }

            if ($nextCode !== false && trim((string)$nextCode) !== '') {
                $setCurrent = $pdo->prepare(
                    'UPDATE HocKy
                     SET IsCurrent = 1,
                         TrangThai = CASE WHEN TrangThai = "INACTIVE" THEN "ACTIVE" ELSE TrangThai END,
                         UpdatedAt = datetime("now", "localtime")
                     WHERE lower(MaHocKy) = lower(:ma)'
                );
                $setCurrent->execute([':ma' => (string)$nextCode]);
            }
        }

        return true;
    }

    public static function restoreByCodeWithPdo(PDO $pdo, string $maHocKy): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            'UPDATE HocKy
             SET TrangThai = "ACTIVE",
                 DeletedAt = NULL,
                 UpdatedAt = datetime("now", "localtime")
             WHERE lower(MaHocKy) = lower(:ma)
               AND TrangThai = "ARCHIVED"'
        );
        $stmt->execute([':ma' => $maHocKy]);
        return $stmt->rowCount() > 0;
    }
}
