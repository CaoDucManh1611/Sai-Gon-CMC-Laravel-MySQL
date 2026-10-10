<section class="mb-5" aria-labelledby="delivery-runs-heading">
    <h2 id="delivery-runs-heading">Lộ trình nhiều đơn</h2>
    @if(in_array($role, ['warehouse', 'admin']) && $shipments->where('status', 'planned')->whereNull('delivery_run_id')->whereNull('return_id')->count() >= 2)
        <details class="order-card">
            <summary>Gom các đơn vào một chuyến xe</summary>
            <form method="post" action="{{ route('staff.delivery-runs.create') }}" class="pt-3">
                @csrf
                <fieldset>
                    <legend class="h6">Chọn ít nhất hai chuyến đã lập</legend>
                    @foreach($shipments as $shipment)
                        @if($shipment->status === 'planned' && !$shipment->delivery_run_id && !$shipment->return_id)
                            <label class="d-inline-flex gap-2 align-items-center me-3 mb-2"><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}">{{ $shipment->code }} · đơn #{{ $shipment->order_id }}</label>
                        @endif
                    @endforeach
                </fieldset>
                <div class="d-flex gap-2 flex-wrap align-items-end">
                    <label>Đơn vị vận chuyển<input class="form-control" name="carrier_name" maxlength="150" required></label>
                    <label>Phương tiện<input class="form-control" name="vehicle" maxlength="100" required></label>
                    <label>Lộ trình<input class="form-control" name="route_note" maxlength="2000" required></label>
                    <button class="btn btn-dark">Lưu lộ trình</button>
                </div>
            </form>
        </details>
    @endif
    @foreach($deliveryRuns as $run)
        <article class="order-card">
            <strong>{{ $run->code }}</strong> · {{ $run->carrier_name }} · {{ $run->vehicle }}
            <p class="mb-1">{{ $run->route_note }}</p>
            <ul class="mb-0">
                @foreach($shipments->where('delivery_run_id', $run->id) as $shipment)
                    <li>{{ $shipment->code }} · đơn #{{ $shipment->order_id }} · {{ \App\Support\UiFormat::status($shipment->status) }}</li>
                @endforeach
            </ul>
        </article>
    @endforeach
</section>
