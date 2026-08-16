<?php

namespace Lightspeed\Http;

use DateTime;
use Illuminate\Http\Request;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\SwooleClient as OctaneSwooleClient;
use Symfony\Component\HttpFoundation\Response;
use Swoole\Http\Response as SwooleResponse;

/**
 * Octane's Swoole client, with the two response paths this server has to write
 * itself.
 *
 * Why this file exists: Lightspeed serves the application's own HTTP routes
 * through Octane while running its own Swoole server rather than Octane's, and
 * two of Octane's response paths do not survive that. Both overrides fix a
 * concrete defect; neither exists to customise anything:
 *
 *   serveStaticFile()      Octane infers a static file's Content-Type from a
 *                          table of its own. Here it comes from MimeTypes plus
 *                          `lightspeed.static_files.mime_types`, so a host app
 *                          can name the types it serves and a file does not go
 *                          out labelled as the wrong one.
 *
 *   sendResponseHeaders()  Set-Cookie is pulled out of the flat header list and
 *                          re-emitted through Swoole's cookie()/rawcookie(), so
 *                          cookie attributes (SameSite, Secure, HttpOnly,
 *                          Partitioned) survive instead of being flattened into
 *                          a header string. Partitioned only exists on Swoole
 *                          6+, which is why that parameter list is built
 *                          conditionally. A Date header is filled in when the
 *                          response carries none, because nothing else in this
 *                          path adds one.
 *
 * Owns: those two response paths and nothing else.
 * Deliberately does not own: the request lifecycle, routing, or the decision
 * that a path is a static file; Octane and the server settle all of that
 * before anything here runs.
 *
 * The name deliberately matches the Octane class it extends, because it is a
 * drop-in replacement for it; the alias above keeps the two apart in this file.
 */
class SwooleClient extends OctaneSwooleClient
{
    public function serveStaticFile(Request $request, RequestContext $context): void
    {
        $swooleResponse = $context->swooleResponse;

        $publicPath = $context->publicPath;
        $octaneConfig = $context->octaneConfig ?? [];

        if (! empty($octaneConfig['static_file_headers'])) {
            $formatHeaders = config('octane.swoole.format_headers', true);

            foreach ($octaneConfig['static_file_headers'] as $pattern => $headers) {
                if ($request->is($pattern)) {
                    foreach ($headers as $name => $value) {
                        $swooleResponse->header($name, $value, $formatHeaders);
                    }
                }
            }
        }

        $swooleResponse->status(200);
        $swooleResponse->header('Content-Type', $this->staticMimeType($request->path()));
        $swooleResponse->sendfile(realpath($publicPath.'/'.$request->path()));
    }

    public function sendResponseHeaders(Response $response, SwooleResponse $swooleResponse): void
    {
        if (!$response->headers->has('Date')) {
            $response->setDate(DateTime::createFromFormat('U', time()));
        }

        $headers = $response->headers->allPreserveCase();

        if (isset($headers['Set-Cookie'])) {
            unset($headers['Set-Cookie']);
        }

        $formatHeaders = config('octane.swoole.format_headers', true);

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value, $formatHeaders);
            }
        }

        if (!is_null($reason = $this->getReasonFromStatusCode($response->getStatusCode()))) {
            $swooleResponse->status($response->getStatusCode(), $reason);
        } else {
            $swooleResponse->status($response->getStatusCode());
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $shouldDelete = (string) $cookie->getValue() === '';

            $method = $cookie->isRaw() ? 'rawcookie' : 'cookie';
            $params = [
                $cookie->getName(),
                $shouldDelete ? 'deleted' : $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? '',
            ];

            if (extension_loaded('swoole') && SWOOLE_VERSION_ID >= 60000) {
                $params[] = '';
                $params[] = method_exists($cookie, 'isPartitioned') ? $cookie->isPartitioned() : false;
            }

            $swooleResponse->$method(...$params);
        }
    }

    private function staticMimeType(string $path): string
    {
        return MimeTypes::forPath(
            $path,
            (array) config('lightspeed.static_files.mime_types', []),
        );
    }
}
