<?php

namespace App\Domain\Audit;

use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bitácora de auditoría append-only (trigger + trait Immutable). Cada registro
 * guarda usuario, fecha y hora (UTC), acción, valor anterior y nuevo, IP,
 * agente y, si la acción vino de la app, el dispositivo (PDF regla 5).
 *
 * Convención: el contexto puede traer `before`/`after` (o `changed`); se
 * copian a `old_values` / `new_values` para consultarlos de forma uniforme.
 * No registra secretos ni datos sensibles: se redactan por nombre de clave.
 */
final class AuditLogger
{
    private const REDACTED_KEYS = ['password', 'secret', 'token', 'curp', 'rfc', 'nss', 'device_secret', 'signature', 'pin', 'signature_pin_hash'];

    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $before  valor anterior (si no viene en el contexto)
     * @param  array<string, mixed>|null  $after  valor nuevo (si no viene en el contexto)
     */
    public function log(string $action, ?Model $subject, string $description, array $context = [], ?int $organizationId = null, ?array $before = null, ?array $after = null): AuditLog
    {
        $request = request();
        $before ??= isset($context['before']) && is_array($context['before']) ? $context['before'] : null;
        $after ??= isset($context['after']) && is_array($context['after']) ? $context['after']
            : (isset($context['changed']) && is_array($context['changed']) ? $context['changed'] : null);

        return AuditLog::query()->create([
            'organization_id' => $organizationId ?? $this->current->id() ?? $subject?->getAttribute('organization_id'),
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => Str::limit($description, 250, ''),
            'context' => $this->redact($context) ?: null,
            'old_values' => $before !== null ? $this->redact($before) : null,
            'new_values' => $after !== null ? $this->redact($after) : null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'device_id' => $this->deviceId(),
            'created_at' => now(),
        ]);
    }

    /**
     * Dispositivo de la app ligado al token de la sesión API (null en la web).
     */
    private function deviceId(): ?int
    {
        $request = request();
        if ($request->user() === null) {
            return null;
        }
        $uuid = ApiContext::tokenDeviceUuid($request);
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = Device::query()->withoutGlobalScopes()->where('user_id', $request->user()->getAuthIdentifier())->where('device_uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $context[$key] = '[redactado]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }
}
