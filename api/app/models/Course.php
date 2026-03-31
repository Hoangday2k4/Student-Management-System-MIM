<?php

class Course
{
    private static function lowerText(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private static function resolveDepartmentId(PDO $pdo, ?string $department): ?int
    {
        $name = trim((string)$department);
        if ($name === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id FROM departments WHERE lower(department_name) = lower(:name) OR lower(department_code) = lower(:name) LIMIT 1');
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function resolveTeacherIdByCode(PDO $pdo, ?string $teacherCode): ?int
    {
        $code = trim((string)$teacherCode);
        if ($code === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM teachers WHERE lower(teacher_code) = lower(:code) LIMIT 1');
        $stmt->execute([':code' => $code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function resolveStudentIdByCode(PDO $pdo, string $studentCode): ?int
    {
        $code = trim($studentCode);
        if ($code === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM students WHERE lower(student_code) = lower(:code) LIMIT 1');
        $stmt->execute([':code' => $code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function firstClassroom(string $classroom): string
    {
        $parts = explode(',', $classroom);
        return trim((string)($parts[0] ?? ''));
    }

    private static function resolveClassroomId(PDO $pdo, ?string $classroomRaw): ?int
    {
        $roomCode = strtoupper(trim(self::firstClassroom((string)$classroomRaw)));
        if ($roomCode === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id FROM classrooms WHERE upper(room_code) = :code LIMIT 1');
        $stmt->execute([':code' => $roomCode]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO classrooms (room_code, room_name, building, description, avatar, created_at, updated_at)
             VALUES (:room_code, :room_name, "", "", "", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':room_code' => $roomCode,
            ':room_name' => $roomCode,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function getSectionIdByCourseIdWithPdo(PDO $pdo, int $courseId): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM course_sections WHERE course_id = :course_id ORDER BY id ASC LIMIT 1');
        $stmt->execute([':course_id' => $courseId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function upsertDefaultSectionWithPdo(PDO $pdo, int $courseId): int
    {
        $courseStmt = $pdo->prepare('SELECT id, course_code, teacher_code, schedule, classroom, max_students FROM courses WHERE id = :id LIMIT 1');
        $courseStmt->execute([':id' => $courseId]);
        $course = $courseStmt->fetch();
        if (!$course) {
            return 0;
        }

        $sectionId = self::getSectionIdByCourseIdWithPdo($pdo, $courseId);
        $teacherId = self::resolveTeacherIdByCode($pdo, (string)($course['teacher_code'] ?? ''));
        $classroomId = self::resolveClassroomId($pdo, (string)($course['classroom'] ?? ''));
        $sectionCode = trim((string)$course['course_code']) . '-01';

        if ($sectionId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO course_sections (
                    section_code, course_id, teacher_id, classroom_id, schedule, semester, academic_year, max_students, created_at
                ) VALUES (
                    :section_code, :course_id, :teacher_id, :classroom_id, :schedule, NULL, NULL, :max_students, CURRENT_TIMESTAMP
                )'
            );
            $stmt->execute([
                ':section_code' => $sectionCode,
                ':course_id' => $courseId,
                ':teacher_id' => $teacherId,
                ':classroom_id' => $classroomId,
                ':schedule' => (string)($course['schedule'] ?? ''),
                ':max_students' => $course['max_students'] !== null ? (int)$course['max_students'] : null,
            ]);
            return (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'UPDATE course_sections
             SET section_code = :section_code,
                 teacher_id = :teacher_id,
                 classroom_id = :classroom_id,
                 schedule = :schedule,
                 max_students = :max_students
             WHERE id = :id'
        );
        $stmt->execute([
            ':section_code' => $sectionCode,
            ':teacher_id' => $teacherId,
            ':classroom_id' => $classroomId,
            ':schedule' => (string)($course['schedule'] ?? ''),
            ':max_students' => $course['max_students'] !== null ? (int)$course['max_students'] : null,
            ':id' => $sectionId,
        ]);
        return $sectionId;
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (!$pdo) {
            $pdo = get_db_connection();
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_code TEXT NOT NULL UNIQUE,
                course_name TEXT NOT NULL,
                credits INTEGER,
                teacher_code TEXT NOT NULL,
                department TEXT,
                schedule TEXT,
                classroom TEXT,
                max_students INTEGER,
                weight_cc REAL DEFAULT 0,
                weight_gk REAL DEFAULT 0,
                weight_ck REAL DEFAULT 0,
                department_id INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        // Legacy tables are kept for backward compatibility.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS course_enrollments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id INTEGER NOT NULL,
                student_code TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(course_id, student_code),
                FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS course_scores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id INTEGER NOT NULL,
                student_code TEXT NOT NULL,
                cc REAL,
                gk REAL,
                ck REAL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(course_id, student_code),
                FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
            )'
        );

        // Normalized tables
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS course_sections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                section_code TEXT NOT NULL UNIQUE,
                course_id INTEGER NOT NULL,
                teacher_id INTEGER,
                classroom_id INTEGER,
                schedule TEXT,
                semester INTEGER,
                academic_year INTEGER,
                max_students INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS enrollments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                course_section_id INTEGER NOT NULL,
                enrolled_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(student_id, course_section_id),
                FOREIGN KEY(course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS scores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                course_section_id INTEGER NOT NULL,
                cc REAL,
                gk REAL,
                ck REAL,
                updated_at TEXT,
                UNIQUE(student_id, course_section_id),
                FOREIGN KEY(course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_courses_code ON courses(course_code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_courses_teacher ON courses(teacher_code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_courses_department ON courses(department)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_course_sections_course_id ON course_sections(course_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_enrollments_course_section_id ON enrollments(course_section_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_enrollments_student_id ON enrollments(student_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scores_course_section_id ON scores(course_section_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scores_student_id ON scores(student_id)');
    }

    public static function insertWithPdo(PDO $pdo, array $data): int
    {
        $departmentId = self::resolveDepartmentId($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'INSERT INTO courses (
                course_code, course_name, credits, teacher_code, department, department_id, schedule, classroom, max_students
            ) VALUES (
                :course_code, :course_name, :credits, :teacher_code, :department, :department_id, :schedule, :classroom, :max_students
            )'
        );

        $stmt->execute([
            ':course_code' => $data['course_code'],
            ':course_name' => $data['course_name'],
            ':credits' => $data['credits'],
            ':teacher_code' => $data['teacher_code'],
            ':department' => $data['department'] ?: null,
            ':department_id' => $departmentId,
            ':schedule' => $data['schedule'] ?: null,
            ':classroom' => $data['classroom'] ?: null,
            ':max_students' => $data['max_students'],
        ]);

        $courseId = (int)$pdo->lastInsertId();
        self::upsertDefaultSectionWithPdo($pdo, $courseId);
        return $courseId;
    }

    public static function updateByIdWithPdo(PDO $pdo, int $courseId, array $data): bool
    {
        $departmentId = self::resolveDepartmentId($pdo, (string)($data['department'] ?? ''));
        $stmt = $pdo->prepare(
            'UPDATE courses
             SET course_code = :course_code,
                 course_name = :course_name,
                 credits = :credits,
                 teacher_code = :teacher_code,
                 department = :department,
                 department_id = :department_id,
                 schedule = :schedule,
                 classroom = :classroom,
                 max_students = :max_students
             WHERE id = :id'
        );

        $ok = $stmt->execute([
            ':id' => $courseId,
            ':course_code' => $data['course_code'],
            ':course_name' => $data['course_name'],
            ':credits' => $data['credits'],
            ':teacher_code' => $data['teacher_code'],
            ':department' => $data['department'] ?: null,
            ':department_id' => $departmentId,
            ':schedule' => $data['schedule'] ?: null,
            ':classroom' => $data['classroom'] ?: null,
            ':max_students' => $data['max_students'],
        ]);
        self::upsertDefaultSectionWithPdo($pdo, $courseId);
        return $ok;
    }

    public static function deleteByIdWithPdo(PDO $pdo, int $courseId): void
    {
        $sectionId = self::getSectionIdByCourseIdWithPdo($pdo, $courseId);
        if ($sectionId !== null) {
            $pdo->prepare('DELETE FROM scores WHERE course_section_id = :id')->execute([':id' => $sectionId]);
            $pdo->prepare('DELETE FROM enrollments WHERE course_section_id = :id')->execute([':id' => $sectionId]);
            $pdo->prepare('DELETE FROM course_sections WHERE id = :id')->execute([':id' => $sectionId]);
        }

        // Keep deleting legacy tables for compatibility.
        $pdo->prepare('DELETE FROM course_scores WHERE course_id = :course_id')->execute([':course_id' => $courseId]);
        $pdo->prepare('DELETE FROM course_enrollments WHERE course_id = :course_id')->execute([':course_id' => $courseId]);
        $pdo->prepare('DELETE FROM courses WHERE id = :course_id')->execute([':course_id' => $courseId]);
    }

    public static function replaceEnrollmentsWithPdo(PDO $pdo, int $courseId, array $studentCodes): void
    {
        $sectionId = self::upsertDefaultSectionWithPdo($pdo, $courseId);
        if ($sectionId <= 0) {
            return;
        }

        $pdo->prepare('DELETE FROM enrollments WHERE course_section_id = :section_id')->execute([':section_id' => $sectionId]);
        $pdo->prepare('DELETE FROM course_enrollments WHERE course_id = :course_id')->execute([':course_id' => $courseId]);

        if (empty($studentCodes)) {
            return;
        }

        self::appendEnrollmentsWithPdo($pdo, $courseId, $studentCodes);
    }

    public static function appendEnrollmentsWithPdo(PDO $pdo, int $courseId, array $studentCodes): int
    {
        $sectionId = self::upsertDefaultSectionWithPdo($pdo, $courseId);
        if ($sectionId <= 0) {
            return 0;
        }

        $insNew = $pdo->prepare('INSERT OR IGNORE INTO enrollments (student_id, course_section_id, enrolled_at) VALUES (:student_id, :course_section_id, CURRENT_TIMESTAMP)');
        $insOld = $pdo->prepare('INSERT OR IGNORE INTO course_enrollments (course_id, student_code, created_at) VALUES (:course_id, :student_code, CURRENT_TIMESTAMP)');

        $added = 0;
        foreach ($studentCodes as $studentCode) {
            $code = trim((string)$studentCode);
            if ($code === '') {
                continue;
            }
            $studentId = self::resolveStudentIdByCode($pdo, $code);
            if ($studentId === null) {
                continue;
            }

            $insNew->execute([
                ':student_id' => $studentId,
                ':course_section_id' => $sectionId,
            ]);
            if ($insNew->rowCount() > 0) {
                $added++;
            }

            // legacy mirror
            $insOld->execute([
                ':course_id' => $courseId,
                ':student_code' => $code,
            ]);
        }
        return $added;
    }

    public static function findValidStudentCodes(PDO $pdo, array $studentCodes): array
    {
        $studentCodes = array_values(array_unique(array_filter(array_map('trim', $studentCodes))));
        if (empty($studentCodes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($studentCodes), '?'));
        $stmt = $pdo->prepare("SELECT student_code FROM students WHERE student_code IN ($placeholders)");
        $stmt->execute($studentCodes);
        $rows = $stmt->fetchAll();
        return array_values(array_map(static fn ($row) => (string)$row['student_code'], $rows));
    }

    public static function findById(int $id)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.course_code, c.course_name, c.credits, c.teacher_code, c.department, c.schedule, c.classroom, c.max_students,
                    c.weight_cc, c.weight_gk, c.weight_ck, c.created_at,
                    t.full_name AS teacher_name,
                    (
                      SELECT COUNT(1)
                      FROM enrollments e
                      INNER JOIN course_sections cs ON cs.id = e.course_section_id
                      WHERE cs.course_id = c.id
                    ) AS enrolled_count
             FROM courses c
             LEFT JOIN teachers t ON t.teacher_code = c.teacher_code
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function isStudentEnrolled(int $courseId, string $studentCode): bool
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM enrollments e
             INNER JOIN course_sections cs ON cs.id = e.course_section_id
             INNER JOIN students s ON s.id = e.student_id
             WHERE cs.course_id = :course_id AND lower(s.student_code) = lower(:student_code)
             LIMIT 1'
        );
        $stmt->execute([
            ':course_id' => $courseId,
            ':student_code' => $studentCode,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    public static function getStudentsByCourseId(int $courseId): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT s.student_code, s.full_name, s.class_name, s.faculty, s.email, s.phone
             FROM enrollments e
             INNER JOIN course_sections cs ON cs.id = e.course_section_id
             INNER JOIN students s ON s.id = e.student_id
             WHERE cs.course_id = :course_id
             ORDER BY s.student_code ASC'
        );
        $stmt->execute([':course_id' => $courseId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function getScoresByCourseId(int $courseId): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT s.student_code, sc.cc, sc.gk, sc.ck
             FROM scores sc
             INNER JOIN course_sections cs ON cs.id = sc.course_section_id
             INNER JOIN students s ON s.id = sc.student_id
             WHERE cs.course_id = :course_id'
        );
        $stmt->execute([':course_id' => $courseId]);
        $rows = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['student_code']] = [
                'cc' => $row['cc'] !== null ? (float)$row['cc'] : null,
                'gk' => $row['gk'] !== null ? (float)$row['gk'] : null,
                'ck' => $row['ck'] !== null ? (float)$row['ck'] : null,
            ];
        }
        return $map;
    }

    public static function updateWeightsWithPdo(PDO $pdo, int $courseId, float $weightCc, float $weightGk, float $weightCk): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE courses
             SET weight_cc = :weight_cc,
                 weight_gk = :weight_gk,
                 weight_ck = :weight_ck
             WHERE id = :id'
        );
        return $stmt->execute([
            ':id' => $courseId,
            ':weight_cc' => $weightCc,
            ':weight_gk' => $weightGk,
            ':weight_ck' => $weightCk,
        ]);
    }

    public static function upsertScoresWithPdo(PDO $pdo, int $courseId, array $scores): void
    {
        $sectionId = self::upsertDefaultSectionWithPdo($pdo, $courseId);
        if ($sectionId <= 0) {
            return;
        }

        $stmtNew = $pdo->prepare(
            'INSERT INTO scores (student_id, course_section_id, cc, gk, ck, updated_at)
             VALUES (:student_id, :course_section_id, :cc, :gk, :ck, CURRENT_TIMESTAMP)
             ON CONFLICT(student_id, course_section_id)
             DO UPDATE SET cc = excluded.cc, gk = excluded.gk, ck = excluded.ck, updated_at = CURRENT_TIMESTAMP'
        );
        $stmtOld = $pdo->prepare(
            'INSERT INTO course_scores (course_id, student_code, cc, gk, ck, updated_at)
             VALUES (:course_id, :student_code, :cc, :gk, :ck, CURRENT_TIMESTAMP)
             ON CONFLICT(course_id, student_code)
             DO UPDATE SET cc = excluded.cc, gk = excluded.gk, ck = excluded.ck, updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($scores as $row) {
            $studentCode = (string)$row['student_code'];
            $studentId = self::resolveStudentIdByCode($pdo, $studentCode);
            if ($studentId === null) {
                continue;
            }
            $params = [
                ':cc' => $row['cc'],
                ':gk' => $row['gk'],
                ':ck' => $row['ck'],
            ];
            $stmtNew->execute([
                ':student_id' => $studentId,
                ':course_section_id' => $sectionId,
                ...$params,
            ]);
            // legacy mirror
            $stmtOld->execute([
                ':course_id' => $courseId,
                ':student_code' => $studentCode,
                ...$params,
            ]);
        }
    }

    public static function searchForStaff(array $filters = []): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $sql = 'SELECT c.id, c.course_code, c.course_name, c.credits, c.teacher_code, c.department, c.schedule, c.classroom, c.max_students, c.created_at,
                       t.full_name AS teacher_name,
                       (
                         SELECT COUNT(1)
                         FROM enrollments e
                         INNER JOIN course_sections cs ON cs.id = e.course_section_id
                         WHERE cs.course_id = c.id
                       ) AS enrolled_count
                FROM courses c
                LEFT JOIN teachers t ON t.teacher_code = c.teacher_code
                WHERE 1 = 1';
        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (
                lower(c.course_code) LIKE :keyword
                OR lower(c.course_name) LIKE :keyword
                OR lower(c.schedule) LIKE :keyword
                OR lower(c.classroom) LIKE :keyword
                OR lower(c.teacher_code) LIKE :keyword
                OR lower(t.full_name) LIKE :keyword
            )';
            $params[':keyword'] = '%' . self::lowerText((string)$filters['keyword']) . '%';
        }

        if (!empty($filters['department'])) {
            $sql .= ' AND lower(c.department) LIKE :department';
            $params[':department'] = '%' . self::lowerText((string)$filters['department']) . '%';
        }

        if (!empty($filters['teacher_code'])) {
            $sql .= ' AND lower(c.teacher_code) LIKE :teacher_code';
            $params[':teacher_code'] = '%' . self::lowerText((string)$filters['teacher_code']) . '%';
        }

        $sql .= ' ORDER BY
            lower(COALESCE(c.department, "")) ASC,
            CAST(substr(c.course_code, 4) AS INTEGER) ASC,
            c.course_code ASC
            LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public static function listByTeacherCode(string $teacherCode): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.course_code, c.course_name, c.credits, c.teacher_code, c.department, c.schedule, c.classroom, c.max_students, c.created_at,
                    t.full_name AS teacher_name,
                    (
                      SELECT COUNT(1)
                      FROM enrollments e
                      INNER JOIN course_sections cs ON cs.id = e.course_section_id
                      WHERE cs.course_id = c.id
                    ) AS enrolled_count
             FROM courses c
             LEFT JOIN teachers t ON t.teacher_code = c.teacher_code
             WHERE lower(c.teacher_code) = lower(:teacher_code)
             ORDER BY c.id DESC'
        );
        $stmt->execute([':teacher_code' => $teacherCode]);
        return $stmt->fetchAll() ?: [];
    }

    public static function listByStudentCode(string $studentCode): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.course_code, c.course_name, c.credits, c.teacher_code, c.department, c.schedule, c.classroom, c.max_students, c.created_at,
                    t.full_name AS teacher_name
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id
             INNER JOIN course_sections cs ON cs.id = e.course_section_id
             INNER JOIN courses c ON c.id = cs.course_id
             LEFT JOIN teachers t ON t.teacher_code = c.teacher_code
             WHERE lower(s.student_code) = lower(:student_code)
             ORDER BY c.id DESC'
        );
        $stmt->execute([':student_code' => $studentCode]);
        return $stmt->fetchAll() ?: [];
    }

    public static function listScoresByStudentCode(string $studentCode): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.course_code, c.course_name, c.credits, c.teacher_code, c.weight_cc, c.weight_gk, c.weight_ck,
                    t.full_name AS teacher_name,
                    sc.cc, sc.gk, sc.ck
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id
             INNER JOIN course_sections cs ON cs.id = e.course_section_id
             INNER JOIN courses c ON c.id = cs.course_id
             LEFT JOIN teachers t ON t.teacher_code = c.teacher_code
             LEFT JOIN scores sc ON sc.course_section_id = cs.id AND sc.student_id = s.id
             WHERE lower(s.student_code) = lower(:student_code)
             ORDER BY c.course_code ASC'
        );
        $stmt->execute([':student_code' => $studentCode]);
        return $stmt->fetchAll() ?: [];
    }

    public static function getScoreDetailForStudent(int $courseId, string $studentCode)
    {
        self::ensureSchema();
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.course_code, c.course_name, c.credits, c.teacher_code, c.weight_cc, c.weight_gk, c.weight_ck,
                    t.full_name AS teacher_name,
                    s.student_code, s.full_name AS student_name, s.class_name,
                    sc.cc, sc.gk, sc.ck
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id
             INNER JOIN course_sections cs ON cs.id = e.course_section_id
             INNER JOIN courses c ON c.id = cs.course_id
             LEFT JOIN teachers t ON t.teacher_code = c.teacher_code
             LEFT JOIN scores sc ON sc.course_section_id = cs.id AND sc.student_id = s.id
             WHERE c.id = :course_id AND lower(s.student_code) = lower(:student_code)
             LIMIT 1'
        );
        $stmt->execute([
            ':course_id' => $courseId,
            ':student_code' => $studentCode,
        ]);
        return $stmt->fetch();
    }
}

