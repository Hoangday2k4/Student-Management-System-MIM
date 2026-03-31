<?php

class Teacher
{
    private static function lowerText(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        $value = str_replace('Đ', 'đ', $value);
        return strtolower($value);
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        self::ensureTable($pdo);
    }

    private static function ensureTable(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS teachers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                teacher_code TEXT NOT NULL UNIQUE,
                full_name TEXT NOT NULL,
                date_of_birth TEXT,
                gender TEXT,
                department TEXT,
                homeroom_class TEXT,
                email TEXT,
                phone TEXT,
                avatar TEXT,
                status TEXT NOT NULL DEFAULT "Đang công tác",
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $columns = $pdo->query('PRAGMA table_info(teachers)')->fetchAll();
        $existingNames = [];
        foreach ($columns as $column) {
            $existingNames[] = (string)($column['name'] ?? '');
        }
        $existing = array_fill_keys($existingNames, true);

        $required = [
            'id',
            'teacher_code',
            'full_name',
            'date_of_birth',
            'gender',
            'department',
            'homeroom_class',
            'email',
            'phone',
            'avatar',
            'status',
            'created_at',
        ];

        foreach ($required as $name) {
            if (isset($existing[$name])) {
                continue;
            }
            if ($name === 'avatar') {
                $pdo->exec('ALTER TABLE teachers ADD COLUMN avatar TEXT');
            } elseif ($name === 'status') {
                $pdo->exec('ALTER TABLE teachers ADD COLUMN status TEXT');
            } elseif ($name === 'created_at') {
                $pdo->exec('ALTER TABLE teachers ADD COLUMN created_at TEXT');
            } elseif ($name !== 'id') {
                $pdo->exec("ALTER TABLE teachers ADD COLUMN {$name} TEXT");
            }
        }

        $legacyColumns = ['name', 'description', 'specialized', 'degree', 'updated', 'created'];
        $hasLegacy = false;
        foreach ($legacyColumns as $legacyCol) {
            if (isset($existing[$legacyCol])) {
                $hasLegacy = true;
                break;
            }
        }

        if ($hasLegacy) {
            $pdo->beginTransaction();
            try {
                $pdo->exec(
                    'CREATE TABLE teachers_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        teacher_code TEXT NOT NULL UNIQUE,
                        full_name TEXT NOT NULL,
                        date_of_birth TEXT,
                        gender TEXT,
                        department TEXT,
                        homeroom_class TEXT,
                        email TEXT,
                        phone TEXT,
                        avatar TEXT,
                        status TEXT NOT NULL DEFAULT "Đang công tác",
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )'
                );

                $pdo->exec(
                    'INSERT INTO teachers_new (
                        id, teacher_code, full_name, date_of_birth, gender, department, homeroom_class, email, phone, avatar, status, created_at
                    )
                    SELECT
                        id,
                        COALESCE(NULLIF(teacher_code, ""), "GV" || id),
                        COALESCE(NULLIF(full_name, ""), NULLIF(name, ""), "Chưa cập nhật"),
                        NULLIF(date_of_birth, ""),
                        NULLIF(gender, ""),
                        NULLIF(department, ""),
                        NULLIF(homeroom_class, ""),
                        NULLIF(email, ""),
                        NULLIF(phone, ""),
                        NULLIF(avatar, ""),
                        COALESCE(NULLIF(status, ""), "Đang công tác"),
                        COALESCE(NULLIF(created_at, ""), NULLIF(created, ""), CURRENT_TIMESTAMP)
                    FROM teachers'
                );

                $pdo->exec('DROP TABLE teachers');
                $pdo->exec('ALTER TABLE teachers_new RENAME TO teachers');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_teachers_code ON teachers(teacher_code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_teachers_name ON teachers(full_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_teachers_department ON teachers(department)');
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO teachers (
                teacher_code, full_name, date_of_birth, gender, department, homeroom_class, email, phone, avatar, status
            ) VALUES (
                :teacher_code, :full_name, :date_of_birth, :gender, :department, :homeroom_class, :email, :phone, :avatar, :status
            )'
        );

        $stmt->execute([
            'teacher_code' => $data['teacher_code'],
            'full_name' => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'department' => $data['department'] ?: null,
            'homeroom_class' => $data['homeroom_class'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'avatar' => $data['avatar'] ?: null,
            'status' => $data['status'] ?: 'Đang công tác',
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function findById(int $id)
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT id, teacher_code, full_name, date_of_birth, gender, department, homeroom_class, email, phone, avatar, status, created_at
             FROM teachers
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function findByTeacherCode(string $teacherCode)
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT id, teacher_code, full_name, date_of_birth, gender, department, homeroom_class, email, phone, avatar, status, created_at
             FROM teachers
             WHERE teacher_code = :teacher_code
             LIMIT 1'
        );
        $stmt->execute(['teacher_code' => $teacherCode]);
        return $stmt->fetch();
    }

    public static function search(array $filters = [])
    {
        self::ensureTable();
        $pdo = get_db_connection();

        $sql = 'SELECT id, teacher_code, full_name, date_of_birth, gender, department, homeroom_class, email, phone, avatar, status, created_at
                FROM teachers
                WHERE 1 = 1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(teacher_code) LIKE :keyword
                OR lower(full_name) LIKE :keyword
                OR lower(department) LIKE :keyword
                OR lower(homeroom_class) LIKE :keyword
                OR lower(email) LIKE :keyword
                OR lower(phone) LIKE :keyword
            )';
            $params['keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }

        if (!empty($filters['department'])) {
            $sql .= ' AND lower(department) LIKE :department';
            $params['department'] = '%' . self::lowerText((string)$filters['department']) . '%';
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' ORDER BY lower(COALESCE(department, "")) ASC, teacher_code ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function updateProfileByTeacherCode(string $teacherCode, array $data): bool
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'UPDATE teachers
             SET full_name = :full_name,
                 date_of_birth = :date_of_birth,
                 gender = :gender,
                 department = :department,
                 homeroom_class = :homeroom_class,
                 email = :email,
                 phone = :phone,
                 avatar = :avatar,
                 status = :status
             WHERE teacher_code = :teacher_code'
        );
        return $stmt->execute([
            'teacher_code' => $teacherCode,
            'full_name' => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'department' => $data['department'] ?: null,
            'homeroom_class' => $data['homeroom_class'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'avatar' => $data['avatar'] ?: null,
            'status' => $data['status'] ?: 'Đang công tác',
        ]);
    }
}
