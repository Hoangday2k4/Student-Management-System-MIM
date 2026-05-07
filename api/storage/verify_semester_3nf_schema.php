<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

function norm(string $name): string
{
    return strtolower(trim($name));
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND lower(name)=lower(:name) LIMIT 1");
    $stmt->execute([':name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function getTableInfo(PDO $pdo, string $table): array
{
    return $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getIndexes(PDO $pdo, string $table): array
{
    return $pdo->query("PRAGMA index_list($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getIndexColumns(PDO $pdo, string $indexName): array
{
    $cols = $pdo->query("PRAGMA index_info($indexName)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static fn(array $row): string => (string)$row['name'], $cols);
}

function getFks(PDO $pdo, string $table): array
{
    return $pdo->query("PRAGMA foreign_key_list($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function findUniqueIndex(PDO $pdo, string $table, array $columns): bool
{
    $target = array_map('norm', $columns);
    $idx = getIndexes($pdo, $table);
    foreach ($idx as $item) {
        if ((int)($item['unique'] ?? 0) !== 1) {
            continue;
        }
        $name = (string)$item['name'];
        $cols = array_map('norm', getIndexColumns($pdo, $name));
        if ($cols === $target) {
            return true;
        }
    }
    return false;
}

function findAnyIndex(PDO $pdo, string $table, array $columns): bool
{
    $target = array_map('norm', $columns);
    $idx = getIndexes($pdo, $table);
    foreach ($idx as $item) {
        $name = (string)$item['name'];
        $cols = array_map('norm', getIndexColumns($pdo, $name));
        if ($cols === $target) {
            return true;
        }
    }
    return false;
}

function hasFk(PDO $pdo, string $table, string $fromCol, string $refTable, string $refCol): bool
{
    $fks = getFks($pdo, $table);
    foreach ($fks as $fk) {
        if (
            norm((string)$fk['from']) === norm($fromCol)
            && norm((string)$fk['table']) === norm($refTable)
            && norm((string)$fk['to']) === norm($refCol)
        ) {
            return true;
        }
    }
    return false;
}

function checkDataQuery(PDO $pdo, string $sql): int
{
    return (int)$pdo->query($sql)->fetchColumn();
}

$dbPath = DB_PATH;
if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 10000');
$pdo->exec('PRAGMA foreign_keys = ON');

$requiredTables = [
    'HocKy',
    'Nganh',
    'GiangVien',
    'LopSinhHoat',
    'SinhVien',
    'MonHoc',
    'LopHocPhan',
    'ThoiKhoaBieu',
    'DangKyHoc',
    'KetQuaHocTap',
];

$columnRules = [
    'HocKy' => [
        ['name' => 'MaHocKy', 'notnull' => false, 'pk' => true],
        ['name' => 'TenHocKy', 'notnull' => true, 'pk' => false],
        ['name' => 'NamHoc', 'notnull' => true, 'pk' => false],
        ['name' => 'Ky', 'notnull' => true, 'pk' => false],
    ],
    'Nganh' => [
        ['name' => 'MaNganh', 'notnull' => false, 'pk' => true],
        ['name' => 'TenNganh', 'notnull' => true, 'pk' => false],
    ],
    'GiangVien' => [
        ['name' => 'MaGV', 'notnull' => false, 'pk' => true],
        ['name' => 'HoTen', 'notnull' => true, 'pk' => false],
        ['name' => 'GioiTinh', 'notnull' => true, 'pk' => false],
        ['name' => 'Email', 'notnull' => true, 'pk' => false],
        ['name' => 'TrangThai', 'notnull' => true, 'pk' => false],
    ],
    'LopSinhHoat' => [
        ['name' => 'MaLop', 'notnull' => false, 'pk' => true],
        ['name' => 'TenLop', 'notnull' => true, 'pk' => false],
        ['name' => 'MaNganh', 'notnull' => true, 'pk' => false],
        ['name' => 'NienKhoa', 'notnull' => true, 'pk' => false],
    ],
    'SinhVien' => [
        ['name' => 'MaSV', 'notnull' => false, 'pk' => true],
        ['name' => 'HoTen', 'notnull' => true, 'pk' => false],
        ['name' => 'GioiTinh', 'notnull' => true, 'pk' => false],
        ['name' => 'CCCD', 'notnull' => true, 'pk' => false],
        ['name' => 'Email', 'notnull' => true, 'pk' => false],
        ['name' => 'MaLop', 'notnull' => true, 'pk' => false],
        ['name' => 'TrangThai', 'notnull' => true, 'pk' => false],
    ],
    'MonHoc' => [
        ['name' => 'MaMon', 'notnull' => false, 'pk' => true],
        ['name' => 'TenMon', 'notnull' => true, 'pk' => false],
        ['name' => 'SoTinChi', 'notnull' => true, 'pk' => false],
        ['name' => 'MaNganh', 'notnull' => true, 'pk' => false],
    ],
    'LopHocPhan' => [
        ['name' => 'MaLHP', 'notnull' => false, 'pk' => true],
        ['name' => 'MaMon', 'notnull' => true, 'pk' => false],
        ['name' => 'MaGV', 'notnull' => true, 'pk' => false],
        ['name' => 'MaHocKy', 'notnull' => true, 'pk' => false],
        ['name' => 'SoLuongToiDa', 'notnull' => true, 'pk' => false],
    ],
    'ThoiKhoaBieu' => [
        ['name' => 'Id', 'notnull' => false, 'pk' => true],
        ['name' => 'MaLHP', 'notnull' => true, 'pk' => false],
        ['name' => 'Thu', 'notnull' => true, 'pk' => false],
        ['name' => 'CaHoc', 'notnull' => true, 'pk' => false],
        ['name' => 'PhongHoc', 'notnull' => true, 'pk' => false],
    ],
    'DangKyHoc' => [
        ['name' => 'Id', 'notnull' => false, 'pk' => true],
        ['name' => 'MaSV', 'notnull' => true, 'pk' => false],
        ['name' => 'MaLHP', 'notnull' => true, 'pk' => false],
        ['name' => 'TrangThai', 'notnull' => true, 'pk' => false],
    ],
    'KetQuaHocTap' => [
        ['name' => 'Id', 'notnull' => false, 'pk' => true],
        ['name' => 'MaSV', 'notnull' => true, 'pk' => false],
        ['name' => 'MaLHP', 'notnull' => true, 'pk' => false],
        ['name' => 'LanHoc', 'notnull' => true, 'pk' => false],
    ],
];

$uniqueRules = [
    ['table' => 'GiangVien', 'columns' => ['Email']],
    ['table' => 'SinhVien', 'columns' => ['CCCD']],
    ['table' => 'SinhVien', 'columns' => ['Email']],
    ['table' => 'ThoiKhoaBieu', 'columns' => ['MaLHP', 'Thu', 'CaHoc']],
    ['table' => 'ThoiKhoaBieu', 'columns' => ['PhongHoc', 'Thu', 'CaHoc']],
    ['table' => 'DangKyHoc', 'columns' => ['MaSV', 'MaLHP']],
    ['table' => 'KetQuaHocTap', 'columns' => ['MaSV', 'MaLHP', 'LanHoc']],
    ['table' => 'HocKy', 'columns' => ['NamHoc', 'Ky']],
];

$indexRules = [
    ['table' => 'LopSinhHoat', 'columns' => ['MaNganh']],
    ['table' => 'LopSinhHoat', 'columns' => ['MaGV_CoVan']],
    ['table' => 'SinhVien', 'columns' => ['MaLop', 'TrangThai']],
    ['table' => 'MonHoc', 'columns' => ['MaNganh']],
    ['table' => 'LopHocPhan', 'columns' => ['MaMon']],
    ['table' => 'LopHocPhan', 'columns' => ['MaGV']],
    ['table' => 'LopHocPhan', 'columns' => ['MaHocKy']],
    ['table' => 'DangKyHoc', 'columns' => ['MaLHP', 'TrangThai']],
];

$fkRules = [
    ['table' => 'LopSinhHoat', 'from' => 'MaNganh', 'refTable' => 'Nganh', 'refCol' => 'MaNganh'],
    ['table' => 'MonHoc', 'from' => 'MaNganh', 'refTable' => 'Nganh', 'refCol' => 'MaNganh'],
    ['table' => 'LopSinhHoat', 'from' => 'MaGV_CoVan', 'refTable' => 'GiangVien', 'refCol' => 'MaGV'],
    ['table' => 'LopHocPhan', 'from' => 'MaGV', 'refTable' => 'GiangVien', 'refCol' => 'MaGV'],
    ['table' => 'SinhVien', 'from' => 'MaLop', 'refTable' => 'LopSinhHoat', 'refCol' => 'MaLop'],
    ['table' => 'LopHocPhan', 'from' => 'MaMon', 'refTable' => 'MonHoc', 'refCol' => 'MaMon'],
    ['table' => 'LopHocPhan', 'from' => 'MaHocKy', 'refTable' => 'HocKy', 'refCol' => 'MaHocKy'],
    ['table' => 'ThoiKhoaBieu', 'from' => 'MaLHP', 'refTable' => 'LopHocPhan', 'refCol' => 'MaLHP'],
    ['table' => 'DangKyHoc', 'from' => 'MaSV', 'refTable' => 'SinhVien', 'refCol' => 'MaSV'],
    ['table' => 'DangKyHoc', 'from' => 'MaLHP', 'refTable' => 'LopHocPhan', 'refCol' => 'MaLHP'],
    ['table' => 'KetQuaHocTap', 'from' => 'MaSV', 'refTable' => 'SinhVien', 'refCol' => 'MaSV'],
    ['table' => 'KetQuaHocTap', 'from' => 'MaLHP', 'refTable' => 'LopHocPhan', 'refCol' => 'MaLHP'],
];

$dataRules = [
    ['label' => 'LopHocPhan missing MaHocKy', 'sql' => 'SELECT COUNT(1) FROM LopHocPhan WHERE MaHocKy IS NULL OR trim(MaHocKy) = ""', 'expect' => 0],
    ['label' => 'LopHocPhan orphan MaHocKy FK', 'sql' => 'SELECT COUNT(1) FROM LopHocPhan l LEFT JOIN HocKy h ON h.MaHocKy = l.MaHocKy WHERE h.MaHocKy IS NULL', 'expect' => 0],
    ['label' => 'DangKyHoc duplicate (MaSV,MaLHP)', 'sql' => 'SELECT COUNT(1) FROM (SELECT MaSV, MaLHP FROM DangKyHoc GROUP BY MaSV, MaLHP HAVING COUNT(*) > 1) t', 'expect' => 0],
    ['label' => 'KetQuaHocTap duplicate (MaSV,MaLHP,LanHoc)', 'sql' => 'SELECT COUNT(1) FROM (SELECT MaSV, MaLHP, LanHoc FROM KetQuaHocTap GROUP BY MaSV, MaLHP, LanHoc HAVING COUNT(*) > 1) t', 'expect' => 0],
];

$results = [];

$check = static function (string $label, bool $pass, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail];
};

foreach ($requiredTables as $table) {
    $check("table_exists::$table", tableExists($pdo, $table), tableExists($pdo, $table) ? 'present' : 'missing');
}

foreach ($columnRules as $table => $rules) {
    if (!tableExists($pdo, $table)) {
        foreach ($rules as $rule) {
            $check("column::$table.{$rule['name']}", false, 'table missing');
        }
        continue;
    }

    $map = [];
    foreach (getTableInfo($pdo, $table) as $col) {
        $map[norm((string)$col['name'])] = $col;
    }

    foreach ($rules as $rule) {
        $colName = (string)$rule['name'];
        $key = norm($colName);
        if (!isset($map[$key])) {
            $check("column::$table.$colName", false, 'column missing');
            continue;
        }

        $meta = $map[$key];
        if (!empty($rule['pk'])) {
            $isPk = ((int)($meta['pk'] ?? 0)) > 0;
            $check("pk::$table.$colName", $isPk, $isPk ? 'pk' : 'not pk');
        }

        if ($rule['notnull']) {
            $isNotNull = ((int)($meta['notnull'] ?? 0)) === 1;
            $check("notnull::$table.$colName", $isNotNull, $isNotNull ? 'NOT NULL' : 'NULL allowed');
        }
    }
}

foreach ($uniqueRules as $rule) {
    $table = (string)$rule['table'];
    $cols = (array)$rule['columns'];
    $ok = tableExists($pdo, $table) && findUniqueIndex($pdo, $table, $cols);
    $check('unique::' . $table . '(' . implode(',', $cols) . ')', $ok, $ok ? 'present' : 'missing');
}

foreach ($indexRules as $rule) {
    $table = (string)$rule['table'];
    $cols = (array)$rule['columns'];
    $ok = tableExists($pdo, $table) && findAnyIndex($pdo, $table, $cols);
    $check('index::' . $table . '(' . implode(',', $cols) . ')', $ok, $ok ? 'present' : 'missing');
}

foreach ($fkRules as $rule) {
    $table = (string)$rule['table'];
    $from = (string)$rule['from'];
    $refTable = (string)$rule['refTable'];
    $refCol = (string)$rule['refCol'];
    $ok = tableExists($pdo, $table) && hasFk($pdo, $table, $from, $refTable, $refCol);
    $check("fk::$table.$from->$refTable.$refCol", $ok, $ok ? 'present' : 'missing');
}

foreach ($dataRules as $rule) {
    $actual = 0;
    $pass = false;
    try {
        $actual = checkDataQuery($pdo, (string)$rule['sql']);
        $pass = $actual === (int)$rule['expect'];
    } catch (Throwable $e) {
        $check('data::' . $rule['label'], false, 'query error: ' . $e->getMessage());
        continue;
    }
    $check('data::' . $rule['label'], $pass, 'actual=' . $actual . ', expected=' . (int)$rule['expect']);
}

$legacyCount = checkDataQuery($pdo, "SELECT COUNT(1) FROM sqlite_master WHERE type='table' AND name LIKE '%_legacy_3nf_%'");
$check('legacy_tables::count', $legacyCount === 0, 'count=' . $legacyCount);

$total = count($results);
$passed = count(array_filter($results, static fn(array $r): bool => $r['pass']));
$failed = $total - $passed;

echo 'DB: ' . $dbPath . PHP_EOL;
echo 'TOTAL_CHECKS=' . $total . PHP_EOL;
echo 'PASSED=' . $passed . PHP_EOL;
echo 'FAILED=' . $failed . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

foreach ($results as $item) {
    $status = $item['pass'] ? 'PASS' : 'FAIL';
    echo '[' . $status . '] ' . $item['label'];
    if ($item['detail'] !== '') {
        echo ' :: ' . $item['detail'];
    }
    echo PHP_EOL;
}

exit($failed === 0 ? 0 : 2);
