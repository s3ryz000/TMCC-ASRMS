<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Staff;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    use AuthorizesRole;

    /**
     * List users with search, filter by role, pagination (admin only).
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $query = User::query()
            ->select(['id', 'name', 'username', 'email', 'role', 'department', 'status', 'created_at', 'updated_at']);

        $query->whereIn('role', ['admin', 'staff']);

        if ($search = $request->input('search')) {
            $search = preg_replace('/\s+/', ' ', trim($search));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            if (in_array($role, ['admin', 'staff'], true)) {
                $query->where('role', $role);
            }
        }

        $allowedSort = ['name', 'username', 'email', 'role', 'status', 'department', 'created_at'];
        $sortKey = $request->input('sort', 'name');
        $sortKey = in_array($sortKey, $allowedSort, true) ? $sortKey : 'name';
        $sortDir = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortKey, $sortDir);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $users = $query->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Create a new user (admin only). Optionally create Staff record for staff/admin roles.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $validated = $request->validated();
        $validated['password'] = bcrypt($validated['password']);
        $validated['status'] = 'active';
        unset($validated['password_confirmation']);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create($validated);
            $user->assignRole($validated['role']);

            if (in_array($validated['role'], ['staff', 'admin'], true)) {
                $name = explode(' ', $user->name, 2);
                Staff::create([
                    'user_id' => $user->id,
                    'first_name' => $name[0] ?? $user->name,
                    'last_name' => $name[1] ?? '',
                    'role' => $validated['role'],
                    'email' => $user->email,
                ]);
            }

            return $user;
        });

        $user->load('roles');

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user,
        ], 201);
    }

    /**
     * Show a single user (admin only).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->load('roles');
        $user->makeHidden(['password']);

        return response()->json(['user' => $user]);
    }

    /**
     * Update user (admin only).
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validated();

        // A blank password field means "leave the credential alone"; only hash
        // and store when the administrator actually supplied a new one.
        $passwordWasReset = filled($validated['password'] ?? null);

        if ($passwordWasReset) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        // Deactivating an account ends its open sessions at once (#79).
        if ($user->wasChanged('status') && $user->status === 'inactive') {
            $user->tokens()->delete();
        }

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        if ($passwordWasReset) {
            SystemLog::create([
                'action'  => "Password reset for user {$user->username} ({$user->email}) by administrator",
                'user_id' => $request->user()->id,
                'role'    => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
            ]);
        }

        $user->load('roles');
        $user->makeHidden(['password']);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Delete user (admin only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ((int) $id === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }
}
