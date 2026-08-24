<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\StaffRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeveloperStaffController extends Controller
{
    public function index(Request $request)
    {
        if ($denied = $this->denyUnlessCan($request, 'staff')) {
            return $denied;
        }

        $query = Admin::query();

        if ($request->user()->isDeveloper()) {
            $query->orderByRaw("CASE WHEN role = 'developer' THEN 0 ELSE 1 END");
        } else {
            $query->where('role', '!=', 'developer');
        }

        return response()->json(
            $query->orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at'])
        );
    }

    public function store(Request $request)
    {
        if ($denied = $this->denyUnlessCan($request, 'staff')) {
            return $denied;
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8',
            'role'     => $this->assignableRoleRule($request),
        ]);

        $admin = Admin::create($data);

        return response()->json([
            'message' => 'Staff account created.',
            'admin'   => $admin->only(['id', 'name', 'email', 'role', 'created_at']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        if ($denied = $this->denyUnlessCan($request, 'staff')) {
            return $denied;
        }

        $admin = $this->findVisibleStaff($request, $id);

        $data = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'email'    => 'sometimes|required|email|unique:admins,email,' . $admin->id,
            'password' => 'nullable|string|min:8',
            'role'     => array_merge(['sometimes'], $this->assignableRoleRule($request)),
        ]);

        if (($data['role'] ?? $admin->role) !== 'developer' && $this->isLastDeveloper($admin)) {
            return response()->json([
                'message' => 'Keep at least one developer account.',
            ], 422);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $admin->update($data);

        return response()->json([
            'message' => 'Staff account updated.',
            'admin'   => $admin->only(['id', 'name', 'email', 'role', 'created_at']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if ($denied = $this->denyUnlessCan($request, 'staff')) {
            return $denied;
        }

        $admin = $this->findVisibleStaff($request, $id);

        if ((int) $admin->id === (int) $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($this->isLastDeveloper($admin)) {
            return response()->json([
                'message' => 'Keep at least one developer account.',
            ], 422);
        }

        $admin->delete();

        return response()->json(['message' => 'Staff account deleted.']);
    }

    public function roles(Request $request)
    {
        if ($denied = $this->denyUnlessCan($request, ['roles', 'staff'])) {
            return $denied;
        }

        $hideDeveloper = ! $request->user()->isDeveloper();
        $roles = StaffRole::query()
            ->when($hideDeveloper, fn ($query) => $query->where('slug', '!=', 'developer'))
            ->orderBy('name')
            ->get();

        $counts = Admin::query()
            ->selectRaw('role, COUNT(*) as total')
            ->when($hideDeveloper, fn ($query) => $query->where('role', '!=', 'developer'))
            ->groupBy('role')
            ->pluck('total', 'role');

        $catalog = StaffRole::catalog();

        return response()->json([
            'catalog' => $catalog,
            'roles'   => $roles->map(function (StaffRole $role) use ($counts) {
                $role->setAttribute('staff_count', (int) ($counts[$role->slug] ?? 0));
                $role->setAttribute('permissions', StaffRole::permissionsFor($role->slug));

                return $role;
            }),
        ]);
    }

    public function storeRole(Request $request)
    {
        if ($denied = $this->denyUnlessCan($request, 'roles')) {
            return $denied;
        }

        $data = $request->validate([
            'name'            => 'required|string|max:80|unique:staff_roles,name',
            'description'     => 'nullable|string|max:255',
            'permissions'     => 'nullable|array',
            'permissions.*'   => 'string|in:'.implode(',', StaffRole::allFunctionKeys()),
        ]);

        $role = StaffRole::create([
            'name'        => $data['name'],
            'slug'        => StaffRole::uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'is_system'   => false,
            'permissions' => StaffRole::sanitizePermissions($data['permissions'] ?? []),
        ]);

        $role->setAttribute('staff_count', 0);

        return response()->json([
            'message' => 'Role created.',
            'role'    => $role,
        ], 201);
    }

    public function updateRole(Request $request, $id)
    {
        if ($denied = $this->denyUnlessCan($request, 'roles')) {
            return $denied;
        }

        $role = $this->findVisibleRole($request, $id);

        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:80|unique:staff_roles,name,' . $role->id,
            'description'     => 'nullable|string|max:255',
            'permissions'     => 'nullable|array',
            'permissions.*'   => 'string|in:'.implode(',', StaffRole::allFunctionKeys()),
        ]);

        if ($role->is_system && isset($data['name']) && $data['name'] !== $role->name) {
            return response()->json([
                'message' => 'System roles cannot be renamed.',
            ], 422);
        }

        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = $role->slug === 'developer'
                ? StaffRole::allFunctionKeys()
                : StaffRole::sanitizePermissions($data['permissions']);
        }

        if (isset($data['name']) && $data['name'] !== $role->name) {
            $data['slug'] = StaffRole::uniqueSlug($data['name'], $role->id);
            Admin::query()->where('role', $role->slug)->update(['role' => $data['slug']]);
        }

        $role->update($data);

        return response()->json([
            'message' => 'Role updated.',
            'role'    => $role,
        ]);
    }

    public function destroyRole(Request $request, $id)
    {
        if ($denied = $this->denyUnlessCan($request, 'roles')) {
            return $denied;
        }

        $role = $this->findVisibleRole($request, $id);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
            ], 422);
        }

        $inUse = Admin::query()->where('role', $role->slug)->count();
        if ($inUse > 0) {
            return response()->json([
                'message' => "Cannot delete “{$role->name}” while {$inUse} staff still use it.",
            ], 409);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    private function denyUnlessCan(Request $request, string|array $functions): ?JsonResponse
    {
        $user = $request->user();
        $needed = (array) $functions;

        if ($user instanceof Admin) {
            foreach ($needed as $function) {
                if ($user->canUse($function)) {
                    return null;
                }
            }
        }

        return response()->json([
            'message' => 'You do not have access to this function.',
        ], 403);
    }

    private function assignableRoleRule(Request $request): array
    {
        $rules = ['required', 'string', Rule::exists('staff_roles', 'slug')];

        if (! $request->user()->isDeveloper()) {
            $rules[] = Rule::notIn(['developer']);
        }

        return $rules;
    }

    private function findVisibleStaff(Request $request, $id): Admin
    {
        $admin = Admin::findOrFail($id);

        if (! $request->user()->isDeveloper() && $admin->isDeveloper()) {
            abort(404);
        }

        return $admin;
    }

    private function findVisibleRole(Request $request, $id): StaffRole
    {
        $role = StaffRole::findOrFail($id);

        if (! $request->user()->isDeveloper() && $role->slug === 'developer') {
            abort(404);
        }

        return $role;
    }

    private function isLastDeveloper(Admin $admin): bool
    {
        if (! $admin->isDeveloper()) {
            return false;
        }

        return Admin::query()->where('role', 'developer')->count() <= 1;
    }
}
