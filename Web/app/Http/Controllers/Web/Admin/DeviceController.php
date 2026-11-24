<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\AccessScope;
use App\Domain\Security\DeviceService;
use App\Enums\DeviceStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends AdminController
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly DeviceService $devices,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Device::class);
        $user = $this->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,revoked'],
            'search' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'in:android,ios'],
            'corporate' => ['nullable', 'in:yes,no'],
            'per_page' => ['nullable', 'integer', 'in:15,25,50,100'],
        ]);

        $base = $this->scoped($user);
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $devices = (clone $base)
            ->with(['employee:id,first_name,last_name,second_last_name,employee_number', 'user:id,name,email', 'approvedBy:id,name', 'revokedBy:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['platform'] ?? null, fn (Builder $q, string $p) => $q->where('platform', $p))
            ->when(($filters['corporate'] ?? null) !== null, fn (Builder $q) => $q->where('is_corporate', $filters['corporate'] === 'yes'))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('devices.name', 'like', $like)->orWhere('devices.model', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhereHas('employee', fn ($e) => $e->where('employee_number', 'like', $like)->orWhereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like])));
            })
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'revoked')")
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        // Cambio de teléfono: teléfono vigente que se revocará al autorizar, y reemplazos de los revocados.
        $page = collect($devices->items());
        $previous = Device::query()
            ->whereIn('user_id', $page->where('pending_reason', 'device_change')->pluck('user_id')->unique()->all())
            ->where('status', DeviceStatus::Approved)
            ->orderByDesc('approved_at')
            ->get(['id', 'user_id', 'name', 'model', 'last_seen_at'])
            ->unique('user_id')->keyBy('user_id');
        $replacements = Device::query()
            ->whereIn('id', $page->pluck('replaced_by_device_id')->filter()->unique()->all())
            ->get(['id', 'name', 'model'])->keyBy('id');
        $brief = fn (?Device $x) => $x ? ['id' => $x->id, 'name' => $x->name, 'model' => $x->model, 'last_seen_at' => $x->last_seen_at?->toIso8601String()] : null;

        $devices = $devices
            ->through(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'platform' => $d->platform,
                'os_version' => $d->os_version,
                'app_version' => $d->app_version,
                'model' => $d->model,
                'biometric_available' => $d->biometric_available,
                'integrity_flags' => array_values((array) ($d->integrity_flags ?? [])),
                'integrity_reported_at' => $d->integrity_reported_at?->toIso8601String(),
                'is_corporate' => $d->is_corporate,
                'status' => $d->status->value,
                'created_at' => $d->created_at?->toIso8601String(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'approved_at' => $d->approved_at?->toIso8601String(),
                'approved_by' => $d->approvedBy?->name,
                'revoked_at' => $d->revoked_at?->toIso8601String(),
                'revoked_by' => $d->revokedBy?->name,
                'revoke_reason' => $d->revoke_reason,
                'pending_reason' => $d->status === DeviceStatus::Pending ? $d->pending_reason : null,
                'previous_device' => $d->status === DeviceStatus::Pending && $d->pending_reason === 'device_change'
                    ? $brief($previous->get($d->user_id)?->id !== $d->id ? $previous->get($d->user_id) : null) : null,
                'replaced_by' => $d->replaced_by_device_id ? $brief($replacements->get($d->replaced_by_device_id)) : null,
                'employee' => $d->employee ? ['id' => $d->employee->id, 'name' => $d->employee->fullName(), 'employee_number' => $d->employee->employee_number] : null,
                'user' => $d->user ? ['name' => $d->user->name, 'email' => $d->user->email] : null,
            ]);

        return Inertia::render('dispositivos/Index', [
            'devices' => $devices,
            'filters' => Arr::only($filters, ['status', 'search', 'platform', 'corporate']),
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'revoked' => (int) ($counts['revoked'] ?? 0),
            ],
            'can' => [
                'approve' => $user->can('devices.approve'),
                'revoke' => $user->can('devices.revoke'),
                'kiosk' => $user->can('devices.kiosk'),
            ],
            'kiosks' => $this->kiosks($user),
            'branches' => $user->can('devices.kiosk') ? $this->kioskBranches($user) : [],
            'kiosk_activation' => $request->session()->get('kiosk_activation'),
        ]);
    }

    public function approve(Request $request, Device $device): RedirectResponse
    {
        $this->authorize('approve', $device);
        if ($device->status === DeviceStatus::Revoked) {
            $this->toast('Un dispositivo revocado debe volver a vincularse desde la app antes de autorizarse.', 'warning');

            return back();
        }
        $this->devices->approve($this->actor($request), $device);
        $this->toast("Dispositivo «{$device->name}» autorizado.");

        return back();
    }

    public function revoke(Request $request, Device $device): RedirectResponse
    {
        $this->authorize('revoke', $device);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);
        $this->devices->revoke($this->actor($request), $device, $data['reason']);
        $this->toast("Dispositivo «{$device->name}» revocado. Sus sesiones de la app se cerraron.");

        return back();
    }

    public function corporate(Request $request, Device $device): RedirectResponse
    {
        $this->authorize('update', $device);
        $data = $request->validate(['is_corporate' => ['required', 'boolean']]);
        $device->forceFill(['is_corporate' => (bool) $data['is_corporate']])->save();
        $this->audit->log('device.corporate_changed', $device, ($device->is_corporate ? 'Marcado como corporativo: ' : 'Marcado como personal: ').$device->name);
        $this->toast($device->is_corporate ? 'Marcado como dispositivo corporativo.' : 'Marcado como dispositivo personal.');

        return back();
    }

    /**
     * Kioscos de sucursal visibles según el alcance (todas o sus sucursales).
     *
     * @return list<array<string, mixed>>
     */
    private function kiosks(User $user): array
    {
        $query = Kiosk::query()->with('branch:id,name,code')->orderByRaw("FIELD(status, 'pending', 'active', 'revoked')")->orderBy('name');
        if (! $this->scope->hasFullAccess($user)) {
            $query->whereIn('branch_id', $user->branches()->pluck('branches.id')->all() ?: [0]);
        }

        return $query->limit(500)->get()->map(fn (Kiosk $k) => [
            'id' => $k->id,
            'name' => $k->name,
            'status' => $k->status,
            'status_label' => $k->statusLabel(),
            'branch' => $k->branch?->name,
            'model' => $k->model,
            'platform' => $k->platform,
            'os_version' => $k->os_version,
            'app_version' => $k->app_version,
            'activated_at' => $k->activated_at?->toIso8601String(),
            'last_seen_at' => $k->last_seen_at?->toIso8601String(),
            'activation_expires_at' => $k->activation_expires_at?->toIso8601String(),
            'revoked_at' => $k->revoked_at?->toIso8601String(),
            'revoke_reason' => $k->revoke_reason,
        ])->values()->all();
    }

    /**
     * @return list<array{value:string, label:string, description?:string}>
     */
    private function kioskBranches(User $user): array
    {
        $query = Branch::query()->where('active', true)->orderBy('name');
        if (! $this->scope->hasFullAccess($user)) {
            $query->whereIn('id', $user->branches()->pluck('branches.id')->all() ?: [0]);
        }

        return $this->options($query->get(['id', 'name', 'code']));
    }

    /**
     * @return Builder<Device>
     */
    private function scoped(User $user): Builder
    {
        $query = Device::query();
        if ($this->scope->hasFullAccess($user)) {
            return $query;
        }

        return $query->whereIn('devices.employee_id', $this->scope->employees($user)->select('employees.id'));
    }
}
