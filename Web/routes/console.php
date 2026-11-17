<?php

use App\Models\PunchChallenge;
use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas (requiere `* * * * * php artisan schedule:run` en cron).
| Ver docs/despliegue/DESPLIEGUE.md.
*/

// Cierre diario: ausencias, omisiones de salida, horas extra y alertas del día anterior.
Schedule::command('asistencias:cerrar-dia --dias=2')->dailyAt('01:15')->withoutOverlapping()->onOneServer();

// Re-evaluación intradía para detectar salidas omitidas de turnos nocturnos.
Schedule::command('asistencias:cerrar-dia --dias=1')->everyFourHours()->withoutOverlapping()->onOneServer();

// Cierre automático de jornadas que superaron su hora máxima de salida.
Schedule::command('asistencias:cierre-automatico')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Anclaje externo de las cadenas de integridad.
Schedule::command('asistencias:integridad anclar')->hourly()->withoutOverlapping()->onOneServer();

// Verificación completa de integridad (falla → revisar logs/alertas).
Schedule::command('asistencias:integridad verificar')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Notificaciones: comunicados programados (cada minuto) y avisos automáticos de asistencia (cada 5 min).
Schedule::command('asistencias:notificaciones --solo-comunicados')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('asistencias:notificaciones --solo-recordatorios')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Limpieza de desafíos de marcaje expirados y tokens vencidos.
Schedule::command('model:prune', ['--model' => [PunchChallenge::class]])->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
