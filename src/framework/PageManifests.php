<?php
declare(strict_types=1);

namespace Deck\Framework;

if (!class_exists('PageRegistry')) {
    require_once dirname(__DIR__) . '/lib/registry.php';
}
if (!class_exists('Util')) {
    require_once dirname(__DIR__) . '/lib/util.php';
}

/**
 * Declarative, AI-editable page manifests.
 *
 * A manifest is deliberately boring: it names a registered page, a title/icon,
 * a route and a list of allowlisted blocks. It can contain no code, no raw CSS,
 * no dynamic imports and no secret-bearing source. Disk manifests are the
 * shipped defaults; once a page has been proposed + approved its manifest is
 * read back out of ConfigGovernance's hash-chained `pages` revision instead.
 *
 * Unknown block types and unknown/forbidden sources are DROPPED (never
 * rendered) rather than trusted. A manifest that tries to smuggle markup,
 * credential-shaped strings or executable-ish keys is rejected outright.
 */
final class PageManifests {
    public const BOOTSTRAP_SOURCE = 'panel.bootstrap';
    private const MAX_BLOCKS = 24;
    private const MAX_TITLE = 120;

    /** Keys that must never appear in an AI-authored manifest. */
    private const FORBIDDEN_KEYS = [
        'script', 'scripts', 'css', 'style', 'styles', 'import', 'imports',
        'eval', 'code', 'html', 'javascript', 'php', 'onclick', 'onerror', 'onload',
    ];

    /** Markup / stylesheet / dynamic-import smuggling markers. */
    private const BAD_MARKERS = [
        '<script', '</script', 'onerror=', 'onload=', 'javascript:', '<style', '</style',
        '@import', 'url(', 'expression(', '<?php', '<iframe', '<svg', '__import__',
        'data:text/html', 'require(', 'include ',
    ];

    /** All shipped manifests, plus honest stubs for any registered page without one. */
    public static function defaults(): array {
        $out = [];
        $files = glob(__DIR__ . '/manifests/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (!is_array($raw)) continue;
            $manifest = self::validate($raw);
            if ($manifest !== null) $out[$manifest['id']] = $manifest;
        }
        foreach (\PageRegistry::ids() as $id) {
            if (!isset($out[$id])) $out[$id] = self::stub($id);
        }
        return $out;
    }

    /**
     * Effective manifests for an active config. Disk defaults render every
     * registered page; an approved revision (origin `manifest:vN`) overrides a
     * page with the exact stored manifest so approve/rollback/history work.
     */
    public static function effective(array $config): array {
        $out = self::defaults();
        foreach (($config['pages'] ?? []) as $page) {
            if (!is_array($page)) continue;
            $origin = (string) ($page['origin'] ?? '');
            if (!str_starts_with($origin, 'manifest:')) continue;
            $manifest = self::fromPage($page);
            if ($manifest !== null) $out[$manifest['id']] = $manifest;
        }
        return $out;
    }

    /** A manifest that only exists on disk (or a rejected draft) validates here. */
    public static function validate(mixed $raw, ?array &$report = null): ?array {
        $report = ['errors' => [], 'dropped' => []];
        if (!is_array($raw)) {
            $report['errors'][] = 'manifest must be an object';
            return null;
        }
        if (self::hasForbiddenKeys($raw)) {
            $report['errors'][] = 'manifest contains a non-declarative key (code/style/import)';
            return null;
        }

        $json = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 200000) {
            $report['errors'][] = 'manifest is too large or not encodable';
            return null;
        }
        foreach (self::BAD_MARKERS as $marker) {
            if (stripos($json, $marker) !== false) {
                $report['errors'][] = 'unsafe marker: ' . $marker;
                return null;
            }
        }
        // Credential shapes (bearer tokens, secrets, PEM, synthetic leak
        // markers) are refused before anything is stored or rendered.
        if (\Util::scrub($json) !== $json) {
            $report['errors'][] = 'manifest contains credential-like material';
            return null;
        }

        $id = (string) ($raw['id'] ?? '');
        if (!\Util::validSlug($id) || !in_array($id, \PageRegistry::ids(), true)) {
            $report['errors'][] = 'id is not a registered page';
            return null;
        }
        $version = (int) ($raw['version'] ?? 0);
        if ($version < 1 || $version > 1000000) {
            $report['errors'][] = 'version must be a positive integer';
            return null;
        }
        $title = trim((string) ($raw['title'] ?? ''));
        if ($title === '' || strlen($title) > self::MAX_TITLE || preg_match('/[\x00-\x1f\x7f]/', $title)) {
            $report['errors'][] = 'title must be 1-120 printable characters';
            return null;
        }
        $icon = trim((string) ($raw['icon'] ?? ''));
        if ($icon === '' || strlen($icon) > 8 || preg_match('/[\x00-\x1f\x7f]/', $icon)) {
            $report['errors'][] = 'icon must be 1-8 printable characters';
            return null;
        }
        $route = (string) ($raw['route'] ?? '');
        if (!preg_match('#^/[A-Za-z0-9/_-]{0,80}$#', $route)) {
            $report['errors'][] = 'route must be a safe absolute path';
            return null;
        }

