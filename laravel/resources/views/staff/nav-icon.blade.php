<span class="staff-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
@switch($name)
    @case('overview')<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>@break
    @case('quotes')<path d="M7 3h10l3 3v15H4V3h3zM17 3v4h3M8 11h8M8 15h8M8 19h5"/>@break
    @case('contracts')<path d="M6 3h9l4 4v14H6zM15 3v5h4M9 12h7M9 16h5M3 7v14"/>@break
    @case('sales_orders')<path d="M4 4h16v16H4zM8 9h8M8 13h8M8 17h5"/>@break
    @case('finance')<circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.6-.8-1.6-1.2-3-1.2-1.9 0-3.1.8-3.1 2.1 0 3.2 6.2 1.1 6.2 4.4 0 1.3-1.2 2.2-3.1 2.2-1.4 0-2.6-.5-3.4-1.5M12 5.5v13"/>@break
    @case('purchases')<path d="M3 8 12 3l9 5-9 5-9-5zM3 8v9l9 5 9-5V8M12 13v9"/>@break
    @case('orders')<path d="M3 7h11v11H3zM14 10h4l3 4v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>@break
    @case('returns')<path d="M8 7H4l4-4M4 7h11a6 6 0 0 1 0 12H8M8 17l-4 2 4 2"/>@break
    @case('inventory')<path d="M4 7 12 3l8 4v10l-8 4-8-4zM4 7l8 4 8-4M12 11v10"/>@break
    @case('management')<path d="M3 7h18M3 12h18M3 17h18"/><circle cx="8" cy="7" r="2" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="17" r="2" fill="currentColor" stroke="none"/>@break
    @case('reports')<path d="M4 20V9M10 20V4M16 20v-7M22 20H2"/>@break
@endswitch
</svg></span>
