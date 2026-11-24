<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FaceVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncidenceController;
use App\Http\Controllers\Api\V1\KioskController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PunchController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\RectificationController;
use App\Http\Controllers\Api\V1\SignatureController;
use App\Http\Controllers\Api\V1\VacationController;
use App\Http\Middleware\AuthenticateKiosk;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 para la app móvil. Contrato: packages/contracts/src/api.ts.
| Los marcajes son append-only: no existen rutas PUT/PATCH/DELETE sobre ellos.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');

    // Archivos con URL firmada temporal (A2): el navegador del teléfono no envía el token Bearer.
    Route::middleware(['signed', 'throttle:60,1'])->prefix('files')->name('files.')->group(function () {
        Route::get('documents/{document}', [SignatureController::class, 'signedPdf'])->whereNumber('document')->name('document');
        Route::get('evidence/{attachment}', [RectificationController::class, 'evidence'])->whereNumber('attachment')->name('evidence');
    });

    // Kiosco de sucursal (B1): token propio del kiosco, no de usuario.
    Route::post('kiosk/activate', [KioskController::class, 'activate'])->middleware('throttle:10,1')->name('kiosk.activate');
    Route::middleware([AuthenticateKiosk::class, 'throttle:120,1'])->prefix('kiosk')->name('kiosk.')->group(function () {
        Route::get('status', [KioskController::class, 'status'])->name('status');
        Route::post('punches', [KioskController::class, 'punch'])->name('punches.store');
        Route::post('sync', [KioskController::class, 'sync'])->name('sync');
        Route::post('face/challenge', [KioskController::class, 'faceChallenge'])->name('face.challenge');
        Route::post('face/verify', [KioskController::class, 'faceVerify'])->name('face.verify');
    });

    Route::middleware(['auth:sanctum', 'organization', 'throttle:api'])->group(function () {
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('acknowledgements/{document}', [ProfileController::class, 'acknowledge'])->whereNumber('document')->name('acknowledgements.store');

        // Firma electrónica (A2): NIP, convenio de registro y aviso de privacidad.
        Route::post('profile/signature-pin', [SignatureController::class, 'pin'])->middleware('throttle:10,1')->name('profile.signature-pin');
        Route::get('documents', [SignatureController::class, 'index'])->name('documents.index');
        Route::get('documents/pending', [SignatureController::class, 'pending'])->name('documents.pending');
        Route::get('documents/{document}/pdf', [SignatureController::class, 'pdf'])->whereNumber('document')->name('documents.pdf');
        Route::post('documents/{document}/sign', [SignatureController::class, 'sign'])->whereNumber('document')->middleware('throttle:20,1')->name('documents.sign');

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
        Route::get('rectifications/{rectification}', [RectificationController::class, 'show'])->whereNumber('rectification')->name('rectifications.show');
        Route::post('rectifications/{rectification}/sign', [RectificationController::class, 'sign'])->whereNumber('rectification')->middleware('throttle:20,1')->name('rectifications.sign');
        Route::post('rectifications/{rectification}/decline', [RectificationController::class, 'decline'])->whereNumber('rectification')->name('rectifications.decline');

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
        // Confirmación de lectura de comunicados importantes (B2).
        Route::post('announcements/{announcement}/read', [AnnouncementController::class, 'read'])->where('announcement', '[0-9a-fA-F-]{1,36}')->name('announcements.read');

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
