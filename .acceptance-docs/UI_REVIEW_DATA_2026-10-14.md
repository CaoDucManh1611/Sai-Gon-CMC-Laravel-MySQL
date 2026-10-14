# Dữ liệu mẫu để nghiệm thu giao diện (14/10/2026)

Dữ liệu được tạo trên giao diện local bằng các tài khoản `accept_20261014_*`. Các tab vai trò dùng miền `*.localhost:8000` để giữ phiên đăng nhập riêng. Đây là dữ liệu QA; chỉ thao tác duyệt, thanh toán, xuất kho hoặc hoàn tất tiếp khi đang thử đúng bước đó.

| Vai trò / phần | Mã để mở | Trạng thái hiện tại và nội dung cần xem |
| --- | --- | --- |
| Khách hàng / Báo giá | `BG-2026-000008` | Báo giá 85.000 ₫ cho PCB40, chờ duyệt giá; xem ở Báo giá của tôi. |
| Kinh doanh / Báo giá | `BG-2026-000008` | Giá thấp hơn niêm yết, đang xử lý; có hồ sơ chi tiết và bước Ban quản lý phê duyệt. |
| Khách hàng, Kinh doanh / Đơn hàng | `DH-2026-000013` | Đơn giao công trình từ `BG-2026-000009`, đã được Kinh doanh xác nhận và lập hợp đồng. |
| Kinh doanh, Quản lý / Hợp đồng | `HD-2026-000006` | Hợp đồng cho `DH-2026-000013`, đang chờ phê duyệt; có điều khoản thanh toán và giao hàng QA. |
| Kho, Quản lý / Nhập hàng | `MH-2026-000005` | Đề xuất nhập 2 đơn vị `QA-LOAD-20261014` từ nhà cung cấp QA, đang chờ duyệt. |
| Khách hàng, Giao nhận / Đổi trả | `DT-2026-000006` | Trả 1 bao của `DH-2026-000011`, đang chờ Giao nhận thu hồi. |
| Khách hàng, Giao nhận, Kho / Đổi trả | `DT-2026-000007` | Đổi 1 bao của `DH-2026-000012`, Giao nhận đã thu hồi, đang chờ Kho kiểm tra. Biên bản `QA-UI-BB-THUHOI-DT7`. |
| Quản lý / Danh mục | Đề xuất `#2`, mã `QA-UI-VL-20261014` | Thêm mặt hàng mẫu 25 kg, đang chờ phê duyệt. |

Hồ sơ từ đợt nghiệm thu nghiệp vụ trước, vẫn hữu ích để xem các trạng thái khác:

| Phần | Mã tiêu biểu | Nội dung |
| --- | --- | --- |
| Thiếu tồn nhận tại kho | `DH-2026-000008` | Chờ xác nhận, có cảnh báo tồn và ngày dự kiến. |
| Giao hàng hai điểm | `LT-2026-000002`, `DH-2026-000011`, `DH-2026-000012` | Đã hoàn tất, có thứ tự dừng, hóa đơn và công nợ. |
| Đổi hàng sau kiểm tra | `DT-2026-000004`, `DT-2026-000005` | Đã kiểm tra, có bước xuất thay thế/trả nhà cung cấp. |
| Trả hàng nhà cung cấp | `TNCC-2026-000001` | Đã ghi nhận trong hồ sơ đổi trả. |
| Tài chính & công nợ | `CN-2026-000010`, `CN-2026-000012`, `CN-2026-000013` | Lần lượt dự kiến, phải trả chưa tất toán, phải thu đã thanh toán. |
| Báo cáo | `BC-2026-000002` đến `BC-2026-000007` | Các bộ lọc, biểu đồ và tệp xuất đã có dữ liệu. |

Các mã chứng từ mới được đọc lại trực tiếp trên giao diện của vai trò tương ứng sau khi tạo. Không có commit hoặc push.
