<?php

class Student
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
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS students (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_code TEXT NOT NULL UNIQUE,
                full_name TEXT NOT NULL,
                date_of_birth TEXT,
                gender TEXT,
                class_name TEXT NOT NULL,
                faculty TEXT,
                email TEXT,
                phone TEXT,
                avatar TEXT,
                status TEXT NOT NULL DEFAULT "studying",
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $columns = $pdo->query('PRAGMA table_info(students)')->fetchAll();
        $hasAvatar = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'avatar') {
                $hasAvatar = true;
                break;
            }
        }
        if (!$hasAvatar) {
            $pdo->exec('ALTER TABLE students ADD COLUMN avatar TEXT');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_students_code ON students(student_code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_students_name ON students(full_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_students_class ON students(class_name)');
    }

    public static function create(array $data)
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $id = self::insertWithPdo($pdo, $data);
        return self::findById($id);
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO students (
                student_code, full_name, date_of_birth, gender, class_name, faculty, email, phone, avatar, status
            ) VALUES (
                :student_code, :full_name, :date_of_birth, :gender, :class_name, :faculty, :email, :phone, :avatar, :status
            )'
        );

        $stmt->execute([
            'student_code' => $data['student_code'],
            'full_name' => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'class_name' => $data['class_name'],
            'faculty' => $data['faculty'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'avatar' => $data['avatar'] ?: null,
            'status' => $data['status'] ?: 'studying',
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function findById(int $id)
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT id, student_code, full_name, date_of_birth, gender, class_name, faculty, email, phone, avatar, status, created_at
             FROM students
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function search(array $filters = [])
    {
        self::ensureTable();
        $pdo = get_db_connection();

        $sql = 'SELECT id, student_code, full_name, date_of_birth, gender, class_name, faculty, email, phone, avatar, status, created_at
                FROM students
                WHERE 1 = 1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(student_code) LIKE :keyword
                OR lower(full_name) LIKE :keyword
                OR lower(class_name) LIKE :keyword
                OR lower(faculty) LIKE :keyword
                OR lower(email) LIKE :keyword
                OR lower(phone) LIKE :keyword
            )';
            $params['keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }

        if (!empty($filters['class_name'])) {
            $sql .= ' AND lower(class_name) LIKE :class_name';
            $params['class_name'] = '%' . self::lowerText((string)$filters['class_name']) . '%';
        }

        if (!empty($filters['faculty'])) {
            $sql .= ' AND lower(faculty) LIKE :faculty';
            $params['faculty'] = '%' . self::lowerText((string)$filters['faculty']) . '%';
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' ORDER BY
            lower(COALESCE(faculty, "")) ASC,
            CASE
                WHEN upper(class_name) GLOB "K[0-9][0-9]*" THEN CAST(substr(class_name, 2, 2) AS INTEGER)
                ELSE 999
            END ASC,
            upper(trim(substr(class_name, 4))) ASC,
            student_code ASC
            LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function findByStudentCode(string $studentCode)
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT id, student_code, full_name, date_of_birth, gender, class_name, faculty, email, phone, avatar, status, created_at
             FROM students
             WHERE student_code = :student_code
             LIMIT 1'
        );
        $stmt->execute(['student_code' => $studentCode]);
        return $stmt->fetch();
    }

    public static function updateProfileByStudentCode(string $studentCode, array $data): bool
    {
        self::ensureTable();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'UPDATE students
             SET full_name = :full_name,
                 date_of_birth = :date_of_birth,
                 gender = :gender,
                 class_name = :class_name,
                 faculty = :faculty,
                 email = :email,
                 phone = :phone,
                 avatar = :avatar,
                 status = :status
             WHERE student_code = :student_code'
        );
        return $stmt->execute([
            'student_code' => $studentCode,
            'full_name' => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'class_name' => $data['class_name'],
            'faculty' => $data['faculty'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'avatar' => $data['avatar'] ?: null,
            'status' => $data['status'] ?: 'studying',
        ]);
    }
}
