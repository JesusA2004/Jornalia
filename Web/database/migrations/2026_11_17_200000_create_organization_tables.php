<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura multiempresa: organizaciones, razones sociales, centros de trabajo,
 * sucursales, departamentos, puestos y pertenencia de usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->string('timezone', 64)->default('America/Mexico_City');
            $table->string('status', 20)->default('active');
            $table->string('privacy_contact_email', 160)->nullable();
            $table->string('brand_logo_path')->nullable();
            $table->string('brand_primary_color', 9)->nullable();
            $table->timestamps();
        });

        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name', 200);
            $table->string('rfc', 13)->nullable();
            $table->string('employer_registration', 20)->nullable()->comment('Registro patronal IMSS');
            $table->string('fiscal_address', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'rfc']);
        });

        Schema::create('work_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('code', 40);
            $table->string('address', 255)->nullable();
            $table->string('state_code', 8)->nullable()->comment('Entidad federativa (ISO 3166-2:MX) para festivos locales');
            $table->string('timezone', 64)->default('America/Mexico_City');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('code', 40);
            $table->string('region', 80)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'region']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 160);
            $table->string('code', 40);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('code', 40);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
            $table->string('status', 20)->default('active')->after('password');
            $table->boolean('is_super_admin')->default(false)->after('status');
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->boolean('access_all_branches')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });

        Schema::create('organization_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('organization_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropColumn(['status', 'is_super_admin', 'last_login_at']);
        });
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('work_centers');
        Schema::dropIfExists('legal_entities');
        Schema::dropIfExists('organizations');
    }
};
