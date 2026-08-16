<?php

namespace Lightspeed\Http;

class MimeTypes
{
    /**
     * Focused web-asset MIME map for static file serving.
     * Keep this intentionally narrow and let applications override as needed.
     */
    private const DEFAULTS = [
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'wasm' => 'application/wasm',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'apng' => 'image/apng',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    public static function forPath(string $path, array $overrides = []): string
    {
        $extension = self::normalizeExtension(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '') {
            return 'application/octet-stream';
        }

        $mimeTypes = array_merge(
            self::DEFAULTS,
            self::normalizeOverrides($overrides),
        );

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    private static function normalizeOverrides(array $overrides): array
    {
        $normalized = [];

        foreach ($overrides as $extension => $mimeType) {
            if (! is_string($extension) || ! is_string($mimeType) || trim($mimeType) === '') {
                continue;
            }

            $normalized[self::normalizeExtension($extension)] = trim($mimeType);
        }

        return $normalized;
    }

    private static function normalizeExtension(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }
}
