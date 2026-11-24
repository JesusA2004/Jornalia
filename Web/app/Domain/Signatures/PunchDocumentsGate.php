<?php

namespace App\Domain\Signatures;

use App\Enums\AlertSeverity;
use App\Models\ComplianceAlert;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Punch;
use Carbon\CarbonImmutable;

/**
 * Documentos de registro exigidos para marcar, validados en el SERVIDOR
 * (Revisión RH, pantalla 09 y regla general 6). La app ya bloquea el marcaje;
 * el servidor aplica la misma regla para que ninguna versión de la app, el
 * kiosco o la web la evite.
 *
 * Regla:
 *  - Convenio de registro electrónico vigente (`CONVENIO_REGISTRO`) firmado en
 *    la app o en papel adjunto al expediente, y aviso de privacidad vigente
 *    (`AVISO_PRIVACIDAD`) aceptado. Sólo cuentan las versiones publicadas.
 *  - Marcaje EN LÍNEA con documentos pendientes ⇒ se rechaza con `documents_pending`.
 *  - Marcaje SIN CONEXIÓN capturado antes de llegar al servidor ⇒ se REGISTRA
 *    (es evidencia de una jornada efectivamente trabajada y nunca se descarta) y
 *    se abre la alerta `punch_without_agreement` para que RH regularice.
 *  - Documento no publicado por la organización ⇒ no se exige (no se puede pedir
 *    lo que no existe) y se abre la alerta de configuración
 *    `registration_documents_unpublished`.
 *
 * Detalle: docs/arquitectura/RECTIFICACIONES_Y_FIRMA.md § «Documentos pendientes al marcar».
 */
final class PunchDocumentsGate
{
    public const RULE_PUNCH_WITHOUT_AGREEMENT = 'punch_without_agreement';

    public const RULE_DOCUMENTS_UNPUBLISHED = 'registration_documents_unpublished';

    private const LEGAL_BASIS = 'LFT art. 132 fr. XXXIV (registro electrónico acordado con el colaborador)';

    /** @var array<string, string> */
    private const LABELS = [
        RegistrationDocuments::AGREEMENT => 'convenio de registro electrónico',
        RegistrationDocuments::PRIVACY => 'aviso de privacidad',
    ];

    public function __construct(private readonly RegistrationDocuments $documents) {}

    /**
     * Documentos vigentes que el colaborador aún no firma (`pending`) y documentos
     * que la organización no ha publicado (`unpublished`).
     *
     * @return array{pending: list<string>, unpublished: list<string>}
     */
    public function check(Employee $employee): array
    {
        $pending = [];
        $unpublished = [];
        foreach (array_keys(RegistrationDocuments::PURPOSES) as $code) {
            $doc = $this->documents->current($code, $employee->organization_id);
            if ($doc === null) {
                $unpublished[] = $code;

                continue;
            }
            if ($this->documents->signatureFor($employee, $doc) !== null) {
                continue;
            }
            // El convenio firmado en papel y adjunto al expediente también cuenta.
            if ($code === RegistrationDocuments::AGREEMENT && $this->documents->hasPaperAgreement($employee)) {
                continue;
            }
            $pending[] = $code;
        }

        return ['pending' => $pending, 'unpublished' => $unpublished];
    }

    /**
     * Mensaje para el colaborador según lo que le falta.
     *
     * @param  list<string>  $pending
     */
    public static function message(array $pending): string
    {
        $agreement = in_array(RegistrationDocuments::AGREEMENT, $pending, true);
        $privacy = in_array(RegistrationDocuments::PRIVACY, $pending, true);

        $what = match (true) {
            $agreement && $privacy => 'firmar el convenio de registro electrónico y aceptar el aviso de privacidad vigentes',
            $privacy => 'aceptar el aviso de privacidad vigente',
            default => 'firmar el convenio de registro electrónico vigente',
        };

        return "Antes de marcar debes {$what}. Hazlo en la app o acude con RH.";
    }

