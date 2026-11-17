<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Workforce\MexicanStates;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\WorkCenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Datos generales de la empresa y logotipo.
 */
class CompanySettingsController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): Response
    {
        $org = $this->organization();

        return Inertia::render('configuracion/Company', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'timezone' => $org->timezone,
                'privacy_contact_email' => $org->privacy_contact_email,
                'brand_primary_color' => $org->brand_primary_color,
                'logo_url' => $org->brand_logo_path ? Storage::disk('public')->url($org->brand_logo_path) : null,
                'created_at' => $org->created_at?->toIso8601String(),
            ],
            'summary' => [
                'legal_entities' => LegalEntity::query()->count(),
                'work_centers' => WorkCenter::query()->count(),
                'branches' => Branch::query()->count(),
                'employees' => Employee::query()->where('status', 'active')->count(),
            ],
            'timezones' => MexicanStates::timezones(),
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $org = $this->organization();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'timezone' => ['required', 'timezone:all'],
            'privacy_contact_email' => ['nullable', 'email', 'max:160'],
            'brand_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], ['brand_primary_color.regex' => 'Usa un color hexadecimal como #1F6FEB.'], [
            'name' => 'nombre comercial', 'timezone' => 'zona horaria', 'privacy_contact_email' => 'contacto de privacidad', 'brand_primary_color' => 'color de marca',
        ]);

        $org->fill($data);
        $changes = $org->getDirty();
        $org->save();
        app(CurrentOrganization::class)->set($org);

        $this->audit->log('organization.updated', $org, 'Datos de la empresa actualizados', ['changed' => $changes]);
        $this->toast('Datos de la empresa guardados.');

        return back();
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $org = $this->organization();
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(4000)->maxHeight(4000)],
        ], ['logo.mimes' => 'El logotipo debe ser PNG, JPG o WebP.', 'logo.dimensions' => 'El logotipo debe medir entre 64 y 4000 px por lado.'], ['logo' => 'logotipo']);

        $file = $request->file('logo');
        $path = $file->storeAs('brand', $org->id.'-'.bin2hex(random_bytes(6)).'.'.$file->extension(), 'public');
        $previous = $org->brand_logo_path;
        // El archivo anterior se conserva (no se borra información).
        $org->forceFill(['brand_logo_path' => $path])->save();

        $this->audit->log('organization.logo_updated', $org, 'Logotipo actualizado', ['path' => $path, 'previous' => $previous, 'sha256' => hash_file('sha256', Storage::disk('public')->path($path))]);
        $this->toast('Logotipo actualizado.');

        return back();
    }

    public function removeLogo(Request $request): RedirectResponse
    {
        $org = $this->organization();
        if ($org->brand_logo_path) {
            $previous = $org->brand_logo_path;
            $org->forceFill(['brand_logo_path' => null])->save();
            $this->audit->log('organization.logo_removed', $org, 'Logotipo retirado', ['previous' => $previous]);
        }
        $this->toast('Logotipo retirado.');

        return back();
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->organizationId());
    }
}
