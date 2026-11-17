<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Biometrics\FaceVerificationProvider;
use App\Domain\Workforce\AutoClockOutAdvisor;
use App\Enums\AuthMethod;
use App\Enums\LocationMode;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\AttendancePolicy;
use App\Models\EmploymentAssignment;
use App\Models\Geofence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Políticas de asistencia: modo de ubicación, métodos de autenticación,
 * marcaje sin conexión, geocercas, marcaje web y tolerancias técnicas.
 */
class AttendancePolicyController extends AdminController
{
    use OrganizationRules;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $assigned = EmploymentAssignment::query()->whereNotNull('attendance_policy_id')->whereNull('effective_to')
            ->selectRaw('attendance_policy_id, count(*) as total')->groupBy('attendance_policy_id')->pluck('total', 'attendance_policy_id');

        return Inertia::render('configuracion/Attendance', [
            'policies' => AttendancePolicy::query()->withTrashed()->with('geofences:id,name,radius_meters')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (AttendancePolicy $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'location_mode' => $p->location_mode->value,
                    'allowed_auth_methods' => array_values((array) $p->allowed_auth_methods),
                    'require_strong_biometric' => $p->require_strong_biometric,
                    'allow_offline' => $p->allow_offline,
                    'offline_max_hours' => (int) $p->offline_max_hours,
                    'face_verification_required' => $p->face_verification_required,
                    'web_punch_allowed' => $p->web_punch_allowed,
                    'max_clock_skew_seconds' => (int) $p->max_clock_skew_seconds,
                    'min_location_accuracy_meters' => (int) $p->min_location_accuracy_meters,
                    'auto_clock_out_at' => $p->auto_clock_out_at ? substr((string) $p->auto_clock_out_at, 0, 5) : null,
                    'is_default' => $p->is_default,
                    'deleted' => $p->trashed(),
                    'geofence_ids' => $p->geofences->pluck('id')->map(fn ($id) => (string) $id)->values(),
                    'geofences' => $p->geofences->map(fn ($g) => ['name' => $g->name, 'radius_meters' => (int) $g->radius_meters])->values(),
                    'assigned' => (int) ($assigned[$p->id] ?? 0),
                ]),
            'geofences' => Geofence::query()->where('active', true)->with('branch:id,name')->orderBy('name')->get()
                ->map(fn (Geofence $g) => ['value' => (string) $g->id, 'label' => $g->name, 'description' => ($g->branch?->name ? $g->branch->name.' · ' : '').$g->radius_meters.' m'])->values(),
            'location_modes' => [
                ['value' => LocationMode::Free->value, 'label' => 'Libre', 'description' => 'No se solicita ubicación.'],
                ['value' => LocationMode::Record->value, 'label' => 'Registrar si está disponible', 'description' => 'Se guarda la ubicación cuando el dispositivo la entrega.'],
                ['value' => LocationMode::Required->value, 'label' => 'Ubicación obligatoria', 'description' => 'Sin ubicación no se acepta el marcaje.'],
                ['value' => LocationMode::Geofence->value, 'label' => 'Dentro de geocerca', 'description' => 'El marcaje debe ocurrir dentro de una geocerca asociada.'],
                ['value' => LocationMode::DeviceOnly->value, 'label' => 'Sólo dispositivo autorizado', 'description' => 'Se valida el dispositivo, sin ubicación.'],
            ],
            'auth_methods' => [
                ['value' => AuthMethod::DeviceBiometric->value, 'label' => 'Biometría del dispositivo', 'description' => 'Huella o rostro del sistema operativo; no se almacena biometría.'],
                ['value' => AuthMethod::DeviceCredential->value, 'label' => 'PIN o patrón del dispositivo', 'description' => 'Credencial de desbloqueo del teléfono.'],
                ['value' => AuthMethod::FaceVerification->value, 'label' => 'Verificación facial con proveedor', 'description' => 'Requiere consentimiento y alta previa.'],
                ['value' => AuthMethod::WebSession->value, 'label' => 'Sesión web', 'description' => 'Marcaje desde el navegador (requiere permitir marcaje web).'],
            ],
            'face_provider' => [
                'available' => $this->faceProvider()->available(),
                'name' => $this->faceProvider()->name(),
            ],
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $policy = DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                AttendancePolicy::query()->where('is_default', true)->update(['is_default' => false]);
            }
            $policy = AttendancePolicy::query()->create(Arr::except($data, ['geofence_ids']));
            $policy->geofences()->sync($data['geofence_ids']);

