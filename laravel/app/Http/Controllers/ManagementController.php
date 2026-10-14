<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CatalogWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManagementController extends Controller
{
    public function index(): View
    {
        return view('staff.management', [
            'changes' => DB::table('catalog_changes')->orderByDesc('id')->limit(50)->get(),
            'products' => DB::table('products')->orderBy('name')->get(),
            'categories' => DB::table('categories')->orderBy('name')->get(),
            'users' => DB::table('users')->orderByDesc('id')->limit(50)->get(),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(),
            'warehouses' => DB::table('warehouses')->orderBy('name')->get(),
            'customers' => DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->select('customers.*', 'users.name')->get(),
        ]);
    }

    public function proposeProduct(Request $request, CatalogWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'exists:categories,id'], 'unit' => ['required', 'string', 'max:30'],
            'specification' => ['nullable', 'string', 'max:200'],
            'price' => ['required', 'numeric', 'min:0'], 'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'volume_m3' => ['nullable', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:5000'], 'active' => ['required', 'boolean'],
        ]);
        $id = $data['product_id'] ?? null;
        unset($data['product_id']);
        $workflow->propose($id ? 'product_update' : 'product_create', $id, $data, $request->user()->id);

        return back()->with('success', 'Đã gửi đề xuất mặt hàng để Ban quản lý duyệt.');
    }

    public function proposeCategory(Request $request, CatalogWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:categories,id'], 'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $id = $data['id'] ?? null;
        $workflow->propose($id ? 'category_update' : 'category_create', $id, $data, $request->user()->id);

        return back()->with('success', 'Đã gửi đề xuất danh mục.');
    }

    public function reviewChange(Request $request, CatalogWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:2000']]);
        $workflow->review($id, $request->user()->id, $data['decision'] === 'approve', $data['note'] ?? null);

        return back()->with('success', 'Đã xử lý đề xuất danh mục.');
    }

    public function supplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('suppliers', 'code')->ignore($request->input('id'))],
            'name' => ['required', 'string', 'max:200'], 'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'], 'address' => ['nullable', 'string', 'max:1000'],
            'id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);
        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['updated_at'] = now();
        if ($id) {
            DB::table('suppliers')->where('id', $id)->update($data);
        } else {
            DB::table('suppliers')->insert($data + ['created_at' => now()]);
        }

        return back()->with('success', 'Đã lưu nhà cung cấp.');
    }

    public function warehouse(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('warehouses', 'code')->ignore($request->input('id'))],
            'name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:1000'],
            'id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);
        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['updated_at'] = now();
        if ($id) {
            DB::table('warehouses')->where('id', $id)->update($data);
        } else {
            DB::table('warehouses')->insert($data + ['created_at' => now()]);
        }

        return back()->with('success', 'Đã lưu kho.');
    }

    public function createUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'role' => ['required', 'in:customer,sales,warehouse,accounting,manager,admin,delivery'],
            'password' => ['required', 'string', 'min:10'],
        ]);
        DB::transaction(function () use ($data) {
            $user = User::create($data + ['status' => 'active']);
            if ($user->role === 'customer') {
                DB::table('customers')->insert([
                    'user_id' => $user->id, 'code' => 'KH-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Đã tạo tài khoản nhân sự.');
    }

    public function updateUser(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', 'in:customer,sales,warehouse,accounting,manager,admin,delivery'], 'status' => ['required', 'in:active,disabled']]);
        abort_if($id === $request->user()->id && ($data['role'] !== 'admin' || $data['status'] !== 'active'), 422);
        DB::transaction(function () use ($id, $data) {
            DB::table('users')->where('id', $id)->update($data + ['updated_at' => now()]);
            if ($data['role'] === 'customer' && ! DB::table('customers')->where('user_id', $id)->exists()) {
                DB::table('customers')->insert([
                    'user_id' => $id, 'code' => 'KH-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Đã cập nhật phân quyền/tình trạng tài khoản.');
    }

    public function resetPassword(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:10', 'confirmed']]);
        DB::table('users')->where('id', $id)->update([
            'password' => Hash::make($data['password']), 'failed_logins' => 0,
            'locked_until' => null, 'updated_at' => now(),
        ]);

        return back()->with('success', 'Đã đặt lại mật khẩu.');
    }

    public function unlockUser(int $id): RedirectResponse
    {
        $changed = DB::table('users')->where('id', $id)->whereNotNull('locked_until')->update([
            'failed_logins' => 0, 'locked_until' => null, 'updated_at' => now(),
        ]);
        abort_unless($changed, 422);

        return back()->with('success', 'Đã mở khóa tài khoản.');
    }

    public function creditLimit(Request $request, int $customerId): RedirectResponse
    {
        $data = $request->validate(['credit_limit' => ['required', 'numeric', 'min:0']]);
        DB::table('customers')->where('id', $customerId)->update([
            'credit_limit' => $data['credit_limit'], 'updated_at' => now(),
        ]);

        return back()->with('success', 'Đã cập nhật hạn mức công nợ.');
    }
}
