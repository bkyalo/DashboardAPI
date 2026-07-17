<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

/**
 * Authentication Controller
 * 
 * Handles user login, registration, and token management.
 */
class AuthController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * User login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->authenticate(
            $request->identifier(),
            $request->validated('password')
        );

        if (! $user) {
            return ApiResponse::unauthorized('Invalid credentials or account is inactive.');
        }

        // Revoke all previous tokens to keep the table lean
        $user->tokens()->delete();
        $token = $user->createToken('API Token')->accessToken;

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'name' => $user->real_name,
                'email' => $user->email,
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'roles' => $user->getRoleNames(),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'token' => $token,
            'status' => 200,
        ], 'Login successful');
    }

    /**
     * User registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return ApiResponse::created([
            'user' => [
                'id'      => $user->id,
                'user_id' => $user->user_id,
                'name'    => $user->real_name,
                'email'   => $user->email,
            ],
        ], 'User registered successfully');
    }

    /**
     * Send password reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Attempt to send reset link (works if mail is configured)
        Password::sendResetLink(['email' => $request->email]);

        // Always return success — never reveal if email exists
        return ApiResponse::success([], 'If this email is registered, you will receive reset instructions shortly.');
    }

    /**
     * Reset password using token from email link.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return ApiResponse::success([], 'Password has been reset successfully.');
        }

        return ApiResponse::error($status === Password::INVALID_TOKEN
            ? 'Invalid or expired reset token. Please request a new link.'
            : 'Unable to reset password. Please try again.', 422);
    }

    /**
     * User logout.
     */
    public function logout(): JsonResponse
    {
        $user = auth()->user();
        if ($user) {
            // Revoke all tokens for the user
            $user->tokens()->update(['revoked' => true]);
        }

        return ApiResponse::deleted('Logged out successfully');
    }

    /**
     * Get current user.
     */
    public function currentUser(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return ApiResponse::unauthorized('Unauthenticated');
        }

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getPermissionsViaRoles()->pluck('name'),
        ]);
    }
}
