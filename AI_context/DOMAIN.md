# Mô hình nghiệp vụ

## Vai trò

`customer` mua hàng; `sales` xử lý báo giá/hợp đồng; `manager` duyệt; `accounting` thu tiền, công nợ, hóa đơn; `warehouse` nhập xuất kiểm kê; `delivery` giao nhận; `admin` quản trị và được phép thực hiện nhiều thao tác nhân viên. Quyền cụ thể nằm trong `laravel/routes/web.php`, `EnsureRole.php`, và ma trận section ở `StaffController::section` cùng `layouts/staff.blade.php`.

## Dòng nghiệp vụ chính

| Dòng | Trạng thái và điều kiện quan trọng | Mã nguồn quyết định |
| --- | --- | --- |
| Danh mục | Nhân viên đề xuất tạo/sửa sản phẩm/nhóm → quản lý duyệt → mới áp dụng; đổi giá tạo `price_history` | `CatalogWorkflow.php`, `ManagementController.php` |
| Báo giá | Giỏ → `requested` → kinh doanh gửi; giá thấp hơn danh mục phải qua `pending_discount_approval` → `sent` → khách `accepted` | `CommerceWorkflow.php`, `StoreController.php`, `StaffController.php` |
| Hợp đồng/đơn giao công trình | Báo giá được chấp nhận → hợp đồng `draft` → `pending_approval` → quản lý duyệt → khách xác nhận → tải bản ký → đơn và công nợ phải thu | `CommerceWorkflow.php` |
| Thanh toán/xuất | Kế toán ghi thanh toán; đủ cọc theo hợp đồng hoặc đủ toàn bộ đơn nhận tại kho → `ready_to_issue`; lập hóa đơn phát lệnh; kho kiểm tồn rồi xuất, tạo chứng từ và biến động kho | `CommerceWorkflow.php`, `FulfillmentWorkflow.php` |
| Mua trực tiếp, nhận tại kho | Sản phẩm cho phép mua trực tiếp, có giá/tồn khả dụng → đặt đơn `pickup`, giữ lượng bằng phép tính dự trữ; thanh toán đủ, lập hóa đơn, xuất và bàn giao tại kho | `CommerceWorkflow::placePickupOrder`, `FulfillmentWorkflow::handoverPickup` |
| Nhập hàng/kiểm kê | Kho đề xuất mua → quản lý duyệt → kho nhận số thực tế và ghi công nợ phải trả; kiểm kê chỉ điều chỉnh tồn sau duyệt; thiếu/hỏng từ nhà cung cấp có sự vụ riêng | `FulfillmentWorkflow.php` |
| Giao, đổi trả | Xuất kho → lên chuyến/ghép tuyến → nhận hàng → bàn giao hoặc ghi sự cố và hẹn lại; khách yêu cầu trả/đổi sau giao; kho kiểm, kế toán điều chỉnh công nợ hoặc ghi có; đổi hàng tạo chuyến thay thế | `FulfillmentWorkflow.php`, `CommerceWorkflow::useCustomerCredit` |
| Báo cáo | 4 loại: `finance`, `inventory`, `sales`, `logistics`; tạo snapshot theo kỳ → quản lý duyệt → CSV hoặc trang in | `ReportController.php` |

## Nhóm bảng để tra cứu

- **Danh mục:** `categories`, `products`, `price_history`, `catalog_changes`, `estimation_norms`.
- **Khách và bán hàng:** `users`, `customers`, `quotes`, `quote_items`, `contracts`, `orders`, `order_items`, `invoices`.
- **Kho/mua hàng:** `suppliers`, `warehouses`, `inventory`, `purchase_orders`, `purchase_items`, `stock_documents`, `stock_document_items`, `stock_movements`, `supplier_incidents`, `supplier_returns`.
- **Tài chính:** `debts`, `payments`, `customer_credits`, `customer_credit_movements`.
- **Giao nhận/đổi trả:** `shipments`, `delivery_runs`, `handovers`, `returns`.
- **Báo cáo:** `reports` chứa snapshot JSON đã lập; sau đó thay đổi giao dịch không nên viết lại snapshot cũ.

Các trạng thái trên là tóm tắt để định vị code, không phải danh sách enum đầy đủ. Khi sửa transition, đọc điều kiện ngay trong service và migration bổ sung; test liên quan là nguồn kiểm chứng hành vi hiện có. Giá/tồn tại thời điểm báo giá chưa được cam kết giữ hàng cho đơn giao công trình; kho kiểm lại khi xuất. Định mức dự toán là minh họa, không dùng như bóc tách thi công chính thức.
