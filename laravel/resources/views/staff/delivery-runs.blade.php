@if($deliveryRuns->isNotEmpty() || (in_array($role, ['warehouse', 'admin']) && $shipments->where('status', 'planned')->whereNull('delivery_run_id')->whereNull('return_id')->count() >= 2))
<section class="mb-5" aria-labelledby="delivery-runs-heading">
    <h2 id="delivery-runs-heading">Gom đơn giao cùng chuyến</h2>
    <p class="warehouse-section-note">Gộp các điểm giao gần nhau, đặt thứ tự dừng theo tuyến đường thực tế và kiểm tra tổng khối lượng với tải trọng xe.</p>
    @if(in_array($role, ['warehouse', 'admin']) && $shipments->where('status', 'planned')->whereNull('delivery_run_id')->whereNull('return_id')->count() >= 2)
        <details class="order-card">
            <summary>Gom các đơn vào một chuyến xe</summary>
            <form method="post" action="{{ route('staff.delivery-runs.create') }}" class="pt-3">
                @csrf
                <fieldset>
                    <legend class="h6">Chọn ít nhất hai chuyến đã lập</legend>
                    @foreach($shipments as $shipment)
                        @if($shipment->status === 'planned' && !$shipment->delivery_run_id && !$shipment->return_id)
                            <label class="d-inline-flex gap-2 align-items-center me-3 mb-2"><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}">{{ $shipment->code }} · {{ $shipment->delivery_address }} · thứ tự dừng <input class="form-control" style="width:80px" type="number" name="stop_order[{{ $shipment->id }}]" min="1" value="{{ $loop->iteration }}"></label>
                            <span class="d-inline-flex gap-2 align-items-center me-3 mb-2">Tọa độ điểm giao <input class="form-control" style="width:112px" type="number" name="stop_coordinates[{{ $shipment->id }}][lat]" min="-90" max="90" step="any" placeholder="Vĩ độ" aria-label="Vĩ độ {{ $shipment->code }}"><input class="form-control" style="width:112px" type="number" name="stop_coordinates[{{ $shipment->id }}][lon]" min="-180" max="180" step="any" placeholder="Kinh độ" aria-label="Kinh độ {{ $shipment->code }}"></span>
                        @endif
                    @endforeach
                </fieldset>
                <p class="warehouse-section-note">Nếu có tọa độ, hệ thống gợi ý thứ tự theo điểm gần nhất kế tiếp tính bằng khoảng cách đường thẳng. Kho kiểm tra lại tuyến đường thực tế trước khi lưu. Khi chỉ có địa chỉ văn bản, dùng thứ tự dừng nhập ở trên.</p>
                <div class="d-flex gap-2 flex-wrap align-items-end">
                    <label class="d-flex gap-2 align-items-center"><input type="checkbox" name="optimize_route" value="1">Gợi ý thứ tự theo tọa độ</label>
                    <label>Vĩ độ xuất phát<input class="form-control" type="number" name="origin_lat" min="-90" max="90" step="any" placeholder="Ví dụ 10.75"></label>
                    <label>Kinh độ xuất phát<input class="form-control" type="number" name="origin_lon" min="-180" max="180" step="any" placeholder="Ví dụ 106.66"></label>
                    <label>Đơn vị vận chuyển<input class="form-control" name="carrier_name" maxlength="150" required></label>
                    <label>Phương tiện<input class="form-control" name="vehicle" maxlength="100" required></label>
                    <label>Tải trọng xe (kg)<input class="form-control" type="number" name="capacity_kg" min="0.001" step="0.001" required></label>
                    <label>Sức chứa xe (m³, nếu theo dõi)<input class="form-control" type="number" name="capacity_m3" min="0.0001" step="0.0001"></label>
                    <label>Lộ trình<input class="form-control" name="route_note" maxlength="2000" required></label>
                    <button class="btn btn-dark">Lưu lộ trình</button>
                </div>
            </form>
        </details>
    @endif
    @foreach($deliveryRuns as $run)
        <article class="order-card">
            <strong>{{ $run->code }}</strong> · {{ $run->carrier_name }} · {{ $run->vehicle }} @if($run->capacity_kg) · {{ \App\Support\UiFormat::quantity($run->load_kg) }} / {{ \App\Support\UiFormat::quantity($run->capacity_kg) }} kg @endif @if($run->capacity_m3) · {{ \App\Support\UiFormat::quantity($run->load_m3) }} / {{ \App\Support\UiFormat::quantity($run->capacity_m3) }} m³ @endif
            <p class="mb-1">{{ $run->route_note }}</p>
            <ul class="mb-0">
                @foreach($shipments->where('delivery_run_id', $run->id)->sortBy('stop_sequence') as $shipment)
                    <li>Điểm {{ $shipment->stop_sequence ?? $loop->iteration }}: {{ $shipment->code }} · {{ $shipment->delivery_address }} · {{ \App\Support\UiFormat::status($shipment->status) }}</li>
                @endforeach
            </ul>
        </article>
    @endforeach
</section>
@endif
