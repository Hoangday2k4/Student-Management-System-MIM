<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

function tableExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $name]);
    return (bool)$stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (strcasecmp((string)$row['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

function addColumnIfMissing(PDO $pdo, string $table, string $columnDef): void
{
    $parts = preg_split('/\s+/', trim($columnDef));
    $column = (string)($parts[0] ?? '');
    if ($column === '') {
        return;
    }
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $columnDef");
    }
}

function normalizeCode(string $value, string $prefix): string
{
    $v = strtoupper(trim($value));
    if ($v === '') {
        return '';
    }
    $v = preg_replace('/\s+/', '_', $v) ?? '';
    $v = preg_replace('/[^A-Z0-9_\\-]/', '', $v) ?? '';
    if ($v === '') {
        return '';
    }
    if (!preg_match('/^[A-Z]/', $v)) {
        $v = $prefix . $v;
    }
    return $v;
}

function splitFirstValue(?string $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $parts = explode(',', $text);
    return trim((string)($parts[0] ?? ''));
}

function resolveForeignId(PDO $pdo, string $table, $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $id = (int)$value;
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $found = $stmt->fetchColumn();
    if ($found === false) {
        return null;
    }
    return (int)$found;
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 10000');

try {
    $pdo->beginTransaction();

    // 1) Accounts
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            email TEXT UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "STUDENT",
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT
        )'
    );

    // 2) Departments
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_code TEXT NOT NULL UNIQUE,
            department_name TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )'
    );

    // 3) Classes
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_code TEXT NOT NULL UNIQUE,
            class_name TEXT NOT NULL,
            department_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(department_id) REFERENCES departments(id)
        )'
    );

    // 4) Ensure legacy students/teachers/courses can reference normalized tables
    if (tableExists($pdo, 'students')) {
        addColumnIfMissing($pdo, 'students', 'account_id INTEGER');
        addColumnIfMissing($pdo, 'students', 'class_id INTEGER');
    }
    if (tableExists($pdo, 'teachers')) {
        addColumnIfMissing($pdo, 'teachers', 'account_id INTEGER');
        addColumnIfMissing($pdo, 'teachers', 'department_id INTEGER');
    }
    if (tableExists($pdo, 'courses')) {
        addColumnIfMissing($pdo, 'courses', 'department_id INTEGER');
    }

    // 5) Classrooms (normalize existing table)
    if (!tableExists($pdo, 'classrooms')) {
        $pdo->exec(
            'CREATE TABLE classrooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_code TEXT NOT NULL UNIQUE,
                room_name TEXT,
                building TEXT,
                description TEXT,
                avatar TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )'
        );
    } else {
        addColumnIfMissing($pdo, 'classrooms', 'room_code TEXT');
        addColumnIfMissing($pdo, 'classrooms', 'room_name TEXT');
        addColumnIfMissing($pdo, 'classrooms', 'description TEXT');
        addColumnIfMissing($pdo, 'classrooms', 'avatar TEXT');
        addColumnIfMissing($pdo, 'classrooms', 'building TEXT');
        addColumnIfMissing($pdo, 'classrooms', 'created_at TEXT');
        addColumnIfMissing($pdo, 'classrooms', 'updated_at TEXT');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_classrooms_room_code ON classrooms(room_code)');

    // 6) Courses (ensure normalized fields)
    if (tableExists($pdo, 'courses')) {
        addColumnIfMissing($pdo, 'courses', 'weight_cc REAL DEFAULT 0');
        addColumnIfMissing($pdo, 'courses', 'weight_gk REAL DEFAULT 0');
        addColumnIfMissing($pdo, 'courses', 'weight_ck REAL DEFAULT 0');
        addColumnIfMissing($pdo, 'courses', 'created_at TEXT');
    } else {
        $pdo->exec(
            'CREATE TABLE courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_code TEXT NOT NULL UNIQUE,
                course_name TEXT NOT NULL,
                credits INTEGER,
                department_id INTEGER,
                weight_cc REAL DEFAULT 0,
                weight_gk REAL DEFAULT 0,
                weight_ck REAL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(department_id) REFERENCES departments(id)
            )'
        );
    }

    // 7) Course sections
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
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY(teacher_id) REFERENCES teachers(id),
            FOREIGN KEY(classroom_id) REFERENCES classrooms(id)
        )'
    );

    // 8) Enrollments
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            course_section_id INTEGER NOT NULL,
            enrolled_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id, course_section_id),
            FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY(course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE
        )'
    );

    // 9) Scores
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
            FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY(course_section_id) REFERENCES course_sections(id) ON DELETE CASCADE
        )'
    );

    // 10) Devices normalize existing table
    if (!tableExists($pdo, 'devices')) {
        $pdo->exec(
            'CREATE TABLE devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_code TEXT NOT NULL UNIQUE,
                device_name TEXT NOT NULL,
                serial TEXT UNIQUE,
                avatar TEXT,
                description TEXT,
                status TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )'
        );
    } else {
        addColumnIfMissing($pdo, 'devices', 'device_code TEXT');
        addColumnIfMissing($pdo, 'devices', 'device_name TEXT');
        addColumnIfMissing($pdo, 'devices', 'status TEXT');
        addColumnIfMissing($pdo, 'devices', 'created_at TEXT');
        addColumnIfMissing($pdo, 'devices', 'updated_at TEXT');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_devices_device_code ON devices(device_code)');

    // 11) Device transactions
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS device_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER,
            teacher_id INTEGER,
            device_id INTEGER,
            classroom_id INTEGER,
            transaction_type TEXT,
            comment TEXT,
            planned_start_at TEXT,
            planned_end_at TEXT,
            returned_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(account_id) REFERENCES accounts(id),
            FOREIGN KEY(teacher_id) REFERENCES teachers(id),
            FOREIGN KEY(device_id) REFERENCES devices(id),
            FOREIGN KEY(classroom_id) REFERENCES classrooms(id)
        )'
    );

    // --------------------
    // Backfill data
    // --------------------

    if (tableExists($pdo, 'admins')) {
        $admins = $pdo->query('SELECT * FROM admins')->fetchAll();
        $stmt = $pdo->prepare(
            'INSERT INTO accounts (id, username, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (:id, :username, :email, :password_hash, :role, :is_active, :created_at, :updated_at)
             ON CONFLICT(username) DO UPDATE SET
                 password_hash = excluded.password_hash,
                 role = excluded.role,
                 is_active = excluded.is_active,
                 updated_at = excluded.updated_at'
        );
        foreach ($admins as $row) {
            $type = strtolower((string)($row['account_type'] ?? 'student'));
            $role = $type === 'teacher' ? 'TEACHER' : ($type === 'staff' ? 'ADMIN' : 'STUDENT');
            $stmt->execute([
                ':id' => (int)$row['id'],
                ':username' => (string)$row['login_id'],
                ':email' => null,
                ':password_hash' => (string)$row['password'],
                ':role' => $role,
                ':is_active' => ((int)($row['active_flag'] ?? 1) === 1) ? 1 : 0,
                ':created_at' => (string)($row['created'] ?? date('Y-m-d H:i:s')),
                ':updated_at' => (string)($row['updated'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    // Build departments from legacy data
    $departmentValues = [];
    if (tableExists($pdo, 'students') && columnExists($pdo, 'students', 'faculty')) {
        $vals = $pdo->query("SELECT DISTINCT trim(faculty) v FROM students WHERE trim(ifnull(faculty,''))<>''")->fetchAll(PDO::FETCH_COLUMN);
        $departmentValues = array_merge($departmentValues, $vals);
    }
    if (tableExists($pdo, 'teachers') && columnExists($pdo, 'teachers', 'department')) {
        $vals = $pdo->query("SELECT DISTINCT trim(department) v FROM teachers WHERE trim(ifnull(department,''))<>''")->fetchAll(PDO::FETCH_COLUMN);
        $departmentValues = array_merge($departmentValues, $vals);
    }
    if (tableExists($pdo, 'courses') && columnExists($pdo, 'courses', 'department')) {
        $vals = $pdo->query("SELECT DISTINCT trim(department) v FROM courses WHERE trim(ifnull(department,''))<>''")->fetchAll(PDO::FETCH_COLUMN);
        $departmentValues = array_merge($departmentValues, $vals);
    }
    $departmentValues = array_values(array_unique(array_filter(array_map('trim', $departmentValues), static fn ($x) => $x !== '')));

    $insDept = $pdo->prepare(
        'INSERT INTO departments (department_code, department_name, created_at)
         VALUES (:code, :name, :created_at)
         ON CONFLICT(department_code) DO UPDATE SET department_name = excluded.department_name'
    );
    foreach ($departmentValues as $name) {
        $code = normalizeCode($name, 'D');
        if ($code === '') {
            continue;
        }
        $insDept->execute([
            ':code' => $code,
            ':name' => $name,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $deptMap = [];
    $deptRows = $pdo->query('SELECT id, department_name FROM departments')->fetchAll();
    foreach ($deptRows as $row) {
        $deptMap[strtolower(trim((string)$row['department_name']))] = (int)$row['id'];
    }

    // Classes
    if (tableExists($pdo, 'students') && columnExists($pdo, 'students', 'class_name')) {
        $rows = $pdo->query("SELECT DISTINCT trim(class_name) class_name, trim(ifnull(faculty,'')) faculty FROM students WHERE trim(ifnull(class_name,''))<>''")->fetchAll();
        $insClass = $pdo->prepare(
            'INSERT INTO classes (class_code, class_name, department_id, created_at)
             VALUES (:class_code, :class_name, :department_id, :created_at)
             ON CONFLICT(class_code) DO UPDATE SET
               class_name = excluded.class_name,
               department_id = COALESCE(excluded.department_id, classes.department_id)'
        );
        foreach ($rows as $row) {
            $className = trim((string)$row['class_name']);
            $faculty = trim((string)$row['faculty']);
            if ($className === '') {
                continue;
            }
            $depId = null;
            $key = strtolower($faculty);
            if ($key !== '' && isset($deptMap[$key])) {
                $depId = $deptMap[$key];
            }
            $insClass->execute([
                ':class_code' => $className,
                ':class_name' => $className,
                ':department_id' => $depId,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Sync students references
    if (tableExists($pdo, 'students')) {
        $classMap = [];
        $classRows = $pdo->query('SELECT id, class_code FROM classes')->fetchAll();
        foreach ($classRows as $row) {
            $classMap[strtolower(trim((string)$row['class_code']))] = (int)$row['id'];
        }

        $rows = $pdo->query("SELECT id, student_code, class_name FROM students")->fetchAll();
        $upd = $pdo->prepare('UPDATE students SET account_id = :account_id, class_id = :class_id WHERE id = :id');
        foreach ($rows as $row) {
            $studentCode = trim((string)$row['student_code']);
            if ($studentCode === '') {
                continue;
            }
            $accStmt = $pdo->prepare('SELECT id FROM accounts WHERE lower(username)=lower(:username) LIMIT 1');
            $accStmt->execute([':username' => $studentCode]);
            $accId = $accStmt->fetchColumn();

            $classId = null;
            $classKey = strtolower(trim((string)$row['class_name']));
            if ($classKey !== '' && isset($classMap[$classKey])) {
                $classId = $classMap[$classKey];
            }

            $upd->execute([
                ':account_id' => $accId !== false ? (int)$accId : null,
                ':class_id' => $classId,
                ':id' => (int)$row['id'],
            ]);
        }
    }

    // Sync teachers references
    if (tableExists($pdo, 'teachers')) {
        $rows = $pdo->query("SELECT id, teacher_code, department FROM teachers")->fetchAll();
        $upd = $pdo->prepare('UPDATE teachers SET account_id = :account_id, department_id = :department_id WHERE id = :id');
        foreach ($rows as $row) {
            $teacherCode = trim((string)$row['teacher_code']);
            if ($teacherCode === '') {
                continue;
            }
            $accStmt = $pdo->prepare('SELECT id FROM accounts WHERE lower(username)=lower(:username) LIMIT 1');
            $accStmt->execute([':username' => $teacherCode]);
            $accId = $accStmt->fetchColumn();

            $depId = null;
            $depKey = strtolower(trim((string)$row['department']));
            if ($depKey !== '' && isset($deptMap[$depKey])) {
                $depId = $deptMap[$depKey];
            }

            $upd->execute([
                ':account_id' => $accId !== false ? (int)$accId : null,
                ':department_id' => $depId,
                ':id' => (int)$row['id'],
            ]);
        }
    }

    // Normalize classrooms rows
    if (tableExists($pdo, 'classrooms')) {
        $rows = $pdo->query('SELECT id, name, room_name, room_code, building, description, avatar, created, updated, created_at, updated_at FROM classrooms')->fetchAll();
        $upd = $pdo->prepare(
            'UPDATE classrooms
             SET room_code = :room_code,
                 room_name = :room_name,
                 building = :building,
                 description = :description,
                 avatar = :avatar,
                 created_at = COALESCE(created_at, :created_at),
                 updated_at = COALESCE(updated_at, :updated_at)
             WHERE id = :id'
        );
        foreach ($rows as $row) {
            $legacyName = trim((string)($row['name'] ?? ''));
            $roomName = trim((string)($row['room_name'] ?? ''));
            $code = trim((string)($row['room_code'] ?? ''));
            if ($roomName === '') {
                $roomName = $legacyName;
            }
            if ($code === '') {
                $base = $roomName !== '' ? $roomName : ('ROOM' . (string)$row['id']);
                $code = normalizeCode($base, 'R');
            }
            $upd->execute([
                ':room_code' => $code,
                ':room_name' => $roomName,
                ':building' => trim((string)($row['building'] ?? '')),
                ':description' => trim((string)($row['description'] ?? '')),
                ':avatar' => trim((string)($row['avatar'] ?? '')),
                ':created_at' => (string)($row['created_at'] ?? $row['created'] ?? date('Y-m-d H:i:s')),
                ':updated_at' => (string)($row['updated_at'] ?? $row['updated'] ?? date('Y-m-d H:i:s')),
                ':id' => (int)$row['id'],
            ]);
        }
    }

    // Sync courses.department_id
    if (tableExists($pdo, 'courses') && columnExists($pdo, 'courses', 'department')) {
        $rows = $pdo->query('SELECT id, department FROM courses')->fetchAll();
        $upd = $pdo->prepare('UPDATE courses SET department_id = :department_id WHERE id = :id');
        foreach ($rows as $row) {
            $depKey = strtolower(trim((string)$row['department']));
            $depId = $depKey !== '' && isset($deptMap[$depKey]) ? $deptMap[$depKey] : null;
            $upd->execute([':department_id' => $depId, ':id' => (int)$row['id']]);
        }
    }

    // Build sections from existing courses (1 course => 1 default section)
    if (tableExists($pdo, 'courses')) {
        $courses = $pdo->query('SELECT * FROM courses')->fetchAll();
        $insSection = $pdo->prepare(
            'INSERT INTO course_sections (
                section_code, course_id, teacher_id, classroom_id, schedule, semester, academic_year, max_students, created_at
             ) VALUES (
                :section_code, :course_id, :teacher_id, :classroom_id, :schedule, :semester, :academic_year, :max_students, :created_at
             )
             ON CONFLICT(section_code) DO UPDATE SET
               teacher_id = excluded.teacher_id,
               classroom_id = excluded.classroom_id,
               schedule = excluded.schedule,
               max_students = excluded.max_students'
        );

        foreach ($courses as $course) {
            $courseId = (int)$course['id'];
            $courseCode = trim((string)($course['course_code'] ?? ''));
            if ($courseId <= 0 || $courseCode === '') {
                continue;
            }

            $sectionCode = $courseCode . '-01';

            $teacherId = null;
            $teacherCode = trim((string)($course['teacher_code'] ?? ''));
            if ($teacherCode !== '') {
                $stmt = $pdo->prepare('SELECT id FROM teachers WHERE lower(teacher_code)=lower(:teacher_code) LIMIT 1');
                $stmt->execute([':teacher_code' => $teacherCode]);
                $teacherVal = $stmt->fetchColumn();
                if ($teacherVal !== false) {
                    $teacherId = (int)$teacherVal;
                }
            }

            $classroomId = null;
            $firstRoom = splitFirstValue((string)($course['classroom'] ?? ''));
            if ($firstRoom !== '') {
                $stmt = $pdo->prepare('SELECT id FROM classrooms WHERE upper(room_code)=upper(:room_code) LIMIT 1');
                $stmt->execute([':room_code' => $firstRoom]);
                $roomVal = $stmt->fetchColumn();
                if ($roomVal === false) {
                    $roomCode = normalizeCode($firstRoom, 'R');
                    $insertRoom = $pdo->prepare(
                        'INSERT OR IGNORE INTO classrooms (room_code, room_name, building, description, avatar, created_at, updated_at)
                         VALUES (:room_code, :room_name, :building, :description, :avatar, :created_at, :updated_at)'
                    );
                    $insertRoom->execute([
                        ':room_code' => $roomCode,
                        ':room_name' => $firstRoom,
                        ':building' => '',
                        ':description' => '',
                        ':avatar' => '',
                        ':created_at' => date('Y-m-d H:i:s'),
                        ':updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $stmt = $pdo->prepare('SELECT id FROM classrooms WHERE upper(room_code)=upper(:room_code) LIMIT 1');
                    $stmt->execute([':room_code' => $roomCode]);
                    $roomVal = $stmt->fetchColumn();
                }
                if ($roomVal !== false) {
                    $classroomId = (int)$roomVal;
                }
            }

            $insSection->execute([
                ':section_code' => $sectionCode,
                ':course_id' => $courseId,
                ':teacher_id' => $teacherId,
                ':classroom_id' => $classroomId,
                ':schedule' => (string)($course['schedule'] ?? ''),
                ':semester' => null,
                ':academic_year' => null,
                ':max_students' => isset($course['max_students']) ? (int)$course['max_students'] : null,
                ':created_at' => (string)($course['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    // Enrollments from course_enrollments
    if (tableExists($pdo, 'course_enrollments')) {
        $rows = $pdo->query('SELECT course_id, student_code, created_at FROM course_enrollments')->fetchAll();
        $ins = $pdo->prepare(
            'INSERT OR IGNORE INTO enrollments (student_id, course_section_id, enrolled_at)
             VALUES (:student_id, :course_section_id, :enrolled_at)'
        );
        foreach ($rows as $row) {
            $courseId = (int)$row['course_id'];
            $studentCode = trim((string)$row['student_code']);
            if ($courseId <= 0 || $studentCode === '') {
                continue;
            }

            $st = $pdo->prepare('SELECT id FROM students WHERE lower(student_code)=lower(:student_code) LIMIT 1');
            $st->execute([':student_code' => $studentCode]);
            $studentId = $st->fetchColumn();
            if ($studentId === false) {
                continue;
            }

            $sec = $pdo->prepare('SELECT id FROM course_sections WHERE course_id = :course_id LIMIT 1');
            $sec->execute([':course_id' => $courseId]);
            $sectionId = $sec->fetchColumn();
            if ($sectionId === false) {
                continue;
            }

            $ins->execute([
                ':student_id' => (int)$studentId,
                ':course_section_id' => (int)$sectionId,
                ':enrolled_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    // Scores from course_scores
    if (tableExists($pdo, 'course_scores')) {
        $rows = $pdo->query('SELECT course_id, student_code, cc, gk, ck, updated_at FROM course_scores')->fetchAll();
        $ins = $pdo->prepare(
            'INSERT INTO scores (student_id, course_section_id, cc, gk, ck, updated_at)
             VALUES (:student_id, :course_section_id, :cc, :gk, :ck, :updated_at)
             ON CONFLICT(student_id, course_section_id) DO UPDATE SET
               cc = excluded.cc, gk = excluded.gk, ck = excluded.ck, updated_at = excluded.updated_at'
        );
        foreach ($rows as $row) {
            $courseId = (int)$row['course_id'];
            $studentCode = trim((string)$row['student_code']);
            if ($courseId <= 0 || $studentCode === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM students WHERE lower(student_code)=lower(:student_code) LIMIT 1');
            $st->execute([':student_code' => $studentCode]);
            $studentId = $st->fetchColumn();
            if ($studentId === false) {
                continue;
            }
            $sec = $pdo->prepare('SELECT id FROM course_sections WHERE course_id = :course_id LIMIT 1');
            $sec->execute([':course_id' => $courseId]);
            $sectionId = $sec->fetchColumn();
            if ($sectionId === false) {
                continue;
            }
            $ins->execute([
                ':student_id' => (int)$studentId,
                ':course_section_id' => (int)$sectionId,
                ':cc' => $row['cc'],
                ':gk' => $row['gk'],
                ':ck' => $row['ck'],
                ':updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    // Normalize devices existing rows
    if (tableExists($pdo, 'devices')) {
        $rows = $pdo->query('SELECT id, serial, name, device_name, avatar, description, status, created, updated, created_at, updated_at FROM devices')->fetchAll();
        $upd = $pdo->prepare(
            'UPDATE devices
             SET device_code = :device_code,
                 device_name = :device_name,
                 serial = :serial,
                 avatar = :avatar,
                 description = :description,
                 status = :status,
                 created_at = COALESCE(created_at, :created_at),
                 updated_at = COALESCE(updated_at, :updated_at)
             WHERE id = :id'
        );
        foreach ($rows as $row) {
            $serial = trim((string)($row['serial'] ?? ''));
            $legacyName = trim((string)($row['name'] ?? ''));
            $deviceName = trim((string)($row['device_name'] ?? ''));
            if ($deviceName === '') {
                $deviceName = $legacyName;
            }
            $code = trim((string)($row['device_code'] ?? ''));
            if ($code === '') {
                $base = $serial !== '' ? $serial : ($deviceName !== '' ? $deviceName : ('DEV' . (string)$row['id']));
                $code = normalizeCode($base, 'D');
            }
            $upd->execute([
                ':device_code' => $code,
                ':device_name' => $deviceName,
                ':serial' => $serial !== '' ? $serial : null,
                ':avatar' => trim((string)($row['avatar'] ?? '')),
                ':description' => trim((string)($row['description'] ?? '')),
                ':status' => trim((string)($row['status'] ?? 'available')),
                ':created_at' => (string)($row['created_at'] ?? $row['created'] ?? date('Y-m-d H:i:s')),
                ':updated_at' => (string)($row['updated_at'] ?? $row['updated'] ?? date('Y-m-d H:i:s')),
                ':id' => (int)$row['id'],
            ]);
        }
    }

    // Copy old transactions => device_transactions
    if (tableExists($pdo, 'transactions')) {
        $rows = $pdo->query('SELECT * FROM transactions')->fetchAll();
        $ins = $pdo->prepare(
            'INSERT OR IGNORE INTO device_transactions (
                id, account_id, teacher_id, device_id, classroom_id, transaction_type, comment,
                planned_start_at, planned_end_at, returned_at, created_at, updated_at
             ) VALUES (
                :id, :account_id, :teacher_id, :device_id, :classroom_id, :transaction_type, :comment,
                :planned_start_at, :planned_end_at, :returned_at, :created_at, :updated_at
             )'
        );
        foreach ($rows as $row) {
            $accountId = resolveForeignId($pdo, 'accounts', $row['user_id'] ?? null);
            $teacherId = resolveForeignId($pdo, 'teachers', $row['teacher_id'] ?? null);
            $deviceId = resolveForeignId($pdo, 'devices', $row['device_id'] ?? null);
            $classroomId = resolveForeignId($pdo, 'classrooms', $row['classroom_id'] ?? null);

            $ins->execute([
                ':id' => (int)$row['id'],
                ':account_id' => $accountId,
                ':teacher_id' => $teacherId,
                ':device_id' => $deviceId,
                ':classroom_id' => $classroomId,
                ':transaction_type' => strtoupper((string)($row['type'] ?? 'BORROW')),
                ':comment' => (string)($row['comment'] ?? ''),
                ':planned_start_at' => (string)($row['start_transaction_plan'] ?? ''),
                ':planned_end_at' => (string)($row['end_transaction_plan'] ?? ''),
                ':returned_at' => (string)($row['returned_date'] ?? ''),
                ':created_at' => (string)($row['created_at'] ?? $row['created'] ?? date('Y-m-d H:i:s')),
                ':updated_at' => (string)($row['updated'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    $pdo->commit();
    echo "Migration normalized schema completed.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
