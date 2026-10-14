# Kế hoạch sửa nghiệp vụ có căn cứ rõ trong Nhom3.doc

Nguồn: `E:/Nhom3/Nhom3/Nhom3.doc`, phần 2.2.2 (bảng văn bản và sơ đồ hoạt động, tuần tự, cộng tác), 2.3.1–2.3.2 (Use Case hệ thống). Bốn mục dưới đây đã được triển khai ở máy cục bộ và kiểm tra bằng test Laravel; chưa commit hoặc push. Kiểm tra tương tác trực tiếp trên trình duyệt còn chờ đăng nhập lại các vai trò kiểm thử.

## 1. Công nợ hiển thị cho khách hàng

Căn cứ: sơ đồ thanh toán và công nợ, tr. 28–35; UC tài chính/công nợ mục 2.3.2. Giao dịch và chứng từ phải được xác lập/đối soát trước khi thể hiện là khoản phải thu thực tế.

- Phân biệt số tiền dự kiến của đơn mới với công nợ phải thu đã phát sinh. Chưa đổi thời điểm tạo bản ghi nội bộ vì báo cáo không quy định cấu trúc lưu trữ.
- Sửa truy vấn `StoreController::profile` và các tổng hợp công nợ mang nghĩa phải thu thực tế theo cùng tiêu chí; loại công nợ phải trả nhà cung cấp khỏi trang khách.
- Kiểm thử: đơn chưa đủ điều kiện không hiện là công nợ; khoản hợp lệ hiện đúng; thanh toán, hoàn/cấn trừ giữ số dư đúng.

## 2. Trạng thái chuyến giao và đơn hàng

Căn cứ: mục 2.2.2.7, tr. 42–45; UC lộ trình mục 2.3.2. Lập chuyến, giao hàng cho đơn vị vận chuyển, đang vận chuyển và đã giao là các mốc khác nhau.

- `FulfillmentWorkflow::planShipment` chỉ tạo chuyến `planned`, không chuyển đơn sang `shipping`; `pickupShipment` chuyển cả chuyến và đơn sang `shipping` sau khi ghi người nhận và thời điểm bàn giao. Rà soát `rescheduleShipment`, `handover` và nhãn trạng thái liên quan.
- Kiểm thử: lập chuyến chưa phải đang vận chuyển; nhận hàng chỉ thực hiện một lần; không giao trước khi nhận; sự cố/giao lại không làm trạng thái đơn và chuyến lệch nhau.
- Thử trên giao diện Kho, Giao nhận và Khách hàng.

## 3. Thu hồi hàng trước khi kho kiểm đổi trả

Căn cứ: mục 2.2.2.6, tr. 39–41; UC đổi trả mục 2.3.2. Giao nhận thu hồi tại công trình và lập biên bản trước khi kho phân loại; kế toán xử lý sau kiểm tra.

- Thêm mốc đã thu hồi và dữ liệu tối thiểu của lần thu hồi: nhân viên, thời điểm, người bàn giao, số lượng/tình trạng và biên bản. Chỉ vai trò Giao nhận được xác nhận thu hồi; `FulfillmentWorkflow::inspectReturn` chỉ chấp nhận sau mốc này.
- Bổ sung thao tác trong route/controller/giao diện Giao nhận, hiển thị trạng thái cho Kho, Kế toán và Khách. Giữ luồng phân loại, đổi hàng, hoàn/cấn trừ hiện có sau kiểm tra.
- Kiểm thử: kho bị chặn trước khi thu hồi; thu hồi không đổi tồn/công nợ; không thu hồi hai lần; sau thu hồi kho kiểm tra và kế toán xử lý được.
- Với phiếu cũ đang dở, định rõ cách chuyển trạng thái mà không tạo biên bản thu hồi giả.

## 4. Khóa tài khoản đăng nhập

Căn cứ: UC Đăng nhập mục 2.3.2: sai **quá 5 lần liên tiếp** thì tạm khóa và liên hệ Quản trị viên để mở.

- Sửa `AuthController::login`: năm lần sai đầu chưa khóa, lần sai thứ sáu khóa; đăng nhập đúng trước ngưỡng xóa bộ đếm. Tài khoản bị khóa không tự mở sau 15 phút. Dùng hoặc bổ sung thao tác mở khóa của Quản trị viên trong phần quản trị hiện có.
- Kiểm thử ngưỡng 5/6, reset sau lần đúng, nhập đúng mật khẩu khi bị khóa vẫn bị chặn, Quản trị viên mở khóa thì đăng nhập lại được.

## Cách thực hiện và nghiệm thu

Đã viết kiểm thử cho đường đúng và sai vai trò/thứ tự, sửa service/controller/giao diện liên quan, chạy test hẹp và toàn bộ test nghiệp vụ. Đợt chạy toàn bộ gần nhất: 49 test đạt, 538 assertions. Kết quả thao tác trực tiếp trên trình duyệt được ghi bên dưới. Không commit hoặc push nếu người dùng chưa yêu cầu.

## Kết quả kiểm thử trực tiếp trên giao diện ngày 12/10/2026

