-- Student Management: Semester-based 3NF delta migration (SQLite)
-- Target: normalize LopHocPhan.MaHocKy -> HocKy.MaHocKy
-- Safety: run on backup first; this is a one-time migration script.

PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;

-- 1) Create HocKy
CREATE TABLE IF NOT EXISTS HocKy (
  MaHocKy TEXT PRIMARY KEY,
  TenHocKy TEXT NOT NULL,
  NamHoc TEXT NOT NULL,
  Ky INTEGER NOT NULL CHECK (Ky IN (1,2,3)),
  TrangThai TEXT NOT NULL DEFAULT 'ACTIVE',
  IsCurrent INTEGER NOT NULL DEFAULT 0,
  CreatedAt TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  UpdatedAt TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_hocky_namhoc_ky ON HocKy(NamHoc, Ky);
CREATE INDEX IF NOT EXISTS idx_hocky_trangthai ON HocKy(TrangThai);

-- 2) Build old->new semester map from LopHocPhan(HocKy,NamHoc)
DROP TABLE IF EXISTS _tmp_semester_map;
CREATE TEMP TABLE _tmp_semester_map (
  HocKyOld INTEGER,
  NamHocOld TEXT,
  MaHocKyNew TEXT,
  PRIMARY KEY (HocKyOld, NamHocOld)
);

WITH pairs AS (
  SELECT DISTINCT
    COALESCE(HocKy, 1) AS HocKyOld,
    COALESCE(NULLIF(trim(NamHoc), ''), 'UNKNOWN') AS NamHocOld
  FROM LopHocPhan
), mapped AS (
  SELECT
    HocKyOld,
    NamHocOld,
    CASE
      WHEN NamHocOld GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9]' AND HocKyOld IN (1,2,3)
        THEN substr(NamHocOld, 3, 2) || CAST(HocKyOld AS TEXT)
      ELSE 'TMP' || printf('%03d', ROW_NUMBER() OVER (ORDER BY NamHocOld, HocKyOld))
    END AS MaHocKyNew
  FROM pairs
)
INSERT OR REPLACE INTO _tmp_semester_map(HocKyOld, NamHocOld, MaHocKyNew)
SELECT HocKyOld, NamHocOld, MaHocKyNew FROM mapped;

-- 3) Seed HocKy from mapping
INSERT OR IGNORE INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent)
SELECT
  m.MaHocKyNew,
  'HK' || CAST(m.HocKyOld AS TEXT),
  m.NamHocOld,
  CASE WHEN m.HocKyOld IN (1,2,3) THEN m.HocKyOld ELSE 1 END,
  'ACTIVE',
  0
FROM _tmp_semester_map m;

-- 4) Rebuild LopHocPhan with MaHocKy + TrangThaiDangKy + FK
DROP TABLE IF EXISTS LopHocPhan_new;
CREATE TABLE LopHocPhan_new (
  MaLHP TEXT PRIMARY KEY,
  MaMon TEXT NOT NULL,
  MaGV TEXT NOT NULL,
  MaHocKy TEXT NOT NULL,
  SoLuongToiDa INTEGER NOT NULL,
  TrongSoCC REAL DEFAULT 0,
  TrongSoGK REAL DEFAULT 0,
  TrongSoCK REAL DEFAULT 0,
  TrangThaiDangKy TEXT NOT NULL DEFAULT 'DRAFT',
  CreatedAt TEXT,
  UpdatedAt TEXT,
  FOREIGN KEY(MaMon) REFERENCES MonHoc(MaMon),
  FOREIGN KEY(MaGV) REFERENCES GiangVien(MaGV),
  FOREIGN KEY(MaHocKy) REFERENCES HocKy(MaHocKy)
);

INSERT INTO LopHocPhan_new (
  MaLHP, MaMon, MaGV, MaHocKy, SoLuongToiDa,
  TrongSoCC, TrongSoGK, TrongSoCK, TrangThaiDangKy, CreatedAt, UpdatedAt
)
SELECT
  l.MaLHP,
  l.MaMon,
  l.MaGV,
  COALESCE(m.MaHocKyNew, 'TMP001') AS MaHocKy,
  COALESCE(l.SoLuongToiDa, 0) AS SoLuongToiDa,
  COALESCE(l.TrongSoCC, 0),
  COALESCE(l.TrongSoGK, 0),
  COALESCE(l.TrongSoCK, 0),
  'DRAFT',
  l.CreatedAt,
  datetime('now', 'localtime')
FROM LopHocPhan l
LEFT JOIN _tmp_semester_map m
  ON m.HocKyOld = COALESCE(l.HocKy, 1)
 AND m.NamHocOld = COALESCE(NULLIF(trim(l.NamHoc), ''), 'UNKNOWN');

