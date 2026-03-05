<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfImageService
{
    /**
     * Converte uma imagem (path relativo/public URL) para data-uri.
     */
    public function toDataUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $normalized = trim($path);
        if ($normalized === '') {
            return null;
        }

        $relativePath = $this->normalizeToStorageRelativePath($normalized);
        if ($relativePath === null) {
            return null;
        }

        if (!Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($relativePath);
        if (!is_file($absolutePath)) {
            return null;
        }

        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return null;
        }

        $mime = File::mimeType($absolutePath) ?: 'application/octet-stream';
        return sprintf('data:%s;base64,%s', $mime, base64_encode($raw));
    }

    private function normalizeToStorageRelativePath(string $path): ?string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            $urlPath = (string) parse_url($path, PHP_URL_PATH);
            if ($urlPath === '') {
                return null;
            }
            $path = $urlPath;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        if (Str::startsWith($path, 'uploads/produtos/')) {
            return ltrim(substr($path, strlen('uploads/')), '/');
        }

        return $path;
    }
}
