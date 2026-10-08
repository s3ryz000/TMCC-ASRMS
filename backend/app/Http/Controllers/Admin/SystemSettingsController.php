<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System settings (admin only): institution, academic year, record types, notifications.
 */
class SystemSettingsController extends Controller
{
    use AuthorizesRole;

    private const KEYS = [
        'institution_name',
        'institution_short_name',
        'institution_address',
        'academic_year',
        'semester',
        'record_types',
        'email_notifications_enabled',
    ];

    /**
     * Get all system settings (admin only).
     */
    public function show(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $settings = [];
        foreach (self::KEYS as $key) {
            $value = SystemSetting::getValue($key);
            if ($key === 'record_types' && is_string($value)) {
                $value = json_decode($value, true) ?? [];
            }
            if ($key === 'email_notifications_enabled') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            $settings[$key] = $value;
        }

        return response()->json(['settings' => $settings]);
    }

    /**
     * Current term labels for headers (staff, admin, student).
     */
    public function current(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin', 'staff', 'student'])) {
            return $err;
        }

        $y = (int) date('Y');
        $academicYear = SystemSetting::getValue('academic_year') ?: $y.'-'.($y + 1);
        $semester = SystemSetting::getValue('semester') ?: '2nd Semester';
        $institutionName = SystemSetting::getValue('institution_name') ?: 'Trece Martires City College';

        return response()->json([
            'academic_year' => $academicYear,
            'semester' => $semester,
            'institution_name' => $institutionName,
        ]);
    }

    /**
     * Update system settings (admin only).
     */
    public function update(UpdateSystemSettingsRequest $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $validated = $request->validated();
        $before = $this->getSettingsArray();

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }
            $value = $validated[$key];
            if ($key === 'record_types' && is_array($value)) {
                $value = json_encode($value);
            }
            if ($key === 'email_notifications_enabled') {
                $value = $value ? '1' : '0';
            }
            SystemSetting::setValue($key, $value);
        }

        $after = $this->getSettingsArray();
        $this->logChanges($request->user(), $before, $after);

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => $after,
        ]);
    }

    /**
     * One log entry listing each changed setting as old → new (#95). None of
     * these settings is secret; a save that changes nothing logs nothing.
     */
    private function logChanges(User $actor, array $before, array $after): void
    {
        $changes = [];
        foreach (self::KEYS as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changes[] = str_replace('_', ' ', $key) . ' ' . AuditLog::value($before[$key] ?? null) . ' → ' . AuditLog::value($after[$key] ?? null);
            }
        }

        if ($changes) {
            AuditLog::write('Settings changed: ' . implode('; ', $changes), $actor);
        }
    }

    private function getSettingsArray(): array
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $value = SystemSetting::getValue($key);
            if ($key === 'record_types' && is_string($value)) {
                $value = json_decode($value, true) ?? [];
            }
            if ($key === 'email_notifications_enabled') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            $settings[$key] = $value;
        }
        return $settings;
    }
}
