<section class="mb-5 staff-management-section staff-record-list" aria-labelledby="permission-matrix-title">
    <h2 id="permission-matrix-title">Ma trận vai trò và quyền</h2>
    <p class="staff-section-lead">Mỗi tài khoản có một vai trò. Quyền dưới đây được dùng để bảo vệ route và điều khiển các thao tác trên giao diện.</p>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th scope="col">Vai trò</th><th scope="col">Mã</th><th scope="col">Quyền được cấp</th></tr></thead>
            <tbody>
                @foreach($roles as $role)
                    @php($assigned = $rolePermissions->get($role->id, collect()))
                    <tr>
                        <th scope="row">{{ $role->name }}</th>
                        <td><code>{{ $role->code }}</code></td>
                        <td>
                            <ul class="list-inline mb-0">
                                @foreach($permissions->whereIn('code', $assigned) as $permission)
                                    <li class="list-inline-item"><span class="badge text-bg-light">{{ $permission->name }} <code>{{ $permission->code }}</code></span></li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
