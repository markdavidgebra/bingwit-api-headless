<?php

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PublicStorageUrl
{
    /**
     * Build a browser-ready URL for a file on the public disk.
     */
    public static function fromRelativePath(string $relativePath): string
    {
        $relativePath = self::normalizeRelativePath($relativePath);

        // Always build from APP_URL so Laragon subdirectory installs
        // (e.g. /bingwit-api-headless/public) are not stripped to http://host/storage/...
        return self::fromAppUrl($relativePath);
    }

    /**
     * Normalize a stored path or URL (including legacy Laragon URLs).
     */
    public static function normalize(?string $value): ?string
    {
        if (! $value || ! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#/storage/(.+)$#i', $value, $matches)) {
            return self::fromRelativePath($matches[1]);
        }

        if (! preg_match('#^https?://#i', $value)) {
            return self::fromRelativePath($value);
        }

        return $value;
    }

    public static function fromMedia(?Media $media): ?string
    {
        if (! $media) {
            return null;
        }

        try {
            return self::fromRelativePath($media->getPathRelativeToRoot());
        } catch (\Throwable) {
            return self::normalize($media->getUrl());
        }
    }

    private static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        return $path;
    }

    private static function fromAppUrl(string $relativePath): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if (str_ends_with($base, '/public')) {
            return $base . '/storage/' . $relativePath;
        }

        return $base . '/storage/' . $relativePath;
    }
}
