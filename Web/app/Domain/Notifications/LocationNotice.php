<?php

namespace App\Domain\Notifications;

use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Aviso de ubicación (`UBICACION`): documento de política publicado que el
 * colaborador acusa en la app (POST /acknowledgements/{document}). Idempotente:
 * si ya existe cualquier versión del código, no crea otra.
 */
final class LocationNotice
{
    public const CODE = 'UBICACION';

    public const VERSION = '1.0';

    public const TITLE = 'Aviso de ubicación';

    public const BODY = 'Durante tu jornada la app registra tu ubicación para el control de asistencia. Fuera de tu turno no se registra.';

    public static function ensureFor(Organization $organization): PolicyDocument
    {
        return app(CurrentOrganization::class)->runAs($organization, function () use ($organization) {
            $existing = PolicyDocument::query()->withTrashed()
                ->where('organization_id', $organization->id)
                ->where('code', self::CODE)
                ->orderByDesc('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return PolicyDocument::query()->create([
                'organization_id' => $organization->id,
                'code' => self::CODE,
                'title' => self::TITLE,
                'version' => self::VERSION,
                'body_markdown' => self::BODY,
                'sha256' => hash('sha256', self::BODY),
                'requires_acknowledgement' => true,
                'published_at' => now(),
            ]);
        });
    }
}
