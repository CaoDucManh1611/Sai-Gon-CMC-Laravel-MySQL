# Đối chiếu nghiệp vụ báo cáo ↔ Laravel

Nguồn: `Group1_Quan_ly_cua_hang_vat_lieu_xay_dung_phase3.docx`, Chương 1–3. Nhà cung cấp và đơn vị vận chuyển là tác nhân bên ngoài; nhân viên ghi thao tác thay họ. Quản trị viên xử lý tài khoản và phân quyền.

| Quy trình trong báo cáo | Hiện thực Laravel | Giới hạn cần lưu ý |
| --- | --- | --- |
| Tư vấn, yêu cầu và lập báo giá | Giỏ hàng tạo yêu cầu báo giá; kinh doanh xem nhu cầu, tồn hiện có, điều chỉnh giá; quản lý duyệt giá giảm; khách chấp nhận | Chưa tự cam kết tồn kho tại thời điểm báo giá; kho kiểm lại khi xuất |
| Ký hợp đồng đặt hàng | Kinh doanh soạn/sửa, quản lý duyệt/hủy, khách xác nhận hoặc yêu cầu sửa, nhân viên tải bản đã ký để tạo đơn | Xác nhận trực tuyến là xác nhận nội dung, không phải chữ ký số |
| Thanh toán | Kế toán ghi thu/chi, hóa đơn nội bộ, phát lệnh xuất tự động sau khi lập hóa đơn | Không phát hành hóa đơn điện tử pháp lý; VAT 10% được tách từ giá đã gồm thuế |
| Công nợ | Theo dõi phải thu/phải trả, lịch quyết toán; xuất kho khi đã đủ cọc theo hợp đồng và kế toán phát lệnh; trả hàng dư tiền có thể hoàn tiền hoặc cấn trừ đơn sau; khách xem dư nợ và khoản ghi có | Hạn mức lưu để tham khảo, không chặn xuất hàng theo hợp đồng; không gửi SMS/email tự động |
| Kiểm tra và bàn giao | Kho xuất theo lệnh; nhân viên ghi người vận chuyển nhận hàng; người nhận, tình trạng và biên bản bàn giao; sự cố có lịch giao lại | Chữ ký trên biên bản được ghi bằng thông tin nhập, chưa thu chữ ký điện tử |
| Đổi trả hàng lỗi | Khách yêu cầu; kho phân loại; nhập lại hàng dùng được; kế toán điều chỉnh công nợ; giao hàng đổi; hàng hỏng ghi trả nhà cung cấp; kế toán bù trừ phải trả sau khi nhà cung cấp xác nhận | Hàng nhà cung cấp đổi mới cần ghi nhập bằng chứng từ nhận hàng riêng |
| Tổ chức giao hàng và vận chuyển | Lập chuyến theo đơn, gom nhiều chuyến vào lộ trình chung, ghi nhận nhận hàng và bàn giao từng đơn | Chưa tính tuyến tối ưu theo bản đồ/tải trọng xe |
| Nhập kho và nguồn cung | Đề xuất mua, quản lý duyệt, nhận số thực tế, tạo phiếu nhập và phải trả; ghi nhận hàng thiếu/hỏng cùng kết quả xử lý | Chưa tự tạo yêu cầu nhập từ ngưỡng tồn tối thiểu |
| Danh mục vật liệu | Đề xuất sản phẩm/nhóm, quản lý duyệt, lưu lịch sử giá; quản trị nhà cung cấp/kho | Chưa có tìm kiếm nâng cao trong mọi trang quản lý |
| Báo cáo thống kê | Bộ phận tạo báo cáo theo kỳ, quản lý duyệt, in/lưu PDF từ trình duyệt hoặc xuất CSV mở bằng Excel; công nợ và tồn kho được tính tại cuối kỳ | Cách xuất CSV + in PDF đã được chủ đồ án chấp nhận; các cập nhật tồn kho trực tiếp ngoài chứng từ không thể truy hồi lịch sử |

## Kiểm thử và triển khai

- PHPUnit chạy với SQLite: 24 kiểm thử, 207 khẳng định; gồm hoàn/cấn trừ, đổi hàng, xuất theo cọc, báo cáo quá khứ, quản trị tài khoản và ma trận 6 vai trò × 7 trang nghiệp vụ. Đã thử trực tiếp trên trình duyệt với cơ sở dữ liệu SQLite riêng: khách yêu cầu báo giá → kinh doanh lập hợp đồng → quản lý duyệt → khách xác nhận → kế toán thu cọc/lập hóa đơn → kho xuất → giao nhận ghi sự cố và giao lại → khách trả hàng → kho kiểm → kế toán hoàn tiền/cấn trừ; thêm nhập hàng thiếu, kiểm kê, duyệt danh mục và bốn loại báo cáo. Tải CSV và bản in đều mở được.
- Mã nguồn đang ở project chính `C:\Users\DUC_STRONG\Projects\Construction_materials_management_system\laravel`; các migration mới đã áp dụng thành công trên MySQL. Bản làm việc tạm vẫn còn trong workspace để đối chiếu.