    /**
     * Alertas después de registrar el marcaje: marcaje sin conexión con documentos
     * pendientes y documentos no publicados por la organización. Nunca altera el marcaje.
     *
     * @param  array{pending: list<string>, unpublished: list<string>}  $check
     */
    public function afterRecorded(Punch $punch, array $check): void
    {
        if ($check['pending'] !== []) {
            $this->punchWithoutAgreement($punch, $check['pending']);
        }
        if ($check['unpublished'] !== []) {
            $this->documentsUnpublished($punch->organization_id, $check['unpublished']);
        }
    }

    /**
     * Una alerta por colaborador y día; acumula los marcajes del día.
     *
     * @param  list<string>  $pending
     */
    private function punchWithoutAgreement(Punch $punch, array $pending): void
    {
        $alert = ComplianceAlert::query()->withoutGlobalScopes()
            ->where('employee_id', $punch->employee_id)
            ->whereDate('date', $punch->local_date)
            ->where('rule_code', self::RULE_PUNCH_WITHOUT_AGREEMENT)
            ->first();

        $data = (array) ($alert->data ?? []);
        $entries = array_values(array_filter((array) ($data['punches'] ?? []), fn ($e) => ($e['punch_uuid'] ?? null) !== $punch->uuid));
        $entries[] = [
            'punch_uuid' => $punch->uuid,
            'type' => $punch->type->value,
            'source' => $punch->source->value,
            'device_time' => $punch->device_time?->utc()->format('Y-m-d\TH:i:s\Z'),
            'received_at' => CarbonImmutable::instance($punch->received_at ?? $punch->server_time)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        $documents = array_values(array_unique([...(array) ($data['documents'] ?? []), ...$pending]));

        $attributes = [
            'severity' => AlertSeverity::Warning,
            'message' => 'Marcaje registrado sin '.implode(' ni ', array_map(fn ($c) => self::LABELS[$c] ?? $c, $documents)).' vigente: regularizar con el colaborador.',
            'legal_basis' => self::LEGAL_BASIS,
            'data' => ['documents' => $documents, 'punches' => $entries],
        ];

        if ($alert === null) {
            ComplianceAlert::query()->create([
                'organization_id' => $punch->organization_id,
                'employee_id' => $punch->employee_id,
                'date' => $punch->local_date->toDateString(),
                'rule_code' => self::RULE_PUNCH_WITHOUT_AGREEMENT,
                'status' => 'open',
                ...$attributes,
            ]);

            return;
        }

        $alert->fill($attributes);
        if ($alert->status === 'resolved') {
            $alert->fill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null]);
        }
        $alert->save();
    }

    /**
     * Alerta de configuración de la organización (sin colaborador): una sola abierta
     * hasta que RH la resuelva o publique los documentos.
     *
     * @param  list<string>  $unpublished
     */
    private function documentsUnpublished(int $organizationId, array $unpublished): void
    {
        $alert = ComplianceAlert::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('employee_id')
            ->where('rule_code', self::RULE_DOCUMENTS_UNPUBLISHED)
            ->where('status', '!=', 'resolved')
            ->first();

        $documents = array_values(array_unique($unpublished));
        if ($alert !== null && (array) (((array) $alert->data)['documents'] ?? []) === $documents) {
            return;
        }

        $attributes = [
            'severity' => AlertSeverity::Warning,
            'message' => 'Sin publicar: '.implode(' y ', array_map(fn ($c) => self::LABELS[$c] ?? $c, $documents)).'. Los marcajes no pueden exigirlo hasta que RH lo publique.',
            'legal_basis' => self::LEGAL_BASIS,
            'data' => ['documents' => $documents],
        ];

        if ($alert !== null) {
            $alert->fill($attributes)->save();

            return;
        }

        $organization = Organization::query()->find($organizationId);
        ComplianceAlert::query()->create([
            'organization_id' => $organizationId,
            'employee_id' => null,
            'date' => CarbonImmutable::now($organization?->timezone ?: 'America/Mexico_City')->toDateString(),
            'rule_code' => self::RULE_DOCUMENTS_UNPUBLISHED,
            'status' => 'open',
            ...$attributes,
        ]);
    }
}
