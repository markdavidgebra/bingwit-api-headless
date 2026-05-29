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
     *  - whether public/storage symlink exists
     *  - default filesystem disk
     *  - APP_URL
     *  - count of catches that ended up with zero media attached
     *
     * Safe to leave on; exposes no user data.
     */
    public function uploads(Request $request)
    {
        $publicStorage = public_path('storage');

        $totalCatches = FishCatch::count();
        $catchesWithoutMedia = FishCatch::doesntHave('media')->count();

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
                'public_storage_path' => $publicStorage,
                'exists'              => file_exists($publicStorage),
                'is_link'             => is_link($publicStorage),
                'readlink'            => is_link($publicStorage) ? readlink($publicStorage) : null,
                'public_disk_writable'=> Storage::disk('public')->getDriver() !== null
                                          && is_writable(storage_path('app/public')),
            ],
            'catches' => [
                'total'         => $totalCatches,
                'without_media' => $catchesWithoutMedia,
                'with_media'    => $totalCatches - $catchesWithoutMedia,
            ],
        ]);
    }
}
