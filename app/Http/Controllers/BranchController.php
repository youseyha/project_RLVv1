<?php

namespace App\Http\Controllers;

use App\Http\Resources\BranchCollection;
use App\Http\Resources\BranchResource;
use App\Models\Branches;
use App\Models\User;
use App\Traits\PaginationTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    use PaginationTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $branches = Branches::with(['users','manager'])
            ->where('tenant_id', $tenant->tenant_id)
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) { 
                    $q->where('branch_name', 'like', "%{$search}%")
                      ->orWhere('branch_code', 'like', "%{$search}%")
                      ->orWhere('address', 'like', "%{$search}%");
                });
            })
            ->when($request->is_active !== null, function ($query) use ($request) {
                $query->where('is_active','=', $request->boolean('is_active'));
            })
            ->withCount('users')
            ->latest()
            ->paginate($request->per_page ?? 20);

        return new BranchCollection($branches);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $tenant = app('tenant');

        // Check if tenant can add more branches
        if (!$tenant->canAddBranch()) {
            return response()->json([
                'success' => false,
                'message' => 'Branch limit reached for your plan',
                'current_count' => $tenant->branches()->count(),
                'max_allowed' => $tenant->subscription->plan->max_branches,
                'action' => 'upgrade',
            ], 403);
        }
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'branch_name' => 'required|string|max:255',
                'branch_code' => [
                    'required',
                    Rule::unique('branches')->where('tenant_id', $tenant->tenant_id),
                ],
                'address' => 'required|string',
                'phone' => [
                    'nullable',
                    'string',
                    'max:20',
                    Rule::unique('branches'),
                ],
                'manager_id' => [
                    'nullable',
                    'uuid',
                    Rule::exists('users', 'user_id')->where(function ($query) use ($tenant) {
                        $query->where('tenant_id', $tenant->tenant_id)
                            ->where('is_active', true);
                    }),
                ],
                'is_active' => 'nullable|boolean',
            ]);
        
            //  VALIDATE MANAGER (if provided)
            if ($request->filled('manager_id')) {
                $manager = User::where('user_id', $validated['manager_id'])
                    ->where('tenant_id', $tenant->tenant_id)
                    ->first();

                if (!$manager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Manager not found.',
                    ], 404);
                }

                // Check if user has manager role
                if (!$manager->hasRole('manager')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected user is not a manager.',
                        'user_roles' => $manager->getRoleNames(),
                    ], 400);
                }

                // Check if manager already assigned to another branch
                if ($manager->branch_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Manager is already assigned to another branch.',
                        'current_branch' => $manager->branch->branch_name ?? 'Unknown',
                    ], 400);
                }
            }
            // CREATE BRANCH
            $branch = Branches::create([
                'tenant_id' => $tenant->tenant_id,
                'branch_name' => $validated['branch_name'],
                'branch_code' => $validated['branch_code'],
                'address' => $validated['address'],
                'phone' => $validated['phone'] ?? null,
                'manager_name' => $manager->username ?? null, // Store manager name
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // AUTO-ASSIGN MANAGER TO BRANCH
            if (isset($manager)) {
                $manager->update([
                    'branch_id' => $branch->branch_id,
                ]);
            }

            DB::commit();

            // RETURN RESPONSE WITH MANAGER INFO
            $branch->load(['users', 'manager']); // Load manager relationship

            return response()->json([
                'success' => true,
                'message' => 'Branch created successfully',
                'data' => new BranchResource($branch),
            ], 201);

        }catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create branch',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::with(['users', 'manager'])
            ->where('tenant_id', $tenant->tenant_id)
            ->where('branch_id', $id)
            ->withCount('users')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new BranchResource($branch),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::where('tenant_id', $tenant->tenant_id)
            ->where('branch_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'branch_name' => 'sometimes|string|max:255',
            'branch_code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('branches')->where('tenant_id', $tenant->tenant_id)
                    ->ignore($branch->branch_id, 'branch_id'),
            ],
            'address' => 'sometimes|string',
            'phone' => 'nullable|string|max:20',
            'manager_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'user_id')->where(function ($query) use ($tenant) {
                    $query->where('tenant_id', $tenant->tenant_id)
                          ->where('is_active', true);
                }),
            ],
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Handle manager change
            if ($request->has('manager_id')) {
                $oldManager = User::where('branch_id', $branch->branch_id)
                    ->where('tenant_id', $tenant->tenant_id)
                    ->first();

                // Remove old manager assignment
                if ($oldManager) {
                    $oldManager->update(['branch_id' => null]);
                }

                // Assign new manager
                if ($validated['manager_id']) {
                    $newManager = User::findOrFail($validated['manager_id']);

                    if (!$newManager->hasRole('manager')) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Selected user is not a manager.',
                        ], 400);
                    }

                    // Check if new manager is assigned to anther branch
                    if ($newManager->branch_id && $newManager->branch_id !== $branch->branch_id) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Manager is already assigned to another branch.',
                            'current_branch' => $newManager->branch->branch_name ?? 'Unknown',
                        ], 400);
                    }
                
                    $newManager->update(['branch_id' => $branch->branch_id]);
                    $validated['manager_name'] = $newManager->username;// Update manager name in branch
                }else {
                    $validated['manager_name'] = null; // Clear manager name if manager_id is null
                }
            }

            // Update branch
            $branch->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Branch updated successfully',
                'data' => new BranchResource($branch->load(['users', 'manager'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update branch',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ASSIGN MANAGER - Assign/Change Manager for Existing Branch
     */
    public function assignManager(Request $request, string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::where('branch_id', $id)
            ->where('tenant_id', $tenant->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'manager_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'user_id')->where(function ($query) use ($tenant) {
                    $query->where('tenant_id', $tenant->tenant_id)
                          ->where('is_active', true);
                }),
            ],
        ]);

        $manager = User::findOrFail($validated['manager_id']);

        // Validate manager role
        if (!$manager->hasRole('manager')) {
            return response()->json([
                'success' => false,
                'message' => 'Selected user does not have manager role.',
                'user_roles' => $manager->getRoleNames(),
            ], 400);
        }

        // Check if manager already assigned elsewhere
        if ($manager->branch_id && $manager->branch_id !== $branch->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Manager is already assigned to another branch.',
                'current_branch' => $manager->branch->branch_name,
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Remove old manager
            User::where('branch_id', $branch->branch_id)
                ->where('tenant_id', $tenant->tenant_id)
                ->update(['branch_id' => null]);

            // Assign new manager
            $manager->update(['branch_id' => $branch->branch_id]);

            // Update branch manager_name
            $branch->update(['manager_name' => $manager->username]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Manager assigned successfully',
                'data' => new BranchResource($branch->load(['users', 'manager'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign manager',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * REMOVE MANAGER - Remove manager from branch
     */
    public function removeManager(Request $request, string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::where('branch_id', $id)
            ->where('tenant_id', $tenant->tenant_id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Remove manager assignment
            User::where('branch_id', $branch->branch_id)
                ->where('tenant_id', $tenant->tenant_id)
                ->update(['branch_id' => null]);

            // Clear manager name
            $branch->update(['manager_name' => null]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Manager removed successfully',
                'data' => new BranchResource($branch->load('users')),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove manager',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::where('tenant_id', $tenant->tenant_id)
            ->where('branch_id', $id)
            ->withCount('users') // Get count of users assigned to this branch and create a users_count attribute automatically
            ->firstOrFail();

        // Check if branch has users
        if ($branch->users_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete branch with assigned users',
                'users_count' => $branch->users_count,
            ], 409);
        }

        $branch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch deleted successfully',
        ]);
    }
    /**
     * Toggle branch active status
     */
    public function toggleStatus(string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::with(['users', 'manager'])
            ->where('tenant_id', $tenant->tenant_id)
            ->where('branch_id', $id)
            ->firstOrFail();

        $branch->update([
            'is_active' => !$branch->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $branch->is_active 
                ? 'Branch activated successfully' 
                : 'Branch deactivated successfully',
            'data' => new BranchResource($branch),
        ]);
    }

    /**
     * Get users assigned to branch
     */
    public function users(string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::with(['users', 'manager'])
            ->where('tenant_id', $tenant->tenant_id)
            ->where('branch_id', $id)
            ->firstOrFail();

        $users = $branch->users()
            ->with(['roles'])
            ->paginate(20);

        return response()->json([
            'success' => true,
            'branch' => new BranchResource($branch),
            'users' => [
                'data' => $users->items(),
                'meta' => $this->paginationMeta($users),
            ],
        ]);
    }
    /**
     * Get branch statistics
     */
    public function statistics(string $id)
    {
        $tenant = app('tenant');

        $branch = Branches::where('tenant_id', $tenant->tenant_id)
            ->where('branch_id', $id)
            ->withCount(['users'])
            ->firstOrFail();

        $statistics = [
            'total_users' => $branch->users_count,
            'active_users' => $branch->users()->where('is_active', true)->count(),
            'inactive_users' => $branch->users()->where('is_active', false)->count(),
            'users_by_role' => $branch->users
                            ->groupBy(function ($user) {
                                return $user->roles->first()?->name ?? 'No Role';
                            })
                            ->map(fn($users) => $users->count()),
        ];

        return response()->json([
            'success' => true,
            'branch' => new BranchResource($branch),
            'statistics' => $statistics,
        ]);
    }
}
