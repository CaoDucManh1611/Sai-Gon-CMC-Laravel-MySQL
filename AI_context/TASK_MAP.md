# Bản đồ tác vụ → file

Đường dẫn trong bảng tính từ gốc repo. Đọc theo thứ tự **route → controller → workflow → view → test → migration/seed** khi tác vụ chạm đủ các lớp; bỏ qua lớp không liên quan.

| Tác vụ / câu hỏi | Điểm đọc hẹp nhất |
| --- | --- |
| URL, middleware, vai trò | `laravel/routes/web.php`; `laravel/bootstrap/app.php`; `laravel/app/Http/Middleware/EnsureRole.php`; test `StaffSectionTest.php` |
| Đăng ký/đăng nhập, thông tin khách | `AuthController.php`, `StoreController::profile/updateProfile`; `resources/views/auth/*`, `store/profile.blade.php`; `StorefrontTest.php` |
| Trang chủ, danh mục, sản phẩm, giỏ | `StoreController.php`; `resources/views/store/{home,catalog,product,cart,_card,pagination}.blade.php`; `public/store/{styles.css,ui.js,components.js}`; `StorefrontTest.php` |
| Báo giá, chiết khấu | `StoreController::requestQuote/acceptQuote`, `StaffController::sendQuote/reviewQuoteDiscount`; `CommerceWorkflow.php`; `store/{quotes,quote}.blade.php`, `staff/dashboard.blade.php`; `CommerceWorkflowTest.php` |
| Hợp đồng và đơn | `CommerceWorkflow::createContract` đến `signContract`; `StoreController::myContracts/respondToContract/myOrders`; `staff/dashboard.blade.php`, `store/{contracts,orders}.blade.php`; `CommerceWorkflowTest.php` |
| Thu tiền, hóa đơn, công nợ, hoàn/cấn trừ | `CommerceWorkflow::recordPayment/useCustomerCredit/issueInvoice/scheduleDebt`; `staff/{dashboard,customer-credits,debt-schedule}.blade.php`, `store/invoice.blade.php`; `CommerceWorkflowTest.php` |
| Nhập hàng, kiểm kê, tồn kho, sự vụ nhà cung cấp | `FulfillmentWorkflow::createPurchaseOrder/receivePurchaseOrder/proposeStocktake/reviewStocktake/reportSupplierIncident`; `staff/{dashboard,supplier-incidents}.blade.php`; `StocktakeWorkflowTest.php`, `CommerceWorkflowTest.php` |
| Xuất kho, chuyến giao, bàn giao, nhận tại kho | `FulfillmentWorkflow::issueOrder/planShipment/groupDeliveryRun/pickupShipment/handover/handoverPickup`; `staff/{dashboard,delivery-runs}.blade.php`; `CommerceWorkflowTest.php` |
| Trả/đổi hàng, trả nhà cung cấp | `FulfillmentWorkflow::requestReturn/inspectReturn/issueExchange/settleReturn/sendDamagedReturnToSupplier`; `staff/{dashboard,supplier-return-action,supplier-return-settlement}.blade.php`; `CommerceWorkflowTest.php` |
| Duyệt danh mục, nhà cung cấp, kho, tài khoản | `ManagementController.php`, `CatalogWorkflow.php`; `staff/management.blade.php`; `CatalogWorkflowTest.php`, `StaffSectionTest.php` |
| Báo cáo, CSV, bản in | `ReportController.php`; `staff/{reports,report-print}.blade.php`; `ReportWorkflowTest.php` |
| Dự toán | `EstimateController.php`, `store/estimate.blade.php`, `database/seeders/DatabaseSeeder.php` (`estimation_norms`); `EstimateTest.php` |
| Trợ lý Gemini/chat | `AssistantController.php`, `config/services.php`, `layouts/store.blade.php`, `public/store/ui.js`, `store/assistant.blade.php`; `AssistantTest.php` |
| Giao diện khách / responsive | `resources/views/layouts/store.blade.php`, view cụ thể, `public/store/styles.css` và `theme.css`; bản mẫu ở `customer-prototype/` chỉ để tham chiếu |
| Giao diện nhân viên / responsive | `resources/views/layouts/staff.blade.php`, `staff/dashboard.blade.php` hoặc view con; `public/store/staff.css`, `styles.css`, `theme.css` |

Tên controller/service/test trong bảng nằm lần lượt dưới `laravel/app/Http/Controllers/`, `laravel/app/Services/`, `laravel/tests/Feature/`. Schema: bắt đầu ở `laravel/database/migrations/2026_10_09_000001_create_cmms_tables.php`, sau đó tìm migration bổ sung bằng tên bảng/cột. Không sửa chỉ một Blade nếu quy tắc trạng thái ở service hoặc quyền ở route cũng cần đổi.
