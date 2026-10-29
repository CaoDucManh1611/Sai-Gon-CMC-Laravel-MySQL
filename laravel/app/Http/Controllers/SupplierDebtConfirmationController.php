<?php

namespace App\Http\Controllers;

use App\Services\SupplierDebtConfirmationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierDebtConfirmationController extends Controller
{
    public function index(): View
    {
        $debts = DB::table('debts')->join('suppliers', 'suppliers.id', '=', 'debts.supplier_id')
            ->where('debts.direction', 'payable')->select('debts.*', 'suppliers.name as supplier_name')
            ->orderByDesc('debts.id')->paginate(20)->withQueryString();
        $payments = DB::table('payments')->whereIn('debt_id', $debts->getCollection()->pluck('id'))
            ->orderByDesc('paid_at')->get()->groupBy('debt_id');
        $confirmations = DB::table('supplier_debt_confirmations')
            ->join('debts', 'debts.id', '=', 'supplier_debt_confirmations.debt_id')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_debt_confirmations.supplier_id')
            ->join('users', 'users.id', '=', 'supplier_debt_confirmations.recorded_by')
            ->leftJoin('payments', 'payments.id', '=', 'supplier_debt_confirmations.payment_id')
            ->select('supplier_debt_confirmations.*', 'debts.code as debt_code', 'suppliers.name as supplier_name',
                'users.name as accountant_name', 'payments.code as payment_code')
            ->orderByDesc('supplier_debt_confirmations.confirmed_on')->orderByDesc('supplier_debt_confirmations.id')
            ->paginate(20)->withQueryString();

        return view('staff.supplier-debt-confirmations.index', compact('debts', 'payments', 'confirmations'));
    }

    public function store(int $debtId, Request $request, SupplierDebtConfirmationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'contact_name' => ['required', 'string', 'max:150'],
            'confirmed_on' => ['required', 'date'],
            'statement' => ['required', 'string', 'max:4000'],
            'attachment' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $workflow->record($debtId, $request->user()->id, isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            $data['contact_name'], $data['confirmed_on'], $data['statement'], $request->file('attachment'));

        return back()->with('success', 'Đã ghi nhận xác nhận nhà cung cấp bằng chứng từ kế toán. Số dư công nợ không thay đổi.');
    }

    public function attachment(int $id): StreamedResponse
    {
        $confirmation = DB::table('supplier_debt_confirmations')->where('id', $id)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($confirmation->file_path), 404);

        return Storage::disk('local')->download($confirmation->file_path, $confirmation->file_name, [
            'Content-Type' => $confirmation->file_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