            return $policy;
        });

        $this->audit->log('attendance_policy.created', $policy, "Política de asistencia creada: {$policy->name}", Arr::except($data, ['name']));
        $this->toast("Política «{$policy->name}» creada.");

        return back();
    }

    public function update(Request $request, AttendancePolicy $attendancePolicy): RedirectResponse
    {
        $this->ensureOwned($attendancePolicy);
        $data = $this->validated($request);

        if ($attendancePolicy->is_default && ! $data['is_default']) {
            throw ValidationException::withMessages(['is_default' => 'Debe existir una política por defecto. Marca otra como predeterminada en lugar de desmarcar ésta.']);
        }

        $before = Arr::except($attendancePolicy->toArray(), ['created_at', 'updated_at', 'geofences']);
        DB::transaction(function () use ($attendancePolicy, $data) {
            if ($data['is_default']) {
                AttendancePolicy::query()->whereKeyNot($attendancePolicy->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $attendancePolicy->update(Arr::except($data, ['geofence_ids']));
            $attendancePolicy->geofences()->sync($data['geofence_ids']);
        });

        $this->audit->log('attendance_policy.updated', $attendancePolicy, "Política de asistencia actualizada: {$attendancePolicy->name}", ['before' => $before, 'after' => $data]);
        $warning = app(AutoClockOutAdvisor::class)->warningForAssigned('attendance_policy_id', $attendancePolicy->id, $data['auto_clock_out_at'] ?? null);
        $this->toast('Política actualizada. Aplica a los marcajes nuevos; los registrados se conservan.'.($warning ? ' Atención: '.$warning : ''), $warning ? 'warning' : 'success');

        return back();
    }

    public function deactivate(Request $request, AttendancePolicy $attendancePolicy): RedirectResponse
    {
        $this->ensureOwned($attendancePolicy);
        if ($attendancePolicy->is_default) {
            throw ValidationException::withMessages(['policy' => 'No puedes desactivar la política por defecto.']);
        }
        $inUse = EmploymentAssignment::query()->where('attendance_policy_id', $attendancePolicy->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))->count();
        if ($inUse > 0) {
            throw ValidationException::withMessages(['policy' => "La política está asignada a {$inUse} colaboradores vigentes. Reasígnalos antes de desactivarla."]);
        }

        $attendancePolicy->delete();
        $this->audit->log('attendance_policy.deactivated', $attendancePolicy, "Política de asistencia desactivada: {$attendancePolicy->name}");
        $this->toast('Política desactivada.');

        return back();
    }

    public function restore(Request $request, AttendancePolicy $attendancePolicy): RedirectResponse
    {
        $this->ensureOwned($attendancePolicy);
        $attendancePolicy->restore();
        $this->audit->log('attendance_policy.restored', $attendancePolicy, "Política de asistencia reactivada: {$attendancePolicy->name}");
        $this->toast('Política reactivada.');

        return back();
    }

    private function faceProvider(): FaceVerificationProvider
    {
        return app(FaceVerificationProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location_mode' => ['required', Rule::enum(LocationMode::class)],
            'allowed_auth_methods' => ['required', 'array', 'min:1'],
            'allowed_auth_methods.*' => [Rule::enum(AuthMethod::class)],
            'require_strong_biometric' => ['required', 'boolean'],
            'allow_offline' => ['required', 'boolean'],
            'offline_max_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'face_verification_required' => ['required', 'boolean'],
            'web_punch_allowed' => ['required', 'boolean'],
            'max_clock_skew_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'min_location_accuracy_meters' => ['required', 'integer', 'min:5', 'max:1000'],
            'is_default' => ['required', 'boolean'],
            'auto_clock_out_at' => ['nullable', 'date_format:H:i'],
            'geofence_ids' => [Rule::requiredIf($request->input('location_mode') === LocationMode::Geofence->value), 'array'],
            'geofence_ids.*' => ['integer', $this->existsInOrg('geofences')],
        ], [
            'geofence_ids.required' => 'Selecciona al menos una geocerca para el modo «Dentro de geocerca».',
            'allowed_auth_methods.required' => 'Selecciona al menos un método de autenticación.',
        ], [
            'name' => 'nombre', 'location_mode' => 'modo de ubicación', 'allowed_auth_methods' => 'métodos permitidos',
            'offline_max_hours' => 'horas máximas sin conexión', 'max_clock_skew_seconds' => 'tolerancia de reloj',
            'min_location_accuracy_meters' => 'precisión mínima', 'auto_clock_out_at' => 'salida automática', 'geofence_ids' => 'geocercas',
        ]);

        if ($data['location_mode'] === LocationMode::Geofence->value && count($data['geofence_ids'] ?? []) === 0) {
            throw ValidationException::withMessages(['geofence_ids' => 'Selecciona al menos una geocerca para el modo «Dentro de geocerca».']);
        }
        $usesFace = $data['face_verification_required'] || in_array(AuthMethod::FaceVerification->value, $data['allowed_auth_methods'], true);
        if ($usesFace && ! $this->faceProvider()->available()) {
            throw ValidationException::withMessages(['face_verification_required' => 'La verificación facial requiere un proveedor configurado. Sin él, nadie podría marcar.']);
        }
        if ($data['face_verification_required'] && ! in_array(AuthMethod::FaceVerification->value, $data['allowed_auth_methods'], true)) {
            throw ValidationException::withMessages(['allowed_auth_methods' => 'Si exiges verificación facial, habilita el método «Verificación facial con proveedor».']);
        }

        $data['allowed_auth_methods'] = array_values(array_unique($data['allowed_auth_methods']));
        $data['geofence_ids'] = array_map('intval', $data['geofence_ids'] ?? []);

        return $data;
    }
}
