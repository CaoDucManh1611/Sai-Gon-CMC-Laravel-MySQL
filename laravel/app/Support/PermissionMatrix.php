<?php

namespace App\Support;

class PermissionMatrix
{
    public static function roles(): array
    {
        return [
            'customer' => 'Khách hàng',
            'sales' => 'Kinh doanh',
            'warehouse' => 'Kho',
            'accounting' => 'Kế toán',
            'manager' => 'Quản lý',
            'admin' => 'Quản trị viên',
            'delivery' => 'Giao nhận',
        ];
    }

    public static function permissions(): array
    {
        return [
            'customer.portal' => 'Truy cập khu vực khách hàng',
            'staff.access' => 'Truy cập khu vực nhân viên',
            'management.view' => 'Xem danh mục quản trị',
            'quotes.view' => 'Xem báo giá',
            'quotes.send' => 'Lập và gửi báo giá',
            'quotes.discount.review' => 'Duyệt chiết khấu báo giá',
            'orders.view' => 'Xem đơn hàng',
            'orders.review' => 'Xác nhận đơn hàng',
            'sales.orders.view' => 'Xem danh sách đơn bán hàng',
            'contracts.view' => 'Xem hợp đồng',
            'contracts.create' => 'Lập và cập nhật hợp đồng',
            'contracts.review' => 'Duyệt hợp đồng',
            'contracts.cancel' => 'Hủy hợp đồng theo quy trình',
            'finance.view' => 'Xem tài chính và công nợ',
            'payments.record' => 'Ghi nhận thanh toán',
            'debts.manage' => 'Quản lý lịch và đối chiếu công nợ',
            'credits.manage' => 'Hoàn hoặc cấn trừ tiền khách',
            'supplier.debts.manage' => 'Quyết toán công nợ nhà cung cấp',
            'supplier.confirmations.manage' => 'Ghi nhận xác nhận công nợ nhà cung cấp và xem chứng từ',
            'invoices.issue' => 'Lập hóa đơn và phát lệnh xuất',
            'purchases.view' => 'Xem đề nghị nhập hàng',
            'purchases.manage' => 'Lập và tiếp nhận hàng nhập',
            'purchases.approve' => 'Duyệt đề nghị nhập hàng',
            'inventory.view' => 'Xem tồn kho và lịch sử kho',
            'inventory.issue' => 'Xuất kho đơn hàng',
            'stocktakes.create' => 'Lập biên bản kiểm kê',
            'stocktakes.review' => 'Duyệt biên bản kiểm kê',
            'shipments.manage' => 'Điều phối và bàn giao chuyến hàng',
            'warehouse.fulfill' => 'Lập chuyến giao hoặc bàn giao tại kho',
            'delivery-runs.manage' => 'Gộp các chuyến giao hàng',
            'returns.view' => 'Xem yêu cầu đổi trả',
            'returns.manage' => 'Kiểm tra và xử lý đổi trả',
            'returns.collect' => 'Thu hồi hàng đổi trả',
            'returns.settle' => 'Quyết toán đổi trả',
            'catalog.propose' => 'Đề xuất thay đổi danh mục',
            'catalog.review' => 'Duyệt thay đổi danh mục',
            'customers.credit.manage' => 'Quản lý hạn mức khách hàng',
            'suppliers.manage' => 'Quản lý nhà cung cấp và sự vụ',
            'warehouses.manage' => 'Quản lý kho hàng',
            'reports.view' => 'Xem báo cáo',
            'reports.create' => 'Lập báo cáo',
            'reports.review' => 'Duyệt báo cáo',
            'users.manage' => 'Quản lý tài khoản và vai trò',
            'backups.manage' => 'Quản lý sao lưu và khôi phục',
            'knowledge.manage' => 'Quản lý tri thức trợ lý',
            'ai.manage' => 'Cấu hình trợ lý AI',
        ];
    }

    public static function rolePermissions(): array
    {
        return [
            'customer' => ['customer.portal'],
            'sales' => [
                'staff.access', 'management.view', 'quotes.view', 'quotes.send',
                'sales.orders.view', 'orders.review', 'contracts.view', 'contracts.create',
                'catalog.propose', 'reports.view', 'reports.create',
            ],
            'warehouse' => [
                'staff.access', 'management.view', 'purchases.view', 'purchases.manage',
                'inventory.view', 'inventory.issue', 'stocktakes.create', 'shipments.manage',
                'warehouse.fulfill', 'delivery-runs.manage',
                'orders.view', 'returns.view', 'returns.manage', 'catalog.propose',
                'suppliers.manage', 'warehouses.manage', 'reports.view', 'reports.create',
            ],
            'accounting' => [
                'staff.access', 'finance.view', 'orders.view', 'returns.view', 'payments.record',
                'debts.manage', 'credits.manage', 'supplier.debts.manage', 'invoices.issue',
                'supplier.confirmations.manage', 'returns.settle', 'reports.view', 'reports.create',
            ],
            'manager' => [
                'staff.access', 'management.view', 'quotes.view', 'quotes.discount.review',
                'orders.view', 'sales.orders.view', 'contracts.view', 'contracts.review', 'contracts.cancel',
                'finance.view', 'purchases.view', 'purchases.approve', 'inventory.view',
                'stocktakes.review', 'catalog.review', 'customers.credit.manage',
                'returns.view', 'reports.view', 'reports.create', 'reports.review',
            ],
            'admin' => array_keys(self::permissions()),
            'delivery' => [
                'staff.access', 'orders.view', 'shipments.manage', 'returns.view',
                'returns.collect', 'reports.view', 'reports.create',
            ],
        ];
    }
}
