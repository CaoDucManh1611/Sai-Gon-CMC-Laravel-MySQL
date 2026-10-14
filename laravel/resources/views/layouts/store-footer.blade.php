<footer class="site-footer">
    <div class="container-fluid container-wide">
        <section class="footer-banner" aria-labelledby="footer-banner-title">
            <div class="footer-banner-copy">
                <span class="footer-kicker">SÀI GÒN CMC · ĐỒNG HÀNH CÙNG CÔNG TRÌNH</span>
                <h2 id="footer-banner-title">Vững nền móng.<br><em>Trọn niềm tin.</em></h2>
                <p>Chọn vật liệu phù hợp, dự toán chủ động và nhận báo giá rõ ràng cho từng công trình.</p>
            </div>
            <div class="footer-banner-actions">
                <a class="footer-banner-primary" href="{{ route('estimate') }}">Dự toán vật liệu <span aria-hidden="true">↗</span></a>
                <a class="footer-banner-secondary" href="{{ route('cart') }}">Yêu cầu báo giá <span aria-hidden="true">→</span></a>
            </div>
        </section>
        <div class="footer-main">
            <div class="footer-about">
                <a class="footer-identity" href="{{ route('home') }}" aria-label="Sài Gòn CMC, về trang chủ">
                    <span class="brand-mark" aria-hidden="true">S</span>
                    <span>SÀI GÒN CMC<small>VẬT LIỆU XÂY DỰNG</small></span>
                </a>
                <p>Vật liệu đúng quy cách. Quy trình mua hàng minh bạch. Đồng hành từ chọn vật tư đến giao nhận.</p>
                <div class="footer-features" aria-label="Giá trị của Sài Gòn CMC">
                    <span>Rõ quy cách</span><span>Chủ động dự toán</span><span>Hỗ trợ báo giá</span>
                </div>
            </div>
            <nav class="footer-column" aria-label="Khám phá cửa hàng">
                <h3>Khám phá</h3>
                <a href="{{ route('catalog') }}">Tất cả sản phẩm</a>
                <a href="{{ route('estimate') }}">Dự toán vật liệu</a>
                <a href="{{ route('cart') }}">Giỏ hàng &amp; báo giá</a>
                <a href="{{ route('assistant') }}">Trợ lý VLXD CMC</a>
            </nav>
            <nav class="footer-column" aria-label="Tài khoản và đơn hàng">
                <h3>Tài khoản</h3>
                @auth
                    @if(auth()->user()->role === 'customer')
                        <a href="{{ route('my.quotes') }}">Báo giá của tôi</a>
                        <a href="{{ route('my.contracts') }}">Hợp đồng</a>
                        <a href="{{ route('my.orders') }}">Đơn hàng</a>
                        <a href="{{ route('profile') }}">Hồ sơ cá nhân</a>
                    @else
                        <a href="{{ route('staff.dashboard') }}">Bảng làm việc</a>
                        <a href="{{ route('catalog') }}">Danh mục sản phẩm</a>
                    @endif
                @else
                    <a href="{{ route('login') }}">Đăng nhập</a>
                    <a href="{{ route('register') }}">Tạo tài khoản</a>
                    <a href="{{ route('catalog') }}">Xem sản phẩm</a>
                @endauth
            </nav>
            <div class="footer-column footer-help">
                <h3>Cần hỗ trợ?</h3>
                <p>Chưa biết chọn loại vật liệu nào? Trợ lý CMC có thể gợi ý theo danh mục sản phẩm đang có.</p>
                <a class="footer-help-link" href="{{ route('assistant') }}">Tư vấn vật liệu <span aria-hidden="true">↗</span></a>
                <small>Giá và tình trạng cung ứng cần được nhân viên xác nhận.</small>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© {{ date('Y') }} Sài Gòn CMC. Bảo lưu mọi quyền.</span>
            <span>Giá hiển thị mang tính tham khảo · Báo giá chính thức do nhân viên xác nhận.</span>
            <a href="#siteHeader">Về đầu trang <span aria-hidden="true">↑</span></a>
        </div>
    </div>
</footer>