<?php

namespace Database\Seeders\Testing\E2E;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\DeviceSignature;
use App\Domain\Audit\AuditLogger;
use App\Domain\Security\DeviceService;
use App\Domain\Security\KioskService;
use App\Domain\Signatures\ElectronicSignatureService;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Signatures\SignaturePinService;
use App\Domain\Workforce\EmployeeAccountService;
use App\Domain\Workforce\WorkforceService;
use App\Models\Attachment;
use App\Models\Device;
use App\Models\Employee;
use App\Models\PolicyDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Colaboradores FICTICIOS: altas con relación laboral y asignación, cuentas de
 * la app, NIP de firma, teléfonos (autorizados, pendientes, reemplazados y
 * revocados), kioscos, convenio y aviso de privacidad publicados con sus firmas,
 * convenio en papel y una baja reciente. Todo por los servicios de dominio.
 */
final class PeopleStage
{
    private const MODELS = ['Samsung Galaxy A54', 'Motorola moto g84', 'Xiaomi Redmi Note 13', 'iPhone 13', 'Samsung Galaxy A34', 'iPhone 14'];

    public function __construct(
        private readonly WorkforceService $workforce,
        private readonly EmployeeAccountService $accounts,
        private readonly SignaturePinService $pins,
        private readonly DeviceService $deviceService,
        private readonly KioskService $kiosks,
        private readonly RegistrationDocuments $documents,
        private readonly AuditLogger $audit,
    ) {}

    public function schedule(Context $ctx, string $password): void
    {
        $golive = $ctx->start->subDays(7);

        foreach (Roster::all() as $index => $person) {
            $hired = $this->date((string) $person['hired'], $ctx);
            $hireAt = $hired->gt($golive) ? $ctx->at($hired, '08:15') : $ctx->at($golive, '09:30')->addMinutes($index);
            $ctx->schedule($hireAt, "Alta {$index}", fn () => $this->hire($ctx, $index, $person, $hired), 5);

            if ($person['app'] === 'none') {
                continue;
            }
            $setupAt = $hireAt->addMinutes(40 + $index);
            $ctx->schedule($setupAt, "Cuenta {$index}", fn () => $this->account($ctx, $index, $person, $password), 6);
            if (in_array($person['app'], ['app', 'pending'], true)) {
                $ctx->schedule($setupAt->addMinutes(20), "Teléfono {$index}", fn () => $this->phone($ctx, $index, $person['app'] === 'app'), 7);
            }
        }

        // Documentos del registro electrónico (RH) y firmas en la app.
        $publishAt = $ctx->at($golive->addDay(), '10:00');
        $ctx->schedule($publishAt, 'Publicar convenio y aviso', fn () => $this->publishDocuments($ctx), 8);
        foreach (Roster::all() as $index => $person) {
            if ($person['app'] === 'none') {
                continue;
            }
            $hired = $this->date((string) $person['hired'], $ctx);
            $signAt = $hired->gt($golive) ? $ctx->at($hired, '12:30') : $ctx->at($golive->addDay(), '12:00')->addMinutes($index * 7);
            $ctx->schedule($signAt, "Firmas de documentos {$index}", fn () => $this->signDocuments($ctx, $index, $person), 9);
        }
        $ctx->schedule($ctx->at($golive->addDay(), '16:00'), 'Convenio en papel', fn () => $this->paperAgreement($ctx, Roster::PAPER_AGREEMENT), 9);

        // Kioscos (Sistemas): activo en Jiutepec, revocado en Cuernavaca, pendiente en Cuautla.
        $ctx->schedule($ctx->at($golive, '11:00'), 'Kiosco Jiutepec', fn () => $this->kiosk($ctx, 'JIU', 'Kiosco Jiutepec · recepción', true), 8);
        $ctx->schedule($ctx->at($golive, '11:20'), 'Kiosco Cuernavaca', fn () => $this->kiosk($ctx, 'CUE', 'Kiosco Cuernavaca · caja 2', true), 8);
        $ctx->schedule($ctx->at($ctx->workday(10), '17:30'), 'Revocar kiosco Cuernavaca', function () use ($ctx) {
            $ctx->actAs($ctx->user('plataforma'));
            $this->kiosks->revoke($ctx->user('plataforma'), $ctx->kiosks['CUE']->refresh(), 'Tableta extraviada; se dará de alta una nueva.');
        });
        $ctx->schedule($ctx->at($ctx->workday(1), '17:45'), 'Kiosco Cuautla (pendiente)', fn () => $this->kiosk($ctx, 'CUA', 'Kiosco Cuautla · acceso principal', false), 8);

        // Cambio de teléfono autorizado (el anterior queda revocado y reemplazado).
        $ctx->schedule($ctx->at($ctx->workday(10), '08:20'), 'Cambio de teléfono autorizado', function () use ($ctx) {
            $this->replacePhone($ctx, Roster::DEVICE_REPLACED, true);
        }, 8);
        // Cambio de teléfono pendiente de autorizar (el actual sigue vigente).
        $ctx->schedule($ctx->at($ctx->workday(1), '21:05'), 'Cambio de teléfono pendiente', function () use ($ctx) {
            $this->replacePhone($ctx, Roster::DEVICE_CHANGE_PENDING, false);
        }, 8);
        // Intento de usar el teléfono de otra persona: bloqueado, alerta y bitácora.
        $ctx->schedule($ctx->at($ctx->workday(2), '09:40'), 'Teléfono de otra persona', function () use ($ctx) {
            $this->borrowedPhone($ctx, Roster::NO_AGREEMENT, Roster::DEVICE_REPLACED);
        }, 8);

        // Baja reciente (revoca app y teléfono el mismo día).
        $endsOn = $ctx->workday(4);
        $ctx->fixtures['terminated_on'] = $endsOn->toDateString();
        $ctx->schedule($ctx->at($endsOn, '18:40'), 'Baja reciente', function () use ($ctx, $endsOn) {
            $ctx->actAs($ctx->user('rh'));
            $this->workforce->terminate($ctx->user('rh'), $ctx->employee(Roster::TERMINATED)->refresh(), $endsOn->toDateString(), 'Renuncia voluntaria');
        }, 90);
    }

