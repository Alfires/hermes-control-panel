<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vault.php';

/**
 * Minimal Forgejo / Gitea API client for project creation.
 *
 * NO credential value is ever read, stored, logged or printed here. Every HTTP
 * call is described to the root-owned vault broker by LOGICAL SECRET NAME only:
 * the request carries the {{secret}} placeholder and the broker injects the
 * real token. Replies are scrubbed again before they leave this class, and any
 * URL is stripped of userinfo / query / fragment before it is returned or
 * included in an error message.
 *
 * Configuration (all non-secret):
 *   FORGEJO_BASE_URL (or FORGEJO_URL)  API base, default http://192.168.2.157:3001
 *   FORGEJO_USER                       owning user, default Alfires
 *   FORGEJO_VAULT_NAME                 logical vault name, default forgejo.token
 */
final class Forgejo {
    private const NAME_RE  = '/^[a-z0-9][a-z0-9_.-]{0,99}$/';
    private const OWNER_RE = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';
    private const MAX_REPLY = 131072;

    /** Agent identity used only for the broker's audit line. No RBAC grant is
     *  implied: the owner capability is checked by the API layer. */
    private static function agent(): array {
        return ['id' => 0, 'slug' => 'owner-forgejo'];
    }

    public static function baseUrl(): string {
        $url = getenv('FORGEJO_BASE_URL') ?: getenv('FORGEJO_URL') ?: '';
        if ($url === '') $url = 'http://192.168.2.157:3001';
        $url = trim($url);
        if (!preg_match('#^https?://[A-Za-z0-9._:-]+(?:/[A-Za-z0-9._~/-]*)?$#', $url)) {
            return 'http://192.168.2.157:3001';
        }
        return rtrim($url, '/');
    }

