<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once repo_path('vendor/autoload.php');

use Deck\Framework\BlockCatalog;
use Deck\Framework\Kernel;
use Deck\Framework\Renderer;
use Slim\Psr7\Factory\ServerRequestFactory;

// The PHP + JS framework is the foundation for config-driven browser pages.
// These checks prove a page is composed from data, escaped, and routed by Slim.
test('framework.page.renders.from.config', static function (): void {
    $html = (new Renderer())->page('overview', fixture_config(), ['agents' => [1, 2, 3]]);
    contains($html, 'data-block="kpi"', 'configured kpi block missing');
    contains($html, 'Overview', 'page title missing');
    contains($html, '/vendor/htmx-2.0.11.min.js', 'vendored htmx missing');
    contains($html, '/vendor/alpine-3.17.5.min.js', 'vendored Alpine missing');
    not_contains($html, 'https://cdn.', 'framework must not depend on a CDN');
    not_contains($html, '<script>', 'framework must not inject inline scripts');
    not_contains($html, fake_secret(1), 'config render leaked a secret');
});

test('framework.unknown.block.is.dropped', static function (): void {
    $config = fixture_config();
    $config['pages'][0]['blocks'][] = ['type' => 'evilBlock', 'props' => ['note' => 'SHOULD-NOT-RENDER']];
    $html = (new Renderer())->page('overview', $config, []);
    not_contains($html, 'SHOULD-NOT-RENDER', 'unknown block kind was rendered');
    ok(!BlockCatalog::supports('evilBlock'), 'unknown block must not be in the catalog');
});

test('framework.props.are.escaped.and.validated', static function (): void {
    $config = fixture_config();
    $config['pages'][0]['blocks'] = [[
        'instance' => 'hero-1', 'type' => 'hero',
        'props' => ['title' => '<img src=x onerror=alert(1)>', 'script' => 'evil()'],
    ]];
    $html = (new Renderer())->page('overview', $config, []);
    not_contains($html, '<img', 'unescaped HTML in a block prop');
    not_contains($html, 'evil()', 'unrecognised prop survived validation');
    contains($html, '&lt;img', 'expected Twig autoescaping');
});

test('framework.preview.renders.one.block', static function (): void {
    $html = (new Renderer())->block('table', [
        'title' => 'Agents', 'columns' => ['Name'], 'rows' => [['Chat']],
    ], []);
    contains($html, 'data-block="table"', 'block preview missing');
    contains($html, 'Chat', 'table row missing');
    not_contains($html, 'Unknown block type', 'known block declared unknown');
});

test('framework.slim.kernel.routes.a.page', static function (): void {
    $app = Kernel::app(fixture_config(), static fn(string $p): array => ['page' => $p]);
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/overview');
    $response = $app->handle($request);
    ok($response->getStatusCode() === 200, 'kernel did not return 200');
    $body = (string)$response->getBody();
    contains($body, 'data-block="kpi"', 'kernel route did not render the page');
    contains($response->getHeaderLine('Content-Security-Policy'), "default-src 'self'", 'CSP missing');
});
