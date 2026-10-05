<?php
namespace CMS\Api;

use Psr\Http\Message\ResponseInterface;

/**
 * Uniform JSON responses so every endpoint returns the same envelope
 * and the correct HTTP status code.
 */
class Responder
{
    /** @var array<int, string> */
    private static array $messages = [
        200 => 'OK',
        201 => 'Created',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
    ];

    public static function json(
        ResponseInterface $response,
        array $data,
        int $status = 200
    ): ResponseInterface {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    public static function ok(ResponseInterface $response, array $data = []): ResponseInterface
    {
        return self::json($response, $data, 200);
    }

    /**
     * An error reply, with optional structured detail merged into the body.
     *
     * The $detail argument exists because three call sites were already
     * passing one — 'available' on an unknown agent or skill, 'rejected' on a
     * bad settings write — and PHP was discarding it without complaint. A
     * caller that misspells a skill name was told it did not exist and left
     * to guess what does.
     *
     * Merged rather than nested under a 'detail' key so those payloads land
     * where the callers that wrote them expected to read them.
     */
    public static function error(ResponseInterface $response, int $status, ?string $message = null, array $detail = []): ResponseInterface
    {
        return self::json($response, array_merge([
            'error'   => $message ?? (self::$messages[$status] ?? 'Error'),
            'status'  => $status,
        ], $detail), $status);
    }

    /** 404 for a missing resource — the API must not report "not found" as 200. */
    public static function notFound(ResponseInterface $response, string $what = 'Resource'): ResponseInterface
    {
        return self::json($response, [
            'error'  => "$what not found",
            'status' => 404,
        ], 404);
    }
}