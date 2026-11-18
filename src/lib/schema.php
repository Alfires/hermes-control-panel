<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/registry.php';

/** Lightweight, dependency-free validation of the AI-editable site config.
 *  Everything an agent may change is checked here before a proposal is stored. */
final class Schema {
    public const VERSION = 1;
    private const TOP_KEYS = ['schema_version', 'revision', 'meta', 'theme', 'pages', 'feature_flags', 'prompts', 'schedule'];
    private const BLOCK_TYPES = [
        'hero', 'kpi', 'cardGrid', 'table', 'form', 'jsonEditor', 'chat', 'timeline', 'chart',
        'terminalView', 'configEditor', 'auditView', 'searchResults', 'wizardStep',
        'voiceRecorder', 'themeGallery', 'statusStrip',
    ];
    private const BAD_MARKERS = ['<script', 'onerror=', 'onload=', 'javascript:', '</style', '@import', 'url('];

    public static function validateConfig(mixed $cfg): array {
        $errors = [];
        if (!is_array($cfg)) return self::err('config must be an object');

        foreach (array_keys($cfg) as $key) {
            if (!is_string($key) || !in_array($key, self::TOP_KEYS, true)) {
                $errors[] = "unknown top-level key: $key";
            }
        }
        if (($cfg['schema_version'] ?? null) !== self::VERSION) $errors[] = 'schema_version must be 1';
        if (isset($cfg['revision']) && (!is_int($cfg['revision']) || $cfg['revision'] < 1)) $errors[] = 'revision must be a positive integer';

        // meta
        $meta = $cfg['meta'] ?? null;
        if (!is_array($meta)) {
            $errors[] = 'meta must be an object';
        } else {
            foreach (array_keys($meta) as $k) {
                if (!in_array($k, ['title', 'locale', 'description'], true)) $errors[] = "unknown meta key: $k";
            }
            if (!isset($meta['title']) || !is_string($meta['title']) || $meta['title'] === '' || strlen($meta['title']) > 200) {
                $errors[] = 'meta.title must be a string of 1-200 characters';
            }
            if (isset($meta['description']) && (!is_string($meta['description']) || strlen($meta['description']) > 4000)) {
                $errors[] = 'meta.description too long';
            }
            if (isset($meta['locale']) && !in_array($meta['locale'], ['en', 'de', 'fr', 'es'], true)) {
                $errors[] = 'unsupported locale';
            }
            foreach (self::BAD_MARKERS as $marker) {
                if (is_string($meta['title'] ?? null) && stripos($meta['title'], $marker) !== false) $errors[] = "meta.title contains $marker";
                if (is_string($meta['description'] ?? null) && stripos($meta['description'], $marker) !== false) $errors[] = "meta.description contains $marker";
            }
        }

        // theme
        $theme = $cfg['theme'] ?? null;
        if (!is_array($theme)) {
            $errors[] = 'theme must be an object';
        } else {
            foreach (array_keys($theme) as $k) {
                if (!in_array($k, ['preset', 'mode', 'radius', 'tokens', 'font'], true)) $errors[] = "unknown theme key: $k";
            }
            if (!isset($theme['preset']) || !is_string($theme['preset']) || !isset(Theme::presets()[$theme['preset']])) {
                $errors[] = 'unknown theme preset';
            }
            if (!in_array($theme['mode'] ?? null, ['light', 'dark'], true)) $errors[] = 'theme.mode invalid';
            if (!in_array($theme['radius'] ?? null, ['rounded', 'square'], true)) $errors[] = 'theme.radius invalid';
            if (isset($theme['font']) && !in_array($theme['font'], ['system', 'sans', 'mono', 'serif'], true)) $errors[] = 'theme.font invalid';
            if (isset($theme['tokens'])) {
                if (!is_array($theme['tokens']) || !Theme::validateTokens($theme['tokens'])) $errors[] = 'invalid theme tokens';
            }
        }

        // pages / blocks
        $pages = $cfg['pages'] ?? null;
        if (!is_array($pages) || count($pages) < 1 || count($pages) > 100) {
            $errors[] = 'pages must contain 1-100 entries';
        } else {
            $seen = [];
            foreach ($pages as $i => $page) {
                if (!is_array($page)) { $errors[] = "page $i must be an object"; continue; }
                foreach (array_keys($page) as $k) {
                    if (!in_array($k, ['id', 'route', 'nav', 'capabilities', 'blocks', 'origin'], true)) $errors[] = "unknown page key: $k";
                }
                $id = (string)($page['id'] ?? '');
                if (!Util::validSlug($id)) $errors[] = "page $i has invalid id";
                if (isset($seen[$id])) $errors[] = "duplicate page id: $id";
                $seen[$id] = true;
                $route = (string)($page['route'] ?? '');
                if ($route === '' || $route[0] !== '/') $errors[] = "page $i route must start with /";
                if (preg_match('/[^a-zA-Z0-9\/_-]/', $route)) $errors[] = "page $i route has unsafe characters";
                if (!is_array($page['nav'] ?? null)) {
                    $errors[] = "page $i nav must be an object";
                } else {
                    foreach (self::BAD_MARKERS as $marker) {
                        foreach (['label', 'icon'] as $nk) {
                            if (is_string($page['nav'][$nk] ?? null) && stripos($page['nav'][$nk], $marker) !== false) {
                                $errors[] = "page $i nav.$nk contains $marker";
                            }
                        }
                    }
                }
                if (!is_array($page['capabilities'] ?? null)) $errors[] = "page $i capabilities must be an array";
                else foreach ($page['capabilities'] as $cap) {
                    if (!is_string($cap) || !self::validCapability($cap)) $errors[] = "page $i has invalid capability: $cap";
                }
                $blocks = $page['blocks'] ?? null;
                if (!is_array($blocks) || count($blocks) > 50) {
                    $errors[] = "page $i must have at most 50 blocks";
                } else foreach ($blocks as $j => $block) {
                    if (!is_array($block)) { $errors[] = "page $i block $j must be an object"; continue; }
                    foreach (array_keys($block) as $k) {
                        if (!in_array($k, ['instance', 'type', 'source', 'props'], true)) $errors[] = "unknown block key: $k";
                    }
                    if (!isset($block['instance']) || !Util::validSlug((string)$block['instance'])) $errors[] = "page $i block $j invalid instance";
                    if (!in_array($block['type'] ?? null, self::BLOCK_TYPES, true)) $errors[] = "page $i block $j unknown type";
                    if (!is_string($block['source'] ?? null) || !self::validSource($block['source'])) $errors[] = "page $i block $j invalid source";
                    if (isset($block['props'])) {
                        if (!is_array($block['props'])) $errors[] = "page $i block $j props must be an object";
                        else {
                            $json = json_encode($block['props']);
                            foreach (self::BAD_MARKERS as $marker) {
                                if (stripos($json, $marker) !== false) $errors[] = "page $i block $j props contains $marker";
                            }
                        }
                    }
                }
            }
        }

        // feature flags
        if (!is_array($cfg['feature_flags'] ?? null)) $errors[] = 'feature_flags must be an object';
        else foreach ($cfg['feature_flags'] as $k => $v) {
            if (!in_array($k, ['hermes_proxy', 'voice', 'self_test', 'search', 'wizard'], true) || !is_bool($v)) {
                $errors[] = "invalid feature flag: $k";
            }
        }

        // prompts
        $prompts = $cfg['prompts'] ?? null;
        if (!is_array($prompts)) $errors[] = 'prompts must be an object';
        else {
            if (!in_array($prompts['style'] ?? null, ['plain', 'concise', 'detailed', 'casual-ok'], true)) $errors[] = 'prompts.style invalid';
            if (isset($prompts['normalise']) && !is_bool($prompts['normalise'])) $errors[] = 'prompts.normalise must be boolean';
        }

        // schedule
        if (!is_array($cfg['schedule'] ?? null)) $errors[] = 'schedule must be an object';
        elseif (!Scheduler::parse($cfg['schedule']['self_test'] ?? null)) $errors[] = 'schedule.self_test invalid';

        return $errors ? ['ok' => false, 'errors' => $errors] : ['ok' => true, 'errors' => []];
    }

    public static function validSource(string $source): bool {
        if (preg_match('/(secret|reveal|raw|debug|ticket|token|pty|terminal)/i', $source)) return false;
        if (str_starts_with($source, '/api/')) return false;
        $allowed = array_merge(['panel.bootstrap', 'panel.agents', 'panel.sessions', 'panel.findings',
            'panel.audit', 'panel.approvals', 'panel.config'], array_keys(HermesSources::catalog()));
        return in_array($source, $allowed, true);
    }

    public static function validCapability(string $cap): bool {
        if (preg_match('/(secret|reveal|raw|debug|admin)/i', $cap)) return false;
        return (bool) preg_match('/^[a-z][a-z0-9_.]{1,60}$/', $cap);
    }

    private static function err(string $message): array {
        return ['ok' => false, 'errors' => [$message]];
    }
}
