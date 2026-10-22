<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function index(BackupService $backups): View
    {
        return view('staff.backups', ['backups' => $backups->listing()]);
    }

    public function store(BackupService $backups): RedirectResponse
    {
        try {
            $id = $backups->exclusive(fn () => $backups->create());
        } catch (Throwable $exception) {
            return $this->error($exception);
        }

        return to_route('management.backups.show', $id)->with('success', 'Đã tạo bản sao lưu. Bạn có thể tải tệp về máy.');
    }

    public function upload(Request $request, BackupService $backups): RedirectResponse
    {
        $request->validate(['backup' => ['required', 'file', 'extensions:cmcbackup', 'max:131072']]);
        try {
            $id = $backups->exclusive(fn () => $backups->import($request->file('backup')->getRealPath()));
        } catch (Throwable $exception) {
            return $this->error($exception);
        }

        return to_route('management.backups.show', $id)->with('success', 'Đã kiểm tra và nhập bản sao lưu. Chưa thay đổi dữ liệu hiện tại.');
    }

    public function show(string $id, BackupService $backups): View|RedirectResponse
    {
        try {
            $payload = $backups->inspect($backups->path($id));
        } catch (Throwable $exception) {
            return $this->error($exception);
        }

        return view('staff.backup', [
            'id' => $id, 'createdAt' => $payload['created_at'], 'reason' => $payload['reason'],
            'tables' => array_map('count', $payload['tables']), 'files' => count($payload['files']),
            'size' => filesize($backups->path($id)),
        ]);
    }

    public function download(string $id, BackupService $backups): BinaryFileResponse
    {
        $path = $backups->path($id);
        abort_unless(is_file($path), 404);

        return response()->download($path, 'cmc-'.$id.'.cmcbackup', [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restore(Request $request, string $id, BackupService $backups): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:KHOI PHUC'],
        ], ['confirmation.in' => 'Nhập chính xác KHOI PHUC để xác nhận.']);
        try {
            $result = $backups->exclusive(fn () => $backups->restore($id));
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('success', 'Đã khôi phục dữ liệu. Bản dự phòng trước khôi phục: '.$result['safety_id'].'. Đăng nhập bằng tài khoản có trong bản sao lưu. Chỉ mục RAG đang được tạo lại.');
    }

    private function error(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof RuntimeException || $exception instanceof QueryException) {
            report($exception);
        }

        return to_route('management.backups.index')->withErrors([
            'backup' => $exception instanceof RuntimeException && ! $exception instanceof QueryException
                ? $exception->getMessage() : 'Không hoàn tất được thao tác. Dữ liệu được giữ nguyên; hãy kiểm tra dung lượng và quyền ghi rồi thử lại.',
        ]);
    }
}
