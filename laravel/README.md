# Sài Gòn CMC — Laravel + MySQL

Ứng dụng PHP nằm trong thư mục `laravel/` của kho mã. Cơ sở dữ liệu Laravel là `cmms_laravel`. Dữ liệu seed chỉ dùng minh họa.

## Chạy trên máy

Yêu cầu PHP 8.3+, Composer và MySQL. Trong thư mục `laravel/`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# Sửa DB_USERNAME, DB_PASSWORD và GEMINI_API_KEY trong .env
php artisan migrate --seed
php artisan cmms:create-admin admin
php artisan serve
```

Vào URL do `artisan serve` in ra. Lệnh `cmms:create-admin` hỏi mật khẩu tương tác; không có tài khoản quản trị mặc định. Không đưa `.env` lên Git. Nếu chưa có Gemini key, phần trợ lý báo chưa cấu hình, các quy trình bán hàng vẫn chạy.

## Tài khoản kiểm thử trên cơ sở dữ liệu hiện tại

| Vai trò | Tên đăng nhập |
| --- | --- |
| Khách hàng | `qa_customer` |
| Kinh doanh | `qa_sales` |
| Kho | `qa_warehouse` |
| Kế toán | `qa_accounting` |
| Giao nhận | `qa_delivery` |
| Quản lý | `qa_manager` |
| Quản trị viên | `qa_admin` |

Các tài khoản trên đang hoạt động trong cơ sở dữ liệu kiểm thử hiện tại; `migrate --seed` không tự tạo lại chúng. Bộ tài khoản thử có tên kết thúc bằng `_20261010` đã bị vô hiệu hóa. Mật khẩu không được ghi trong README; nếu cần dùng lại sau khi khởi tạo cơ sở dữ liệu mới, hãy tạo tài khoản qua phần quản trị hoặc lệnh tạo quản trị viên ở trên.

## Quy trình hiện có

Khách đăng ký, xem 15 sản phẩm mỗi trang, lập giỏ yêu cầu báo giá, chấp nhận báo giá, xem hợp đồng và đơn hàng. Kinh doanh gửi báo giá, lập hợp đồng. Quản lý duyệt hợp đồng và đề xuất nhập hàng. Kế toán ghi nhận thanh toán, phát lệnh xuất kho và theo dõi công nợ. Kho nhập/xuất theo chứng từ, kiểm kê và xử lý trả hoặc đổi hàng bằng chuyến giao thay thế. Giao nhận ghi biên bản bàn giao. Danh mục và giá cần duyệt trước khi áp dụng. Báo cáo có duyệt, xuất CSV và bản in A4 để lưu PDF từ trình duyệt. Dự toán vật liệu dùng định mức minh họa từ bản Java; trợ lý Gemini sử dụng danh mục sản phẩm hiện hành.

## Giới hạn cần hoàn thiện trước khi triển khai thực tế

- Chứng từ hợp đồng được tải lên, chưa có ký điện tử hay hóa đơn điện tử.
- Báo cáo xuất CSV và trang in để lưu PDF từ trình duyệt; chưa tạo tệp XLSX/PDF tự động.
- Định mức và giá seed là minh họa, cần kỹ sư và cửa hàng xác nhận.
- Gemini chưa thể kiểm tra trực tiếp cho đến khi cấu hình API key.
- Biên bản thu hồi tại công trình, xử lý sai lệch từ nhà cung cấp, điều phối nhiều chuyến và bộ báo cáo chi tiết theo mọi tiêu chí của tài liệu cần tiếp tục đối chiếu và mở rộng.

Chạy `php artisan test` để kiểm tra các quy trình hiện có.
