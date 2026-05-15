<?php

declare(strict_types=1);

// Set temp DB path before define.php loads (only effective if DB_PATH not yet defined)
if (!defined('DB_PATH')) {
    putenv('DB_PATH_OVERRIDE=' . sys_get_temp_dir() . '/phpunit_unit_tests.sqlite');
}

use PHPUnit\Framework\TestCase;

final class AdminUnitTest extends TestCase
{
    private static string $apiRoot;

    public static function setUpBeforeClass(): void
    {
        self::$apiRoot = dirname(__DIR__);
        require_once self::$apiRoot . '/app/common/define.php';
        require_once self::$apiRoot . '/app/common/db.php';
        require_once self::$apiRoot . '/app/models/Admin.php';

        $pdo = get_db_connection();
        Admin::ensureSchema($pdo);
    }

    // ── ensureSchema seeds the base admin account ────────────────────────────

    public function testEnsureSchemaSeedsAdminAccount(): void
    {
        $account = Admin::findByLoginId('admin');

        $this->assertIsArray($account);
        $this->assertSame('admin', $account['login_id']);
        $this->assertSame('staff', $account['account_type']);
    }

    // ── verifyPassword ────────────────────────────────────────────────────────

    public function testVerifyPasswordReturnsTrueForCorrectPassword(): void
    {
        $result = Admin::verifyPassword('admin', '123456');

        $this->assertTrue($result);
    }

    public function testVerifyPasswordReturnsFalseForWrongPassword(): void
    {
        $result = Admin::verifyPassword('admin', 'wrong-password');

        $this->assertFalse($result);
    }

    public function testVerifyPasswordIsCaseInsensitiveOnLoginId(): void
    {
        $result = Admin::verifyPassword('ADMIN', '123456');

        $this->assertTrue($result);
    }

    public function testVerifyPasswordReturnsFalseForNonExistentUser(): void
    {
        $result = Admin::verifyPassword('user-does-not-exist-xyz', '123456');

        $this->assertFalse($result);
    }

    // ── createAccountWithPdo + findByLoginId ─────────────────────────────────

    public function testCreateAccountAndFindByLoginId(): void
    {
        $pdo = get_db_connection();
        $loginId = 'unit_test_' . bin2hex(random_bytes(4));

        Admin::createAccountWithPdo($pdo, $loginId, 'pass1234', 'Unit Test User', 'student');

        $account = Admin::findByLoginId($loginId);

        $this->assertIsArray($account);
        $this->assertSame($loginId, $account['login_id']);
        $this->assertSame('Unit Test User', $account['name']);
        $this->assertSame('student', $account['account_type']);
    }

    public function testFindByLoginIdReturnsNullForMissingAccount(): void
    {
        $result = Admin::findByLoginId('absolutely-does-not-exist-xyz-99');

        $this->assertFalse($result);
    }

    // ── updatePasswordByLoginId ───────────────────────────────────────────────

    public function testUpdatePasswordAllowsNewPasswordLogin(): void
    {
        $pdo = get_db_connection();
        $loginId = 'unit_pwd_' . bin2hex(random_bytes(4));
        Admin::createAccountWithPdo($pdo, $loginId, 'original-pass', 'Password Test', 'student');

        $updated = Admin::updatePasswordByLoginId($loginId, 'new-pass-456');

        $this->assertTrue($updated);
        $this->assertTrue(Admin::verifyPassword($loginId, 'new-pass-456'));
        $this->assertFalse(Admin::verifyPassword($loginId, 'original-pass'));
    }

    public function testUpdatePasswordReturnsFalseForNonExistentLogin(): void
    {
        $result = Admin::updatePasswordByLoginId('nonexistent-login-xyz', 'newpass');

        $this->assertFalse($result);
    }
}
