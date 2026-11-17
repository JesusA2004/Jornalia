<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FaceVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncidenceController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PunchController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\RectificationController;
use App\Http\Controllers\Api\V1\VacationController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 para la app móvil. Contrato: packages/contracts/src/api.ts.
| Los marcajes son append-only: no existen rutas PUT/PATCH/DELETE sobre ellos.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');

    Route::middleware(['auth:sanctum', 'organization', 'throttle:api'])->group(function () {
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('acknowledgements/{document}', [ProfileController::class, 'acknowledge'])->whereNumber('document')->name('acknowledgements.store');

        Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
        Route::get('attendance/policy', [AttendanceController::class, 'policy'])->name('attendance.policy');
        Route::get('attendance/history', [AttendanceController::class, 'history'])->name('attendance.history');
        Route::get('attendance/punches/{uuid}', [PunchController::class, 'show'])->whereUuid('uuid')->name('attendance.punches.show');

        Route::middleware('throttle:punches')->group(function () {
            Route::post('attendance/challenge', [AttendanceController::class, 'challenge'])->name('attendance.challenge');
            Route::post('attendance/punches', [PunchController::class, 'store'])->name('attendance.punches.store');
            Route::post('sync/punches', [PunchController::class, 'sync'])->name('sync.punches');
        });

        Route::get('rectifications', [RectificationController::class, 'index'])->name('rectifications.index');
        Route::post('rectifications', [RectificationController::class, 'store'])->name('rectifications.store');

        Route::get('incidence-types', [IncidenceController::class, 'types'])->name('incidence-types.index');
        Route::get('incidences', [IncidenceController::class, 'index'])->name('incidences.index');
        Route::post('incidences', [IncidenceController::class, 'store'])->name('incidences.store');

        Route::get('vacations/balance', [VacationController::class, 'balance'])->name('vacations.balance');
        Route::get('vacations', [VacationController::class, 'index'])->name('vacations.index');
        Route::post('vacations', [VacationController::class, 'store'])->name('vacations.store');
        Route::post('vacations/{vacation}/cancel', [VacationController::class, 'cancel'])->whereNumber('vacation')->name('vacations.cancel');

        Route::get('calendar', CalendarController::class)->name('calendar');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::post('devices/push-token', PushTokenController::class)->name('devices.push-token');

        // Límite propio: 30 lotes por minuto por usuario.
        Route::post('location/points', [LocationController::class, 'store'])->middleware('throttle:location')->name('location.points.store');
        Route::get('location/state', [LocationController::class, 'state'])->name('location.state');

        Route::get('face-verification/status', [FaceVerificationController::class, 'status'])->name('face-verification.status');
        // Límite estricto por usuario (además del bloqueo de dominio: 5 fallos / 15 min).
        Route::middleware('throttle:20,1')->group(function () {
            Route::post('face-verification/challenge', [FaceVerificationController::class, 'challenge'])->name('face-verification.challenge');
            Route::post('face-verification/verify', [FaceVerificationController::class, 'verify'])->name('face-verification.verify');
        });
    });
});