    /** Logical name of the vault entry. This is NOT a credential value. */
    public static function vaultName(): string {
        $name = trim((string)(getenv('FORGEJO_VAULT_NAME') ?: 'forgejo.token'));
        return preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $name) ? $name : 'forgejo.token';
    }

    public static function user(): string {
        $user = trim((string)(getenv('FORGEJO_USER') ?: 'Alfires'));
        return preg_match(self::OWNER_RE, $user) ? $user : 'Alfires';
    }

    public static function validRepoName(string $name): bool {
        if (!preg_match(self::NAME_RE, $name)) return false;
        // No relative-path tricks and no trailing ".git" suffix.
        return $name !== '.' && $name !== '..' && !str_ends_with($name, '.git')
            && !str_contains($name, '..') && !str_contains($name, '\\');
    }

    /** Return a URL safe to log or show: no userinfo, query or fragment. */
    public static function safeUrl(string $url): string {
        $url = substr($url, 0, 500);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '[invalid-url]';
        }
        $scheme = in_array($parts['scheme'], ['http', 'https'], true) ? $parts['scheme'] : 'http';
        $host = preg_replace('/[^A-Za-z0-9._:-]/', '', (string)$parts['host']) ?: 'invalid-host';
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '');
        $path = preg_replace('/[^\/A-Za-z0-9._~%+-]/', '', $path) ?: '';
        return Util::scrub($scheme . '://' . $host . $port . $path);
    }

    /** Scrub broker errors and neutralise any URL embedded in them. */
    public static function safeError(mixed $value): string {
        $text = Util::str(is_scalar($value) ? $value : '', 500);
        $text = Util::scrub($text);
        $text = preg_replace_callback('#https?://[^\s"\'<>)]+#i',
            static fn(array $m): string => self::safeUrl($m[0]), $text) ?? $text;
        return substr($text, 0, 500);
    }

    /** Keep only known repository fields; everything else is untrusted output. */
    public static function curateRepo(array $repo): array {
        $name = (string)($repo['name'] ?? '');
        $full = (string)($repo['full_name'] ?? $name);
        if (!self::validRepoName($name)) return [];
        $out = [
            'id'            => (int)($repo['id'] ?? 0),
            'name'          => $name,
            'full_name'     => preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $full) ? $full : self::user() . '/' . $name,
            'private'       => !empty($repo['private']),
            'default_branch'=> in_array($repo['default_branch'] ?? '', ['main', 'master'], true) ? $repo['default_branch'] : 'main',
            'updated_at'    => Util::scrub(Util::str($repo['updated_at'] ?? '', 40)),
        ];
        foreach (['html_url', 'clone_url', 'ssh_url'] as $key) {
            $raw = (string)($repo[$key] ?? '');
            $out[$key] = $raw === '' ? '' : self::safeUrl($raw);
        }
        if ($out['clone_url'] === '') {
            $out['clone_url'] = self::safeUrl(self::baseUrl() . '/' . $out['full_name'] . '.git');
        }
        return $out;
    }

    /** Describe one API request to the vault broker; never send a value. */
    private function request(string $method, string $path, ?array $body = null): array {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($path, '/')) {
            return ['ok' => false, 'error' => 'invalid Forgejo request'];
        }
        $url = self::safeUrl(self::baseUrl() . $path);
        $params = [
            'url'     => $url,
            'method'  => $method,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // The broker replaces the placeholder; no value crosses this boundary.
                'Authorization' => 'token {{secret}}',
            ],
        ];
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $params['body'] = substr((string)$encoded, 0, 8000);
        }
        $reply = Vault::use(self::agent(), [
            'name'    => self::vaultName(),
            'kind'    => 'http',
            'purpose' => 'Forgejo ' . $method . ' ' . substr($path, 0, 120),
            'params'  => $params,
        ]);
        $reply = Util::scrubDeep($reply);
        if (!is_array($reply) || empty($reply['ok'])) {
            return ['ok' => false, 'error' => self::safeError($reply['error'] ?? 'vault use denied')];
        }
        $raw = $reply['body'] ?? $reply['response'] ?? $reply['raw'] ?? '';
        $data = is_array($raw) ? $raw : (is_string($raw) ? json_decode(substr($raw, 0, self::MAX_REPLY), true) : null);
        $status = (int)($reply['status'] ?? $reply['http_status'] ?? $reply['code'] ?? 0);
        if ($status >= 400) {
            $message = is_array($data) ? ($data['message'] ?? '') : '';
            return ['ok' => false, 'status' => $status,
                    'error' => self::safeError($message !== '' ? $message : 'Forgejo returned HTTP ' . $status)];
        }
        return ['ok' => true, 'status' => $status, 'data' => is_array($data) ? $data : []];
    }

    public function createRepo(string $name, bool $private, string $description = ''): array {
        if (!self::validRepoName($name)) return ['ok' => false, 'error' => 'invalid repository name'];
        $body = [
            'name'        => $name,
            'description' => Util::str($description, 255),
            'private'     => $private,
            'auto_init'   => false,
        ];
        $res = $this->request('POST', '/api/v1/user/repos', $body);
        if (empty($res['ok']) && (int)($res['status'] ?? 0) === 409) {
            // Retry-safe: an earlier attempt may already have created it.
            $existing = $this->getRepo(self::user(), $name);
            if (!empty($existing['ok'])) {
                return ['ok' => true, 'created' => false, 'repo' => $existing['repo']];
            }
        }
        if (empty($res['ok'])) return $res;
        $repo = self::curateRepo(is_array($res['data'] ?? null) ? $res['data'] : []);
        if (!$repo) return ['ok' => false, 'error' => 'Forgejo returned an unusable repository record'];
        return ['ok' => true, 'created' => true, 'repo' => $repo];
    }

    public function listRepos(int $limit = 50): array {
        $limit = max(1, min(50, $limit));
        $res = $this->request('GET', '/api/v1/user/repos?limit=' . $limit . '&page=1');
        if (empty($res['ok'])) return $res;
        $rows = $res['data'] ?? [];
        if (!is_array($rows)) return ['ok' => true, 'repos' => []];
        $repos = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $repo = self::curateRepo($row);
            if ($repo) $repos[] = $repo;
        }
        return ['ok' => true, 'repos' => $repos];
    }

    public function getRepo(string $owner, string $name): array {
        if (!preg_match(self::OWNER_RE, $owner) || !self::validRepoName($name)) {
            return ['ok' => false, 'error' => 'invalid repository coordinates'];
        }
        $res = $this->request('GET', '/api/v1/repos/' . rawurlencode($owner) . '/' . rawurlencode($name));
        if (empty($res['ok'])) return $res;
        $repo = self::curateRepo(is_array($res['data'] ?? null) ? $res['data'] : []);
        if (!$repo) return ['ok' => false, 'error' => 'Forgejo returned an unusable repository record'];
        return ['ok' => true, 'repo' => $repo];
    }
}
