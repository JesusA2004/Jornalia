<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas web. Cada módulo vive en su propio archivo bajo routes/web/ para que
| los equipos trabajen sin conflictos. Todas requieren sesión, correo
| verificado y organización activa (aislamiento multiempresa).
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    foreach (glob(__DIR__.'/web/*.php') ?: [] as $file) {
        require $file;
    }
});

require __DIR__.'/settings.php';
