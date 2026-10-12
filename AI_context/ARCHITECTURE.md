# Kiến trúc và điểm vào

## Ranh giới repo

| Vị trí | Vai trò |
| --- | --- |
| `laravel/` | Ứng dụng chính: Laravel 13, PHP 8.3, MySQL theo `.env.example`; PHPUnit dùng SQLite trong bộ nhớ |
| `customer-prototype/` | HTML/CSS/JS tĩnh tham khảo; thay đổi ở đây không tự xuất hiện trong Laravel |
| `docs/screenshots/` | Ảnh chụp desktop hiện có, không phải bằng chứng responsive |
| `laravel/docs/BUSINESS_TRACEABILITY.md` | Đối chiếu nghiệp vụ với báo cáo đồ án; có thông tin kiểm thử/đường dẫn máy cũ cần xác minh lại |

## Luồng một HTTP request

`laravel/bootstrap/app.php` đăng ký `routes/web.php` và alias middleware `role` → `app/Http/Middleware/EnsureRole.php`. Route gọi controller trong `app/Http/Controllers/`. Controller xác thực input, lấy dữ liệu bằng Query Builder hoặc gọi workflow service. Service ghi nhiều bảng trong transaction và kiểm tra trạng thái. Controller trả Blade trong `resources/views/`. Hai layout chính là `layouts/store.blade.php` (khách) và `layouts/staff.blade.php` (nhân viên).

| Khu vực | Controller | Phần xử lý chính |
| --- | --- | --- |
| Cửa hàng, giỏ, tài khoản khách | `StoreController`, `AuthController` | `CommerceWorkflow`, `FulfillmentWorkflow`; session cart; các view `store/*`, `auth/*` |
| Nghiệp vụ theo vai trò | `StaffController` | `CommerceWorkflow`, `FulfillmentWorkflow`; `staff/dashboard.blade.php` là trang làm việc lớn với `activeSection` |
| Danh mục và người dùng | `ManagementController` | `CatalogWorkflow`; `staff/management.blade.php` |
| Báo cáo | `ReportController` | Tạo snapshot JSON, duyệt, xuất CSV, view in A4 |
| Dự toán | `EstimateController` | `estimation_norms` × diện tích/số tầng; `store/estimate.blade.php` |
| Trợ lý | `AssistantController` | Danh mục đang bán → Gemini nếu có `GEMINI_API_KEY`; `store/assistant.blade.php` và chat trong layout khách |

## Công nghệ giao diện

Giao diện ứng dụng dùng Blade, Bootstrap được lưu cục bộ tại `laravel/public/store/bootstrap.min.css` và các file `public/store/styles.css`, `staff.css`, `theme.css`, `ui.js`, `components.js`, `theme.js`. `staff.css` chỉ được nạp trong layout nhân viên. `resources/css/app.css`, `resources/js/app.js`, `vite.config.js` thuộc cấu hình Vite mặc định; hai layout chính hiện tham chiếu asset từ `public/store/` trực tiếp. Vì vậy hãy sửa đúng asset đang được nạp, không mặc định rằng `npm run build` sẽ thay giao diện này.

## Dữ liệu, cấu hình và an toàn

- Schema gốc: `laravel/database/migrations/2026_10_09_000001_create_cmms_tables.php`; `0001_01_01_000000_create_users_table.php` tạo tài khoản. Các migration ngày 09–10/10/2026 bổ sung tín dụng khách, hóa đơn, đổi trả, giao hàng, mua trực tiếp.
- `DatabaseSeeder.php` thêm danh mục, sản phẩm, định mức minh họa và kho; `DemoOpeningStockSeeder.php` là lệnh riêng cho tồn đầu kỳ và cần nhân viên kho/quản trị có sẵn.
- `EnsureRole` kiểm tra tài khoản `active` và vai trò; route nhóm theo vai trò. `StaffController::section` còn kiểm tra quyền riêng cho từng tab nghiệp vụ.
- Tiền dùng cột decimal và phép `bc*`; tồn kho dùng decimal 3 chữ số. Luồng nhiều bảng dùng transaction và một số bản ghi được `lockForUpdate()`.
- Hợp đồng tải lên lưu đường dẫn chứng từ; báo cáo in PDF qua trình duyệt hoặc tải CSV. Gemini gọi API ngoài chỉ khi được cấu hình. Không đưa bí mật từ `.env` hoặc dữ liệu khách hàng thật vào tài liệu.
