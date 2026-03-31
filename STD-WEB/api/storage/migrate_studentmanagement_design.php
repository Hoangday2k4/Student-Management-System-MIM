<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function slugCode(string $value, string $prefix = 'X'): string
{
    $text = strtoupper(trim($value));
    $text = preg_replace('/\s+/', '_', $text) ?? '';
    $text = preg_replace('/[^A-Z0-9_\\-]/', '', $text) ?? '';
    if ($text === '') {
        $text = $prefix;
    }
    if (!preg_match('/^[A-Z]/', $text)) {
        $text = $prefix . $text;
    }
    return $text;
}

function mapGender(?string $gender): string
{
    $g = strtolower(trim((string)$gender));
    if ($g === 'nam' || $g === 'male' || $g === 'm') {
        return 'Nam';
    }
    if ($g === 'nu' || $g === 'nữ' || $g === 'female' || $g === 'f') {
        return 'Nữ';
    }
    return 'Khác';
}

function splitCsvValues(?string $raw): array
{
    $parts = explode(',', (string)$raw);
    $out = [];
    foreach ($parts as $part) {
        $v = trim($part);
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

function parseScheduleSlot(string $item): ?array
{
    $raw = strtoupper(trim($item));
    if (!preg_match('/^T([2-8])-\\((\\d{1,2})-(\\d{1,2})\\)$/', $raw, $m)) {
        return null;
    }
    $thu = (int)$m[1];
    $start = (int)$m[2];
    $end = (int)$m[3];
    if ($start <= 0 || $end <= 0 || $start > $end) {
        return null;
    }
    return [
        'Thu' => $thu,
        'CaHoc' => $start . '-' . $end,
    ];
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 10000');

try {
    $pdo->beginTransaction();

    // 1) CREATE TABLES
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS Nganh (
            MaNganh TEXT PRIMARY KEY,
            TenNganh TEXT NOT NULL,
            MoTa TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS GiangVien (
            MaGV TEXT PRIMARY KEY,
            HoTen TEXT NOT NULL,
            GioiTinh TEXT,
            NgaySinh TEXT,
            Email TEXT UNIQUE NOT NULL,
            SoDienThoai TEXT,
            HocHamHocVi TEXT,
            TrangThai TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS LopSinhHoat (
            MaLop TEXT PRIMARY KEY,
            TenLop TEXT NOT NULL,
            MaNganh TEXT,
            MaGV_CoVan TEXT,
            NienKhoa TEXT,
            FOREIGN KEY(MaNganh) REFERENCES Nganh(MaNganh),
            FOREIGN KEY(MaGV_CoVan) REFERENCES GiangVien(MaGV)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS SinhVien (
            MaSV TEXT PRIMARY KEY,
            HoTen TEXT NOT NULL,
            NgaySinh TEXT,
            GioiTinh TEXT,
            CCCD TEXT UNIQUE,
            DiaChi TEXT,
            SoDienThoai TEXT,
            Email TEXT UNIQUE,
            MaLop TEXT,
            NgayNhapHoc TEXT,
            TrangThai TEXT,
            FOREIGN KEY(MaLop) REFERENCES LopSinhHoat(MaLop)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS MonHoc (
            MaMon TEXT PRIMARY KEY,
            TenMon TEXT NOT NULL,
            SoTinChi INTEGER NOT NULL,
            LoaiMon TEXT,
            MaNganh TEXT,
            FOREIGN KEY(MaNganh) REFERENCES Nganh(MaNganh)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS LopHocPhan (
            MaLHP TEXT PRIMARY KEY,
            MaMon TEXT,
            MaGV TEXT,
            HocKy INTEGER,
            NamHoc TEXT,
            SoLuongToiDa INTEGER,
            FOREIGN KEY(MaMon) REFERENCES MonHoc(MaMon),
            FOREIGN KEY(MaGV) REFERENCES GiangVien(MaGV)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ThoiKhoaBieu (
            Id INTEGER PRIMARY KEY AUTOINCREMENT,
            MaLHP TEXT,
            Thu INTEGER,
            CaHoc TEXT,
            PhongHoc TEXT,
            FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS KetQuaHocTap (
            Id INTEGER PRIMARY KEY AUTOINCREMENT,
            MaSV TEXT,
            MaLHP TEXT,
            DiemChuyenCan REAL,
            DiemGiuaKy REAL,
            DiemCuoiKy REAL,
            UNIQUE(MaSV, MaLHP),
            FOREIGN KEY(MaSV) REFERENCES SinhVien(MaSV),
            FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP)
        )'
    );

    // 2) BACKFILL NGANH
    $departments = [];
    if (tableExists($pdo, 'departments')) {
        $rows = $pdo->query('SELECT department_code, department_name FROM departments')->fetchAll();
        foreach ($rows as $r) {
            $code = trim((string)($r['department_code'] ?? ''));
            $name = trim((string)($r['department_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($code === '') {
                $code = slugCode($name, 'N');
            }
            $departments[$name] = ['MaNganh' => $code, 'TenNganh' => $name];
        }
    } elseif (tableExists($pdo, 'teachers')) {
        $rows = $pdo->query("SELECT DISTINCT trim(department) AS name FROM teachers WHERE trim(ifnull(department,'')) <> ''")->fetchAll();
        foreach ($rows as $r) {
            $name = trim((string)$r['name']);
            if ($name === '') {
                continue;
            }
            $departments[$name] = ['MaNganh' => slugCode($name, 'N'), 'TenNganh' => $name];
        }
    }

    $insNganh = $pdo->prepare(
        'INSERT INTO Nganh (MaNganh, TenNganh, MoTa)
         VALUES (:MaNganh, :TenNganh, :MoTa)
         ON CONFLICT(MaNganh) DO UPDATE SET TenNganh = excluded.TenNganh'
    );
    foreach ($departments as $dep) {
        $insNganh->execute([
            ':MaNganh' => $dep['MaNganh'],
            ':TenNganh' => $dep['TenNganh'],
            ':MoTa' => null,
        ]);
    }

    $nganhByName = [];
    $rowsN = $pdo->query('SELECT MaNganh, TenNganh FROM Nganh')->fetchAll();
    foreach ($rowsN as $r) {
        $nganhByName[strtolower(trim((string)$r['TenNganh']))] = (string)$r['MaNganh'];
    }

    // 3) BACKFILL GIANGVIEN
    if (tableExists($pdo, 'teachers')) {
        $teacherRows = $pdo->query('SELECT * FROM teachers')->fetchAll();
        $insGV = $pdo->prepare(
            'INSERT INTO GiangVien (
                MaGV, HoTen, GioiTinh, NgaySinh, Email, SoDienThoai, HocHamHocVi, TrangThai
             ) VALUES (
                :MaGV, :HoTen, :GioiTinh, :NgaySinh, :Email, :SoDienThoai, :HocHamHocVi, :TrangThai
             )
             ON CONFLICT(MaGV) DO UPDATE SET
                HoTen = excluded.HoTen,
                GioiTinh = excluded.GioiTinh,
                NgaySinh = excluded.NgaySinh,
                Email = excluded.Email,
                SoDienThoai = excluded.SoDienThoai,
                TrangThai = excluded.TrangThai'
        );

        foreach ($teacherRows as $t) {
            $maGV = trim((string)($t['teacher_code'] ?? ''));
            if ($maGV === '') {
                continue;
            }
            $email = trim((string)($t['email'] ?? ''));
            if ($email === '') {
                $email = strtolower($maGV) . '@local';
            }
            $insGV->execute([
                ':MaGV' => $maGV,
                ':HoTen' => trim((string)($t['full_name'] ?? '')),
                ':GioiTinh' => mapGender((string)($t['gender'] ?? '')),
                ':NgaySinh' => trim((string)($t['date_of_birth'] ?? '')) ?: null,
                ':Email' => $email,
                ':SoDienThoai' => trim((string)($t['phone'] ?? '')) ?: null,
                ':HocHamHocVi' => null,
                ':TrangThai' => trim((string)($t['status'] ?? 'DANG_DAY')) ?: 'DANG_DAY',
            ]);
        }
    }

    // 4) BACKFILL LOPSINHHOAT
    if (tableExists($pdo, 'classes')) {
        $classRows = $pdo->query(
            'SELECT c.class_code, c.class_name, d.department_name
             FROM classes c
             LEFT JOIN departments d ON d.id = c.department_id'
        )->fetchAll();
    } elseif (tableExists($pdo, 'students')) {
        $classRows = $pdo->query("SELECT DISTINCT class_name AS class_code, class_name, faculty AS department_name FROM students")->fetchAll();
    } else {
        $classRows = [];
    }

    $insLSH = $pdo->prepare(
        'INSERT INTO LopSinhHoat (MaLop, TenLop, MaNganh, MaGV_CoVan, NienKhoa)
         VALUES (:MaLop, :TenLop, :MaNganh, :MaGV_CoVan, :NienKhoa)
         ON CONFLICT(MaLop) DO UPDATE SET
            TenLop = excluded.TenLop,
            MaNganh = excluded.MaNganh'
    );
    foreach ($classRows as $c) {
        $maLop = trim((string)($c['class_code'] ?? ''));
        if ($maLop === '') {
            continue;
        }
        $depName = strtolower(trim((string)($c['department_name'] ?? '')));
        $maNganh = $nganhByName[$depName] ?? null;
        $insLSH->execute([
            ':MaLop' => $maLop,
            ':TenLop' => trim((string)($c['class_name'] ?? $maLop)),
            ':MaNganh' => $maNganh,
            ':MaGV_CoVan' => null,
            ':NienKhoa' => null,
        ]);
    }

    // 5) BACKFILL SINHVIEN
    if (tableExists($pdo, 'students')) {
        $studentRows = $pdo->query('SELECT * FROM students')->fetchAll();
        $insSV = $pdo->prepare(
            'INSERT INTO SinhVien (
                MaSV, HoTen, NgaySinh, GioiTinh, CCCD, DiaChi, SoDienThoai, Email, MaLop, NgayNhapHoc, TrangThai
             ) VALUES (
                :MaSV, :HoTen, :NgaySinh, :GioiTinh, :CCCD, :DiaChi, :SoDienThoai, :Email, :MaLop, :NgayNhapHoc, :TrangThai
             )
             ON CONFLICT(MaSV) DO UPDATE SET
                HoTen = excluded.HoTen,
                NgaySinh = excluded.NgaySinh,
                GioiTinh = excluded.GioiTinh,
                SoDienThoai = excluded.SoDienThoai,
                Email = excluded.Email,
                MaLop = excluded.MaLop,
                TrangThai = excluded.TrangThai'
        );
        foreach ($studentRows as $s) {
            $maSV = trim((string)($s['student_code'] ?? ''));
            if ($maSV === '') {
                continue;
            }
            $email = trim((string)($s['email'] ?? ''));
            if ($email === '') {
                $email = strtolower($maSV) . '@local';
            }
            $insSV->execute([
                ':MaSV' => $maSV,
                ':HoTen' => trim((string)($s['full_name'] ?? '')),
                ':NgaySinh' => trim((string)($s['date_of_birth'] ?? '')) ?: null,
                ':GioiTinh' => mapGender((string)($s['gender'] ?? '')),
                ':CCCD' => null,
                ':DiaChi' => null,
                ':SoDienThoai' => trim((string)($s['phone'] ?? '')) ?: null,
                ':Email' => $email,
                ':MaLop' => trim((string)($s['class_name'] ?? '')) ?: null,
                ':NgayNhapHoc' => null,
                ':TrangThai' => trim((string)($s['status'] ?? 'DANG_HOC')) ?: 'DANG_HOC',
            ]);
        }
    }

    // 6) BACKFILL MONHOC + LOPHOCPHAN + TKB
    if (tableExists($pdo, 'courses')) {
        $courseRows = $pdo->query('SELECT * FROM courses')->fetchAll();

        $insMon = $pdo->prepare(
            'INSERT INTO MonHoc (MaMon, TenMon, SoTinChi, LoaiMon, MaNganh)
             VALUES (:MaMon, :TenMon, :SoTinChi, :LoaiMon, :MaNganh)
             ON CONFLICT(MaMon) DO UPDATE SET
               TenMon = excluded.TenMon,
               SoTinChi = excluded.SoTinChi,
               MaNganh = excluded.MaNganh'
        );

        $insLHP = $pdo->prepare(
            'INSERT INTO LopHocPhan (MaLHP, MaMon, MaGV, HocKy, NamHoc, SoLuongToiDa)
             VALUES (:MaLHP, :MaMon, :MaGV, :HocKy, :NamHoc, :SoLuongToiDa)
             ON CONFLICT(MaLHP) DO UPDATE SET
               MaMon = excluded.MaMon,
               MaGV = excluded.MaGV,
               SoLuongToiDa = excluded.SoLuongToiDa'
        );

        $insTKB = $pdo->prepare(
            'INSERT INTO ThoiKhoaBieu (MaLHP, Thu, CaHoc, PhongHoc)
             VALUES (:MaLHP, :Thu, :CaHoc, :PhongHoc)'
        );

        $delTKB = $pdo->prepare('DELETE FROM ThoiKhoaBieu WHERE MaLHP = :MaLHP');

        foreach ($courseRows as $c) {
            $maMon = trim((string)($c['course_code'] ?? ''));
            if ($maMon === '') {
                continue;
            }
            $depName = strtolower(trim((string)($c['department'] ?? '')));
            $maNganh = $nganhByName[$depName] ?? null;
            $maLHP = $maMon . '-01';

            $insMon->execute([
                ':MaMon' => $maMon,
                ':TenMon' => trim((string)($c['course_name'] ?? '')),
                ':SoTinChi' => (int)($c['credits'] ?? 0),
                ':LoaiMon' => 'BAT_BUOC',
                ':MaNganh' => $maNganh,
            ]);

            $insLHP->execute([
                ':MaLHP' => $maLHP,
                ':MaMon' => $maMon,
                ':MaGV' => trim((string)($c['teacher_code'] ?? '')) ?: null,
                ':HocKy' => null,
                ':NamHoc' => null,
                ':SoLuongToiDa' => $c['max_students'] !== null ? (int)$c['max_students'] : null,
            ]);

            // Refresh timetable for each LHP from text schedule + classroom
            $delTKB->execute([':MaLHP' => $maLHP]);
            $schedules = splitCsvValues((string)($c['schedule'] ?? ''));
            $rooms = splitCsvValues((string)($c['classroom'] ?? ''));

            foreach ($schedules as $idx => $item) {
                $slot = parseScheduleSlot($item);
                if (!$slot) {
                    continue;
                }
                $room = $rooms[$idx] ?? ($rooms[0] ?? null);
                $insTKB->execute([
                    ':MaLHP' => $maLHP,
                    ':Thu' => $slot['Thu'],
                    ':CaHoc' => $slot['CaHoc'],
                    ':PhongHoc' => $room,
                ]);
            }
        }
    }

    // 7) BACKFILL KETQUAHOCTAP
    $insKQ = $pdo->prepare(
        'INSERT INTO KetQuaHocTap (MaSV, MaLHP, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy)
         VALUES (:MaSV, :MaLHP, :DiemChuyenCan, :DiemGiuaKy, :DiemCuoiKy)
         ON CONFLICT(MaSV, MaLHP) DO UPDATE SET
            DiemChuyenCan = excluded.DiemChuyenCan,
            DiemGiuaKy = excluded.DiemGiuaKy,
            DiemCuoiKy = excluded.DiemCuoiKy'
    );

    if (tableExists($pdo, 'scores') && tableExists($pdo, 'course_sections')) {
        $rows = $pdo->query(
            'SELECT st.student_code AS MaSV, c.course_code || "-01" AS MaLHP, sc.cc, sc.gk, sc.ck
             FROM scores sc
             INNER JOIN students st ON st.id = sc.student_id
             INNER JOIN course_sections cs ON cs.id = sc.course_section_id
             INNER JOIN courses c ON c.id = cs.course_id'
        )->fetchAll();
        foreach ($rows as $r) {
            $insKQ->execute([
                ':MaSV' => (string)$r['MaSV'],
                ':MaLHP' => (string)$r['MaLHP'],
                ':DiemChuyenCan' => $r['cc'],
                ':DiemGiuaKy' => $r['gk'],
                ':DiemCuoiKy' => $r['ck'],
            ]);
        }
    } elseif (tableExists($pdo, 'course_scores')) {
        $rows = $pdo->query(
            'SELECT student_code AS MaSV, (SELECT course_code FROM courses c WHERE c.id = s.course_id) || "-01" AS MaLHP, cc, gk, ck
             FROM course_scores s'
        )->fetchAll();
        foreach ($rows as $r) {
            if (trim((string)($r['MaSV'] ?? '')) === '' || trim((string)($r['MaLHP'] ?? '')) === '') {
                continue;
            }
            $insKQ->execute([
                ':MaSV' => (string)$r['MaSV'],
                ':MaLHP' => (string)$r['MaLHP'],
                ':DiemChuyenCan' => $r['cc'],
                ':DiemGiuaKy' => $r['gk'],
                ':DiemCuoiKy' => $r['ck'],
            ]);
        }
    }

    $pdo->commit();
    echo "Migration theo thiet ke StudentManagement da hoan tat.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
