<?php

use App\Http\Controllers\Web\Biometrics\FaceEnrollmentController;
use Illuminate\Support\Facades\Route;

/*
| Registro facial (agente Biometría). Se carga dentro del grupo auth + verified
| + organization (routes/web.php). Permiso: employees.update (EmployeePolicy).
| Sin rutas DELETE: revocación y cancelación ARCO son POST auditados.
*/

Route::prefix('colaboradores/{employee}/rostro')
    ->whereNumber('employee')
    ->name('biometria.rostro.')
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::get('/', [FaceEnrollmentController::class, 'show'])->name('show');
        Route::post('/', [FaceEnrollmentController::class, 'store'])->name('store');
        Route::post('{enrollment}/revocar', [FaceEnrollmentController::class, 'revoke'])->whereNumber('enrollment')->name('revoke');
        Route::post('{enrollment}/cancelar', [FaceEnrollmentController::class, 'erase'])->whereNumber('enrollment')->name('erase');
    });
