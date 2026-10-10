<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cmms:create-admin {username}', function (string $username) {
    if (User::where('username', $username)->exists()) {
        $this->error('Tên đăng nhập đã tồn tại.');

        return 1;
    }
    $name = $this->ask('Họ và tên quản trị viên');
    $password = $this->secret('Mật khẩu (ít nhất 10 ký tự)');
    if (! $name || ! $password || mb_strlen($password) < 10) {
        $this->error('Cần tên và mật khẩu ít nhất 10 ký tự.');

        return 1;
    }
    User::create([
        'name' => $name, 'username' => $username, 'password' => $password,
        'role' => 'admin', 'status' => 'active',
    ]);
    $this->info('Đã tạo tài khoản quản trị viên.');

    return 0;
})->purpose('Tạo quản trị viên đầu tiên với mật khẩu nhập ẩn');
