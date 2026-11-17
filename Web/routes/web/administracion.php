<?php

use App\Http\Controllers\Web\Admin\ApprovalFlowController;
use App\Http\Controllers\Web\Admin\AttendancePolicyController;
use App\Http\Controllers\Web\Admin\CompanySettingsController;
use App\Http\Controllers\Web\Admin\DeviceController;
use App\Http\Controllers\Web\Admin\EmployeeAccountController;
use App\Http\Controllers\Web\Admin\EmployeeController;
use App\Http\Controllers\Web\Admin\EmployeeImportController;
use App\Http\Controllers\Web\Admin\EmploymentController;
use App\Http\Controllers\Web\Admin\HolidayController;
use App\Http\Controllers\Web\Admin\IncidenceTypeController;
use App\Http\Controllers\Web\Admin\LegalVersionController;
use App\Http\Controllers\Web\Admin\OrganizationController;
use App\Http\Controllers\Web\Admin\OrganizationStructureController;
use App\Http\Controllers\Web\Admin\PolicyDocumentController;
use App\Http\Controllers\Web\Admin\RoleController;
use App\Http\Controllers\Web\Admin\ScheduleAssignmentController;
use App\Http\Controllers\Web\Admin\ScheduleController;
use App\Http\Controllers\Web\Admin\ScheduleExceptionController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Http\Controllers\Web\Admin\VacationPolicyController;
use Illuminate\Support\Facades\Route;

/*
| Administración y personal (agente "Web administración y personal").
| Se carga dentro del grupo auth + verified + organization (routes/web.php).
| Autorización: Policies (colaboradores, horarios, dispositivos) y `can:` por módulo.
*/

