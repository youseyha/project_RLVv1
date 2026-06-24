<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Get all available roles
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'success' => true,
            'data' => $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name'),
                    'users_count' => $role->users()->count(),
                ];
            }),
        ]);
    }

    /**
     * Get all permissions
     */
    public function permissions()
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            // Group by prefix (e.g., 'users', 'products')
            return explode('.', $permission->name)[0] ?? 'other';
        });

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Assign permissions to role
     */
    public function assignPermissions(Request $request,string $roleId)
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::findOrFail($roleId);
        
        // Sync permissions (replace existing)
        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Permissions assigned successfully',
            'data' => [
                'role' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
            ],
        ]);
    }
}
