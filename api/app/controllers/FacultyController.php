<?php
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/Faculty.php';
require_once __DIR__ . '/../helpers/response.php';

class FacultyController
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

    private function teacherExists(PDO $pdo, string $teacherCode): bool
    {
        if ($teacherCode === '') {
            return true;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM GiangVien WHERE lower(MaGV) = lower(:code) LIMIT 1');
        $stmt->execute([':code' => $teacherCode]);
        return (bool)$stmt->fetchColumn();
    }

    public function index(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $code = trim((string)($_GET['code'] ?? ''));
        $pdo = get_db_connection();
        Faculty::ensureSchema($pdo);

        if ($code !== '') {
            $item = Faculty::findByCode($code, $pdo);
            if (!$item) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy khoa.'], 404);
                return;
            }
            jsonResponse(['status' => 'success', 'data' => $item]);
            return;
        }

        jsonResponse([
            'status' => 'success',
            'data' => Faculty::searchWithStats($keyword, $pdo),
            'summary' => Faculty::summary($pdo),
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
        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $headTeacherCode = trim((string)($payload['head_teacher_code'] ?? ''));
        $status = Faculty::normalizeStatus((string)($payload['status'] ?? ''));
        $oldCode = trim((string)($payload['old_code'] ?? ''));

        if ($action === 'update' && $oldCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã khoa cần cập nhật.'], 422);
            return;
        }
        if ($name === '') {
            jsonResponse(['status' => 'error', 'message' => 'Tên khoa không được để trống.'], 422);
            return;
        }

        // For create action, code is required
        if ($action !== 'update' && $code === '') {
            jsonResponse(['status' => 'error', 'message' => 'Mã khoa không được để trống.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        Faculty::ensureSchema($pdo);

        if (!$this->teacherExists($pdo, $headTeacherCode)) {
            jsonResponse(['status' => 'error', 'message' => 'Mã giảng viên trưởng khoa không tồn tại.'], 422);
            return;
        }

        try {
            $pdo->beginTransaction();
            if ($action === 'update') {
                $saved = Faculty::updateWithPdo($pdo, $oldCode, [
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'head_teacher_code' => $headTeacherCode,
                    'status' => $status,
                ]);
            } else {
                $saved = Faculty::createWithPdo($pdo, [
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'head_teacher_code' => $headTeacherCode,
                    'status' => $status,
                ]);
            }
            $pdo->commit();
            jsonResponse(['status' => 'success', 'data' => $saved]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = (string)$e->getMessage();
            if (strpos($message, 'UNIQUE constraint failed: Khoa.MaKhoa') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'Mã khoa đã tồn tại.'], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể lưu khoa. (' . $message . ')'], 500);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function delete(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã khoa cần xóa.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        Faculty::ensureSchema($pdo);

        if (!Faculty::findByCode($code, $pdo)) {
            jsonResponse(['status' => 'error', 'message' => 'Khoa khong ton tai.'], 404);
            return;
        }

        try {
            $pdo->beginTransaction();
            Faculty::deleteByCodeWithPdo($pdo, $code);
            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Đã xóa khoa thành công.']);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa khoa. (' . $e->getMessage() . ')'], 500);
        }
    }
}
