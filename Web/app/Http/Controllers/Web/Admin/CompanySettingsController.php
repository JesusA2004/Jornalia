<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Workforce\MexicanStates;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\WorkCenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración › Empresa (PDF pantalla 19): razón social, RFC y domicilio
 * fiscal (razón social principal en `legal_entities`), nombre comercial
 * (`organizations.name`), contacto de privacidad, aviso de privacidad vigente,
 * color institucional y logotipo oficial. Sin datos de ejemplo: lo no
 * capturado se muestra como pendiente.
 */
class CompanySettingsController extends AdminController
{
    /** Paleta institucional (hoja membretada): verde #225C54 con acentos dorados #AF8B51. */
    public const PALETTE = ['#163A36', '#225C54', '#2F5D3A', '#1B4B5A', '#AF8B51', '#7A4F1E', '#E6D3A8'];

    public const DEFAULT_COLOR = '#225C54';

    /** Logotipo oficial (docs/identidad; nunca se modifica). */
    public const OFFICIAL_LOGO = '/brand/logo.svg';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RegistrationDocuments $documents,
    ) {}

    public function edit(Request $request): Response
    {
        $org = $this->organization();
        $entity = $this->primaryEntity();
        $privacy = $this->documents->current(RegistrationDocuments::PRIVACY);

        return Inertia::render('configuracion/Company', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'timezone' => $org->timezone,
                'privacy_contact_email' => $org->privacy_contact_email,
                'brand_primary_color' => $org->brand_primary_color ?: self::DEFAULT_COLOR,
                'logo_url' => $org->brand_logo_path ? Storage::disk('public')->url($org->brand_logo_path) : null,
                'official_logo_url' => self::OFFICIAL_LOGO,
                'created_at' => $org->created_at?->toIso8601String(),
            ],
            'legal_entity' => [
                'id' => $entity?->id,
                'legal_name' => $entity?->legal_name,
                'rfc' => $entity?->rfc,
                'fiscal_address' => $entity?->fiscal_address,
                'count' => LegalEntity::query()->count(),
            ],
            'privacy_notice' => $privacy ? [
                'id' => $privacy->id,
                'version' => $privacy->version,
                'published_at' => $privacy->published_at?->toIso8601String(),
                'has_pdf' => $privacy->file_path !== null,
            ] : null,
            'palette' => self::PALETTE,
            'summary' => [
                'legal_entities' => LegalEntity::query()->count(),
                'work_centers' => WorkCenter::query()->count(),
                'branches' => Branch::query()->count(),
                'employees' => Employee::query()->where('status', 'active')->count(),
            ],
            'timezones' => MexicanStates::timezones(),
            'can' => [
                'manage' => $request->user()?->can('settings.manage') ?? false,
                'documents' => $request->user()?->can('settings.documents') ?? false,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $org = $this->organization();
        $entity = $this->primaryEntity();
        $request->merge([
            'rfc' => $request->filled('rfc') ? strtoupper(trim((string) $request->input('rfc'))) : null,
            'brand_primary_color' => $request->filled('brand_primary_color') ? strtoupper((string) $request->input('brand_primary_color')) : null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['required', 'string', 'max:200'],
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('legal_entities', 'rfc')->where('organization_id', $org->id)->ignore($entity?->id)],
            'fiscal_address' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'privacy_contact_email' => ['nullable', 'email:rfc', 'max:160', 'not_regex:/@(empresa\.mx|example\.|ejemplo\.)/i'],
            'brand_primary_color' => ['nullable', Rule::in(self::PALETTE)],
        ], [
            'rfc.regex' => 'RFC no válido (persona moral: 3 letras, 6 dígitos de fecha y 3 de homoclave).',
            'brand_primary_color.in' => 'Elige un color de la paleta institucional.',
            'privacy_contact_email.not_regex' => 'Captura el correo real del responsable de datos personales.',
        ], [
            'name' => 'nombre comercial', 'legal_name' => 'razón social', 'rfc' => 'RFC', 'fiscal_address' => 'domicilio fiscal',
            'timezone' => 'zona horaria', 'privacy_contact_email' => 'contacto de privacidad', 'brand_primary_color' => 'color institucional',
        ]);

        DB::transaction(function () use ($org, $entity, $data) {
            $org->fill([
                'name' => $data['name'],
                'timezone' => $data['timezone'],
                'privacy_contact_email' => $data['privacy_contact_email'] ?? null,
                'brand_primary_color' => $data['brand_primary_color'] ?? self::DEFAULT_COLOR,
            ]);
            $orgBefore = array_intersect_key($org->getOriginal(), $org->getDirty());
            $orgAfter = $org->getDirty();
            $org->save();

            $entity ??= new LegalEntity(['active' => true]);
            $entity->fill(['legal_name' => $data['legal_name'], 'rfc' => $data['rfc'], 'fiscal_address' => $data['fiscal_address']]);
            // El RFC de la persona moral es público: se registra con clave propia para no redactarlo.
            $entityBefore = $this->renameRfc(array_intersect_key($entity->getOriginal(), $entity->getDirty()));
            $entityAfter = $this->renameRfc($entity->getDirty());
            $entity->save();

            app(CurrentOrganization::class)->set($org);

            if ($orgAfter !== [] || $entityAfter !== []) {
                $this->audit->log('organization.updated', $org, 'Datos de la empresa actualizados', [], null,
                    ['organization' => $orgBefore, 'legal_entity' => $entityBefore],
                    ['organization' => $orgAfter, 'legal_entity' => $entityAfter],
                );
            }
        });

        $this->toast('Datos de la empresa guardados.');

        return back();
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $org = $this->organization();
        $request->validate([
            // Sin SVG subido por usuarios (podría contener scripts); el oficial ya vive en /brand/logo.svg.
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(4000)->maxHeight(4000)],
        ], ['logo.mimes' => 'El logotipo debe ser PNG, JPG o WebP.', 'logo.dimensions' => 'El logotipo debe medir entre 64 y 4000 px por lado.'], ['logo' => 'logotipo']);

        $file = $request->file('logo');
        $path = $file->storeAs('brand', $org->id.'-'.bin2hex(random_bytes(6)).'.'.$file->extension(), 'public');
        if ($path === false) {
            return back()->withErrors(['logo' => 'No se pudo guardar el logotipo. Intenta de nuevo.']);
        }
        $previous = $org->brand_logo_path;
        // El archivo anterior se conserva (no se borra información). El archivo se guarda tal cual: nunca se modifica.
        $org->forceFill(['brand_logo_path' => $path])->save();

        $this->audit->log('organization.logo_updated', $org, 'Logotipo actualizado', ['sha256' => hash_file('sha256', Storage::disk('public')->path($path))], null, ['brand_logo_path' => $previous], ['brand_logo_path' => $path]);
        $this->toast('Logotipo actualizado.');

        return back();
    }

    public function removeLogo(Request $request): RedirectResponse
    {
        $org = $this->organization();
        if ($org->brand_logo_path) {
            $previous = $org->brand_logo_path;
            $org->forceFill(['brand_logo_path' => null])->save();
            $this->audit->log('organization.logo_removed', $org, 'Se vuelve al logotipo oficial', [], null, ['brand_logo_path' => $previous], ['brand_logo_path' => null]);
        }
        $this->toast('Se usa el logotipo oficial.');

        return back();
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->organizationId());
    }

    /**
     * Razón social principal: la activa más antigua de la organización (B1 carga la oficial).
     */
    private function primaryEntity(): ?LegalEntity
    {
        return LegalEntity::query()->orderByDesc('active')->orderBy('id')->first();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function renameRfc(array $values): array
    {
        if (array_key_exists('rfc', $values)) {
            $values['company_rfc'] = $values['rfc'];
            unset($values['rfc']);
        }

        return $values;
    }
}
