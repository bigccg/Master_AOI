<?php
/**
 * Create the administrator password hash for AOI_ADMIN_PASSWORD_HASH (CLI only, prints nothing secret).
 * Asks for the password twice without echoing it, so it never lands in the shell history.
 *
 *   docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/admin-password-hash.php
 *   php scripts/admin-password-hash.php
 *
 * Prints the hash plus the docker-compose.yml line (compose needs $$ for every $). Exit code 1 = nothing created.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("สคริปต์นี้รันได้จาก Command Line เท่านั้น / This script can only be run from the command line.\n");
}

const MIN_PASSWORD_LENGTH = 10;

/** Read one line from STDIN, hiding the typing when STDIN is a terminal */
function askHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim((string)$line, "\r\n");
}

$password = askHidden('รหัสผ่านผู้ดูแลใหม่ / New administrator password: ');
if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
    fwrite(STDERR, 'ยังไม่ได้สร้างแฮช เนื่องจากรหัสผ่านสั้นกว่า ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร กรุณารันใหม่แล้วตั้งรหัสผ่านที่ยาวขึ้น / No hash created: the password is shorter than ' . MIN_PASSWORD_LENGTH . ' characters. Run again with a longer one.' . PHP_EOL);
    exit(1);
}
if (askHidden('พิมพ์อีกครั้ง / Repeat it: ') !== $password) {
    fwrite(STDERR, 'ยังไม่ได้สร้างแฮช เนื่องจากรหัสผ่านสองครั้งไม่ตรงกัน กรุณารันใหม่ / No hash created: the two passwords differ. Run again.' . PHP_EOL);
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo PHP_EOL . 'แฮช / Hash (AOI_ADMIN_PASSWORD_HASH):' . PHP_EOL . "  $hash" . PHP_EOL . PHP_EOL;
echo 'docker-compose.yml → services → web → environment (ทุก $ เขียนเป็น $$ / every $ written as $$):' . PHP_EOL;
echo '      AOI_ADMIN_PASSWORD_HASH: "' . str_replace('$', '$$', $hash) . '"' . PHP_EOL . PHP_EOL;
echo 'จากนั้น / Then: docker compose up -d web' . PHP_EOL;
echo 'เปลี่ยนแฮชเมื่อไร ผู้ดูแลที่เข้าสู่ระบบค้างไว้ทุกคนจะถูกออกจากระบบ / Changing the hash signs every administrator out.' . PHP_EOL;
