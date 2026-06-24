<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TokenController extends Controller
{
    // List all tokens
    public function index(Request $request)
    {
        $tokens = $request->user()
            ->tokens()
            ->select([
                'id', 'name',
                'last_used_at',
                'expires_at',
                'created_at',
            ])
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                    'created_at' => $token->created_at,
                    'is_current' => $token->id === request()->user()->currentAccessToken()->id,
                ];
            });

        return response()->json(['tokens' => $tokens]);
    }

    // Create new token
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        $expiresAt = $request->expires_in_days
            ? now()->addDays($request->expires_in_days)
            : now()->addDays(30);

        $token = $request->user()->createToken(
            $request->name,
            $this->getAbilitiesForRole($request->user()->role),
            $expiresAt
        );

        return response()->json([
            'message' => 'Token created successfully',
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toDateTimeString(),
        ], 201);
    }

    // Revoke specific token
    public function destroy(Request $request, string $tokenId)
    {
        $token = $request->user()
            ->tokens()
            ->where('id', $tokenId)
            ->first();

        if (!$token) {
            return response()->json([
                'message' => 'Token not found',
            ], 404);
        }

        $token->delete();

        return response()->json([
            'message' => 'Token revoked successfully',
        ]);
    }

    // Revoke all tokens
    public function destroyAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'All tokens revoked',
        ]);
    }

    private function getAbilitiesForRole(string $role): array
    {
        return match($role) {
            'admin' => ['*'],
            'manager' => ['read', 'create', 'update'],
            'cashier' => ['read', 'pos:create'],
            default => ['read'],
        };
    }
}