// ── Colaboradores ───────────────────────────────────────────────────────────
Route::prefix('colaboradores')->name('colaboradores.')->group(function () {
    Route::get('/', [EmployeeController::class, 'index'])->name('index');
    Route::get('nuevo', [EmployeeController::class, 'create'])->name('create');
    Route::post('/', [EmployeeController::class, 'store'])->name('store');

    Route::get('importar', [EmployeeImportController::class, 'create'])->name('import');
    Route::get('importar/plantilla', [EmployeeImportController::class, 'template'])->name('import.template');
    Route::post('importar', [EmployeeImportController::class, 'store'])->name('import.store');
    Route::post('sincronizar-people', [EmployeeImportController::class, 'sync'])->name('import.sync');

    Route::get('{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('show');
    Route::put('{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('update');
    Route::post('{employee}/identificadores', [EmployeeController::class, 'revealIdentifiers'])->whereNumber('employee')->name('identifiers');

    Route::post('{employee}/baja', [EmploymentController::class, 'terminate'])->whereNumber('employee')->name('terminate');
    Route::post('{employee}/reingreso', [EmploymentController::class, 'rehire'])->whereNumber('employee')->name('rehire');
    Route::post('{employee}/movimientos', [EmploymentController::class, 'move'])->whereNumber('employee')->name('move');

    Route::post('{employee}/cuenta', [EmployeeAccountController::class, 'store'])->whereNumber('employee')->name('account.store');
    Route::post('{employee}/cuenta/restablecer', [EmployeeAccountController::class, 'reset'])->whereNumber('employee')->name('account.reset');
    Route::post('{employee}/cuenta/desvincular', [EmployeeAccountController::class, 'unlink'])->whereNumber('employee')->name('account.unlink');
});

// ── Horarios ────────────────────────────────────────────────────────────────
Route::prefix('horarios')->name('horarios.')->group(function () {
    Route::get('/', [ScheduleController::class, 'index'])->name('index');
    Route::get('nuevo', [ScheduleController::class, 'create'])->name('create');
    Route::post('/', [ScheduleController::class, 'store'])->name('store');
    Route::post('validar', [ScheduleController::class, 'check'])->name('check');

    Route::get('asignacion', [ScheduleAssignmentController::class, 'index'])->name('assignment');
    Route::post('asignacion', [ScheduleAssignmentController::class, 'store'])->name('assignment.store');

    Route::get('excepciones', [ScheduleExceptionController::class, 'index'])->name('exceptions');
    Route::post('excepciones', [ScheduleExceptionController::class, 'store'])->name('exceptions.store');
    Route::post('excepciones/{scheduleException}/anular', [ScheduleExceptionController::class, 'deactivate'])->whereNumber('scheduleException')->name('exceptions.deactivate');

    Route::get('{schedule}/editar', [ScheduleController::class, 'edit'])->whereNumber('schedule')->name('edit');
    Route::put('{schedule}', [ScheduleController::class, 'update'])->whereNumber('schedule')->name('update');
    Route::post('{schedule}/desactivar', [ScheduleController::class, 'deactivate'])->whereNumber('schedule')->name('deactivate');
    Route::post('{schedule}/reactivar', [ScheduleController::class, 'restore'])->whereNumber('schedule')->withTrashed()->name('restore');
});

// ── Dispositivos ────────────────────────────────────────────────────────────
Route::prefix('dispositivos')->name('dispositivos.')->group(function () {
    Route::get('/', [DeviceController::class, 'index'])->name('index');
    Route::post('{device}/autorizar', [DeviceController::class, 'approve'])->whereNumber('device')->name('approve');
    Route::post('{device}/revocar', [DeviceController::class, 'revoke'])->whereNumber('device')->name('revoke');
    Route::patch('{device}/corporativo', [DeviceController::class, 'corporate'])->whereNumber('device')->name('corporate');
});

// ── Organización ────────────────────────────────────────────────────────────
// PUT organizacion/activa vive en routes/web/sesion.php (Web\OrganizationSwitchController).

Route::prefix('organizacion')->name('organizacion.')->group(function () {
    Route::get('/', [OrganizationController::class, 'index'])->middleware('can:organization.view')->name('index');

    Route::middleware('can:organization.manage')->group(function () {
        Route::post('{entity}', [OrganizationStructureController::class, 'store'])
            ->where('entity', OrganizationStructureController::ENTITY_PATTERN)->name('store');
        Route::put('{entity}/{id}', [OrganizationStructureController::class, 'update'])
            ->where('entity', OrganizationStructureController::ENTITY_PATTERN)->whereNumber('id')->name('update');
        Route::post('{entity}/{id}/reactivar', [OrganizationStructureController::class, 'restore'])
            ->where('entity', OrganizationStructureController::ENTITY_PATTERN)->whereNumber('id')->name('restore');
        Route::post('{entity}/{id}/desactivar', [OrganizationStructureController::class, 'deactivate'])
            ->where('entity', OrganizationStructureController::ENTITY_PATTERN)->whereNumber('id')->name('destroy');
    });
});

// ── Usuarios y roles ────────────────────────────────────────────────────────
Route::prefix('usuarios')->name('usuarios.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->middleware('can:users.view')->name('index');
    Route::middleware('can:users.manage')->group(function () {
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
        Route::post('autoridad', [UserController::class, 'storeAuthority'])->name('authority');
        Route::post('{user}/desactivar', [UserController::class, 'deactivate'])->whereNumber('user')->name('deactivate');
        Route::post('{user}/reactivar', [UserController::class, 'restore'])->whereNumber('user')->name('restore');
        Route::post('{user}/restablecer', [UserController::class, 'reset'])->whereNumber('user')->name('reset');
    });

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:users.view')->name('roles');
    Route::middleware('can:users.roles')->group(function () {
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
        Route::post('roles/{role}/desactivar', [RoleController::class, 'deactivate'])->whereNumber('role')->name('roles.deactivate');
        Route::post('roles/{role}/reactivar', [RoleController::class, 'restore'])->whereNumber('role')->name('roles.restore');
    });
});

// ── Configuración ───────────────────────────────────────────────────────────
Route::prefix('configuracion')->name('configuracion.')->group(function () {
    Route::middleware('can:settings.view')->group(function () {
        Route::get('/', [CompanySettingsController::class, 'edit'])->name('company');
        Route::get('asistencia', [AttendancePolicyController::class, 'index'])->name('attendance');
        Route::get('autorizaciones', [ApprovalFlowController::class, 'index'])->name('approvals');
        Route::get('incidencias', [IncidenceTypeController::class, 'index'])->name('incidences');
        Route::get('vacaciones', [VacationPolicyController::class, 'index'])->name('vacations');
        Route::get('festivos', [HolidayController::class, 'index'])->name('holidays');
        Route::get('documentos', [PolicyDocumentController::class, 'index'])->name('documents');
    });

    Route::get('normativa', [LegalVersionController::class, 'index'])->middleware('can:settings.legal')->name('legal');

    Route::middleware('can:settings.manage')->group(function () {
        Route::put('empresa', [CompanySettingsController::class, 'update'])->name('company.update');
        Route::post('empresa/logotipo', [CompanySettingsController::class, 'uploadLogo'])->name('company.logo');
        Route::post('empresa/logotipo/quitar', [CompanySettingsController::class, 'removeLogo'])->name('company.logo.remove');

        Route::post('asistencia', [AttendancePolicyController::class, 'store'])->name('attendance.store');
        Route::put('asistencia/{attendancePolicy}', [AttendancePolicyController::class, 'update'])->whereNumber('attendancePolicy')->name('attendance.update');
        Route::post('asistencia/{attendancePolicy}/desactivar', [AttendancePolicyController::class, 'deactivate'])->whereNumber('attendancePolicy')->name('attendance.deactivate');
        Route::post('asistencia/{attendancePolicy}/reactivar', [AttendancePolicyController::class, 'restore'])->whereNumber('attendancePolicy')->withTrashed()->name('attendance.restore');

        Route::put('autorizaciones/horas-extra/politica', [ApprovalFlowController::class, 'updateOvertime'])->name('approvals.overtime');
        Route::put('autorizaciones/{subject}', [ApprovalFlowController::class, 'update'])->whereIn('subject', ['rectification', 'incidence', 'vacation', 'overtime'])->name('approvals.update');

        Route::post('incidencias', [IncidenceTypeController::class, 'store'])->name('incidences.store');
        Route::put('incidencias/{incidenceType}', [IncidenceTypeController::class, 'update'])->whereNumber('incidenceType')->name('incidences.update');
        Route::post('incidencias/{incidenceType}/desactivar', [IncidenceTypeController::class, 'deactivate'])->whereNumber('incidenceType')->name('incidences.deactivate');
        Route::post('incidencias/{incidenceType}/reactivar', [IncidenceTypeController::class, 'restore'])->whereNumber('incidenceType')->withTrashed()->name('incidences.restore');

        Route::post('vacaciones', [VacationPolicyController::class, 'store'])->name('vacations.store');
        Route::put('vacaciones/{vacationPolicy}', [VacationPolicyController::class, 'update'])->whereNumber('vacationPolicy')->name('vacations.update');
        Route::post('vacaciones/{vacationPolicy}/desactivar', [VacationPolicyController::class, 'deactivate'])->whereNumber('vacationPolicy')->name('vacations.deactivate');
        Route::post('vacaciones/{vacationPolicy}/reactivar', [VacationPolicyController::class, 'restore'])->whereNumber('vacationPolicy')->withTrashed()->name('vacations.restore');

        Route::post('festivos', [HolidayController::class, 'store'])->name('holidays.store');
        Route::put('festivos/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('holidays.update');
        Route::post('festivos/{holiday}/desactivar', [HolidayController::class, 'deactivate'])->whereNumber('holiday')->name('holidays.deactivate');
        Route::post('festivos/{holiday}/reactivar', [HolidayController::class, 'restore'])->whereNumber('holiday')->withTrashed()->name('holidays.restore');
    });

    Route::middleware('can:settings.documents')->group(function () {
        Route::post('documentos', [PolicyDocumentController::class, 'store'])->name('documents.store');
        Route::put('documentos/{policyDocument}', [PolicyDocumentController::class, 'update'])->whereNumber('policyDocument')->name('documents.update');
        Route::post('documentos/{policyDocument}/publicar', [PolicyDocumentController::class, 'publish'])->whereNumber('policyDocument')->name('documents.publish');
        Route::post('documentos/{policyDocument}/nueva-version', [PolicyDocumentController::class, 'newVersion'])->whereNumber('policyDocument')->name('documents.version');
        Route::post('documentos/{policyDocument}/desactivar', [PolicyDocumentController::class, 'deactivate'])->whereNumber('policyDocument')->name('documents.deactivate');
        Route::post('documentos/{policyDocument}/reactivar', [PolicyDocumentController::class, 'restore'])->whereNumber('policyDocument')->withTrashed()->name('documents.restore');
    });
});
