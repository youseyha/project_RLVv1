<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\PaginationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use PaginationTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');
        
        $users = User::with(['branch', 'roles', 'profile'])
            ->where('tenant_id', $tenant->tenant_id)
            ->when($request->branch_id, function ($query, $branchId) {
                $query->where('branch_id', $branchId);
            })
            ->when($request->role, function ($query, $role) {
                $query->role($role);
            })
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($request->per_page ?? 20);

        return new UserCollection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $tenant = app('tenant');

        // Check if tenant can add more users
        if (!$tenant->canAddUser()) {
            return response()->json([
                'success' => false,
                'message' => 'User limit reached for your plan',
                'action' => 'upgrade',
            ], 403);
        }
        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users')->where('tenant_id', $tenant->tenant_id),
            ],
            'email' => [
                'required',
                'email',
                Rule::unique('users')->where('tenant_id', $tenant->tenant_id),
            ],
            'password' => 'required|string|min:8|confirmed',
            'branch_id' => 'nullable|uuid|exists:branches,branch_id',
            'role' => 'required|string|in:admin,manager,cashier,staff',
            'is_active' => 'nullable|boolean',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->tenant_id,
            'branch_id' => $validated['branch_id'] ?? null,
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Assign role
        $user->assignRole($validated['role']);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => new UserResource($user->load(['branch', 'roles'])),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $user = User::where('tenant_id', $tenant->tenant_id)
            ->where('user_id', $id)
            ->with(['branch', 'roles', 'permissions', 'profile'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $tenant = app('tenant');

        $user = User::where('tenant_id', $tenant->tenant_id)
            ->where('user_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('users')->where('tenant_id', $tenant->tenant_id)
                    ->ignore($user->user_id, 'user_id'),
            ],
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users')->where('tenant_id', $tenant->tenant_id)
                    ->ignore($user->user_id, 'user_id'),
            ],
            'password' => 'sometimes|string|min:8|confirmed',
            'branch_id' => 'nullable|uuid|exists:branches,branch_id',
            'role' => 'sometimes|string|in:admin,manager,cashier,staff',
            'is_active' => 'sometimes|boolean',
        ]);

        // Update password if provided
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // Update role if changed
        if (isset($validated['role']) && $validated['role'] !== $user->role) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => new UserResource($user->fresh(['branch', 'roles'])),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id,Request $request)
    {
        $tenant = app('tenant');

        $user = User::where('tenant_id', $tenant->tenant_id)
            ->where('user_id', $id)
            ->firstOrFail();

        // Prevent self-deletion
        if ($user->user_id === $request->user()->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete user
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
    /**
     * Assign role to user
     */
    public function assignRole(Request $request,string $id)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'role' => 'required|string|in:admin,manager,cashier,staff',
        ]);

        $user = User::where('tenant_id', $tenant->tenant_id)
            ->where('user_id', $id)
            ->firstOrFail();
     
        // Prevent super_admin modification
        if ($user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify super admin role',
            ], 403);
        }

        // Sync role (replace existing)
        $user->syncRoles([$validated['role']]);

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully',
            'data' => new UserResource($user->fresh('roles')),
        ]);
    }

    /**
     * Toggle user active status
     */
    public function toggleStatus(Request $request,string $id)
    {
        $tenant = app('tenant');

        $user = User::where('tenant_id', $tenant->tenant_id)
            ->where('user_id', $id)
            ->firstOrFail();

        // Prevent self-deactivation
        if ($user->user_id === $request->user()->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 403);
        }

        $user->update([
            'is_active' => !$user->is_active,
        ]);

        // Revoke tokens if deactivated
        if (!$user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $user->is_active 
                ? 'User activated successfully' 
                : 'User deactivated successfully',
            'data' => new UserResource($user),
        ]);
    }
    /**
     * Get user statistics
     */
    public function statistics()
    {
        $tenant = app('tenant');

        $statistics = [
            'total_users'     => User::where('tenant_id', $tenant->tenant_id)->count(),
            'active_users'    => User::where('tenant_id', $tenant->tenant_id)
                                ->where('is_active', true)->count(),
            'inactive_users'  => User::where('tenant_id', $tenant->tenant_id)
                                ->where('is_active', false)->count(),
            'users_by_role' => DB::table('model_has_roles')
                                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                ->join('users', 'model_has_roles.model_id', '=', 'users.user_id')
                                ->where('model_has_roles.tenant_id', $tenant->tenant_id)
                                ->where('model_has_roles.model_type', User::class)
                                ->select('roles.name', DB::raw('COUNT(*) as users_count'))
                                ->groupBy('roles.name')
                                ->pluck('users_count', 'name'),
            'users_by_branch' => User::where('tenant_id', $tenant->tenant_id)
                                ->whereNotNull('branch_id')
                                ->with('branch:branch_id,branch_name')
                                ->get()
                                ->groupBy('branch.branch_name')
                                ->map->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }
}
