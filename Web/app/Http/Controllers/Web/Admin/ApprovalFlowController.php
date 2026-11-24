<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Audit\AuditLogger;
use App\Domain\Security\PermissionCatalog;
use App\Models\ApprovalFlow;
use App\Models\OrganizationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flujos de autorización multinivel por tipo de solicitud.
 */
class ApprovalFlowController extends AdminController
{
    /** organization_settings.key de la política de horas extraordinarias. */
    public const OVERTIME_SETTING = 'overtime_policy';

    private const SUBJECTS = [
        'rectification' => ['label' => 'Rectificaciones de marcaje', 'description' => 'Correcciones solicitadas por colaboradores o responsables.'],
        'incidence' => ['label' => 'Incidencias', 'description' => 'Permisos, faltas justificadas e incapacidades.'],
        'vacation' => ['label' => 'Vacaciones', 'description' => 'Solicitudes de disfrute de vacaciones.'],
        'overtime' => ['label' => 'Horas extraordinarias', 'description' => 'Autorización de tiempo extraordinario.'],
    ];

    public function __construct(
        private readonly ApprovalWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $configured = ApprovalFlow::query()->pluck('subject')->all();
        $flows = [];
        foreach (self::SUBJECTS as $subject => $meta) {
            $flows[] = $meta + [
                'subject' => $subject,
                'levels' => $this->workflow->levels($subject),
                'configured' => in_array($subject, $configured, true),
                'default_permission' => ApprovalWorkflow::SUBJECT_PERMISSIONS[$subject],
            ];
        }

        return Inertia::render('configuracion/Approvals', [
            'flows' => $flows,
            'overtime' => $this->overtimePolicy(),
            'permissions' => $this->permissionOptions(),
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function update(Request $request, string $subject): RedirectResponse
    {
        $data = $request->validate([
            'levels' => ['required', 'array', 'min:1', 'max:5'],
            'levels.*.permission' => ['required', 'string', Rule::in(PermissionCatalog::all())],
            'levels.*.label' => ['required', 'string', 'max:60'],
        ], ['levels.required' => 'Define al menos un nivel de autorización.', 'levels.max' => 'El máximo es de 5 niveles.'], [
            'levels.*.permission' => 'permiso', 'levels.*.label' => 'etiqueta',
        ]);

        // Los niveles se renumeran 1..n en el orden recibido.
        $levels = [];
        foreach (array_values((array) $data['levels']) as $i => $l) {
            $levels[] = ['level' => $i + 1, 'permission' => (string) $l['permission'], 'label' => trim((string) $l['label'])];
        }
        $before = $this->workflow->levels($subject);

        $flow = ApprovalFlow::query()->updateOrCreate(['subject' => $subject], ['levels' => $levels]);

        $this->audit->log('approval_flow.updated', $flow, 'Flujo de autorización actualizado: '.self::SUBJECTS[$subject]['label'], ['before' => $before, 'after' => $levels]);
        $this->toast('Flujo de autorización guardado. Las solicitudes en curso continúan en su nivel actual.');

        return back();
    }

    /**
     * Política de horas extraordinarias (complementa el flujo `overtime`).
     */
    public function updateOvertime(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'requires_prior_authorization' => ['required', 'boolean'],
            'min_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'allow_employee_request' => ['required', 'boolean'],
        ], [], ['min_minutes' => 'mínimo a considerar', 'requires_prior_authorization' => 'autorización previa']);

        $setting = OrganizationSetting::query()->firstOrNew(['key' => self::OVERTIME_SETTING]);
        $before = $setting->value;
        $setting->forceFill(['value' => [
            'requires_prior_authorization' => (bool) $data['requires_prior_authorization'],
            'min_minutes' => (int) $data['min_minutes'],
            'allow_employee_request' => (bool) $data['allow_employee_request'],
        ], 'updated_by' => $request->user()?->id])->save();

        $this->audit->log('overtime_policy.updated', $setting, 'Política de horas extraordinarias actualizada', ['before' => $before, 'after' => $setting->value]);
        $this->toast('Política de horas extra guardada.');

        return back();
    }

    /**
     * @return array{requires_prior_authorization:bool, min_minutes:int, allow_employee_request:bool}
     */
    private function overtimePolicy(): array
    {
        $value = (array) (OrganizationSetting::query()->where('key', self::OVERTIME_SETTING)->first()->value ?? []);

        return [
            'requires_prior_authorization' => (bool) ($value['requires_prior_authorization'] ?? false),
            'min_minutes' => (int) ($value['min_minutes'] ?? 0),
            'allow_employee_request' => (bool) ($value['allow_employee_request'] ?? false),
        ];
    }

    /**
     * @return list<array{value:string, label:string, description:string}>
     */
    private function permissionOptions(): array
    {
        $out = [];
        foreach (PermissionCatalog::MODULES as $module => $def) {
            foreach ($def['actions'] as $action => $label) {
                if (in_array($action, ['approve', 'resolve', 'manage', 'adjust'], true)) {
                    $out[] = ['value' => "{$module}.{$action}", 'label' => "{$def['label']}: {$label}", 'description' => "{$module}.{$action}"];
                }
            }
        }

        return $out;
    }
}
