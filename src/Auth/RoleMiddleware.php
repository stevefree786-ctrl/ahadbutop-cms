<?php
namespace CMS\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces a minimum role. Must run AFTER AuthMiddleware so that
 * `user_role` is present on the request.
 */
class RoleMiddleware implements MiddlewareInterface
{
    public const HIERARCHY = ['author' => 1, 'editor' => 2, 'admin' => 3];

    private string $required;
    private ResponseFactoryInterface $responseFactory;

    public function __construct(string $required = 'author', ?ResponseFactoryInterface $responseFactory = null)
    {
        $this->required = $required;
        // PSR-7 requests have no getResponseFactory(); fall back to a plain factory.
        $this->responseFactory = $responseFactory ?? new \Slim\Psr7\Factory\ResponseFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role', '');

        if (!isset(self::HIERARCHY[$role])) {
            return $this->deny($request, 'Forbidden', 403);
        }

        if (self::HIERARCHY[$role] < self::HIERARCHY[$this->required]) {
            return $this->deny($request, "Requires {$this->required} role or higher", 403);
        }

        return $handler->handle($request);
    }

    private function deny(ServerRequestInterface $request, string $message, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(json_encode([
            'error'  => $message,
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}