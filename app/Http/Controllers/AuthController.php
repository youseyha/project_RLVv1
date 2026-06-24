<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Tenants;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    // Register (Tenant + Admin User)
    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'busines_type' => 'required|string',
            'email' => 'required|email|unique:tenants,email',
            'phone' => 'required|string|unique:tenants,phone',
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        DB::beginTransaction();

        try {
            // Create Tenant
            $tenant = Tenants::create([
                'company_name' => $request->company_name,
                'busines_type' => $request->busines_type,
                'email' => $request->email,
                'phone' => $request->phone,
                'status' => 'active',
            ]);

            // Create Admin User
            $user = User::create([
                'tenant_id' => $tenant->tenant_id,
                'username' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'is_active' => true,
            ]);


            // SET TENANT CONTEXT FOR SPATIE (Important!)
            app(PermissionRegistrar::class)
                ->setPermissionsTeamId($tenant->tenant_id);

            // Assign Role
            $user->assignRole('admin');

            // Update tenant_id in model_has_roles for multi-tenancy
            DB::table('model_has_roles')
                ->where('model_id', $user->user_id)
                ->where('model_type', User::class)
                ->update(['tenant_id' => $tenant->tenant_id]);
            
            // Reload to get permissions
            $user->load(['roles', 'permissions']);

            DB::commit();

            return response()->json([
                'message' => 'Created account successfully',
                'user' => new UserResource($user),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Login
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        // Find user
        $user = User::where('email', $request->email)
                    ->with(['branch', 'tenant'])
                    ->first();

        // Check credentials
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check user active
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated.',
            ], 403);
        }
        /**
         * SET CURRENT TENANT FOR SPATIE
         */
        app(PermissionRegistrar::class)
            ->setPermissionsTeamId($user->tenant_id);

        // Reload roles & permissions AFTER setting tenant
        $user->load(['roles', 'permissions']);
        // SUPER ADMIN (NO TENANT)
        if (!$user->hasRole('super_admin')) {
            // Check tenant exists
            if (!$user->tenant) {
                return response()->json([
                    'message' => 'Tenant not found.',
                ], 404);
            }
            
            // Check tenant status
            if ($user->tenant->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your tenant account is ' . $user->tenant->status.'. Please contact support.',
                    'status' => $user->tenant->status,
                ], 403);
            }
        }

        // Update last login
        $user->update(['last_login' => now()]);

        // Create new token
        $deviceName = $request->device_name ?? 'api_token';
        $token = $user->createToken(
            $deviceName,
            [], // Empty array - we check permissions via Spatie
            now()->addDays(30)
        )->plainTextToken;

        return response()->json([ 
            'message' => 'Login successful',
            'user' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(30)->toDateTimeString(),
        ]);
    }

    // Logout
    public function logout(Request $request)
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    // Logout All Devices
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices',
        ]);
    }

    // Get Current User
    public function me(Request $request)
    {
        $user = $request->user()->load(['tenant', 'branch', 'profile']);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    // Refresh Token
    public function refresh(Request $request)
    {
        $user = $request->user();

        // Get current permissions (in case they changed)
        $permissions = $user->getAllPermissions()->pluck('name')->toArray();
        $roles = $user->getRoleNames()->toArray();

        // Delete current token
        $user->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken(
            'auth_token',
            [], // Empty - use Spatie permission checks
            now()->addDays(30)
        )->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => now()->addDays(30)->toDateTimeString(),
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }
}
