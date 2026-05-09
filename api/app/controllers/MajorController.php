<?php

require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/Major.php';
require_once __DIR__ . '/../helpers/response.php';

class MajorController
{
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

    private function requireStaff(): ?array
    {
        $identity = $this->currentIdentity();
        if (!$identity) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
            return null;
        }
        if (!$this->isStaff($identity)) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            return null;
        }
        return $identity;
    }

    public function index(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $code = trim((string)($_GET['code'] ?? ''));
        $pdo = get_db_connection();
        Major::ensureSchema($pdo);

        if ($code !== '') {
            $item = Major::findByCode($code, $pdo);
            if (!$item) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy ngành.'], 404);
                return;
            }
            jsonResponse(['status' => 'success', 'data' => $item]);
            return;
        }

        jsonResponse([
            'status' => 'success',
            'data' => Major::search($keyword, $pdo),
            'summary' => Major::summary($pdo),
            'faculties' => Major::listFacultyOptions($pdo),
            'status_options' => Major::validStatuses(),
        ]);
    }

    public function save(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            jsonResponse(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'], 400);
            return;
        }

        $action = strtolower(trim((string)($payload['action'] ?? 'create')));
        $oldCode = trim((string)($payload['old_code'] ?? ''));
        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $facultyCode = trim((string)($payload['faculty_code'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $status = Major::normalizeStatus((string)($payload['status'] ?? ''));

        if ($action === 'update' && $oldCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã ngành cần cập nhật.'], 422);
            return;
        }
        if ($name === '') {
            jsonResponse(['status' => 'error', 'message' => 'Tên ngành không được để trống.'], 422);
            return;
        }
        if ($facultyCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Vui lòng chọn khoa cho ngành.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        Major::ensureSchema($pdo);

        if ($action === 'create' && $code !== '') {
            $dup = $pdo->prepare('SELECT 1 FROM Nganh WHERE lower(MaNganh) = lower(:code) LIMIT 1');
            $dup->execute([':code' => $code]);
            if ($dup->fetchColumn()) {
                jsonResponse(['status' => 'error', 'message' => 'Mã ngành đã tồn tại.'], 409);
                return;
            }
        }

        try {
            $pdo->beginTransaction();
            if ($action === 'update') {
                $saved = Major::updateWithPdo($pdo, $oldCode, [
                    'code' => $code,
                    'name' => $name,
                    'faculty_code' => $facultyCode,
                    'description' => $description,
                    'status' => $status,
                ]);
            } else {
                $saved = Major::createWithPdo($pdo, [
                    'code' => $code,
                    'name' => $name,
                    'faculty_code' => $facultyCode,
                    'description' => $description,
                    'status' => $status,
                ]);
            }
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => $saved]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = (string)$e->getMessage();
            if (strpos($message, 'UNIQUE constraint failed: Nganh.MaNganh') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'Mã ngành đã tồn tại.'], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể lưu ngành. (' . $message . ')'], 500);
        }
    }

    public function delete(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã ngành cần xóa.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        Major::ensureSchema($pdo);

        if (!Major::findByCode($code, $pdo)) {
            jsonResponse(['status' => 'error', 'message' => 'Nganh khong ton tai.'], 404);
            return;
        }

        try {
            $pdo->beginTransaction();
            Major::deleteByCodeWithPdo($pdo, $code);
            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Đã xóa ngành thành công.']);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa ngành. (' . $e->getMessage() . ')'], 500);
        }
    }
}

