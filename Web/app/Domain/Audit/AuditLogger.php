<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bitácora de auditoría append-only. No registra secretos ni datos sensibles:
 * el contexto debe contener sólo identificadores y valores no confidenciales.
 */
final class AuditLogger
{
    private const REDACTED_KEYS = ['password', 'secret', 'token', 'curp', 'rfc', 'nss', 'device_secret', 'signature'];

    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $action, ?Model $subject, string $description, array $context = [], ?int $organizationId = null): AuditLog
    {
        $request = request();

        return AuditLog::query()->create([
            'organization_id' => $organizationId ?? $this->current->id() ?? $subject?->getAttribute('organization_id'),
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => Str::limit($description, 250, ''),
            'context' => $this->redact($context) ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'created_at' => now(),
        ]);
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
