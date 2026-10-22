<?php

namespace App\Http\Controllers;

use App\Services\GroqSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiSetupController extends Controller
{
    public function index(GroqSettings $settings): View
    {
        $credentials = $settings->credentials();

        return view('staff.ai-setup', [
            'configured' => (bool) $credentials['key'],
            'model' => $credentials['model'],
        ]);
    }

    public function update(Request $request, GroqSettings $settings): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'min:20', 'max:512', 'regex:/\\A[A-Za-z0-9_-]+\\z/'],
            'model' => ['required', 'string', 'max:120', 'regex:/\\A[A-Za-z0-9][A-Za-z0-9._\\/-]*\\z/'],
            'action' => ['required', 'in:save,test,clear'],
        ], [
            'api_key.regex' => 'API key không đúng định dạng. Không nhập dấu cách hoặc xuống dòng.',
            'api_key.min' => 'API key quá ngắn. Hãy sao chép đầy đủ khóa Groq.',
            'model.regex' => 'Tên model chỉ được chứa chữ, số, dấu chấm, gạch ngang, gạch dưới hoặc dấu /.',
        ]);
        $current = $settings->credentials();
        $key = $data['api_key'] ?? $current['key'];
        if ($data['action'] === 'clear') {
            $settings->save(null, $data['model']);

            return to_route('management.ai-setup')->with('success', 'Đã gỡ API key. Trợ lý sẽ dùng các đoạn trích từ kho tri thức.');
        }
        if (! $key) {
            throw ValidationException::withMessages(['api_key' => 'Hãy nhập API key Groq trước khi lưu hoặc kiểm tra kết nối.']);
        }
        if ($data['action'] === 'save') {
            $settings->save($key, $data['model']);

            return to_route('management.ai-setup')->with('success', 'Đã lưu cấu hình Groq. Trợ lý áp dụng ngay cho câu hỏi tiếp theo.');
        }

        try {
            $response = Http::connectTimeout(5)->timeout(20)->withToken($key)->post(
                'https://api.groq.com/openai/v1/chat/completions',
                [
                    'model' => $data['model'], 'max_completion_tokens' => 32,
                    'messages' => [['role' => 'user', 'content' => 'Reply OK.']],
                ],
            );
            $message = match (true) {
                $response->successful() => 'Kết nối Groq thành công với model '.$data['model'].'. Bấm Lưu cấu hình nếu bạn vừa nhập khóa hoặc model mới.',
                $response->status() === 401 => 'Groq từ chối API key. Kiểm tra hoặc tạo lại khóa trong tài khoản Groq.',
                $response->status() === 403 => 'Tài khoản hoặc API key chưa có quyền sử dụng model này.',
                $response->status() === 429 => 'Groq đang giới hạn yêu cầu hoặc tài khoản đã hết hạn mức. Hãy thử lại sau.',
                in_array($response->status(), [400, 404, 422], true) => 'Groq không chấp nhận model hoặc cấu hình này. Hãy kiểm tra lại tên model.',
                default => 'Groq hiện chưa phản hồi thành công. Hãy thử lại sau.',
            };
            $success = $response->successful();
        } catch (\Throwable) {
            // Never log an HTTP exception containing credentials or provider response bodies.
            $message = 'Không kết nối được Groq. Hãy kiểm tra Internet rồi thử lại.';
            $success = false;
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => $success, 'message' => $message]);
        }

        return to_route('management.ai-setup')->withInput($request->only('model'))
            ->with('ai_test', $message)->with('ai_test_success', $success);
    }
}
