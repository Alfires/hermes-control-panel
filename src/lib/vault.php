<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';

/** Thin client for the Deck's existing root-owned vault broker. The panel can
 *  only list and use; there is no read operation to expose, and every answer
 *  is scrubbed again here. */
final class Vault {
    private static function socket(): string {
        return getenv('VAULT_SOCKET') ?: '/run/vault-broker.sock';
    }

    public static function available(): bool {
        return file_exists(self::socket());
    }

    public static function listSecrets(): array {
        $r = Util::sock(self::socket(), ['op' => 'list']);
        $rows = $r['ok'] ?? false ? ($r['secrets'] ?? []) : [];
        $clean = [];
        foreach ($rows as $row) {
            $clean[] = [
                'name'  => (string)($row['name'] ?? ''),
                'label' => Util::scrub((string)($row['label'] ?? '')),
                'allow' => $row['allow'] ?? [],
                'approval' => $row['approval'] ?? 'always',
            ];
        }
        return ['ok' => (bool)($r['ok'] ?? false), 'secrets' => $clean,
                'error' => Util::scrub((string)($r['error'] ?? ''))];
    }

    /** The agent never supplies or receives a value. Only a logical name, a
     *  kind, and the parameters of the one action to perform. */
    public static function use(array $agent, array $args): array {
        $name = Util::str($args['name'] ?? '', 120);
        $kind = Util::str($args['kind'] ?? '', 20);
        $purpose = Util::str($args['purpose'] ?? '', 300);

        if (!in_array($kind, ['http', 'file', 'exec'], true)) {
            return ['ok' => false, 'error' => 'kind must be http, file or exec'];
        }
        $params = $args['params'] ?? [];
        if (!is_array($params)) $params = [];

        $reply = Util::sock(self::socket(), [
            'op' => 'use',
            'name' => $name,
            'kind' => $kind,
            'purpose' => $purpose,
            'requestor' => 'panel agent: ' . ($agent['slug'] ?? 'unknown'),
            'params' => $params,
        ], 120);

        // Even if a buggy helper echoes the value, it never leaves this box.
        $reply = self::scrubTree($reply);
        DB::audit('agent:' . ($agent['id'] ?? 0), 'vault.use',
            sprintf('%s/%s purpose=%s result=%s', $name, $kind, $purpose,
                    !empty($reply['ok']) ? 'ok' : 'denied'));
        return $reply;
    }

    private static function scrubTree($v) {
        if (is_string($v)) return Util::scrub($v);
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) $out[$k] = self::scrubTree($x);
            return $out;
        }
        return $v;
    }
}
