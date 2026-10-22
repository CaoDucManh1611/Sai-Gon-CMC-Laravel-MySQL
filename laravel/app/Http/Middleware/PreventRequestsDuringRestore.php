<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class PreventRequestsDuringRestore
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('management/backups', 'management/backups/*')) {
            return $next($request);
        }
        File::ensureDirectoryExists(config('backup.path'));
        $handle = fopen(config('backup.path').'/.operation.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_SH | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }

            return response('Hệ thống đang sao lưu hoặc khôi phục dữ liệu. Vui lòng thử lại sau ít phút.', 503, ['Retry-After' => '15']);
        }
        try {
            return $next($request);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
