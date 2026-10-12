# Tình trạng giao diện và kiểm thử

## Responsive — kiểm tra mã và trình duyệt

**Có triển khai responsive; các màn hình đã đo không bị cuộn ngang toàn trang.** Cả hai layout có meta viewport. Cửa hàng dùng Bootstrap grid, menu offcanvas và breakpoint trong `public/store/styles.css` (1199, 991, 767, 575 px); trang sản phẩm, giỏ, tài khoản có quy tắc riêng. Khu nhân viên dùng sidebar `offcanvas-lg`, bảng bọc `.table-responsive`, và breakpoint 1199, 991, 650, 390 px trong `public/store/staff.css`.

Dashboard nhân viên từng bị quy tắc `@media(max-width:650px)` ở cuối `laravel/public/store/staff.css` ghi đè quy tắc một cột tại `max-width:390px`: ở 320 px, hai cột chỉ rộng 137 px. Đã sửa bằng breakpoint cuối `max-width:480px`: các thẻ thành một cột, bố trí nhãn/liên kết bên trái và số đếm bên phải. Chrome đo lại ở 320 px: thẻ rộng **292 px**, cao **86 px**; số và nhãn hiện rõ. Ở 481 px trở lên giữ hai cột, và desktop giữ bốn cột.

Đã mở ứng dụng bằng Chrome headless với SQLite cô lập ở **320, 375, 390, 768, 1440 px**: trang chủ, danh mục, giỏ trống, dự toán, đăng nhập, dashboard nhân viên, quản trị, báo cáo và danh sách báo giá đều trả HTTP 200; `document.documentElement.scrollWidth === innerWidth`. Trang sản phẩm, đăng ký, trợ lý ở 320 px cũng không tràn ngang. Menu cửa hàng, ngăn thông số sản phẩm và menu nhân viên đều mở được ở 320 px. Sau sửa CSS, dashboard được đo thêm tại **430, 480, 481, 650 px**, không tràn ngang.

Đã kiểm thêm giỏ **3 sản phẩm** ở trạng thái khách chưa đăng nhập và khách đã đăng nhập, cùng bản in báo cáo tồn kho có **19 dòng tồn và 19 dòng biến động**, tại 320/375/390/768/1440 px. Giỏ từng tràn ngang **4 px** ở 320/375/390 px do lề âm của `.row.g-5` lớn hơn padding của `.container-wide`; đã đổi gutter ngang thành `gx-4` trên màn nhỏ, giữ `gx-lg-5` trên desktop và `gy-5` dọc. Chrome đo lại: `document.documentElement.scrollWidth === innerWidth` ở cả năm kích thước, với khách chưa/đã đăng nhập. Trang báo cáo giờ hiển thị từng bản ghi thành thẻ nhãn–giá trị trên màn ≤600 px; Chrome đo lại không tràn ngang. Trong chế độ in, bảng vẫn là `display:table`, ô là `table-cell`, nút thao tác bị ẩn. Chưa kiểm tra zoom 200% hoặc mọi trạng thái biểu mẫu. Ảnh ở `docs/screenshots/` vốn chỉ là desktop 1440×900; repo chưa có test viewport tự động.

## Test — tình trạng hiện tại

`laravel/phpunit.xml` định nghĩa Unit và Feature, cấu hình SQLite `:memory:` cho test. Có **10 file `*Test.php`, 33 phương thức `test_*`**: bán hàng, nhận tại kho, thanh toán/công nợ, kiểm kê, danh mục, báo cáo, quyền vai trò, dự toán, trợ lý và storefront. Hai `ExampleTest.php` là bài kiểm tra mẫu; phần còn lại có kiểm tra nghiệp vụ thực. `package.json` chỉ có `dev` và `build`, không có test JavaScript; không tìm thấy Playwright/Cypress hay test tự động cho responsive.

Lệnh chuẩn ở `laravel/`: `php artisan test --compact` (hoặc chạy file `tests/Feature/<Tên>Test.php`). **Kết quả chạy ngày 11/10/2026:** 33/33 test pass, 313 assertions, khoảng 3 giây trên PHP 8.3.30, SQLite `:memory:`. Checkout ban đầu thiếu `vendor/` và `.env`; đã cài dependency theo `composer.lock` bằng Composer của Laragon, bật `zip` riêng cho lệnh cài, rồi đặt `APP_KEY` ngẫu nhiên chỉ trong tiến trình chạy test. Không ghi khóa vào repo. Ở môi trường mới, cần cài dependency và cung cấp `APP_KEY` hợp lệ trước khi chạy test. Tài liệu `laravel/docs/BUSINESS_TRACEABILITY.md` ghi 24 test/207 assertions ở lần chạy cũ; không dùng số đó làm kết quả hiện tại.

Khi thay đổi giao diện, kiểm tra bằng trình duyệt thật ở các viewport nêu trên. Khi thay đổi nghiệp vụ, chạy test Feature liên quan trước rồi chạy toàn bộ suite nếu thay đổi đi qua nhiều workflow.
