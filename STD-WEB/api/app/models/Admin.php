<?php
class Admin
{
    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login_id TEXT UNIQUE,
                password TEXT,
                name TEXT,
                account_type TEXT NOT NULL DEFAULT "staff",
                active_flag INTEGER DEFAULT 1,
                reset_password_token TEXT,
                updated DATETIME,
                created DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $columns = $pdo->query('PRAGMA table_info(admins)')->fetchAll();
        $existing = [];
        foreach ($columns as $column) {
            $existing[$column['name']] = true;
        }

        if (!isset($existing['name'])) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN name TEXT');
        }
        if (!isset($existing['active_flag'])) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN active_flag INTEGER DEFAULT 1');
        }
        if (!isset($existing['reset_password_token'])) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN reset_password_token TEXT');
        }
        if (!isset($existing['updated'])) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN updated DATETIME');
        }
        if (!isset($existing['created'])) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN created DATETIME DEFAULT CURRENT_TIMESTAMP');
        }
        if (!isset($existing['account_type'])) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN account_type TEXT NOT NULL DEFAULT "student"');
        }

        // backfill account_type for legacy data
        $pdo->exec("UPDATE admins SET account_type = 'staff' WHERE lower(login_id) IN ('admin', 'manager')");
        $pdo->exec("UPDATE admins SET account_type = 'student' WHERE account_type IS NULL OR trim(account_type) = ''");
    }

    public static function verifyPassword(string $loginId, string $password): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT id FROM admins
             WHERE lower(login_id) = lower(:login_id)
               AND active_flag = 1
               AND (password = :password_plain OR password = :password_md5)
             LIMIT 1'
        );
        $stmt->execute([
            ':login_id' => $loginId,
            ':password_plain' => $password,
            ':password_md5' => md5($password),
        ]);
        return (bool) $stmt->fetch();
    }

    public static function createAccountWithPdo(PDO $pdo, string $loginId, string $password, string $name = '', string $accountType = 'student'): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO admins (login_id, password, name, account_type, active_flag)
             VALUES (:login_id, :password, :name, :account_type, 1)'
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
            'UPDATE admins
             SET password = :password,
                 reset_password_token = NULL,
                 updated = datetime("now", "localtime")
             WHERE lower(login_id) = lower(:login_id)'
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
            'UPDATE admins
             SET name = :name,
                 updated = datetime(\"now\", \"localtime\")
             WHERE lower(login_id) = lower(:login_id)'
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
        $stmt = $pdo->query('SELECT id, name FROM admins LIMIT 100');
        return $stmt->fetchAll();
    }

    public static function findByLoginId($loginId)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE lower(login_id) = lower(:login_id) LIMIT 1');
        $stmt->execute([':login_id' => $loginId]);
        return $stmt->fetch();
    }

    public static function findById($id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function findIdentityByLoginId(string $loginId): ?array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT login_id, name, account_type
             FROM admins
             WHERE lower(login_id) = lower(:login_id)
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
        $stmt = $pdo->prepare('UPDATE admins SET reset_password_token = :token WHERE id = :id');
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
            "SELECT id, name, login_id, reset_password_token, account_type
             FROM admins
             WHERE reset_password_token IS NOT NULL
               AND reset_password_token != ''
               AND account_type IN ('student', 'teacher')
             ORDER BY id ASC"
        );
        return $stmt->fetchAll();
    }

    public static function resetPassword($adminId, $newPassword)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'UPDATE admins
             SET password = :password, reset_password_token = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':password' => $newPassword,
            ':id' => $adminId,
        ]);
    }
}
