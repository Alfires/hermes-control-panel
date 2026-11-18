<?php
declare(strict_types=1);

require_once __DIR__ . '/registry.php';

/** Server-side page/block renderer. All output is escaped; no raw HTML from config
 *  is ever emitted. The client hydrates these blocks with data from the API. */
final class Render {
    private static function e(mixed $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function page(string $id, array $config, array $opts = []): string {
        $pages = [];
        foreach ($config['pages'] ?? [] as $p) $pages[(string)($p['id'] ?? '')] = $p;
        $page = $pages[$id] ?? PageRegistry::builtin()[$id] ?? null;
        $title = self::e($page['nav']['label'] ?? ucwords(str_replace('-', ' ', $id)));
        $mode = $opts['mode'] ?? 'empty';
        $h = ['<section class="hcp-page" data-page="' . self::e($id) . '" data-mode="' . self::e($mode) . '">'];
        $h[] = '<header class="hcp-page-header"><h1>' . $title . '</h1>';
        $h[] = '<span class="hcp-page-badge">' . self::e($page['nav']['group'] ?? 'Workspace') . '</span></header>';
        if ($mode === 'error') {
            $h[] = '<div class="hcp-error" role="alert">This view could not load its data. The panel is still safe; retry or check Hermes health.</div>';
        }
        if (!$page) {
            $h[] = '<div class="hcp-empty">Unknown page. Choose a page from the sidebar.</div>';
        } else {
            foreach ($page['blocks'] ?? [] as $block) {
                $h[] = self::block((string)($block['type'] ?? 'table'), array_merge(
                    ['title' => $page['nav']['label'] ?? $id], $block['props'] ?? []),
                    ['mode' => $mode, 'instance' => $block['instance'] ?? '', 'source' => $block['source'] ?? '']);
            }
        }
        if ($mode === 'populated') {
            $h[] = '<div class="hcp-sample" aria-label="sample rows">'
                . '<div class="hcp-sample-row"><span>system</span><span>healthy</span></div>'
                . '<div class="hcp-sample-row"><span>security</span><span>scanning</span></div></div>';
        } elseif ($mode === 'empty') {
            $h[] = '<div class="hcp-empty">Nothing here yet. Data appears as your agents work.</div>';
        }
        $h[] = '</section>';
        return implode("\n", $h);
    }

    public static function block(string $type, array $props, array $opts = []): string {
        $mode = $opts['mode'] ?? 'empty';
        $title = self::e($props['title'] ?? ucfirst($type));
        $instance = self::e($opts['instance'] ?? $type);
        $h = ['<div class="hcp-block hcp-block-' . self::e($type) . '" data-block="' . self::e($type)
            . '" data-instance="' . $instance . '" data-mode="' . self::e($mode) . '">'];
        $h[] = '<h2 class="hcp-block-title">' . $title . '</h2>';
        if ($mode === 'error') {
            $h[] = '<p class="hcp-error">Data unavailable for this block.</p>';
        } elseif ($mode === 'empty') {
            $h[] = '<p class="hcp-empty">No items yet.</p>';
        } else {
            $h[] = '<ul class="hcp-block-list"><li>Deck System <span class="hcp-pill hcp-pill-good">healthy</span></li>'
                . '<li>Exploit Watch <span class="hcp-pill hcp-pill-info">idle</span></li></ul>';
        }
        $h[] = '</div>';
        return implode("\n", $h);
    }
}
