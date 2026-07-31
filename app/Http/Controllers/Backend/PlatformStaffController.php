<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Mail\PlatformStaffLoginMail;
use Spatie\Permission\Models\Role;

class PlatformStaffController extends Controller
{
    public function index(Request $request)
    {
        $staff = User::query()
            ->whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) in ('superadmin', 'platformstaff')")
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('firstname', 'like', "%{$search}%")
                        ->orWhere('surname', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->get('per_page', 15));

        $staff->getCollection()->transform(fn (User $user) => $this->staffPayload($user));

        return response()->json([
            'types' => $this->types(),
            'staff' => $staff,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:100'],
            'surname' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'super_admin_type' => ['required', Rule::in(array_keys(User::SUPER_ADMIN_PERMISSION_MAP))],
            'status' => ['nullable', 'boolean'],
        ]);

        if ($data['super_admin_type'] === 'owner' && $this->ownerExists()) {
            return response()->json([
                'message' => 'Only one Super Admin Owner account is allowed. Create this person as platform staff instead.',
            ], 422);
        }

        $password = 'GQ-' . Str::upper(Str::random(8));
        $role = $data['super_admin_type'] === 'owner' ? 'Super-Admin' : 'Platform-Staff';

        $user = User::create([
            'firstname' => $data['firstname'],
            'surname' => $data['surname'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $role,
            'super_admin_type' => $data['super_admin_type'],
            'status' => $request->boolean('status', true) ? 1 : 0,
            'password' => Hash::make($password),
            'default_password' => $password,
            'force_password_change' => true,
        ]);

        if (method_exists($user, 'assignRole')) {
            $spatieRole = Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);

            $user->assignRole($spatieRole);
        }

        try {
            Mail::to($user->email)->send(new PlatformStaffLoginMail($user, $password, $this->loginUrl()));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Platform staff account created and login details were sent by email.',
            'staff' => $this->staffPayload($user->fresh()),
            'default_password' => $password,
        ], 201);
    }

    public function update(Request $request, User $staff)
    {
        abort_unless($staff->isSuperAdminUser(), 404);
        abort_if((int) $request->user()->id === (int) $staff->id && $request->filled('status') && ! $request->boolean('status'), 422, 'You cannot deactivate your own platform owner account.');

        $data = $request->validate([
            'firstname' => ['sometimes', 'required', 'string', 'max:100'],
            'surname' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'super_admin_type' => ['sometimes', 'required', Rule::in(array_keys(User::SUPER_ADMIN_PERMISSION_MAP))],
            'status' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('status', $data)) {
            $data['status'] = $request->boolean('status') ? 1 : 0;
        }

        if (($data['super_admin_type'] ?? null) === 'owner' && $this->ownerExists($staff->id)) {
            return response()->json([
                'message' => 'Only one Super Admin Owner account is allowed.',
            ], 422);
        }

        if (array_key_exists('super_admin_type', $data)) {
            $data['role'] = $data['super_admin_type'] === 'owner' ? 'Super-Admin' : 'Platform-Staff';
        }

        $staff->update($data);

        if (isset($data['role']) && method_exists($staff, 'syncRoles')) {
            $spatieRole = Role::firstOrCreate([
                'name' => $data['role'],
                'guard_name' => 'web',
            ]);

            $staff->syncRoles([$spatieRole]);
        }

        return response()->json([
            'message' => 'Platform staff account updated.',
            'staff' => $this->staffPayload($staff->fresh()),
        ]);
    }

    public function sendLoginDetails(Request $request, User $staff)
    {
        abort_unless($staff->isSuperAdminUser(), 404);

        $password = 'GQ-' . Str::upper(Str::random(8));

        $staff->update([
            'password' => Hash::make($password),
            'default_password' => $password,
            'force_password_change' => true,
        ]);

        Mail::to($staff->email)->send(new PlatformStaffLoginMail($staff->fresh(), $password, $this->loginUrl()));

        return response()->json([
            'message' => 'Login details have been sent to the platform staff email.',
        ]);
    }

    public function destroy(Request $request, User $staff)
    {
        abort_unless($staff->isSuperAdminUser(), 404);
        abort_if((int) $request->user()->id === (int) $staff->id, 422, 'You cannot delete your own account.');
        abort_if($staff->role === 'Super-Admin' || $staff->super_admin_type === 'owner', 422, 'The Super Admin owner account cannot be deleted here.');

        $staff->delete();

        return response()->json([
            'message' => 'Platform staff account deleted.',
        ]);
    }

    private function staffPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'surname' => $user->surname,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => (int) $user->status,
            'super_admin_type' => $user->super_admin_type ?: 'owner',
            'super_admin_type_label' => $user->superAdminTypeLabel(),
            'super_admin_permissions' => $user->superAdminPermissions(),
            'created_at' => $user->created_at,
        ];
    }

    private function types(): array
    {
        return collect(User::SUPER_ADMIN_PERMISSION_MAP)
            ->map(fn ($permissions, $key) => [
                'key' => $key,
                'label' => (new User(['super_admin_type' => $key, 'role' => 'Super-Admin']))->superAdminTypeLabel(),
                'permissions' => $permissions,
            ])
            ->values()
            ->all();
    }

    private function ownerExists(?int $exceptUserId = null): bool
    {
        return User::query()
            ->whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) = 'superadmin'")
            ->when($exceptUserId, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->exists();
    }

    private function loginUrl(): string
    {
        return rtrim((string) config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/') . '/login';
    }
}
