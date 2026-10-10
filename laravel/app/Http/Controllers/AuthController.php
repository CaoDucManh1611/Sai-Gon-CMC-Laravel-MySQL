<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $phone = preg_replace('/\D/', '', (string) $request->input('phone'));
        $request->merge(['phone' => str_starts_with($phone, '84') ? '0'.substr($phone, 2) : $phone]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'regex:/^0[0-9]{9}$/', 'unique:users,phone'],
            'address' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.regex' => 'Số điện thoại không hợp lệ.',
            'phone.unique' => 'Số điện thoại này đã có tài khoản. Vui lòng đăng nhập hoặc dùng số khác.',
            'address.required' => 'Vui lòng nhập địa chỉ.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'password.min' => 'Mật khẩu cần ít nhất 8 ký tự.',
            'password.confirmed' => 'Xác nhận mật khẩu chưa khớp.',
        ]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'],
                'username' => $data['phone'], 'role' => 'customer', 'status' => 'active',
                'password' => $data['password'],
            ]);
            DB::table('customers')->insert([
                'user_id' => $user->id, 'code' => 'KH-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('catalog')->with('success', 'Đăng ký thành công.');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'], 'password' => ['required', 'string'],
        ]);
        $login = trim($credentials['login']);
        if (preg_match('/^[0-9+() .-]+$/', $login)) {
            $login = preg_replace('/\D/', '', $login);
            $login = str_starts_with($login, '84') ? '0'.substr($login, 2) : $login;
        }
        $user = User::where('username', $login)->orWhere('phone', $login)->first();
        if (! $user || $user->status !== 'active') {
            return back()->withErrors(['login' => preg_match('/\s/u', $login)
                ? 'Khách hàng đăng nhập bằng số điện thoại đã dùng khi đăng ký, không dùng họ tên.'
                : 'Số điện thoại/tên đăng nhập hoặc mật khẩu không đúng.'])->onlyInput('login');
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            return back()->withErrors(['login' => 'Tài khoản tạm khóa. Vui lòng thử lại sau.'])->onlyInput('login');
        }
        if (! Hash::check($credentials['password'], $user->password)) {
            $attempts = $user->failed_logins + 1;
            $user->forceFill([
                'failed_logins' => $attempts >= 5 ? 0 : $attempts,
                'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null,
            ])->save();

            return back()->withErrors(['login' => 'Thông tin đăng nhập không đúng.'])->onlyInput('login');
        }
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended($user->role === 'customer' ? route('catalog') : route('staff.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
