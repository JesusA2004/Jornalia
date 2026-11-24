<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Integrations\People\PeopleStructureSource;
use App\Domain\Location\LocationRetention;
use App\Domain\Location\LocationTracking;
use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\NotificationSettings;
use App\Domain\Rectifications\RectificationSettings;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración › Ubicación (reglas de uso por puesto, contingencia y
 * retención de recorridos) y Configuración › Sistema (políticas de
 * conservación, notificaciones e integraciones). Lectura con `settings.view`;
 * cambios con `settings.manage`, siempre con bitácora (antes/después).
 */
class PlatformSettingsController extends AdminController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LocationTracking $tracking,
    ) {}

    public function location(Request $request, LocationRetention $retention): Response
    {
        $positions = Position::query()->orderBy('name')->get(['id', 'name', 'code', 'location_tracking', 'active'])
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'active' => (bool) $p->active,
                'location_tracking' => $p->location_tracking === LocationTracking::MODE_DURING_SHIFT ? LocationTracking::MODE_DURING_SHIFT : LocationTracking::MODE_ON_PUNCH,
            ])->values();

        return Inertia::render('configuracion/Location', [
            'positions' => $positions,
            'settings' => $this->tracking->settings(),
            'modes' => collect(LocationTracking::MODES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'pending_purge' => $retention->pending(),
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function updateLocation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'retention_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'contingency_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'retention_confirmed' => ['required', 'boolean'],
        ], [], ['retention_days' => 'conservación de recorridos', 'contingency_minutes' => 'margen tras el fin de turno']);

        $before = $this->tracking->settings();
        $after = ['retention_days' => (int) $data['retention_days'], 'contingency_minutes' => (int) $data['contingency_minutes'], 'retention_confirmed' => (bool) $data['retention_confirmed']];
        $this->tracking->save($after, $request->user()?->id);
        $this->audit->log('location.settings_updated', null, 'Reglas de ubicación actualizadas', [], null, $before, $after);
        $this->toast('Reglas de ubicación guardadas.');

        return back();
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        $this->ensureOwned($position);
        $data = $request->validate([
            'location_tracking' => ['required', Rule::in(array_keys(LocationTracking::MODES))],
        ]);

        $before = $position->location_tracking;
        if ($before !== $data['location_tracking']) {
            $position->forceFill(['location_tracking' => $data['location_tracking']])->save();
            $this->audit->log('location.position_mode', $position, "Ubicación del puesto {$position->name}: ".LocationTracking::MODES[$data['location_tracking']], [], null,
                ['location_tracking' => $before], ['location_tracking' => $data['location_tracking']]);
        }
        $this->toast('Puesto actualizado.');

        return back();
    }

    public function system(Request $request, FcmClient $fcm, NotificationSettings $notifications, RectificationSettings $rectifications, PeopleStructureSource $people): Response
    {
        $legal = json_decode((string) file_get_contents(database_path('data/legal/parametros_legales.json')), true);
        $retention = (array) ($legal['retention'] ?? []);

        $devices = Device::query()->where('status', DeviceStatus::Approved);

        return Inertia::render('configuracion/System', [
            'retention' => [
                'attendance_years' => (int) ($retention['attendance_records_years'] ?? 5),
                'attendance_basis' => (string) ($retention['basis'] ?? ''),
                'location' => $this->tracking->settings(),
            ],
            'rectifications' => $rectifications->all(),
            'notifications' => [
                'push_configured' => $fcm->enabled(),
                'devices_with_token' => (clone $devices)->where('push_provider', 'fcm')->whereNotNull('push_token')->whereNull('push_token_invalidated_at')->count(),
                'invalid_tokens' => (clone $devices)->whereNotNull('push_token_invalidated_at')->count(),
                'reminders' => $notifications->all(),
                'punch_receipt_mandatory' => true,
            ],
            'integrations' => [
                'people_configured' => $people->isConfigured(),
                'fcm_configured' => $fcm->enabled(),
            ],
            'can' => [
                'manage' => $request->user()?->can('settings.manage') ?? false,
                'integrations' => $request->user()?->can('integrations.view') ?? false,
            ],
        ]);
    }
}
