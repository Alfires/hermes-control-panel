<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

final class Auth {
    private const COOKIE = 'panel_session';

    public static function ensureOwner(): void {
        $username = getenv('PANEL_ADMIN_USER') ?: 'admin';
        $password = getenv('PANEL_ADMIN_PASSWORD') ?: '';
        if ($password === '') {
            Util::json(['ok' => false, 'error' => 'panel admin password is not configured'], 500);
        }
        $row = DB::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($row) {
                DB::run('UPDATE users SET password_hash=? WHERE id=?', [$hash, $row['id']]);
            } else {
                DB::run('INSERT INTO users(username,password_hash,created_at) VALUES(?,?,?)',
                    [$username, $hash, time()]);
            }
            DB::audit('system', 'owner-credentials-synced', $username);
        }
    }

    public static function login(string $user, string $pass): ?string {
        $row = DB::one('SELECT * FROM users WHERE username = ?', [$user]);
        if (!$row || !password_verify($pass, $row['password_hash'])) return null;
        $token = bin2hex(random_bytes(32));
        DB::run('INSERT INTO logins(user_id,token_hash,created_at,expires_at) VALUES(?,?,?,?)',
            [$row['id'], hash('sha256', $token), time(), time() + 12 * 3600]);
        return $token;
    }

    public static function current(): ?array {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token === '') return null;
        $row = DB::one('SELECT l.*, u.username FROM logins l JOIN users u ON u.id=l.user_id
                        WHERE l.token_hash=? AND l.expires_at > ?',
            [hash('sha256', $token), time()]);
        return $row;
    }

    public static function require(): array {
        self::ensureOwner();
        $u = self::current();
        if (!$u) Util::json(['ok' => false, 'error' => 'unauthorized'], 401);
        return $u;
    }

    public static function setCookie(string $token): void {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + 12 * 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    public static function logout(): void {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token !== '') {
            DB::run('DELETE FROM logins WHERE token_hash=?', [hash('sha256', $token)]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
}