DROP TABLE LopHocPhan;
ALTER TABLE LopHocPhan_new RENAME TO LopHocPhan;

CREATE INDEX IF NOT EXISTS idx_lophocphan_mamon ON LopHocPhan(MaMon);
CREATE INDEX IF NOT EXISTS idx_lophocphan_magv ON LopHocPhan(MaGV);
CREATE INDEX IF NOT EXISTS idx_lophocphan_mahocky ON LopHocPhan(MaHocKy);
CREATE INDEX IF NOT EXISTS idx_lophocphan_trangthai_dk ON LopHocPhan(TrangThaiDangKy);

-- 5) Ensure DangKyHoc exists and backfill from KetQuaHocTap
CREATE TABLE IF NOT EXISTS DangKyHoc (
  Id INTEGER PRIMARY KEY AUTOINCREMENT,
  MaSV TEXT NOT NULL,
  MaLHP TEXT NOT NULL,
  NgayDangKy TEXT,
  TrangThai TEXT NOT NULL,
  CreatedAt TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  UpdatedAt TEXT,
  UNIQUE(MaSV, MaLHP),
  FOREIGN KEY(MaSV) REFERENCES SinhVien(MaSV),
  FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP)
);

CREATE INDEX IF NOT EXISTS idx_dangkyhoc_lhp_status ON DangKyHoc(MaLHP, TrangThai);

INSERT OR IGNORE INTO DangKyHoc (MaSV, MaLHP, NgayDangKy, TrangThai)
SELECT DISTINCT k.MaSV, k.MaLHP, NULL, 'APPROVED'
FROM KetQuaHocTap k
WHERE trim(COALESCE(k.MaSV, '')) <> ''
  AND trim(COALESCE(k.MaLHP, '')) <> '';

-- 6) Add LanHoc to KetQuaHocTap and enforce unique (MaSV, MaLHP, LanHoc)
ALTER TABLE KetQuaHocTap ADD COLUMN LanHoc INTEGER NOT NULL DEFAULT 1;

-- Rebuild to ensure constraints/indexes are explicit and clean
DROP TABLE IF EXISTS KetQuaHocTap_new;
CREATE TABLE KetQuaHocTap_new (
  Id INTEGER PRIMARY KEY AUTOINCREMENT,
  MaSV TEXT NOT NULL,
  MaLHP TEXT NOT NULL,
  LanHoc INTEGER NOT NULL DEFAULT 1,
  DiemChuyenCan REAL,
  DiemGiuaKy REAL,
  DiemCuoiKy REAL,
  FOREIGN KEY(MaSV) REFERENCES SinhVien(MaSV),
  FOREIGN KEY(MaLHP) REFERENCES LopHocPhan(MaLHP),
  UNIQUE(MaSV, MaLHP, LanHoc)
);

INSERT INTO KetQuaHocTap_new (Id, MaSV, MaLHP, LanHoc, DiemChuyenCan, DiemGiuaKy, DiemCuoiKy)
SELECT
  Id,
  MaSV,
  MaLHP,
  COALESCE(LanHoc, 1),
  DiemChuyenCan,
  DiemGiuaKy,
  DiemCuoiKy
FROM KetQuaHocTap;

DROP TABLE KetQuaHocTap;
ALTER TABLE KetQuaHocTap_new RENAME TO KetQuaHocTap;

CREATE INDEX IF NOT EXISTS idx_ketquahoctap_lhp ON KetQuaHocTap(MaLHP);
CREATE INDEX IF NOT EXISTS idx_ketquahoctap_masv ON KetQuaHocTap(MaSV);

COMMIT;
PRAGMA foreign_keys = ON;

-- =========================
-- Post-migration checks
-- =========================
-- 1) LopHocPhan without semester (must be 0)
SELECT COUNT(1) AS missing_semester
FROM LopHocPhan
WHERE MaHocKy IS NULL OR trim(MaHocKy) = '';

-- 2) Orphan semester FK (must be 0)
SELECT COUNT(1) AS orphan_semester_fk
FROM LopHocPhan l
LEFT JOIN HocKy h ON h.MaHocKy = l.MaHocKy
WHERE h.MaHocKy IS NULL;

-- 3) DangKyHoc duplicates (must be 0 rows)
SELECT MaSV, MaLHP, COUNT(*) AS c
FROM DangKyHoc
GROUP BY MaSV, MaLHP
HAVING COUNT(*) > 1;

-- 4) KetQuaHocTap duplicates by triple key (must be 0 rows)
SELECT MaSV, MaLHP, LanHoc, COUNT(*) AS c
FROM KetQuaHocTap
GROUP BY MaSV, MaLHP, LanHoc
HAVING COUNT(*) > 1;
