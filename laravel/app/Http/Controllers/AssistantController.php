<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;

class AssistantController extends Controller
{
    public function index(): View
    {
        return view('store.assistant');
    }

    public function ask(Request $request): View|JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:1000']]);
        $key = config('services.gemini.key');
        if (! $key) {
            $reply = 'Trợ lý AI chưa được cấu hình. Vui lòng liên hệ nhân viên để được tư vấn.';
            return $request->expectsJson() ? response()->json(['reply' => $reply]) : view('store.assistant', ['question' => $data['question'], 'reply' => $reply]);
        }
        $products = DB::table('products')->where('active', true)->orderBy('name')
            ->limit(50)->get(['name', 'specification', 'unit', 'price']);
        $catalog = $products->map(fn ($p) => $p->name.' | '.$p->specification.' | '.number_format($p->price, 0, ',', '.').' đồng/'.$p->unit)->implode("\n");
        try {
            $response = Http::timeout(20)->withHeaders(['x-goog-api-key' => $key])->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent',
                ['contents' => [['parts' => [['text' => "Bạn là tư vấn viên vật liệu xây dựng Sài Gòn CMC. Chỉ tư vấn dựa trên danh mục bên dưới. Giá là tham khảo, báo giá chính thức do nhân viên xác nhận. Nếu chưa đủ thông tin về công trình, hãy hỏi thêm. Không tự tạo giá hay khẳng định về tồn kho.\nDanh mục:\n".$catalog."\nCâu hỏi khách hàng: ".$data['question']]]]]],
            );
            $reply = $response->successful() ? $response->json('candidates.0.content.parts.0.text') : null;
        } catch (\Throwable $e) {
            report($e);
            $reply = null;
        }

        if ($request->expectsJson()) {
            return response()->json(['reply' => $reply ?: 'Hiện chưa kết nối được trợ lý AI. Vui lòng thử lại hoặc liên hệ nhân viên.']);
        }

        return view('store.assistant', [
            'question' => $data['question'],
            'reply' => $reply ?: 'Hiện chưa kết nối được trợ lý AI. Vui lòng thử lại hoặc liên hệ nhân viên.',
        ]);
    }
}