    public function date(string $value, Context $ctx): CarbonImmutable
    {
        if (str_starts_with($value, 'rel:')) {
            return $ctx->today->addDays((int) substr($value, 4));
        }
        if (str_starts_with($value, 'rel-years:')) {
            return $ctx->today->addYears((int) substr($value, 10))->subMonths(5);
        }

        return CarbonImmutable::parse($value, Context::TZ);
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function hire(Context $ctx, int $index, array $person, CarbonImmutable $hired): void
    {
        $ctx->actAs($ctx->user('rh'));
        $branch = $ctx->branches[$person['branch']];
        $employee = $this->workforce->hire($ctx->user('rh'), [
            'employee_number' => sprintf('E2E-%03d', $index),
            'first_name' => $person['first'],
            'last_name' => $person['last'],
            'second_last_name' => $person['second'],
            'email' => $person['app'] === 'none' ? null : Roster::email($person, $index),
            'phone' => null,
            'birth_date' => $this->date((string) $person['birth'], $ctx)->toDateString(),
        ], [
            // La normativa versionada inicia en 2023: la relación vigente arranca ahí y la
            // antigüedad reconocida conserva la fecha original de ingreso.
            'starts_on' => $hired->max(CarbonImmutable::parse('2023-01-09', Context::TZ))->toDateString(),
            'seniority_starts_on' => $hired->toDateString(),
            'contract_type' => $index === Roster::MINOR ? 'training' : 'indefinite',
            'work_modality' => $person['position'] === 'COB' ? 'field' : 'onsite',
            'legal_entity_id' => $ctx->legalEntityId ?: null,
        ], [
            'work_center_id' => $branch->work_center_id,
            'branch_id' => $branch->id,
            'department_id' => $ctx->departments[$person['dept']],
            'position_id' => $ctx->positions[$person['position']],
            'schedule_id' => $ctx->schedules[$person['schedule']]->id,
            'attendance_policy_id' => $person['branch'] === 'VER' ? $ctx->strictPolicyId : null,
        ]);
        $ctx->employees[$index] = $employee;
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function account(Context $ctx, int $index, array $person, string $password): void
    {
        $ctx->actAs($ctx->user('rh'));
        $employee = $ctx->employee($index)->refresh();
        $result = $this->accounts->createFor($ctx->user('rh'), $employee, Roster::email($person, $index), 'password', 'Colaborador');
        $user = $result['user'];
        // Contraseña común del JSON (la temporal aleatoria sólo se mostraría una vez).
        $user->forceFill(['password' => $password])->save();
        $ctx->employeeUsers[$index] = $user;
        $ctx->employees[$index] = $employee->refresh();

        // El colaborador define su NIP de firma en la app.
        $ctx->actAs($user);
        $this->pins->set($user, $ctx->pin);
    }

    private function phone(Context $ctx, int $index, bool $approve): void
    {
        $user = $ctx->employeeUser($index);
        $ctx->actAs($user);
        $registered = $this->deviceService->register($user, $ctx->organization->id, $this->deviceInfo($index, 'a'));
        /** @var Device $device */
        $device = $registered['device'];
        if ($approve) {
            $ctx->actAs($ctx->user('rh'));
            $device = $this->deviceService->approve($ctx->user('rh'), $device);
        }
        $ctx->devices[$index] = ['device' => $device->refresh(), 'secret' => (string) $registered['secret']];
    }

    private function replacePhone(Context $ctx, int $index, bool $approve): void
    {
        $user = $ctx->employeeUser($index);
        $ctx->actAs($user);
        $registered = $this->deviceService->register($user, $ctx->organization->id, $this->deviceInfo($index, 'b'));
        $device = $registered['device'];
        if ($approve) {
            $ctx->actAs($ctx->user('rh'));
            $device = $this->deviceService->approve($ctx->user('rh'), $device);
            $ctx->devices[$index] = ['device' => $device->refresh(), 'secret' => (string) $registered['secret']];
        }
        $ctx->fixtures['device_'.($approve ? 'replacement' : 'change_pending')] = $device->id;
    }

    private function borrowedPhone(Context $ctx, int $index, int $owner): void
    {
        $user = $ctx->employeeUser($index);
        $ctx->actAs($user);
        $info = $this->deviceInfo($owner, 'b');
        $info['device_uuid'] = (string) Str::uuid();
        try {
            $this->deviceService->register($user, $ctx->organization->id, $info);
            $ctx->warn('El intento con un teléfono ajeno no fue bloqueado.');
        } catch (AttendanceException $e) {
            $ctx->count('telefono_ajeno_bloqueado');
        }
    }

    /**
     * @return array{device_uuid:string, hardware_id:string, name:string, platform:string, os_version:string, app_version:string, model:string, biometric_available:bool}
     */
    private function deviceInfo(int $index, string $suffix): array
    {
        $model = self::MODELS[($index + ($suffix === 'b' ? 1 : 0)) % count(self::MODELS)];
        $ios = str_starts_with($model, 'iPhone');

        return [
            'device_uuid' => (string) Str::uuid(),
            'hardware_id' => sprintf('e2e-hw-%03d-%s', $index, $suffix),
            'name' => 'Teléfono de '.strtok(Roster::all()[$index]['first'], ' '),
            'platform' => $ios ? 'ios' : 'android',
            'os_version' => $ios ? '17.6' : '14',
            'app_version' => '1.4.0',
            'model' => $model,
            'biometric_available' => true,
        ];
    }

    private function kiosk(Context $ctx, string $branch, string $name, bool $activate): void
    {
        $ctx->actAs($ctx->user('plataforma'));
        $created = $this->kiosks->create($ctx->user('plataforma'), $ctx->branches[$branch], $name);
        $kiosk = $created['kiosk'];
        if ($activate) {
            $activated = $this->kiosks->activate($created['activation_code'], [
                'device_uuid' => (string) Str::uuid(), 'platform' => 'android', 'os_version' => '13', 'app_version' => '1.4.0', 'model' => 'Lenovo Tab M10',
            ]);
            $kiosk = $activated['kiosk'];
            $ctx->kioskTokens[$branch] = $activated['token'];
        }
        $ctx->kiosks[$branch] = $kiosk->refresh();
    }

    private function publishDocuments(Context $ctx): void
    {
        $rh = $ctx->user('rh');
        $ctx->actAs($rh);
        $docs = [
            RegistrationDocuments::AGREEMENT => ['Convenio de registro electrónico', "**Documento de prueba (entorno E2E).** Este texto no es el convenio oficial de la empresa.\n\nEl colaborador acepta registrar su jornada desde la app en su teléfono y que se registre su ubicación al marcar y, si su puesto lo indica, durante la jornada."],
            RegistrationDocuments::PRIVACY => ['Aviso de privacidad para colaboradores', "**Documento de prueba (entorno E2E).** Este texto no es el aviso de privacidad oficial.\n\nLos datos de asistencia y ubicación se tratan sólo para el control de la jornada laboral."],
        ];
        foreach ($docs as $code => [$title, $body]) {
            $doc = PolicyDocument::query()->create([
                'code' => $code, 'title' => $title, 'version' => '1.0', 'body_markdown' => $body,
                'sha256' => hash('sha256', $body), 'requires_acknowledgement' => true, 'created_by' => $rh->id,
            ]);
            $this->audit->log('policy_document.created', $doc, "Borrador creado: {$doc->title} v{$doc->version}", ['code' => $doc->code, 'sha256' => $doc->sha256]);

            $file = Files::pdf($code.'-1.0.pdf', $title.' (prueba E2E)', ['Versión 1.0']);
            $path = (string) $file->storeAs('policy-documents/'.$ctx->organization->id, $code.'-1.0-'.bin2hex(random_bytes(6)).'.pdf', 'local');
            $doc->forceFill([
                'file_path' => $path, 'file_name' => $code.'-1.0.pdf',
                'file_sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'file_size' => $file->getSize(),
            ])->save();
            $this->audit->log('policy_document.pdf_uploaded', $doc, "PDF cargado: {$doc->title} v{$doc->version}", [], null, null, ['file_path' => $path, 'file_sha256' => $doc->file_sha256]);

            $doc->forceFill(['sha256' => hash('sha256', $doc->body_markdown), 'published_at' => now()])->save();
            $this->audit->log('policy_document.published', $doc, "Documento publicado: {$doc->title} v{$doc->version}", ['code' => $doc->code, 'sha256' => $doc->sha256, 'file_sha256' => $doc->file_sha256]);
            $ctx->fixtures['policy_document_'.strtolower($code)] = $doc->id;
        }
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function signDocuments(Context $ctx, int $index, array $person): void
    {
        if (! isset($ctx->employeeUsers[$index])) {
            return;
        }
        $user = $ctx->employeeUser($index);
        $employee = $ctx->employee($index)->refresh();
        $ctx->actAs($user);
        $codes = [RegistrationDocuments::PRIVACY];
        if ($person['agreement'] === true) {
            $codes[] = RegistrationDocuments::AGREEMENT;
        }
        foreach ($codes as $code) {
            $doc = $this->documents->current($code);
            if ($doc === null) {
                $ctx->warn("Documento {$code} no publicado al firmar ({$index}).");

                continue;
            }
            // Algunos firman con la biometría del teléfono (firma HMAC del dispositivo); el resto con NIP.
            $device = $ctx->devices[$index] ?? null;
            if ($device !== null && $index % 4 === 0) {
                $purpose = RegistrationDocuments::PURPOSES[$code];
                $statement = RegistrationDocuments::statement($doc);
                $payload = ElectronicSignatureService::payloadHashFor($purpose, $doc, (int) $employee->id, $statement);
                $hmac = DeviceSignature::sign($device['secret'], ElectronicSignatureService::biometricCanonical($purpose, (int) $doc->id, $payload, (string) $device['device']->device_uuid));
                $this->documents->sign($user, $employee, $doc, 'biometric', null, $device['device'], null, $hmac);
            } else {
                $this->documents->sign($user, $employee, $doc, 'pin', $ctx->pin, $device['device'] ?? null);
            }
            $ctx->count('firmas_documentos');
        }
    }

    private function paperAgreement(Context $ctx, int $index): void
    {
        $ctx->actAs($ctx->user('rh'));
        $employee = $ctx->employee($index)->refresh();
        $file = Files::pdf('convenio-firmado-papel.pdf', 'Convenio de registro firmado en papel (prueba E2E)', ['Colaborador: '.$employee->fullName()]);
        $path = (string) $file->store("expedientes/{$employee->organization_id}/convenios", 'local');
        $attachment = Attachment::query()->create([
            'organization_id' => $employee->organization_id,
            'attachable_type' => $employee->getMorphClass(),
            'attachable_id' => $employee->id,
            'category' => RegistrationDocuments::PAPER_AGREEMENT_CATEGORY,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'convenio-firmado-papel.pdf',
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'uploaded_by' => $ctx->user('rh')->id,
        ]);
        $this->audit->log('employee.registration_agreement_attached', $employee, 'Convenio de registro firmado adjunto al expediente de '.$employee->fullName(), [
            'attachment_id' => $attachment->id, 'sha256' => $attachment->sha256,
        ]);
    }

    /**
     * Expediente vigente del colaborador (por si otro proceso lo modificó).
     */
    public static function fresh(Context $ctx, int $index): Employee
    {
        return $ctx->employees[$index] = $ctx->employee($index)->refresh();
    }
}
