<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AssistantController extends Controller
{
    private const OUT_OF_SCOPE = 'Mình là trợ lý của VLXD Sài Gòn CMC, chỉ hỗ trợ tư vấn vật liệu xây dựng, sản phẩm, báo giá và dịch vụ của cửa hàng. Bạn cần tìm loại vật liệu nào cho công trình?';
    public function index(): View
    {
        return view('store.assistant');
    }

    public function ask(Request $request): View|JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:1000']]);
        $question = trim($data['question']);
        $normalized = Str::lower(Str::ascii($question));

        if (preg_match('/^(xin chao|chao|hello|hi|alo|cam on|thanks)[!?. ]*$/', $normalized)) {
            return $this->respond($request, $question, 'Xin chào! Mình hỗ trợ tư vấn vật liệu xây dựng, tìm sản phẩm và quy trình báo giá tại Sài Gòn CMC. Bạn đang cần vật liệu gì?');
        }

        if (! $this->isCmcTopic($normalized)) {
            return $this->respond($request, $question, self::OUT_OF_SCOPE);
        }

        return $this->respond($request, $question, $this->localReply($question, $normalized));
    }

    private function localReply(string $question, string $normalized): string
    {
        $products = DB::table('products')->where('active', true)->orderBy('name')
            ->limit(100)->get(['name', 'specification', 'unit', 'price']);
        $ignored = ['bao', 'gia', 'cho', 'cong', 'trinh', 'can', 'loai', 'nao', 'san', 'pham', 'mua', 'hang', 'toi', 'co', 'the', 'duoc', 'khong'];
        $terms = collect(preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $term) => strlen($term) >= 3 && ! in_array($term, $ignored, true));
        $matches = $products->filter(function ($product) use ($terms) {
            $text = Str::lower(Str::ascii($product->name.' '.$product->specification));

            return $terms->contains(fn (string $term) => Str::contains($text, $term));
        })->take(5);

        if ($matches->isEmpty()) {
            return 'Mình chưa tìm thấy sản phẩm phù hợp với từ khóa này trong danh mục CMC. Bạn hãy cho biết loại vật liệu, quy cách hoặc khối lượng cần dùng để mình tra cứu chính xác hơn.';
        }

        return "Mình tìm thấy các sản phẩm phù hợp trong danh mục CMC:\n\n"
            .$matches->map(fn ($product) => '- '.$product->name.' — '.$product->specification.' — '
                .number_format($product->price, 0, ',', '.').' đồng/'.$product->unit)->implode("\n")
            ."\n\nGiá trên đây chỉ mang tính tham khảo. Nhân viên CMC sẽ xác nhận báo giá và tình trạng cung ứng trước khi chốt đơn.";
    }

    private function isCmcTopic(string $question): bool
    {
        $terms = [
            'cmc', 'vlxd', 'vat lieu', 'vat tu', 'xay dung', 'cong trinh',
            'xi mang', 'pcb', 'gach', 'cat', 'da 1x2', 'da mi', 'da dam', 'da base', 'thep', 'sat', 'ton', 'ngoi',
            'be tong', 'co lieu', 'cot thep', 'son', 'chong tham', 'keo dan', 'ong nuoc',
            'cap phoi', 'luoi thep', 'gach lat', 'mua vat lieu', 'san pham',
            'bao gia', 'gia ban', 'don gia', 'du toan', 'khoi luong',
            'quy cach', 'kich thuoc', 'chat luong', 'bao hanh', 'doi tra',
            'dat hang', 'don hang', 'giao hang', 'nhan hang', 'van chuyen',
            'hoa don', 'hop dong', 'thanh toan', 'ton kho', 'chiet khau',
            'tu van vat lieu', 'cua hang', 'lien he', 'ho tro khach hang',
        ];

        foreach ($terms as $term) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?![a-z0-9])/', $question)) {
                return true;
            }
        }

        return false;
    }

    private function respond(Request $request, string $question, string $reply): View|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['reply' => $reply])
            : view('store.assistant', ['question' => $question, 'reply' => $reply]);
    }
}
