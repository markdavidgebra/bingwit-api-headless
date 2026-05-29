<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * GET /api/_health/uploads
     *
     * Public, read-only diagnostic. Tells you whether the live server is
     * actually capable of accepting photo uploads:
     *  - PHP file_uploads + size limits
     *  - whether public/storage symlink exists and is reachable
     *  - whether the public disk is actually writable + URL-resolvable
     *  - default filesystem disk
     *  - APP_URL
     *  - count of catches that ended up with zero media attached
     *
     * Safe to leave on; exposes no user data.
     */
    public function uploads(Request $request)
    {
        $publicStoragePath = public_path('storage');

        $totalCatches = FishCatch::count();
        $catchesWithoutMedia = FishCatch::doesntHave('media')->count();

        // Active write probe: write a tiny file via the public disk, build a
        // URL for it, hit that URL with a HEAD request, and confirm the round
        // trip works. This is the most reliable signal that uploads will
        // actually surface to clients.
        $writeProbe = $this->probePublicDiskWrite();

        return response()->json([
            'php' => [
                'version'             => PHP_VERSION,
                'file_uploads'        => (bool) ini_get('file_uploads'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'max_file_uploads'    => ini_get('max_file_uploads'),
                'memory_limit'        => ini_get('memory_limit'),
                'max_execution_time'  => ini_get('max_execution_time'),
                'gd_loaded'           => extension_loaded('gd'),
                'imagick_loaded'      => extension_loaded('imagick'),
            ],
            'laravel' => [
                'app_url'         => config('app.url'),
                'app_env'         => config('app.env'),
                'default_disk'    => config('filesystems.default'),
                'public_disk_url' => config('filesystems.disks.public.url'),
            ],
            'storage_symlink' => [
                'public_storage_path' => $publicStoragePath,
                'exists'              => file_exists($publicStoragePath),
                'is_link'             => is_link($publicStoragePath),
                'readlink'            => is_link($publicStoragePath) ? @readlink($publicStoragePath) : null,
                'public_app_dir'      => storage_path('app/public'),
                'public_dir_writable' => is_writable(storage_path('app/public')),
            ],
            'write_probe' => $writeProbe,
            'catches' => [
                'total'         => $totalCatches,
                'without_media' => $catchesWithoutMedia,
                'with_media'    => $totalCatches - $catchesWithoutMedia,
            ],
        ]);
    }

    /**
     * Try to actually write a tiny file to the public disk and confirm it's
     * publicly fetchable via the URL Spatie would construct.
     */
    private function probePublicDiskWrite(): array
    {
        $relativePath = '_health/probe-' . uniqid() . '.txt';
        $body = 'bingwit-upload-probe ' . now()->toDateTimeString();

        $result = [
            'wrote'        => false,
            'url'          => null,
            'fetchable'    => null,
            'fetch_status' => null,
            'error'        => null,
        ];

        try {
            Storage::disk('public')->put($relativePath, $body);
            $result['wrote'] = true;
            $result['url']   = Storage::disk('public')->url($relativePath);

            // Round-trip: fetch the URL we just minted. If symlink + APP_URL
            // are correct, this returns 200. If 404 -> storage:link missing
            // or APP_URL wrong. If 403 -> directory permissions wrong.
            $ctx = stream_context_create([
                'http' => ['method' => 'HEAD', 'timeout' => 4, 'ignore_errors' => true],
            ]);
            $headers = @get_headers($result['url'], 0, $ctx);
            if (is_array($headers) && isset($headers[0])) {
                $result['fetch_status'] = $headers[0];
                $result['fetchable']    = (bool) preg_match('/\b200\b/', $headers[0]);
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        } finally {
            try {
                if (Storage::disk('public')->exists($relativePath)) {
                    Storage::disk('public')->delete($relativePath);
                }
            } catch (\Throwable $e) {
                // best-effort cleanup
            }
        }

        return $result;
    }
}
