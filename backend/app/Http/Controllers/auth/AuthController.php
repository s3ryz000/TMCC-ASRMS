<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    /**
     * Authenticate user and return token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->validated());

            return response()->json([
                'message' => 'Login successful.',
                'user' => $result['user']->load('roles'),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Login failed.',
                'error' => config('app.debug') ? $e->getMessage() : 'Invalid credentials or server error.',
            ], 500);
        }
    }

    /**
     * Revoke the current access token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return response()->json([
                'message' => 'Logged out successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Logout failed.',
                'error' => config('app.debug') ? $e->getMessage() : 'Something went wrong.',
            ], 500);
        }
    }

    /**
     * Change password for authenticated user (all roles).
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->changePassword(
                $request->user(),
                $request->validated('current_password'),
                $request->validated('password')
            );

            return response()->json(['message' => 'Password changed successfully.']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Password change failed.',
                'error' => config('app.debug') ? $e->getMessage() : 'Something went wrong.',
            ], 500);
        }
    }
}
