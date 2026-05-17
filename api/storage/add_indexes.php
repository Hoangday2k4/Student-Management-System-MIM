<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$indexes = [
    'CREATE INDEX IF NOT EXISTS idx_sinhvien_trang_thai ON SinhVien(TrangThai)',
    'CREATE INDEX IF NOT EXISTS idx_sinhvien_ma_lop     ON SinhVien(MaLop)',
    'CREATE INDEX IF NOT EXISTS idx_sinhvien_ma_nganh   ON SinhVien(MaNganh)',
    'CREATE INDEX IF NOT EXISTS idx_sinhvien_created_at ON SinhVien(CreatedAt DESC)',
    'CREATE INDEX IF NOT EXISTS idx_ketqua_ma_sv        ON KetQuaHocTap(MaSV)',
    'CREATE INDEX IF NOT EXISTS idx_ketqua_ma_lhp       ON KetQuaHocTap(MaLHP)',
    'CREATE INDEX IF NOT EXISTS idx_giangvien_trang_thai ON GiangVien(TrangThai)',
    'CREATE INDEX IF NOT EXISTS idx_lophocphan_ma_mon   ON LopHocPhan(MaMon)',
    'CREATE INDEX IF NOT EXISTS idx_lophocphan_ma_gv    ON LopHocPhan(MaGV)',
];

foreach ($indexes as $sql) {
    $pdo->exec($sql);
    echo "OK: $sql\n";
}

echo "\nDone. Indexes created.\n";
