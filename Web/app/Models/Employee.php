<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'employees';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['curp', 'rfc', 'nss'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'curp' => 'encrypted',
            'rfc' => 'encrypted',
            'nss' => 'encrypted',
            'birth_date' => 'date',
            'status' => EmployeeStatus::class,
            'external_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Employment, $this>
     */
    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class);
    }

    /**
     * @return HasMany<Punch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<WorkdaySummary, $this>
     */
    public function workdays(): HasMany
    {
        return $this->hasMany(WorkdaySummary::class);
    }

    /**
     * @return HasMany<Incidence, $this>
     */
    public function incidences(): HasMany
    {
        return $this->hasMany(Incidence::class);
    }

    /**
     * @return HasMany<VacationRequest, $this>
     */
    public function vacationRequests(): HasMany
    {
        return $this->hasMany(VacationRequest::class);
    }

    /**
     * @return HasMany<RectificationRequest, $this>
     */
    public function rectificationRequests(): HasMany
    {
        return $this->hasMany(RectificationRequest::class);
    }

    /**
     * @return HasMany<ScheduleException, $this>
     */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    /**
     * @return HasMany<OvertimeRecord, $this>
     */
    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->last_name, $this->second_last_name])));
    }

    /**
     * Relación laboral vigente en una fecha (soporta reingresos).
     */
    public function employmentOn(CarbonInterface|string $date): ?Employment
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        return $this->employments()
            ->whereDate('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day))
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Asignación (sucursal, puesto, horario, política) vigente en una fecha.
     */
    public function assignmentOn(CarbonInterface|string $date): ?EmploymentAssignment
    {
        return $this->employmentOn($date)?->assignmentOn($date);
    }

    /**
     * Convenio de registro electrónico firmado: versión vigente publicada aceptada y
     * firmada en la app, o convenio en papel adjunto al expediente.
     */
    public function hasSignedRegistrationAgreement(): bool
    {
        return app(\App\Domain\Signatures\RegistrationDocuments::class)->hasSigned($this, \App\Domain\Signatures\RegistrationDocuments::AGREEMENT);
    }

    /**
     * Aviso de privacidad (versión vigente publicada) aceptado en la app.
     */
    public function hasAcceptedPrivacyNotice(): bool
    {
        return app(\App\Domain\Signatures\RegistrationDocuments::class)->hasSigned($this, \App\Domain\Signatures\RegistrationDocuments::PRIVACY);
    }
}
