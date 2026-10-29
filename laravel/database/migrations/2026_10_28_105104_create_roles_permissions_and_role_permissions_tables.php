<?php

use App\Support\PermissionMatrix;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $validRoles = array_keys(PermissionMatrix::roles());
        $unknownRoles = DB::table('users')->whereNull('role')
            ->orWhereNotIn('role', $validRoles)->distinct()->pluck('role')->all();

        if ($unknownRoles !== []) {
            throw new RuntimeException('Không thể chuyển đổi vai trò người dùng chưa được khai báo: '.implode(', ', array_map(
                static fn ($role) => $role === null ? '(null)' : (string) $role,
                $unknownRoles,
            )));
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 180);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        app(RolePermissionSeeder::class)->run();

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained()->restrictOnDelete();
        });

        $roleIds = DB::table('roles')->pluck('id', 'code');
        foreach (DB::table('users')->select('id', 'role')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update(['role_id' => $roleIds[$user->role]]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
