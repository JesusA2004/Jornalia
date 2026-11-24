<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    protected $table = 'organizations';

    protected $guarded = ['id'];

    /**
     * @return HasMany<LegalEntity, $this>
     */
    public function legalEntities(): HasMany
    {
        return $this->hasMany(LegalEntity::class);
    }

    /**
     * @return HasMany<WorkCenter, $this>
     */
    public function workCenters(): HasMany
    {
        return $this->hasMany(WorkCenter::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * @return HasMany<OrganizationSetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(OrganizationSetting::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user');
    }
}