Tài khoản demo ghi trong báo cáo không đăng nhập được vào dữ liệu local hiện tại. Đã tạo các tài khoản QA riêng cho Khách hàng, Kinh doanh, Kho, Giao nhận, Kế toán, Quản lý và Quản trị viên; không thay đổi tài khoản có sẵn. Các hồ sơ QA dùng để đối chiếu: đơn `DH-2026-000005`, hợp đồng `HD-2026-000002`, công nợ `CN-2026-000006`, hóa đơn `HDON-2026-000002`, phiếu trả `DT-2026-000002`.

| Ca | Bằng chứng giao diện | Kết luận / độ tin cậy |
| --- | --- | --- |
| Công nợ trước và sau bản ký | Trước khi ghi nhận bản ký, Khách hàng thấy “Chưa phát sinh công nợ”, Kế toán ghi “Dự kiến / chưa phát sinh” và “Chờ hợp đồng đã ký”. Sau khi Khách hàng xác nhận nội dung và Kinh doanh ghi nhận bản ký, Kế toán chuyển khoản `CN-2026-000006` thành “Phải thu”. | Đạt; cao. |
| Thanh toán, hóa đơn, xuất kho, nhận tại kho | Kế toán ghi nhận thanh toán 1.250 ₫, phát hành `HDON-2026-000002`; Kho duyệt xuất rồi bàn giao. Đơn `DH-2026-000005` chuyển từ “Chờ xuất kho” qua “Sẵn sàng giao” sang “Đã giao”. | Đạt với nhánh nhận tại kho; cao. |
| Thu hồi trước khi Kho kiểm tra trả hàng | Khách tạo `DT-2026-000002`; khi còn “Chờ xử lý”, Kho chưa có nút kiểm tra. Giao nhận ghi người bàn giao, số lượng 1 và biên bản, phiếu chuyển “Đã thu hồi”; lúc này Kho mới thấy nút kiểm tra. Kế toán điều chỉnh 1.250 ₫, phiếu chuyển “Hoàn tất”. | Đạt với một lượt trả hàng; cao. Chưa thử lặp thu hồi và nhánh hàng hỏng trực tiếp trên giao diện. |
| Biến động tồn kho | Lịch sử kho ghi `PX-2026-000006` xuất -1, tồn 3.000 → 2.999; `PT-2026-000007` nhập trả +1, tồn 2.999 → 3.000. | Đạt; cao. |
| Khóa và mở khóa tài khoản | Sau các lần đăng nhập sai trên tài khoản QA, giao diện báo tạm khóa; Quản trị viên thấy trạng thái “Tạm khóa”, mở khóa, rồi tài khoản đăng nhập lại được. | Đạt cho khóa/mở khóa; cao. Ngưỡng chính xác 5/6 và các trường hợp reset bộ đếm được kiểm tra bằng test tự động, không đếm đủ tin cậy chỉ từ lượt bấm UI. |
| Lập chuyến giao đến công trình | Đơn QA dùng hình thức nhận tại kho, nên không xuất hiện thao tác lập chuyến/đơn vị vận chuyển. Test tự động kiểm tra trạng thái `planned` → `shipping` sau khi nhận chuyến, không giao trước khi nhận, sự cố và giao lại. | Chưa kiểm thử trực tiếp trên UI; độ tin cậy UI dưới cao. Cần đơn QA giao đến công trình để nghiệm thu riêng. |

**Điểm cần xử lý sau:** trong trang Kho → Tồn kho → Lịch sử nhập, xuất, phiếu `PT-2026-000007` của hàng trả hiện nhãn chung “Giao dịch kho”. Giá trị thay đổi và tồn sau giao dịch đúng; nên đổi nhãn thành “Nhập hàng trả” sau khi kiểm tra loại chứng từ thực tế. Chưa chỉnh sửa trong đợt kiểm thử này.

## Tạm ghi chú, chưa sửa

- Mục 1.3.2 và sơ đồ hợp đồng tr. 25–27 mô tả đồng ý báo giá → hợp đồng; mục 2.3.2 mô tả xác nhận đơn → hợp đồng. Xem `REPORT_ORDER_CONTRACT_FLOW_NOTE.md`.
- UC thiếu tồn không ấn định 14 ngày. Theo quyết định của người dùng, thông báo thiếu tồn mặc định đề xuất ngày sau 14 ngày; Kinh doanh có thể chọn ngày khác và đề xuất vật liệu thay thế khi gửi báo giá. Đây là ngày dự kiến, cần xác nhận lịch thực tế với Kho.
- UC báo cáo mục 2.3.2 nêu Excel/PDF. Đã đổi tệp tải từ CSV sang định dạng XML Spreadsheet mở bằng Excel; trang in vẫn cho phép lưu PDF bằng hộp thoại in của trình duyệt. Chưa có tệp `.xlsx` hoặc PDF tải trực tiếp vì dự án chưa có thư viện tương ứng.
- Đã sửa nhãn chứng từ kho `return` thành “Nhập hàng trả”; biến động tồn kho không thay đổi.
- Báo cáo mô tả đối soát công nợ, không ấn định thời điểm insert bảng `debts`. Mục 1 chỉ sửa ý nghĩa hiển thị/tổng hợp, không xóa bản ghi nội bộ sớm.
