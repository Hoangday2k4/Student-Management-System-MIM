<?php
require_once __DIR__ . '/../models/Semester.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../helpers/response.php';

class SemesterController
{
    private function parseAcademicYear(string $namHoc): ?array
    {
        $namHoc = trim($namHoc);
        if (!preg_match('/^(\d{4})-(\d{4})$/', $namHoc, $m)) {
            return null;
        }
        $startYear = (int)$m[1];
        $endYear = (int)$m[2];
        if ($endYear !== $startYear + 1) {
            return null;
        }
        return [$startYear, $endYear];
    }

    private function buildSemesterCode(string $namHoc, int $ky): string
    {
        $parts = $this->parseAcademicYear($namHoc);
        if (!$parts) {
            return '';
        }
        $yy = substr((string)$parts[0], -2);
        return $yy . (string)$ky;
    }

    private function currentIdentity(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $loginId = isset($_SESSION['login_id']) ? (string)$_SESSION['login_id'] : '';
        if ($loginId === '') {
            return null;
        }
        return Admin::findIdentityByLoginId($loginId);
    }

    private function isStaff(array $identity): bool
    {
        return ($identity['account_type'] ?? '') === 'staff';
    }

    private function parseJsonPayload(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return null;
        }
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : null;
    }

    private function validateCreatePayload(array $payload): array
    {
        $maHocKyInput = trim((string)($payload['ma_hoc_ky'] ?? ''));
        $tenHocKy = trim((string)($payload['ten_hoc_ky'] ?? ''));
        $namHoc = trim((string)($payload['nam_hoc'] ?? ''));
        $ky = (int)($payload['ky'] ?? 0);
        $trangThai = strtoupper(trim((string)($payload['trang_thai'] ?? 'ACTIVE')));
        $isCurrent = !empty($payload['is_current']);
        $ghiChu = trim((string)($payload['ghi_chu'] ?? ''));

        $errors = [];
        if ($tenHocKy === '') $errors['ten_hoc_ky'] = 'Hay nhap ten hoc ky.';
        if ($namHoc === '') $errors['nam_hoc'] = 'Hay nhap nam hoc.';
        if (!$this->parseAcademicYear($namHoc)) {
            $errors['nam_hoc'] = 'Nam hoc phai theo dinh dang YYYY-YYYY va lien tiep (vi du 2024-2025).';
        }
        if (!in_array($ky, [1, 2, 3], true)) $errors['ky'] = 'Ky chi nhan 1, 2 hoac 3.';
        if (!in_array($trangThai, ['ACTIVE', 'INACTIVE', 'ARCHIVED'], true)) $errors['trang_thai'] = 'Trang thai khong hop le.';

        $maHocKy = '';
        if (!isset($errors['nam_hoc']) && !isset($errors['ky'])) {
            $maHocKy = $this->buildSemesterCode($namHoc, $ky);
            if ($maHocKy === '') {
                $errors['ma_hoc_ky'] = 'Khong the tao ma hoc ky tu nam hoc va ky.';
            } elseif ($maHocKyInput !== '' && strcasecmp($maHocKyInput, $maHocKy) !== 0) {
                $errors['ma_hoc_ky'] = 'Ma hoc ky phai dung theo quy tac YYK (vi du 241, 242, 251).';
            }
        }

        return [
            [
                'ma_hoc_ky' => $maHocKy,
                'ten_hoc_ky' => $tenHocKy,
                'nam_hoc' => $namHoc,
                'ky' => $ky,
                'trang_thai' => $trangThai,
                'is_current' => $isCurrent,
                'ghi_chu' => $ghiChu,
            ],
            $errors,
        ];
    }

    public function index()
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
                return;
            }

            Semester::ensureSchema();

            $rows = Semester::list([
                'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
                'nam_hoc' => isset($_GET['nam_hoc']) ? trim((string)$_GET['nam_hoc']) : '',
                'ky' => isset($_GET['ky']) ? (int)$_GET['ky'] : 0,
                'include_inactive' => isset($_GET['include_inactive']) ? (string)$_GET['include_inactive'] : 'false',
            ]);

            jsonResponse($rows);
        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tai duoc danh sach hoc ky.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function detail()
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
                return;
            }

            Semester::ensureSchema();
            $maHocKy = trim((string)($_GET['ma_hoc_ky'] ?? ''));
            if ($maHocKy === '') {
                jsonResponse(['status' => 'error', 'message' => 'Thieu ma hoc ky.'], 422);
                return;
            }

            $row = Semester::findByCode($maHocKy);
            if (!$row) {
                jsonResponse(['status' => 'error', 'message' => 'Khong tim thay hoc ky.'], 404);
                return;
            }

            jsonResponse(['status' => 'success', 'data' => $row]);
        } catch (Throwable $e) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tai duoc chi tiet hoc ky.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function create()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        Semester::ensureSchema();
        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        [$data, $errors] = $this->validateCreatePayload($payload);
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.', 'fields' => $errors], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Semester::ensureSchema($pdo);
            $pdo->beginTransaction();
            $created = Semester::createWithPdo($pdo, $data);
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => $created], 201);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $msg = (string)$e->getMessage();
            if (strpos($msg, 'UNIQUE constraint failed: HocKy.MaHocKy') !== false) {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'Ma hoc ky da ton tai.',
                    'fields' => ['ma_hoc_ky' => 'Ma hoc ky da ton tai.'],
                ], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Khong the tao hoc ky.', 'detail' => $msg], 500);
        }
    }

    public function update()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        Semester::ensureSchema();
        $maHocKy = trim((string)($_GET['ma_hoc_ky'] ?? ''));
        if ($maHocKy === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma hoc ky.'], 422);
            return;
        }

        $payload = $this->parseJsonPayload();
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.'], 400);
            return;
        }

        [$data, $errors] = $this->validateCreatePayload($payload);
        if (!isset($errors['nam_hoc']) && !isset($errors['ky'])) {
            $expectedCode = $this->buildSemesterCode((string)$data['nam_hoc'], (int)$data['ky']);
            if ($expectedCode !== '' && strcasecmp($expectedCode, $maHocKy) !== 0) {
                $errors['nam_hoc'] = 'Nam hoc/ky khong khop voi ma hoc ky hien tai. Vui long tao hoc ky moi neu can doi ma.';
                $errors['ky'] = 'Nam hoc/ky khong khop voi ma hoc ky hien tai.';
            }
            $data['ma_hoc_ky'] = $maHocKy;
        }
        if (!empty($errors)) {
            jsonResponse(['status' => 'error', 'message' => 'Du lieu khong hop le.', 'fields' => $errors], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Semester::ensureSchema($pdo);
            $pdo->beginTransaction();
            $updated = Semester::updateByCodeWithPdo($pdo, $maHocKy, $data);
            if (!$updated) {
                $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Khong tim thay hoc ky.'], 404);
                return;
            }
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => $updated]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the cap nhat hoc ky.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function delete()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        Semester::ensureSchema();
        $maHocKy = trim((string)($_GET['ma_hoc_ky'] ?? ''));
        if ($maHocKy === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma hoc ky.'], 422);
            return;
        }

        $current = Semester::findByCode($maHocKy);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay hoc ky.'], 404);
            return;
        }

        try {
            $pdo = get_db_connection();
            Semester::ensureSchema($pdo);
            $pdo->beginTransaction();
            $ok = Semester::softDeleteByCodeWithPdo($pdo, $maHocKy);
            if (!$ok) {
                $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Hoc ky da o trang thai ARCHIVED.'], 409);
                return;
            }
            $pdo->commit();
            $row = Semester::findByCode($maHocKy);
            jsonResponse(['status' => 'success', 'data' => $row]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the xoa hoc ky.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function endSemester()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        Semester::ensureSchema();
        $maHocKy = trim((string)($_GET['ma_hoc_ky'] ?? ''));
        if ($maHocKy === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma hoc ky.'], 422);
            return;
        }

        $current = Semester::findByCode($maHocKy);
        if (!$current) {
            jsonResponse(['status' => 'error', 'message' => 'Khong tim thay hoc ky.'], 404);
            return;
        }

        $namHoc = (string)($current['nam_hoc'] ?? '');
        $ky = (int)($current['ky'] ?? 0);
        $parts = $this->parseAcademicYear($namHoc);
        if (!$parts) {
            jsonResponse(['status' => 'error', 'message' => 'Nam hoc khong hop le.'], 422);
            return;
        }

        $nextKy = $ky === 1 ? 2 : 1;
        $nextStart = $parts[0];
        $nextEnd = $parts[1];
        if ($ky !== 1) {
            $nextStart = $parts[0] + 1;
            $nextEnd = $parts[1] + 1;
        }
        $nextNamHoc = $nextStart . '-' . $nextEnd;
        $nextMa = $this->buildSemesterCode($nextNamHoc, $nextKy);
        if ($nextMa === '') {
            jsonResponse(['status' => 'error', 'message' => 'Khong the tao hoc ky tiep theo.'], 500);
            return;
        }

        try {
            $pdo = get_db_connection();
            Semester::ensureSchema($pdo);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE HocKy
                 SET TrangThai = "ARCHIVED",
                     IsCurrent = 0,
                     DeletedAt = datetime("now", "localtime"),
                     UpdatedAt = datetime("now", "localtime")
                 WHERE lower(MaHocKy) = lower(:ma)
                   AND TrangThai != "ARCHIVED"'
            );
            $stmt->execute([':ma' => $maHocKy]);

            $pdo->exec('UPDATE HocKy SET IsCurrent = 0 WHERE IsCurrent = 1');

            $nextRow = Semester::findByCode($nextMa);
            if ($nextRow) {
                $stmt = $pdo->prepare(
                    'UPDATE HocKy
                     SET TrangThai = "ACTIVE",
                         IsCurrent = 1,
                         DeletedAt = NULL,
                         UpdatedAt = datetime("now", "localtime")
                     WHERE lower(MaHocKy) = lower(:ma)'
                );
                $stmt->execute([':ma' => $nextMa]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO HocKy (MaHocKy, TenHocKy, NamHoc, Ky, TrangThai, IsCurrent, GhiChu, CreatedAt, UpdatedAt)
                     VALUES (:ma, :ten, :nam_hoc, :ky, "ACTIVE", 1, NULL, datetime("now", "localtime"), datetime("now", "localtime"))'
                );
                $stmt->execute([
                    ':ma' => $nextMa,
                    ':ten' => 'HK' . $nextKy,
                    ':nam_hoc' => $nextNamHoc,
                    ':ky' => $nextKy,
                ]);
            }

            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Da ket thuc hoc ky va chuyen sang hoc ky tiep theo.']);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the ket thuc hoc ky.', 'detail' => $e->getMessage()], 500);
        }
    }

    public function restore()
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return;
        }

        Semester::ensureSchema();
        $maHocKy = trim((string)($_GET['ma_hoc_ky'] ?? ''));
        if ($maHocKy === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thieu ma hoc ky.'], 422);
            return;
        }

        try {
            $pdo = get_db_connection();
            Semester::ensureSchema($pdo);
            $pdo->beginTransaction();
            $ok = Semester::restoreByCodeWithPdo($pdo, $maHocKy);
            if (!$ok) {
                $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Hoc ky khong o trang thai ARCHIVED.'], 409);
                return;
            }
            $pdo->commit();
            $row = Semester::findByCode($maHocKy);
            jsonResponse(['status' => 'success', 'data' => $row]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => 'Khong the khoi phuc hoc ky.', 'detail' => $e->getMessage()], 500);
        }
    }
}
