<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Staff;
use App\Models\SystemLog;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

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

        $users->getCollection()->each(fn (User $u) => $u->append('can_approve_grades'));

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

        AuditLog::write("User created: {$user->username} ({$user->role})", $request->user());

        $user->load('roles')->append('can_approve_grades');

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

        $user->load('roles')->append('can_approve_grades');
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
        $canApproveGrades = array_key_exists('can_approve_grades', $validated) ? (bool) $validated['can_approve_grades'] : null;
        unset($validated['can_approve_grades']);

        // Checked before anything is saved, so a refusal changes nothing (#81).
        if ($refusal = $this->refuseAdminLockout($request->user(), $user, $validated['role'] ?? null, $validated['status'] ?? null)) {
            return $refusal;
        }
        if ($canApproveGrades && ($validated['role'] ?? $user->role) !== 'staff') {
            $message = 'Only staff accounts can approve grades.';

            return response()->json(['message' => $message, 'errors' => ['can_approve_grades' => [$message]]], 422);
        }

        // A blank password field means "leave the credential alone"; only hash
        // and store when the administrator actually supplied a new one.
        $passwordWasReset = filled($validated['password'] ?? null);

        if ($passwordWasReset) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        $this->logUserChanges($request->user(), $user);

        // Deactivating an account ends its open sessions at once (#79).
        if ($user->wasChanged('status') && $user->status === 'inactive') {
            $user->tokens()->delete();
        }

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }
        $this->syncGradeApproval($request->user(), $user->fresh(), $canApproveGrades);

        if ($passwordWasReset) {
            SystemLog::create([
                'action'  => "Password reset for user {$user->username} ({$user->email}) by administrator",
                'user_id' => $request->user()->id,
                'role'    => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
            ]);
        }

        $user->load('roles', 'permissions')->append('can_approve_grades');
        $user->makeHidden(['password']);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Grants or revokes "approve-grades" (#107). Only staff can hold it, so a
     * user moved off staff loses it. The permission is created on first use,
     * so the live server needs no seeder run.
     */
    private function syncGradeApproval(User $actor, User $user, ?bool $wanted): void
    {
        $had = $user->checkPermissionTo(User::APPROVE_GRADES, 'api');
        $should = $user->role === 'staff' ? ($wanted ?? $had) : false;

        if ($should && ! $had) {
            $user->givePermissionTo(Permission::findOrCreate(User::APPROVE_GRADES, 'api'));
        } elseif (! $should && $had) {
            $user->revokePermissionTo(User::APPROVE_GRADES);
        }

        if ($should !== $had) {
            AuditLog::write("User updated: {$user->username}: can approve grades " . AuditLog::value($should), $actor);
        }
    }

    /**
     * Logs what an update changed (#95): activation or deactivation as its
     * own entry, then the role (old → new) and the names of other changed
     * fields. Password resets are logged separately and never shown.
     */
    private function logUserChanges(User $actor, User $user): void
    {
        if ($user->wasChanged('status')) {
            AuditLog::write(($user->status === 'active' ? 'User activated: ' : 'User deactivated: ') . $user->username, $actor);
        }

        $parts = [];
        if ($user->wasChanged('role')) {
            $parts[] = 'role ' . AuditLog::value($user->getPrevious()['role'] ?? null) . ' → ' . AuditLog::value($user->role);
        }
        $fields = array_values(array_intersect(['name', 'email', 'department'], array_keys($user->getChanges())));
        if ($fields) {
            $parts[] = 'changed ' . implode(', ', $fields);
        }

        if ($parts) {
            AuditLog::write("User updated: {$user->username} — " . implode('; ', $parts), $actor);
        }
    }

    /**
     * The one place that keeps administrators from locking everyone out (#81):
     * nobody changes their own role or status, and the last active admin
     * (users.role = admin, status = active) can't be deactivated or demoted.
     * Accounts are never deleted (#103). Returns the 422 response to send, or null when the change is
     * allowed. Submitting a user's current role and status is not a change.
     */
    private function refuseAdminLockout(User $actor, User $target, ?string $newRole = null, ?string $newStatus = null): ?JsonResponse
    {
        $roleAfter = $newRole ?? $target->role;
        $statusAfter = $newStatus ?? $target->status;

        if ((int) $actor->id === (int) $target->id
            && ($roleAfter !== $target->role || $statusAfter !== $target->status)) {
            return response()->json(['message' => 'You cannot change your own status or role.'], 422);
        }

        $isActiveAdmin = fn (?string $role, ?string $status) => $role === 'admin' && $status === 'active';

        if ($isActiveAdmin($target->role, $target->status) && ! $isActiveAdmin($roleAfter, $statusAfter)) {
            $otherActiveAdmins = User::where('role', 'admin')->where('status', 'active')->whereKeyNot($target->id)->count();
            if ($otherActiveAdmins === 0) {
                return response()->json(['message' => 'At least one active administrator is required.'], 422);
            }
        }

        return null;
    }
}
