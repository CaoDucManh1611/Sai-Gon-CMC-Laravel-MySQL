<?php

namespace App\Http\Controllers;

use App\Services\DebtReconciliationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DebtReconciliationController extends Controller
{
    public function staffIndex(Request $request): View
    {
        $reconciliations = $this->staffQuery()->paginate(20)->withQueryString();

        return view('staff.debt-reconciliations.index', [
            'reconciliations' => $reconciliations,
            'customers' => \DB::table('users')->where('role', 'customer')->orderBy('name')->get(['id', 'name', 'username']),
            'defaultPeriodStart' => now()->startOfMonth()->toDateString(),
        ]);
    }

    public function createSnapshot(Request $request, DebtReconciliationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'period_start' => ['required', 'date'],
            'as_of_date' => ['required', 'date'],
        ]);
        $id = $workflow->createSnapshot((int) $data['customer_id'], $data['as_of_date'], $request->user()->id, $data['period_start']);

        return redirect()->route('staff.debt-reconciliations.show', $id)->with('success', 'Đã lập snapshot đối chiếu ở trạng thái nháp.');
    }

    public function staffShow(int $id): View
    {
        $reconciliation = $this->staffQuery()->where('debt_reconciliations.id', $id)->firstOrFail();
        app(DebtReconciliationWorkflow::class)->assertSnapshotIntegrity($reconciliation);

        return view('staff.debt-reconciliations.show', [
            'reconciliation' => $reconciliation,
            'snapshot' => json_decode($reconciliation->content_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'messages' => $this->messages($id),
        ]);
    }

    public function send(int $id, Request $request, DebtReconciliationWorkflow $workflow): RedirectResponse
    {
        $workflow->send($id, $request->user()->id);

        return back()->with('success', 'Đã gửi bản đối chiếu cho khách hàng.');
    }

    public function resolve(int $id, Request $request, DebtReconciliationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);
        $workflow->resolve($id, $request->user()->id, $data['message']);

        return back()->with('success', 'Đã ghi cách kế toán xử lý chênh lệch. Khách hàng chưa được đánh dấu xác nhận.');
    }

    public function customerIndex(Request $request): View
    {
        $reconciliations = \DB::table('debt_reconciliations')
            ->where('customer_id', $request->user()->id)->where('status', '!=', 'draft')
            ->orderByDesc('as_of_date')->orderByDesc('id')->paginate(10)->withQueryString();

        return view('store.debt-reconciliations.index', compact('reconciliations'));
    }

    public function customerShow(Request $request, int $id): View
    {
        $reconciliation = \DB::table('debt_reconciliations')->where('id', $id)
            ->where('customer_id', $request->user()->id)->where('status', '!=', 'draft')->firstOrFail();
        app(DebtReconciliationWorkflow::class)->assertSnapshotIntegrity($reconciliation);

        return view('store.debt-reconciliations.show', [
            'reconciliation' => $reconciliation,
            'snapshot' => json_decode($reconciliation->content_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'messages' => $this->messages($id),
        ]);
    }

    public function respond(int $id, Request $request, DebtReconciliationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:confirm,dispute'],
            'message' => ['nullable', 'required_if:decision,dispute', 'string', 'max:2000'],
        ]);
        $workflow->respond($id, $request->user()->id, $data['decision'], $data['message'] ?? null);

        return back()->with('success', $data['decision'] === 'confirm'
            ? 'Đã ghi nhận thao tác xác nhận của bạn.' : 'Đã gửi nội dung chênh lệch cho kế toán.');
    }

    private function staffQuery()
    {
        return \DB::table('debt_reconciliations')->join('users', 'users.id', '=', 'debt_reconciliations.customer_id')
            ->select('debt_reconciliations.*', 'users.name as customer_name')
            ->orderByDesc('debt_reconciliations.as_of_date')->orderByDesc('debt_reconciliations.id');
    }

    private function messages(int $id)
    {
        return \DB::table('debt_reconciliation_messages')->join('users', 'users.id', '=', 'debt_reconciliation_messages.author_id')
            ->where('reconciliation_id', $id)->select('debt_reconciliation_messages.*', 'users.name as author_name')
            ->orderBy('debt_reconciliation_messages.id')->get();
    }
}
