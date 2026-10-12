<?php

namespace App\Support;

class UiFormat
{
    public static function quantity(int|float|string|null $value): string
    {
        $formatted = number_format((float) ($value ?? 0), 3, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    public static function inputQuantity(int|float|string|null $value): string
    {
        $formatted = number_format((float) ($value ?? 0), 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function status(?string $status): string
    {
        return [
            'requested' => 'Chờ xử lý', 'draft' => 'Bản nháp', 'sent' => 'Đã gửi',
            'accepted' => 'Đã chấp nhận', 'pending' => 'Chờ duyệt',
            'pending_approval' => 'Chờ phê duyệt', 'approved' => 'Đã duyệt',
            'rejected' => 'Đã từ chối', 'changes_requested' => 'Yêu cầu chỉnh sửa',
            'signed' => 'Đã ký', 'cancelled' => 'Đã hủy',
            'awaiting_confirmation' => 'Chờ xác nhận', 'awaiting_payment' => 'Chờ thanh toán', 'ready_to_issue' => 'Chờ xuất kho',
            'ready_to_ship' => 'Sẵn sàng giao', 'shipping' => 'Đang giao',
            'planned' => 'Đã lên lịch', 'picked_up' => 'Đã nhận hàng để giao',
            'delivered' => 'Đã giao', 'delivery_incident' => 'Giao hàng gặp sự cố',
            'inspected' => 'Đã kiểm tra', 'completed' => 'Hoàn tất',
            'received' => 'Đã nhập kho', 'open' => 'Đang xử lý',
            'resolved' => 'Đã xử lý', 'settled' => 'Đã thanh toán đủ',
            'incident' => 'Giao hàng gặp sự cố', 'replacement_incident' => 'Giao hàng thay thế gặp sự cố', 'replacement_shipping' => 'Đang giao hàng thay thế',
        ][$status ?? ''] ?? 'Đang xử lý';
    }
}
