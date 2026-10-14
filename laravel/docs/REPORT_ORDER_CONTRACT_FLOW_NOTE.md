# Ghi chú: thứ tự báo giá, đơn hàng và hợp đồng trong `Nhom3.doc`

Nguồn đối chiếu: `E:/Nhom3/Nhom3/Nhom3.doc`.

- **Mục 1.3.2 – Quy trình nghiệp vụ chi tiết (trang 15):** Sau khi khách hàng đồng ý báo giá, nhân viên Kinh doanh soạn hợp đồng, trình Ban Quản lý phê duyệt, chuyển khách xác nhận rồi ký.
- **Mục 2.3.2 – Đặc tả Use Case hệ thống (từ trang 60), UC Quản lý đơn hàng và UC Quản lý hợp đồng:** Khách hàng đặt đơn, nhân viên Kinh doanh xác nhận đơn; sau đó nhân viên chọn đơn đã xác nhận để lập hợp đồng.

Hai đoạn mô tả khác nhau về thời điểm tạo đơn hàng. Luồng code đang triển khai theo **đặc tả hệ thống ở mục 2.3.2**: yêu cầu báo giá (nếu có) → đơn hàng → xác nhận đơn → lập, duyệt và ký hợp đồng → thanh toán và xuất kho. Đây là quyết định đối chiếu khi kiểm thử, không phải sửa nội dung báo cáo gốc.

Khi hoàn thiện báo cáo, cần thống nhất mục 1.3.2 với mục 2.3.2 hoặc giải thích rõ báo giá là bước tư vấn trước khi khách đặt đơn. Không dùng riêng mục 1.3.2 để kết luận luồng đơn hàng trong code là sai.
