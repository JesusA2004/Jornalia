<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicación en tiempo real (PDF pantalla 04):
 *  1. Parámetro por puesto: «Solo ubicación al marcar» (on_punch) o «Seguimiento durante la jornada» (during_shift).
 *  2. Depuración legal de recorridos (LFPDPPP: supresión al concluir la finalidad). ÚNICA excepción
 *     a la regla «nada se elimina»: el trigger de `location_points` sólo permite DELETE cuando la
 *     sesión de BD lo habilita explícitamente (`@jornalia_location_purge = 1`), lo que únicamente hace
 *     el comando programado `ubicacion:depurar`, que además deja constancia en la bitácora.
 *     UPDATE sigue prohibido siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('location_tracking', 16)->default('on_punch')->after('code')->comment('on_punch|during_shift');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS location_points_no_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER location_points_no_delete BEFORE DELETE ON location_points FOR EACH ROW
                BEGIN
                    IF @jornalia_location_purge IS NULL OR @jornalia_location_purge <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: location_points sólo admite la depuración legal programada';
                    END IF;
                END
                SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS location_points_no_delete');
            DB::unprepared("CREATE TRIGGER location_points_no_delete BEFORE DELETE ON location_points FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: location_points no admite DELETE'");
        }
        Schema::table('positions', fn (Blueprint $table) => $table->dropColumn('location_tracking'));
    }
};
