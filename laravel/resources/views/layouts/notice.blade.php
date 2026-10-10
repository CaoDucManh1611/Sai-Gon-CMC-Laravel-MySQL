<div class="site-notice site-notice-{{ $type }}" role="{{ $type === 'error' ? 'alert' : 'status' }}">
    <span class="site-notice-icon" aria-hidden="true">{{ $type === 'error' ? '!' : '✓' }}</span>
    <div class="site-notice-copy"><strong>{{ $type === 'error' ? 'Vui lòng kiểm tra' : 'Đã hoàn tất' }}</strong>
        @if(count($messages) === 1)<p>{{ $messages[0] }}</p>@else<ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    </div>
    <button class="site-notice-close" type="button" data-notice-close aria-label="Đóng thông báo">×</button>
</div>
