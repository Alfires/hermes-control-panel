<?php
declare(strict_types=1);

namespace Deck\Framework;

/** The allowlist that makes AI-editable UI safe: a block is a template plus a
 *  small, typed prop schema. Unknown kinds are dropped; unknown props are
 *  discarded. No executable code, raw CSS or dynamic imports ever reach HTML. */
final class BlockCatalog {
    private const SCHEMAS = [
        'hero'        => ['title' => 'string', 'subtitle' => 'string', 'action' => 'string'],
        'kpi'         => ['title' => 'string', 'metrics' => 'list'],
        'cardGrid'    => ['title' => 'string', 'cards' => 'list'],
        'table'       => ['title' => 'string', 'columns' => 'list', 'rows' => 'list'],
        'statusStrip' => ['title' => 'string', 'items' => 'list'],
        'chat'        => ['title' => 'string', 'agent' => 'string'],
        'auditView'   => ['title' => 'string', 'limit' => 'int'],
        'searchResults' => ['title' => 'string', 'query' => 'string'],
        'voiceRecorder' => ['title' => 'string', 'hint' => 'string'],
        'themeGallery'  => ['title' => 'string', 'presets' => 'list'],
        'timeline'    => ['title' => 'string', 'events' => 'list'],
        'form'        => ['title' => 'string', 'fields' => 'list'],
        'generic'     => ['title' => 'string', 'note' => 'string'],
    ];

    public static function supports(string $type): bool {
        return isset(self::SCHEMAS[$type]);
    }

    /** @return array<string, mixed> only typed, scalar/list props survive. */
    public static function validate(string $type, mixed $props): array {
        $schema = self::SCHEMAS[$type] ?? self::SCHEMAS['generic'];
        $props = is_array($props) ? $props : [];
        $out = [];
        foreach ($schema as $key => $kind) {
            if (!array_key_exists($key, $props)) continue;
            $value = $props[$key];
            $ok = match ($kind) {
                'string' => is_scalar($value),
                'int' => is_numeric($value),
                'list' => is_array($value) || is_string($value),
                default => false,
            };
            if (!$ok) continue;
            $out[$key] = $kind === 'string' ? (string)$value
                : ($kind === 'int' ? (int)$value : self::cleanList($value));
        }
        return $out;
    }

    private static function cleanList(mixed $value): array {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }
        $out = [];
        foreach ((array)$value as $key => $item) {
            // Only scalar leaves, or one more level of scalar leaves (table rows).
            if (is_scalar($item)) $out[$key] = (string)$item;
            elseif (is_array($item)) {
                $row = [];
                foreach ($item as $k => $v) if (is_scalar($v)) $row[$k] = (string)$v;
                $out[$key] = $row;
            }
        }
        return $out;
    }

    public static function template(string $type): string {
        $path = dirname(__DIR__) . '/framework/templates/blocks/' . preg_replace('/[^a-zA-Z0-9]/', '', $type) . '.twig';
        return is_file($path) ? $type : 'generic';
    }
}
