<?php

require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/HomeroomClass.php';
require_once __DIR__ . '/../helpers/response.php';

class HomeroomClassController
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
        HomeroomClass::ensureSchema($pdo);

        if ($code !== '') {
            $item = HomeroomClass::findByCode($code, $pdo);
            if (!$item) {
                jsonResponse(['status' => 'error', 'message' => 'Không tìm thấy lớp.'], 404);
                return;
            }
            jsonResponse(['status' => 'success', 'data' => $item]);
            return;
        }

        jsonResponse([
            'status' => 'success',
            'data' => HomeroomClass::search($keyword, $pdo),
            'summary' => HomeroomClass::summary($pdo),
            'major_options' => HomeroomClass::listMajorOptions($pdo),
            'teacher_options' => HomeroomClass::listTeacherOptions($pdo),
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
        $majorCode = trim((string)($payload['major_code'] ?? ''));
        $headTeacherCode = trim((string)($payload['head_teacher_code'] ?? ''));
        $schoolYear = trim((string)($payload['school_year'] ?? ''));

        if ($action === 'update' && $oldCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp cần cập nhật.'], 422);
            return;
        }
        if ($name === '') {
            jsonResponse(['status' => 'error', 'message' => 'Tên lớp không được để trống.'], 422);
            return;
        }
        if ($majorCode === '') {
            jsonResponse(['status' => 'error', 'message' => 'Vui lòng chọn ngành cho lớp.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        HomeroomClass::ensureSchema($pdo);

        try {
            $pdo->beginTransaction();
            if ($action === 'update') {
                $saved = HomeroomClass::updateWithPdo($pdo, $oldCode, [
                    'code' => $code,
                    'name' => $name,
                    'major_code' => $majorCode,
                    'head_teacher_code' => $headTeacherCode,
                    'school_year' => $schoolYear,
                ]);
            } else {
                $saved = HomeroomClass::createWithPdo($pdo, [
                    'code' => $code,
                    'name' => $name,
                    'major_code' => $majorCode,
                    'head_teacher_code' => $headTeacherCode,
                    'school_year' => $schoolYear,
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
            if (strpos($message, 'UNIQUE constraint failed: LopSinhHoat.MaLop') !== false) {
                jsonResponse(['status' => 'error', 'message' => 'Mã lớp đã tồn tại.'], 409);
                return;
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể lưu lớp. (' . $message . ')'], 500);
        }
    }

    public function delete(): void
    {
        if (!$this->requireStaff()) {
            return;
        }

        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') {
            jsonResponse(['status' => 'error', 'message' => 'Thiếu mã lớp cần xóa.'], 422);
            return;
        }

        $pdo = get_db_connection();
        $pdo->exec('PRAGMA busy_timeout = 5000');
        HomeroomClass::ensureSchema($pdo);

        try {
            $pdo->beginTransaction();
            HomeroomClass::deleteByCodeWithPdo($pdo, $code);
            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Đã xóa lớp thành công.']);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => 'Không thể xóa lớp. (' . $e->getMessage() . ')'], 500);
        }
    }
}

