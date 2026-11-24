<?php

namespace App\Http\Middleware;

use App\Domain\Security\PermissionCatalog;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props compartidas. Contrato para el frontend (`resources/js/types`):
     *  auth.user, auth.organization, auth.organizations, auth.permissions (string[]),
     *  auth.roles, auth.is_super_admin, auth.employee_id, notifications_unread.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $organization = app(CurrentOrganization::class)->get();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'email_verified_at']),
                'organization' => $organization ? [
                    ...$organization->only(['id', 'name', 'timezone', 'brand_logo_path']),
                    'brand_logo_url' => $organization->brand_logo_path ? Storage::disk('public')->url($organization->brand_logo_path) : null,
                ] : null,
                'organizations' => $user
                    ? $user->organizations()->wherePivot('status', 'active')->orderBy('name')->get(['organizations.id', 'organizations.name'])
                        ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values()
                    : [],
                'permissions' => fn () => $user && $organization
                    ? ($user->is_super_admin ? array_values(array_diff(PermissionCatalog::all(), PermissionCatalog::OPERATIONAL)) : $user->getAllPermissions()->pluck('name')->values()->all())
                    : [],
                'roles' => fn () => $user && $organization ? $user->getRoleNames()->values()->all() : [],
                'is_super_admin' => (bool) $user?->is_super_admin,
                'employee_id' => fn () => $user && $organization ? Employee::query()->where('user_id', $user->id)->value('id') : null,
            ],
            'notifications_unread' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
