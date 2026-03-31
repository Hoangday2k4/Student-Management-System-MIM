<?php

class Admin
{
    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS TaiKhoan (
                Id INTEGER PRIMARY KEY AUTOINCREMENT,
                LoginId TEXT NOT NULL UNIQUE,
                MatKhau TEXT NOT NULL,
                HoTen TEXT,
                LoaiTaiKhoan TEXT NOT NULL DEFAULT "student",
                TrangThai INTEGER NOT NULL DEFAULT 1,
                ResetToken TEXT,
                Updated TEXT,
                Created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        self::seedBaseAccounts($pdo);
        self::seedFromPeopleTables($pdo);
    }

    private static function seedBaseAccounts(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO TaiKhoan (LoginId, MatKhau, HoTen, LoaiTaiKhoan, TrangThai, Created)
             VALUES (:login_id, :password, :name, :account_type, 1, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            ':login_id' => 'admin',
            ':password' => '123456',
            ':name' => 'admin',
            ':account_type' => 'staff',
        ]);
        $stmt->execute([
            ':login_id' => 'manager',
            ':password' => '123456',
            ':name' => 'manager',
            ':account_type' => 'staff',
        ]);
    }

    private static function seedFromPeopleTables(PDO $pdo): void
    {
        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='SinhVien'")->fetchColumn()) {
            $rows = $pdo->query('SELECT MaSV, HoTen FROM SinhVien')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO TaiKhoan (LoginId, MatKhau, HoTen, LoaiTaiKhoan, TrangThai, Created)
                 VALUES (:login_id, "123456", :name, "student", 1, CURRENT_TIMESTAMP)'
            );
            foreach ($rows as $row) {
                $login = trim((string)($row['MaSV'] ?? ''));
                if ($login === '') {
                    continue;
                }
                $stmt->execute([
                    ':login_id' => $login,
                    ':name' => trim((string)($row['HoTen'] ?? '')),
                ]);
            }
        }

        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='GiangVien'")->fetchColumn()) {
            $rows = $pdo->query('SELECT MaGV, HoTen FROM GiangVien')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO TaiKhoan (LoginId, MatKhau, HoTen, LoaiTaiKhoan, TrangThai, Created)
                 VALUES (:login_id, "123456", :name, "teacher", 1, CURRENT_TIMESTAMP)'
            );
            foreach ($rows as $row) {
                $login = trim((string)($row['MaGV'] ?? ''));
                if ($login === '') {
                    continue;
                }
                $stmt->execute([
                    ':login_id' => $login,
                    ':name' => trim((string)($row['HoTen'] ?? '')),
                ]);
            }
        }
    }

    public static function verifyPassword(string $loginId, string $password): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT Id FROM TaiKhoan
             WHERE lower(LoginId) = lower(:login_id)
               AND TrangThai = 1
               AND (MatKhau = :password_plain OR MatKhau = :password_md5)
             LIMIT 1'
        );
        $stmt->execute([
            ':login_id' => $loginId,
            ':password_plain' => $password,
            ':password_md5' => md5($password),
        ]);
        return (bool)$stmt->fetch();
    }

    public static function createAccountWithPdo(PDO $pdo, string $loginId, string $password, string $name = '', string $accountType = 'student'): int
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO TaiKhoan (LoginId, MatKhau, HoTen, LoaiTaiKhoan, TrangThai, Created)
             VALUES (:login_id, :password, :name, :account_type, 1, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':login_id' => $loginId,
            ':password' => $password,
            ':name' => $name,
            ':account_type' => $accountType,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updatePasswordByLoginId(string $loginId, string $newPassword): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'UPDATE TaiKhoan
             SET MatKhau = :password,
                 ResetToken = NULL,
                 Updated = datetime("now", "localtime")
             WHERE lower(LoginId) = lower(:login_id)'
        );
        $stmt->execute([
            ':password' => $newPassword,
            ':login_id' => $loginId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function updateNameByLoginId(string $loginId, string $name): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'UPDATE TaiKhoan
             SET HoTen = :name,
                 Updated = datetime("now", "localtime")
             WHERE lower(LoginId) = lower(:login_id)'
        );
        $stmt->execute([
            ':name' => $name,
            ':login_id' => $loginId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function all()
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->query('SELECT Id AS id, HoTen AS name FROM TaiKhoan LIMIT 100');
        return $stmt->fetchAll();
    }

    public static function findByLoginId($loginId)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT
                Id AS id,
                LoginId AS login_id,
                MatKhau AS password,
                HoTen AS name,
                LoaiTaiKhoan AS account_type,
                TrangThai AS active_flag,
                ResetToken AS reset_password_token,
                Updated AS updated,
                Created AS created
             FROM TaiKhoan
             WHERE lower(LoginId) = lower(:login_id)
             LIMIT 1'
        );
        $stmt->execute([':login_id' => $loginId]);
        return $stmt->fetch();
    }

    public static function findById($id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT
                Id AS id,
                LoginId AS login_id,
                MatKhau AS password,
                HoTen AS name,
                LoaiTaiKhoan AS account_type,
                TrangThai AS active_flag,
                ResetToken AS reset_password_token,
                Updated AS updated,
                Created AS created
             FROM TaiKhoan
             WHERE Id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function findIdentityByLoginId(string $loginId): ?array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT LoginId AS login_id, HoTen AS name, LoaiTaiKhoan AS account_type
             FROM TaiKhoan
             WHERE lower(LoginId) = lower(:login_id)
             LIMIT 1'
        );
        $stmt->execute([':login_id' => $loginId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updateResetToken($adminId, $token)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('UPDATE TaiKhoan SET ResetToken = :token WHERE Id = :id');
        $stmt->execute([
            ':token' => $token,
            ':id' => $adminId,
        ]);
    }

    public static function listPendingReset()
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->query(
            "SELECT Id AS id, HoTen AS name, LoginId AS login_id, ResetToken AS reset_password_token, LoaiTaiKhoan AS account_type
             FROM TaiKhoan
             WHERE ResetToken IS NOT NULL
               AND ResetToken != ''
               AND LoaiTaiKhoan IN ('student', 'teacher')
             ORDER BY Id ASC"
        );
        return $stmt->fetchAll();
    }

    public static function resetPassword($adminId, $newPassword)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'UPDATE TaiKhoan
             SET MatKhau = :password, ResetToken = NULL
             WHERE Id = :id'
        );
        $stmt->execute([
            ':password' => $newPassword,
            ':id' => $adminId,
        ]);
    }
}