        $blocks = $raw['blocks'] ?? null;
        if (!is_array($blocks) || count($blocks) < 1 || count($blocks) > self::MAX_BLOCKS) {
            $report['errors'][] = 'blocks must contain 1-24 entries';
            return null;
        }

        $seen = [];
        $cleanBlocks = [];
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                $report['dropped'][] = ['index' => $i, 'reason' => 'block is not an object'];
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if (!BlockCatalog::supports($type)) {
                $report['dropped'][] = ['index' => $i, 'type' => $type, 'reason' => 'unknown block type'];
                continue; // unknown kind: dropped, never rendered
            }
            $source = (string) ($block['source'] ?? '');
            if (!self::isValidSource($source)) {
                $report['dropped'][] = ['index' => $i, 'source' => $source, 'reason' => 'source is not curated'];
                continue;
            }
            $instance = (string) ($block['instance'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $instance)) $instance = 'block-' . $i;
            if (isset($seen[$instance])) $instance = substr($instance, 0, 58) . '-' . $i;
            $seen[$instance] = true;
            $cleanBlocks[] = [
                'instance' => $instance,
                'type' => $type,
                'source' => $source,
                'props' => BlockCatalog::validate($type, $block['props'] ?? []),
            ];
        }
        if (!$cleanBlocks) {
            $report['errors'][] = 'no renderable blocks remain after validation';
            return null;
        }

        return [
            'id' => $id,
            'version' => $version,
            'title' => $title,
            'icon' => $icon,
            'route' => $route,
            'blocks' => $cleanBlocks,
        ];
    }

    /** Only panel.bootstrap and curated Hermes sources are resolvable. */
    public static function isValidSource(string $source): bool {
        if ($source === self::BOOTSTRAP_SOURCE) return true;
        return isset(\HermesSources::catalog()[$source]) && !\HermesSources::isForbidden($source);
    }

    /** Manifest -> the exact page shape Schema::validateConfig already allows. */
    public static function toPage(array $manifest): array {
        $id = (string) ($manifest['id'] ?? '');
        $registered = \PageRegistry::builtin()[$id] ?? null;
        return [
            'id' => $id,
            'route' => (string) ($manifest['route'] ?? ('/' . $id)),
            'nav' => [
                'label' => (string) ($manifest['title'] ?? $id),
                'icon' => (string) ($manifest['icon'] ?? '▦'),
                'order' => (int) ($registered['nav']['order'] ?? 10),
            ],
            'capabilities' => ['panel.bootstrap'],
            'blocks' => array_values($manifest['blocks'] ?? []),
            'origin' => 'manifest:v' . (int) ($manifest['version'] ?? 1),
        ];
    }

    /** Read a stored, approved page entry back into a manifest. */
    public static function fromPage(array $page): ?array {
        $origin = (string) ($page['origin'] ?? '');
        $version = preg_match('/^manifest:v(\d+)$/', $origin, $m) ? (int) $m[1] : 1;
        $nav = is_array($page['nav'] ?? null) ? $page['nav'] : [];
        $raw = [
            'id' => (string) ($page['id'] ?? ''),
            'version' => $version,
            'title' => (string) ($nav['label'] ?? ($page['id'] ?? '')),
            'icon' => (string) ($nav['icon'] ?? '▦'),
            'route' => (string) ($page['route'] ?? ''),
            'blocks' => $page['blocks'] ?? [],
        ];
        return self::validate($raw);
    }

    /** Honest stub for a registered page with no custom manifest yet. */
    public static function stub(string $id): array {
        $registered = \PageRegistry::builtin()[$id] ?? null;
        $label = (string) ($registered['nav']['label'] ?? ucwords(str_replace('-', ' ', $id)));
        $raw = [
            'id' => $id,
            'version' => 1,
            'title' => $label,
            'icon' => (string) ($registered['nav']['icon'] ?? '▦'),
            'route' => (string) ($registered['route'] ?? ('/' . $id)),
            'blocks' => [[
                'instance' => 'stub-' . $id,
                'type' => 'hero',
                'source' => self::BOOTSTRAP_SOURCE,
                'props' => [
                    'title' => $label,
                    'subtitle' => 'Honest stub: this page is registered but has no custom manifest yet.',
                ],
            ]],
        ];
        return self::validate($raw) ?? $raw;
    }

    private static function hasForbiddenKeys(mixed $value): bool {
        if (!is_array($value)) return false;
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) return true;
            if (is_array($child) && self::hasForbiddenKeys($child)) return true;
        }
        return false;
    }
}
