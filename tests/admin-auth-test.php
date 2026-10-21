<?php
/**
 * Test suite for custom config/admin.php and admin-auth.php enhancements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';

function test_assert(string $desc, bool $condition): void {
    if ($condition) {
        echo "  PASS: $desc\n";
    } else {
        echo "  FAIL: $desc\n";
        exit(1);
    }
}

echo "Running Admin Auth Tests...\n\n";

// 1. Test IP Pattern Matching
echo "1. IP Pattern Matching:\n";
test_assert("Exact IPv4 match", ipMatchesPattern("10.17.86.126", "10.17.86.126"));
test_assert("Exact IPv4 mismatch", !ipMatchesPattern("10.17.86.127", "10.17.86.126"));
test_assert("Exact IPv6 match", ipMatchesPattern("::1", "::1"));
test_assert("Wildcard match", ipMatchesPattern("192.168.1.100", "192.168.1.*"));
test_assert("Wildcard mismatch", !ipMatchesPattern("192.168.2.100", "192.168.1.*"));
test_assert("CIDR /24 match", ipMatchesPattern("192.168.1.45", "192.168.1.0/24"));
test_assert("CIDR /24 mismatch", !ipMatchesPattern("192.168.2.45", "192.168.1.0/24"));
test_assert("CIDR /16 match", ipMatchesPattern("10.50.1.2", "10.50.0.0/16"));
test_assert("CIDR /16 mismatch", !ipMatchesPattern("10.51.1.2", "10.50.0.0/16"));

// 2. Test Blank / Default Config State
echo "\n2. Default Config State:\n";
$cfg = adminConfig(true);
test_assert("Config has allowed_ips containing 10.17.86.126", in_array('10.17.86.126', $cfg['allowed_ips'], true));
test_assert("Config mode is ip_or_password", $cfg['mode'] === 'ip_or_password');

// 3. Test Password Verification (Plaintext & Hash)
echo "\n3. Password Verification in Config:\n";
$configFile = __DIR__ . '/../config/admin.php';
$origConfig = file_get_contents($configFile);

// Test plaintext password in config
file_put_contents($configFile, "<?php return ['admin_password' => 'PlainSecret123', 'allowed_ips' => ['10.17.86.126'], 'mode' => 'ip_or_password'];");
adminConfig(true);

test_assert("adminConfigured() is true with config password", adminConfigured());
test_assert("Verify correct plaintext password", adminVerifyPassword('PlainSecret123'));
test_assert("Verify wrong plaintext password", !adminVerifyPassword('WrongPass'));

// Test password_hash in config
$hash = password_hash('HashSecret456', PASSWORD_BCRYPT);
file_put_contents($configFile, "<?php return ['admin_password' => '$hash', 'allowed_ips' => ['10.17.86.126'], 'mode' => 'ip_or_password'];");
adminConfig(true);

test_assert("Verify correct hashed password", adminVerifyPassword('HashSecret456'));
test_assert("Verify wrong hashed password", !adminVerifyPassword('WrongPass'));

// 4. Test Auto-login for 10.17.86.126 vs Other IP
echo "\n4. Auto-login for 10.17.86.126 vs Other IP:\n";
file_put_contents($configFile, "<?php return ['admin_password' => 'PassForOtherIP', 'allowed_ips' => ['10.17.86.126'], 'mode' => 'ip_or_password'];");
adminConfig(true);
unset($_SESSION['aoi_admin']);

// Case A: 10.17.86.126 -> auto-login immediately
$_SERVER['REMOTE_ADDR'] = '10.17.86.126';
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
test_assert("10.17.86.126 is allowed", isClientIpAllowed());
test_assert("10.17.86.126 has adminStatus() === 'ok' automatically", adminStatus() === 'ok');
test_assert("10.17.86.126 has isAdmin() === true automatically", isAdmin());

// Case B: 10.17.86.126 via X-Forwarded-For (behind reverse proxy)
$_SERVER['REMOTE_ADDR'] = '172.18.0.1'; // docker gateway
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.17.86.126';
test_assert("10.17.86.126 via proxy is allowed", isClientIpAllowed());
test_assert("10.17.86.126 via proxy auto-logs in", isAdmin());

// Case C: Other IP (10.17.86.171) -> NOT logged in automatically
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
$_SERVER['REMOTE_ADDR'] = '10.17.86.171';
test_assert("10.17.86.171 is NOT allowed by IP", !isClientIpAllowed());
test_assert("10.17.86.171 has adminStatus() === 'none'", adminStatus() === 'none');
test_assert("10.17.86.171 has isAdmin() === false", !isAdmin());

// Case D: Other IP signs in with correct password
$res = adminSignIn('PassForOtherIP');
test_assert("10.17.86.171 signs in with correct password successfully", $res['ok']);
test_assert("10.17.86.171 is now admin after login", isAdmin());

// Case E: Other IP signs out
adminSignOut();
test_assert("10.17.86.171 is no longer admin after sign out", !isAdmin());

// Case F: Other IP tries wrong password
$res = adminSignIn('WrongPassword');
test_assert("10.17.86.171 is refused with wrong password", !$res['ok']);

// Restore original config
file_put_contents($configFile, $origConfig);
adminConfig(true);

echo "\nAll Admin Auth Tests Passed Successfully!\n";
