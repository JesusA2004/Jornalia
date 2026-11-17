<?php

use App\Http\Controllers\Web\Comunicados\AnnouncementController;
use Illuminate\Support\Facades\Route;

/*
| Comunicados a colaboradores (agente "Notificaciones").
| Se carga dentro del grupo auth + verified + organization (routes/web.php).
| Nunca se borran: se retiran y pueden reactivarse.
*/

Route::middleware('can:communications.view')->prefix('comunicados')->name('comunicados.')->group(function () {
    Route::get('/', [AnnouncementController::class, 'index'])->name('index');

    Route::middleware('can:communications.send')->group(function () {
        Route::get('nuevo', [AnnouncementController::class, 'create'])->name('create');
        Route::post('/', [AnnouncementController::class, 'store'])->name('store');
        Route::put('ajustes', [AnnouncementController::class, 'updateSettings'])->name('settings.update');
        Route::post('{announcement}/retirar', [AnnouncementController::class, 'withdraw'])->whereNumber('announcement')->name('withdraw');
        Route::post('{announcement}/reactivar', [AnnouncementController::class, 'reactivate'])->whereNumber('announcement')->name('reactivate');
    });

    Route::get('{announcement}', [AnnouncementController::class, 'show'])->whereNumber('announcement')->name('show');
});
