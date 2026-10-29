<?php

namespace Database\Seeders;

use App\Support\PermissionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (PermissionMatrix::roles() as $code => $name) {
            DB::table('roles')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (PermissionMatrix::permissions() as $code => $name) {
            DB::table('permissions')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $roles = DB::table('roles')->pluck('id', 'code');
        $permissions = DB::table('permissions')->pluck('id', 'code');

        foreach (PermissionMatrix::rolePermissions() as $roleCode => $permissionCodes) {
            foreach ($permissionCodes as $permissionCode) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roles[$roleCode],
                    'permission_id' => $permissions[$permissionCode],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
