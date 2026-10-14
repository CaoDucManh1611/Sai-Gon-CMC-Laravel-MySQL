<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendPasswordResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
        ], [
            'login.required' => 'Vui lòng nhập email, số điện thoại hoặc tên đăng nhập.',
        ]);
        $login = $this->normalizeLogin(trim($data['login']));
        $user = User::where('username', $login)->orWhere('phone', $login)->orWhere('email', $login)->first();

        if ($user?->email) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', 'Nếu tài khoản có email, liên kết đặt lại mật khẩu sẽ được gửi đến email đó. Nếu chưa thêm email, vui lòng liên hệ quản trị viên.');
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'email' => $request->query('email'),
            'token' => $token,
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.required' => 'Vui lòng nhập email nhận liên kết khôi phục.',
            'email.email' => 'Email không hợp lệ.',
            'token.required' => 'Liên kết đặt lại mật khẩu không hợp lệ.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.min' => 'Mật khẩu cần ít nhất 8 ký tự.',
            'password.confirmed' => 'Xác nhận mật khẩu chưa khớp.',
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Mật khẩu đã được cập nhật. Bạn có thể đăng nhập lại.');
        }

        return back()->withErrors([
            'email' => 'Liên kết không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.',
        ])->withInput($request->only('email'));
    }

    public function sendRegistrationOtp(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254', 'unique:users,email', 'unique:users,username'],
        ], [
            'email.required' => 'Vui lòng nhập email đăng nhập.',
            'email.email' => 'Định dạng email không hợp lệ.',
            'email.unique' => 'Email này đã có tài khoản. Vui lòng đăng nhập hoặc dùng email khác.',
        ]);

        $previous = $request->session()->get('registration_otp');
        if ($previous && ($previous['sent_at'] ?? 0) > now()->subMinute()->timestamp) {
            throw ValidationException::withMessages(['email' => 'Vui lòng đợi 60 giây trước khi gửi lại mã.']);
        }

        $code = (string) random_int(100000, 999999);
        Mail::raw("Mã xác nhận đăng ký Sài Gòn CMC của bạn là: {$code}. Mã có hiệu lực trong 5 phút.", function ($message) use ($data): void {
            $message->to($data['email'])->subject('Mã OTP xác nhận đăng ký Sài Gòn CMC');
        });

        $request->session()->put('registration_otp', [
            'email' => $data['email'],
            'hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5)->timestamp,
            'sent_at' => now()->timestamp,
            'attempts' => 0,
        ]);

        return response()->json(['message' => 'Đã gửi mã OTP đến email. Mã có hiệu lực trong 5 phút.']);
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email', 'unique:users,username'],
            'otp' => ['required', 'digits:6'],
            'address' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'email.required' => 'Vui lòng nhập email đăng nhập.',
            'email.unique' => 'Email này đã có tài khoản. Vui lòng đăng nhập hoặc dùng email khác.',
            'otp.required' => 'Mã OTP không hợp lệ. Vui lòng nhập mã đã nhận.',
            'otp.digits' => 'Mã OTP không hợp lệ. Vui lòng nhập đủ 6 chữ số.',
            'address.required' => 'Vui lòng nhập địa chỉ.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'password.min' => 'Mật khẩu cần ít nhất 8 ký tự.',
            'password.confirmed' => 'Xác nhận mật khẩu chưa khớp.',
        ]);

        $otp = $request->session()->get('registration_otp');
        if (! $otp || $otp['email'] !== $data['email'] || $otp['expires_at'] < now()->timestamp) {
            throw ValidationException::withMessages(['otp' => 'Mã OTP không hợp lệ hoặc đã hết hạn. Vui lòng gửi mã mới.']);
        }
        if (! Hash::check($data['otp'], $otp['hash'])) {
            $otp['attempts'] = ($otp['attempts'] ?? 0) + 1;
            if ($otp['attempts'] >= 5) {
                $request->session()->forget('registration_otp');
                throw ValidationException::withMessages(['otp' => 'Mã OTP không hợp lệ. Bạn đã nhập sai 5 lần, vui lòng gửi mã mới.']);
            }
            $request->session()->put('registration_otp', $otp);
            throw ValidationException::withMessages(['otp' => 'Mã OTP không hợp lệ. Vui lòng kiểm tra và thử lại.']);
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'],
                'address' => $data['address'],
                'username' => $data['email'], 'role' => 'customer', 'status' => 'active',
                'password' => $data['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            DB::table('customers')->insert([
                'user_id' => $user->id, 'code' => 'KH-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $user;
        });
        $request->session()->forget('registration_otp');
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('catalog')->with('success', 'Đăng ký thành công.');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'], 'password' => ['required', 'string'],
        ]);
        $login = $this->normalizeLogin(trim($credentials['login']));
        $user = User::where('username', $login)->orWhere('phone', $login)->orWhere('email', $login)->first();
        if (! $user || $user->status !== 'active') {
            return back()->withErrors(['login' => preg_match('/\s/u', $login)
                ? 'Vui lòng đăng nhập bằng email hoặc tên đăng nhập, không dùng họ tên.'
                : 'Email/số điện thoại/tên đăng nhập hoặc mật khẩu không đúng.'])->onlyInput('login');
        }
        if ($user->locked_until !== null) {
            return back()->withErrors(['login' => 'Tài khoản tạm khóa. Vui lòng liên hệ quản trị viên để mở khóa.'])->onlyInput('login');
        }
        if (! Hash::check($credentials['password'], $user->password)) {
            $attempts = $user->failed_logins + 1;
            $user->forceFill([
                'failed_logins' => $attempts,
                'locked_until' => $attempts > 5 ? now() : null,
            ])->save();

            return back()->withErrors(['login' => $attempts > 5
                ? 'Tài khoản tạm khóa. Vui lòng liên hệ quản trị viên để mở khóa.'
                : 'Thông tin đăng nhập không đúng.'])->onlyInput('login');
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

    private function normalizeLogin(string $login): string
    {
        if (preg_match('/^[0-9+() .-]+$/', $login)) {
            $login = preg_replace('/\D/', '', $login) ?? $login;
            $login = str_starts_with($login, '84') ? '0'.substr($login, 2) : $login;
        }

        return str_contains($login, '@') ? Str::lower($login) : $login;
    }
}
