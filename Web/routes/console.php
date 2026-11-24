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

// Ningún proceso programado crea marcajes: el cierre de día sólo recalcula y alerta omisiones.

// Precarga idempotente de días de descanso obligatorio (art. 74 LFT) del año actual y siguiente.
Schedule::command('asistencias:festivos')->monthlyOn(1, '00:30')->withoutOverlapping()->onOneServer();

// Anclaje externo de las cadenas de integridad.
Schedule::command('asistencias:integridad anclar')->hourly()->withoutOverlapping()->onOneServer();

// Rutina diaria automática: anclaje externo + verificación completa. Si falla → alerta
// crítica en Cumplimiento y aviso a RH y Sistemas (IntegrityMonitor).
Schedule::command('asistencias:integridad diaria')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Depuración legal de recorridos de ubicación vencidos (LFPDPPP; plazo en Configuración › Ubicación).
Schedule::command('ubicacion:depurar')->dailyAt('02:45')->withoutOverlapping()->onOneServer();

// Omisiones de checada sin rectificar por más de 2 días → alerta en Cumplimiento.
Schedule::command('cumplimiento:omisiones')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

// Notificaciones: comunicados programados (cada minuto) y avisos automáticos de asistencia (cada 5 min).
Schedule::command('asistencias:notificaciones --solo-comunicados')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('asistencias:notificaciones --solo-recordatorios')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Rectificaciones con plazo de firma vencido → rechazo automático (el día queda como omisión).
Schedule::command('rectificaciones:vencer')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Incidencias aprobadas en Mr. Lana People (sólo si la integración está configurada).
Schedule::command('people:incidencias')->hourly()->withoutOverlapping()->onOneServer();

// Bajas con fecha programada: revoca acceso a la app y dispositivos el día de la baja.
Schedule::command('asistencias:revocar-bajas')->hourly()->withoutOverlapping()->onOneServer();

// Accesos: vence autoridad laboral y abre la revisión trimestral de usuarios (idempotente).
Schedule::command('asistencias:revision-usuarios')->dailyAt('02:10')->withoutOverlapping()->onOneServer();

// Limpieza de desafíos de marcaje expirados y tokens vencidos.
Schedule::command('model:prune', ['--model' => [PunchChallenge::class]])->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
