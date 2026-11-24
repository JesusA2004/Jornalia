<?php

use App\Http\Controllers\Web\Operacion\AttendanceController;
use App\Http\Controllers\Web\Operacion\AuditController;
use App\Http\Controllers\Web\Operacion\CalendarController;
use App\Http\Controllers\Web\Operacion\ComplianceController;
use App\Http\Controllers\Web\Operacion\DashboardController;
use App\Http\Controllers\Web\Operacion\IncidenceController;
use App\Http\Controllers\Web\Operacion\IntegrityController;
use App\Http\Controllers\Web\Operacion\LocationController;
use App\Http\Controllers\Web\Operacion\OvertimeController;
use App\Http\Controllers\Web\Operacion\RectificationController;
use App\Http\Controllers\Web\Operacion\ReportController;
use App\Http\Controllers\Web\Operacion\VacationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Operación (agente "Web operación"). El grupo padre aplica auth + verified +
| organization. Cada ruta exige su permiso; el alcance de datos lo impone
| AccessScope en los controladores. Los IDs se resuelven dentro del
| controlador (después de fijar la organización activa) para no depender del
| orden de middleware en el enlace implícito de modelos.
|
| No existen rutas de edición ni borrado de marcajes (registro append-only) y
| ninguna ruta DELETE: nada se elimina físicamente.
*/

// Panel: visible para todos; sin `dashboard.view` muestra el resumen personal.
Route::get('dashboard', DashboardController::class)->name('dashboard');

// Marcaje web propio (la política de asistencia decide si está permitido).
Route::post('asistencias/marcar', [AttendanceController::class, 'punch'])
    ->middleware('throttle:punches')->name('asistencias.punch');

Route::middleware('can:punches.view')->group(function () {
    Route::get('asistencias', [AttendanceController::class, 'index'])->name('asistencias.index');
    // Marcajes originales: sólo consulta y exportación (ningún rol edita ni borra).
    Route::get('asistencias/marcajes/exportar', [AttendanceController::class, 'exportPunches'])
        ->middleware('throttle:20,1')->name('asistencias.export');
    Route::get('asistencias/{employee}/{date}', [AttendanceController::class, 'show'])
        ->whereNumber('employee')->where('date', '\d{4}-\d{2}-\d{2}')->name('asistencias.show');
});

Route::get('ubicacion', LocationController::class)->middleware('can:locations.view')->name('ubicacion.index');

Route::middleware('can:rectifications.view')->prefix('rectificaciones')->name('rectificaciones.')->group(function () {
    Route::get('/', [RectificationController::class, 'index'])->name('index');
    Route::post('/', [RectificationController::class, 'store'])->name('store');
    Route::get('marcajes-del-dia', [RectificationController::class, 'punches'])->name('punches');
    Route::get('{rectification}', [RectificationController::class, 'show'])->whereNumber('rectification')->name('show');
    // Autorización (jefe, rectifications.approve) o validación RH (rectifications.validate):
    // la etapa y el permiso se verifican en el dominio.
    Route::post('{rectification}/decision', [RectificationController::class, 'decide'])->whereNumber('rectification')
        ->middleware('throttle:30,1')->name('decide');
    Route::post('{rectification}/cancelar', [RectificationController::class, 'cancel'])->whereNumber('rectification')->name('cancel');
    Route::get('{rectification}/evidencias/{attachment}', [RectificationController::class, 'evidence'])
        ->whereNumber(['rectification', 'attachment'])->name('evidence');
});

Route::middleware('can:incidences.view')->prefix('incidencias')->name('incidencias.')->group(function () {
    Route::get('/', [IncidenceController::class, 'index'])->name('index');
    Route::post('/', [IncidenceController::class, 'store'])->name('store');
    Route::get('{incidence}', [IncidenceController::class, 'show'])->whereNumber('incidence')->name('show');
    Route::post('{incidence}/decision', [IncidenceController::class, 'decide'])->whereNumber('incidence')
        ->middleware('can:incidences.approve')->name('decide');
    Route::post('{incidence}/cancelar', [IncidenceController::class, 'cancel'])->whereNumber('incidence')->name('cancel');
    Route::get('{incidence}/evidencias/{attachment}', [IncidenceController::class, 'evidence'])
        ->whereNumber(['incidence', 'attachment'])->name('evidence');
});

Route::middleware('can:vacations.view')->prefix('vacaciones')->name('vacaciones.')->group(function () {
    Route::get('/', [VacationController::class, 'index'])->name('index');
    Route::post('/', [VacationController::class, 'store'])->name('store');
    Route::get('{vacation}', [VacationController::class, 'show'])->whereNumber('vacation')->name('show');
    Route::post('{vacation}/decision', [VacationController::class, 'decide'])->whereNumber('vacation')
        ->middleware('can:vacations.approve')->name('decide');
    Route::post('{vacation}/cancelar', [VacationController::class, 'cancel'])->whereNumber('vacation')->name('cancel');
});

Route::middleware('can:overtime.view')->prefix('horas-extra')->name('horas-extra.')->group(function () {
    Route::get('/', [OvertimeController::class, 'index'])->name('index');
    Route::post('{overtime}/autorizacion', [OvertimeController::class, 'decide'])->whereNumber('overtime')
        ->middleware('can:overtime.approve')->name('decide');
});

Route::get('calendario', CalendarController::class)->middleware('can:calendar.view')->name('calendario.index');

Route::middleware('can:reports.view')->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::post('exportar', [ReportController::class, 'export'])->middleware(['can:reports.export', 'throttle:20,1'])->name('export');
    Route::get('descargas/{export}', [ReportController::class, 'download'])->whereNumber('export')->middleware('can:reports.export')->name('download');
    Route::get('{report}', [ReportController::class, 'show'])->where('report', '[a-z_]+')->name('show');
});

// Cumplimiento: un módulo con tres pestañas (Alertas legales, Integridad del registro,
// Bitácora de cambios). Cada pestaña conserva su propio permiso.
Route::prefix('cumplimiento')->name('cumplimiento.')->group(function () {
    Route::middleware('can:compliance.view')->group(function () {
        Route::get('/', [ComplianceController::class, 'index'])->name('index');
        Route::post('alertas/{alert}', [ComplianceController::class, 'resolve'])->whereNumber('alert')
            ->middleware('can:compliance.resolve')->name('resolve');
    });

    Route::middleware('can:integrity.view')->prefix('integridad')->name('integridad.')->group(function () {
        Route::get('/', [IntegrityController::class, 'index'])->name('index');
        Route::post('verificar', [IntegrityController::class, 'verify'])->middleware(['can:integrity.verify', 'throttle:6,1'])->name('verify');
        Route::post('anclar', [IntegrityController::class, 'anchor'])->middleware(['can:integrity.verify', 'throttle:6,1'])->name('anchor');
    });

    Route::get('bitacora', AuditController::class)->middleware('can:audit.view')->name('bitacora');
});

// Rutas anteriores: redirigen a la pestaña correspondiente para no romper enlaces.
Route::get('integridad', fn (Request $request) => redirect('/cumplimiento/integridad'.($request->getQueryString() ? '?'.$request->getQueryString() : '')))
    ->name('integridad.index');
Route::get('auditoria', fn (Request $request) => redirect('/cumplimiento/bitacora'.($request->getQueryString() ? '?'.$request->getQueryString() : '')))
    ->name('auditoria.index');
