<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use App\Support\SessionLifetime;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Authenticate user and create an API token.
     *
     * @throws ValidationException
     */
    public function login(array $credentials): array
    {
        $user = User::where('username', $credentials['username'])->first();

        // Failed attempts are logged with the username typed, never the
        // password (#95); they belong to nobody, so the row's role is "guest".
        if (! $user) {
            AuditLog::write("Failed login: unknown user '" . AuditLog::typed($credentials['username']) . "'", null, 'guest');
        }
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                AuditLog::write("Failed login: {$user->username} — wrong password", null, 'guest');
            }
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // A deactivated account cannot sign in (#79). Checked after the
        // password so the message never reveals whether an account exists,
        // and before anything else so a refused login changes nothing.
        if ($user->status !== 'active') {
            AuditLog::write("Failed login: {$user->username} — account inactive", null, 'guest');
            throw ValidationException::withMessages([
                'username' => ['This account is inactive. Please contact the administrator.'],
            ]);
        }

        $user->tokens()->delete();

        // The token carries its absolute end (#86); idle expiry is SessionLifetime.
        $token = $user->createToken('auth-token', ['*'], now()->addHours(SessionLifetime::maxHours()))->plainTextToken;

        AuditLog::write("Login: {$user->username}", $user);

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Revoke the current access token for the user.
     */
    public function logout(User $user): void
    {
        /** @var \Laravel\Sanctum\PersonalAccessToken $token */
        $token = $user->currentAccessToken();
        $token->delete();

        AuditLog::write("Logout: {$user->username}", $user);
    }

    /**
     * Change authenticated user's password.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $newPassword])->save();
    }
}
