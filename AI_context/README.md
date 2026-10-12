# AI context — Sài Gòn CMC

Tài liệu dẫn đường cho AI làm việc với đồ án quản lý vật liệu xây dựng. **Mã nguồn hiện tại là nguồn sự thật**; thư mục này chỉ giúp chọn đúng điểm đọc. Ứng dụng đang chạy nằm trong `laravel/`; `customer-prototype/` là bản mẫu tĩnh trước khi tích hợp.

## Chọn tài liệu theo việc cần làm

| Việc cần làm | Đọc trước | Sau đó mở |
| --- | --- | --- |
| Hiểu kiến trúc, điểm vào và phân quyền | [ARCHITECTURE.md](ARCHITECTURE.md) | `laravel/routes/web.php`, controller đúng khu vực |
| Hiểu nghiệp vụ, trạng thái, dữ liệu | [DOMAIN.md](DOMAIN.md) | service tương ứng, migration gốc và migration bổ sung |
| Tìm file cần sửa cho một yêu cầu | [TASK_MAP.md](TASK_MAP.md) | đúng controller → service → Blade/CSS → test |
| Xem tình trạng responsive và test | [QUALITY.md](QUALITY.md) | CSS, Blade và test được trỏ tới |
| Đối chiếu `Nhom3.doc` với test hiện tại | [BUSINESS_TEST_GAP.md](BUSINESS_TEST_GAP.md) | các Feature test được nêu theo từng quy trình |
| Xem đối chiếu cũ từ tài liệu Word khác | `laravel/docs/BUSINESS_TRACEABILITY.md` | chỉ dùng làm lịch sử; đối chiếu `Nhom3.doc` ở hàng trên |

## Quy tắc tra cứu ngắn

1. Xác định bề mặt: **khách hàng**, **nhân viên**, **quản trị/danh mục**, **báo cáo**, **dự toán**, hoặc **trợ lý**.
2. Dùng [TASK_MAP.md](TASK_MAP.md) để mở vài file liên quan; bắt đầu từ `laravel/routes/web.php` khi cần xác định quyền hoặc URL.
3. Đọc migration liên quan trước khi sửa dữ liệu; nghiệp vụ chủ yếu dùng `DB::table(...)`, chỉ tài khoản có model `User`.
4. Đọc `laravel/AGENTS.md` trước khi sửa trong `laravel/`. Nếu repo có `.codegraph/` trong tương lai, dùng CodeGraph trước khi tìm text.
5. Khi thay đổi nghiệp vụ, chạy test liên quan trong `laravel/tests/Feature/`; khi thay đổi giao diện, kiểm tra thực tế ở các viewport nhỏ và lớn. Cập nhật tài liệu này nếu điểm vào hoặc quy trình đổi.

Không coi giá seed, tồn kho seed, định mức dự toán, ảnh minh họa hay tài khoản QA trong README là dữ liệu sản xuất. Không đọc hoặc đưa `.env` vào ngữ cảnh AI.
