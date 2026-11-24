<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cumplimiento › Bitácora de cambios: bitácora inmutable de la organización
 * activa (sólo lectura). Usuario, fecha y hora, acción, valor anterior y
 * nuevo, dispositivo o IP.
 */
final class AuditController extends Controller
{
    public const MODULES = [
        'punch' => 'Marcajes',
        'rectification' => 'Rectificaciones',
        'incidence' => 'Incidencias',
        'vacation' => 'Vacaciones',
        'overtime' => 'Horas extra',
        'compliance' => 'Cumplimiento',
        'integrity' => 'Integridad',
        'report' => 'Reportes',
        'device' => 'Dispositivos',
        'employee' => 'Colaboradores',
        'user' => 'Usuarios',
        'auth' => 'Acceso',
        'settings' => 'Configuración',
        'organization' => 'Empresa',
        'policy_document' => 'Documentos',
        'announcement' => 'Comunicados',
        'location' => 'Ubicación',
        'schedule' => 'Horarios',
        'signature' => 'Firmas',
    ];

    public function __invoke(ListFiltersRequest $request, CurrentOrganization $current): Response
    {
        $orgId = (int) $current->id();
        $filters = $request->filters();
        $module = array_key_exists((string) $request->query('module'), self::MODULES) ? (string) $request->query('module') : null;
        $userId = (int) $request->query('user_id', 0) ?: null;
        $tz = (string) ($current->get()->timezone ?? 'UTC');

        $query = AuditLog::query()->with(['user:id,name,email'])
            ->where('organization_id', $orgId)
            ->when($module, fn ($q) => $q->where('action', 'like', $module.'.%'))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when(isset($filters['from']), fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($filters['from'], $tz)->startOfDay()->utc()))
            ->when(isset($filters['to']), fn ($q) => $q->where('created_at', '<', CarbonImmutable::parse($filters['to'], $tz)->addDay()->startOfDay()->utc()))
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%';
                $q->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('action', 'like', $like)->orWhere('ip_address', 'like', $like));
            });

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage(25))->withQueryString();
        $devices = Device::query()->withoutGlobalScopes()->where('organization_id', $orgId)
            ->whereIn('id', $page->getCollection()->pluck('device_id')->filter()->unique()->values())
            ->get(['id', 'name', 'platform'])->keyBy('id');
        $page->through(fn (AuditLog $l) => [
            'device' => $l->device_id && isset($devices[$l->device_id]) ? $devices[$l->device_id]->name.' ('.$devices[$l->device_id]->platform.')' : null,
            'id' => $l->id,
            'action' => $l->action,
            'module' => strtok($l->action, '.'),
            'module_label' => self::MODULES[strtok($l->action, '.')] ?? ucfirst((string) strtok($l->action, '.')),
            'description' => $l->description,
            'subject_type' => $l->subject_type,
            'subject_id' => $l->subject_id,
            'context' => $l->context,
            'old_values' => $l->old_values,
            'new_values' => $l->new_values,
            'device_id' => $l->device_id,
            'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email] : null,
            'ip_address' => $l->ip_address,
            'user_agent' => $l->user_agent,
            'created_at' => OperacionScope::iso($l->created_at),
        ]);

        $users = User::query()->whereIn('id', AuditLog::query()->where('organization_id', $orgId)->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('name')->limit(300)->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name, 'description' => $u->email])->values();

        return Inertia::render('cumplimiento/Bitacora', [
            'logs' => $page,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'module' => $module,
                'user_id' => $userId ? (string) $userId : null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'modules' => collect(self::MODULES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'users' => $users,
            ],
            'timezone' => (string) ($current->get()->timezone ?? 'UTC'),
        ]);
    }
}
