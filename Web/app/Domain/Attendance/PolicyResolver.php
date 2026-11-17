<?php

namespace App\Domain\Attendance;

use App\Enums\LocationMode;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmploymentAssignment;
use App\Models\Geofence;
use Illuminate\Support\Collection;

/**
 * Resuelve la política de asistencia efectiva de un colaborador:
 * asignación vigente → política por defecto de la organización → política
 * conservadora integrada (registrar ubicación si está disponible).
 */
final class PolicyResolver
{
    public function for(Employee $employee, ?EmploymentAssignment $assignment = null): AttendancePolicy
    {
        $assignment ??= $employee->assignmentOn(now());

        $policy = $assignment?->attendance_policy_id
            ? AttendancePolicy::query()->with('geofences')->find($assignment->attendance_policy_id)
            : null;

        $policy ??= AttendancePolicy::query()
            ->with('geofences')
            ->where('organization_id', $employee->organization_id)
            ->where('is_default', true)
            ->first();

        return $policy ?? new AttendancePolicy([
            'name' => 'Política base',
            'location_mode' => LocationMode::Record,
            'allowed_auth_methods' => ['device_biometric', 'device_credential'],
            'require_strong_biometric' => false,
            'allow_offline' => true,
            'offline_max_hours' => 72,
            'face_verification_required' => false,
            'web_punch_allowed' => false,
            'max_clock_skew_seconds' => 300,
            'min_location_accuracy_meters' => 150,
        ]);
    }

    /**
     * @return Collection<int, Geofence>
     */
    public function geofences(AttendancePolicy $policy, ?EmploymentAssignment $assignment): Collection
    {
        $fences = $policy->exists ? $policy->geofences->where('active', true)->values() : collect();

        if ($fences->isEmpty() && $assignment?->branch_id) {
            $fences = Geofence::query()->where('branch_id', $assignment->branch_id)->where('active', true)->get();
        }

        return $fences;
    }

    /**
     * Distancia geodésica (Haversine) en metros.
     */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371008.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
