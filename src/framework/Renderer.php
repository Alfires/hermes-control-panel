<?php
declare(strict_types=1);

namespace Deck\Framework;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Composes a page from a declarative config + the built-in page registry.
 *  This is the server-rendered foundation the browser views will migrate to:
 *  adding a page is one registry/config entry plus one block template. */
final class Renderer {
    private Environment $twig;

    public function __construct(?string $templateDir = null) {
        if (!class_exists('PageRegistry')) {
            require_once dirname(__DIR__) . '/lib/registry.php';
        }
        $dir = $templateDir ?: dirname(__DIR__) . '/framework/templates';
        $this->twig = new Environment(new FilesystemLoader($dir), [
            'autoescape' => 'html',
            'cache' => false,
            'strict_variables' => false,
        ]);
        // Twig's `replace` filter is a mapping replacement, not preg_replace;
        // give templates an explicit, deterministic field-name slugger.
        $this->twig->addFilter(new \Twig\TwigFilter('slug', static function (mixed $value): string {
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value) ?? '';
            return $slug === '' ? 'field' : substr($slug, 0, 64);
        }));
    }

    /** Render a registered page. Unknown pages get an honest "not registered" view.
     *  A validated manifest in `$config['manifests'][$pageId]` takes priority;
     *  otherwise the ordinary page registry/config array is used (unchanged). */
    public function page(string $pageId, array $config = [], array $data = []): string {
        $page = null;
        if (!empty($config['manifests'][$pageId])) {
            $manifest = PageManifests::validate($config['manifests'][$pageId]);
            if ($manifest !== null && $manifest['id'] === $pageId) {
                $page = PageManifests::toPage($manifest);
            }
        }
        foreach (($page === null ? ($config['pages'] ?? []) : []) as $candidate) {
            if (($candidate['id'] ?? null) === $pageId) { $page = $candidate; break; }
        }
        if ($page === null) $page = \PageRegistry::builtin()[$pageId] ?? null;
        if ($page === null) {
            return $this->twig->render('layout.twig', [
                'title' => 'Page not registered', 'description' => $pageId,
                'blocks' => [['html' => $this->twig->render('blocks/generic.twig', [
                    'props' => ['title' => 'Page not registered', 'note' => 'Add it to PageRegistry first.'],
                    'data' => [], 'instance' => 'missing',
                ])]],
                'theme' => $config['theme'] ?? [],
            ]);
        }

        $blocks = [];
        foreach (($page['blocks'] ?? []) as $i => $block) {
            $type = (string)($block['type'] ?? '');
            if (!BlockCatalog::supports($type)) continue; // unknown kind: dropped, never rendered
            $blocks[] = [
                'html' => $this->twig->render('blocks/' . BlockCatalog::template($type) . '.twig', [
                    'props' => BlockCatalog::validate($type, $block['props'] ?? []),
                    'data' => $data,
                    'instance' => (string)($block['instance'] ?? 'block-' . $i),
                ]),
            ];
        }
        return $this->twig->render('layout.twig', [
            'title' => (string)($page['nav']['label'] ?? ucwords(str_replace('-', ' ', $pageId))),
            'description' => (string)($page['description'] ?? ''),
            'blocks' => $blocks,
            'theme' => $config['theme'] ?? [],
        ]);
    }

    /** Render the full page shell directly from a manifest (AI preview path).
     *  Invalid input falls back to the honest "not registered" view. */
    public function manifest(array $manifest, array $data = []): string {
        $clean = PageManifests::validate($manifest);
        if ($clean === null) return $this->page('manifest-not-valid');
        return $this->page($clean['id'], ['manifests' => [$clean['id'] => $clean]], $data);
    }

    /** Render one block directly; used by the AI preview/diff endpoint. */
    public function block(string $type, array $props = [], array $data = []): string {
        if (!BlockCatalog::supports($type)) {
            return '<div class="card">Unknown block type</div>';
        }
        return $this->twig->render('blocks/' . BlockCatalog::template($type) . '.twig', [
            'props' => BlockCatalog::validate($type, $props),
            'data' => $data,
            'instance' => 'preview',
        ]);
    }
}
