<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';

/** Client for the root-owned host-control broker. Only allowlisted action IDs
 *  cross this boundary; agents never get a shell or an arbitrary command.
 *  Privileged actions are HMAC-signed with a root-only key so a forged or
 *  replayed request cannot reach the host even if the socket were exposed. */
final class HostCtl {
    public const ACTIONS = [
        'system_status' => 'Read-only Deck health: services, disk, containers, updates.',
        'security_scan' => 'Read-only exploit/config scan.',
        'cve_lookup'    => 'Look up a CVE or package version.',
        'fix_updates'   => 'Apply available package updates.',
        'fix_service'   => 'Restart one named systemd service.',
        'fix_docker'    => 'Pull and recreate one named docker compose stack.',
        'fix_ssh'       => 'Harden sshd configuration.',
        'reboot'        => 'Reboot the Deck.',
    ];

    public const READ_ONLY = ['system_status', 'security_scan', 'cve_lookup'];

    private static function socket(): string {
        return getenv('HOSTCTL_SOCKET') ?: '/run/panel-host.sock';
    }

    private static function keyPath(): string {
        return getenv('PANEL_HOST_KEY') ?: '/run/secrets/hostctl.key';
    }

    public static function available(): bool {
        return file_exists(self::socket());
    }

    public static function call(string $action, array $args = [], ?array $agent = null): array {
        if (!isset(self::ACTIONS[$action])) {
            return ['ok' => false, 'error' => "unknown host action $action"];
        }
        // Cast to object so an empty argument set is {} (not []) on both sides
        // of the HMAC - Python canonicalises args as a JSON object.
        $argsObj = (object)$args;
        $payload = [
            'op' => 'action',
            'action' => $action,
            'args' => $argsObj,
            'requestor' => 'panel' . ($agent ? ':' . ($agent['slug'] ?? '') : ''),
        ];
        if (!in_array($action, self::READ_ONLY, true)) {
            $key = @file_get_contents(self::keyPath());
            if ($key === false || trim($key) === '') {
                return ['ok' => false, 'error' => 'host control key unavailable'];
            }
            $ts = (string)time();
            $canonical = json_encode($argsObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $msg = $action . '|' . $canonical . '|' . $ts;
            $payload['ts'] = $ts;
            $payload['token'] = hash_hmac('sha256', $msg, trim($key));
        }
        $reply = Util::sock(self::socket(), $payload, 240);
        $reply = self::scrub($reply);
        DB::audit('agent:' . ($agent['id'] ?? 0), 'host.' . $action,
            substr(json_encode($args, JSON_UNESCAPED_SLASHES) ?: '', 0, 500));
        return $reply;
    }

    private static function scrub($v) {
        if (is_string($v)) return Util::scrub($v);
        if (is_array($v)) {
            $o = [];
            foreach ($v as $k => $x) $o[$k] = self::scrub($x);
            return $o;
        }
        return $v;
    }
}
