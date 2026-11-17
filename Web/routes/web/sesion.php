<?php

use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OrganizationSwitchController;
use Illuminate\Support\Facades\Route;

/*
| Sesión del usuario: organización activa y bandeja de notificaciones.
| Se carga dentro del grupo auth + verified + organization (routes/web.php).
*/

Route::put('organizacion/activa', OrganizationSwitchController::class)->name('organizacion.switch');

Route::prefix('notificaciones')->name('notificaciones.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('leer-todas', [NotificationController::class, 'markAllAsRead'])->name('read-all');
    Route::patch('{notification}/leida', [NotificationController::class, 'markAsRead'])
        ->whereUuid('notification')
        ->name('read');
});
