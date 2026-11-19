<?php
declare(strict_types=1);

namespace Deck\Framework;

use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

/** Thin Slim 4 kernel. Routes are generated from the page registry: a new page
 *  in config/registry gets a URL automatically, with no router edits. */
final class Kernel {
    public static function app(array $config, ?callable $dataFor = null): App {
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $renderer = new Renderer();

        $handler = static function (Request $request, Response $response, array $args) use ($renderer, $config, $dataFor): Response {
            $page = trim((string)($args['page'] ?? ''), '/');
            if ($page === '') $page = 'overview';
            $data = $dataFor ? $dataFor($page) : [];
            $response->getBody()->write($renderer->page($page, $config, $data));
            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Referrer-Policy', 'no-referrer')
                ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'");
        };
        $app->get('/', $handler);
        $app->get('/{page:[a-zA-Z0-9-]+}', $handler);
        return $app;
    }
}
