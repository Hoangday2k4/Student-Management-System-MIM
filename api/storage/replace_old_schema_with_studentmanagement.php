<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

function mustTableExist(PDO $pdo, string $table): void
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    $stmt->execute([':name' => $table]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException("Thiếu bảng bắt buộc: {$table}. Hãy chạy migrate_studentmanagement_design.php trước.");
    }
}

$dbPath = DB_PATH;
if (!is_file($dbPath)) {
    fwrite(STDERR, "Không tìm thấy DB: {$dbPath}" . PHP_EOL);
    exit(1);
}

$backupPath = dirname($dbPath) . '/ltweb_backup_before_replace_' . date('Ymd_His') . '.sqlite';
if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Không tạo được backup DB." . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    // Verify target schema exists
    foreach (['Nganh', 'GiangVien', 'LopSinhHoat', 'SinhVien', 'MonHoc', 'LopHocPhan', 'ThoiKhoaBieu', 'KetQuaHocTap'] as $table) {
        mustTableExist($pdo, $table);
    }

    $dropTables = [
        'accounts',
        'admins',
        'classes',
        'classrooms',
        'course_enrollments',
        'course_scores',
        'course_sections',
        'courses',
        'departments',
        'device_transactions',
        'devices',
        'enrollments',
        'scores',
        'students',
        'teachers',
        'transactions',
        'users',
    ];

    $pdo->beginTransaction();
    $pdo->exec('PRAGMA foreign_keys = OFF');
    foreach ($dropTables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->commit();

    echo "Đã thay thế schema cũ bằng schema mới thành công." . PHP_EOL;
    echo "Backup: {$backupPath}" . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Replace failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

