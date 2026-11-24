<?php

namespace Database\Seeders\Testing\E2E;

/**
 * Plantilla FICTICIA del entorno E2E (listas fijas, sin Faker). Los correos
 * usan el dominio reservado `@e2e.test` para distinguirlos de datos reales.
 *
 * Claves:
 *  - branch: CUE | JIU | CUA | PUE | VER        - schedule: S48 | S44 | MIX | S40
 *  - position: ASE | CAJ | COB | AUX | COORD     - dept: VEN | OPE | COB | ADM
 *  - app: teléfono autorizado | pending: teléfono por autorizar | none: sin app | kiosk: marca en kiosco
 *  - agreement: firma el convenio en la app (true), en papel ('paper') o no (false)
 */
final class Roster
{
    /** Colaborador con nombre y puesto muy largos (cuenta `colaborador`). */
    public const LONG_NAME = 1;

    public const OVERTIME_HEAVY = 2;

    public const MINOR = 8;

    public const TERMINATED = 16;

    public const DEVICE_REPLACED = 19;

    public const DEVICE_CHANGE_PENDING = 20;

    public const CLOCK_ALTERED = 21;

    public const REST_DAY_WORKER = 24;

    public const OVERTIME_MODERATE = 26;

    public const OVERTIME_REJECTED = 28;

    public const MOCK_LOCATION = 29;

    public const OVERTIME_OVER_LIMIT = 30;

    public const NO_AGREEMENT = 33;

    public const OFFLINE_FIELD = 34;

    public const PENDING_DEVICE = 38;

    public const NO_ACCOUNT = 39;

    public const PAPER_AGREEMENT = 40;

    /** Trabajan el día de descanso obligatorio del periodo. */
    public const HOLIDAY_WORKERS = [9, 26];

    /** Seguimiento de ubicación durante la jornada (mapa en tiempo real). */
    public const TRACKED = [4, 10, 17, 23, 31];

    /** Registran descanso de comida (inicio y fin). */
    public const BREAKS = [1, 3, 6, 12, 18, 22, 27, 36];

    /**
     * @return array<int, array{first:string, last:string, second:?string, branch:string, dept:string, position:string, schedule:string, app:string, agreement:bool|string, hired:string, birth:string}>
     */
    public static function all(): array
    {
        return [
            1 => ['first' => 'María de los Ángeles Guadalupe', 'last' => 'Hernández-Villaseñor', 'second' => 'de la Concepción', 'branch' => 'CUE', 'dept' => 'OPE', 'position' => 'COORD', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2019-03-04', 'birth' => '1986-08-15'],
            2 => ['first' => 'José Antonio', 'last' => 'Ramírez', 'second' => 'Gutiérrez', 'branch' => 'CUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2021-06-14', 'birth' => '1990-02-11'],
            3 => ['first' => 'Ana Karen', 'last' => 'López', 'second' => 'Morales', 'branch' => 'CUE', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2022-01-10', 'birth' => '1995-11-03'],
            4 => ['first' => 'Luis Fernando', 'last' => 'Castillo', 'second' => 'Pérez', 'branch' => 'CUE', 'dept' => 'COB', 'position' => 'COB', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2020-09-01', 'birth' => '1988-04-22'],
            5 => ['first' => 'Gabriela', 'last' => 'Torres', 'second' => 'Medina', 'branch' => 'CUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2022-08-15', 'birth' => '1993-07-30'],
            6 => ['first' => 'Ricardo', 'last' => 'Flores', 'second' => 'Navarro', 'branch' => 'CUE', 'dept' => 'ADM', 'position' => 'AUX', 'schedule' => 'S40', 'app' => 'app', 'agreement' => true, 'hired' => '2023-02-20', 'birth' => '1997-01-19'],
            7 => ['first' => 'Daniela', 'last' => 'Cruz', 'second' => 'Vázquez', 'branch' => 'CUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2021-11-08', 'birth' => '1992-05-27'],
            8 => ['first' => 'Diego Alonso', 'last' => 'Ramírez', 'second' => 'Soto', 'branch' => 'CUE', 'dept' => 'ADM', 'position' => 'AUX', 'schedule' => 'S40', 'app' => 'app', 'agreement' => true, 'hired' => 'rel:-170', 'birth' => 'rel-years:-17'],
            9 => ['first' => 'Sofía', 'last' => 'Jiménez', 'second' => 'Rojas', 'branch' => 'CUE', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'MIX', 'app' => 'app', 'agreement' => true, 'hired' => '2020-02-03', 'birth' => '1991-09-09'],
            10 => ['first' => 'Héctor', 'last' => 'Aguilar', 'second' => 'Domínguez', 'branch' => 'CUE', 'dept' => 'COB', 'position' => 'COB', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2018-07-16', 'birth' => '1984-12-01'],
            11 => ['first' => 'Fernanda', 'last' => 'Reyes', 'second' => 'Salazar', 'branch' => 'JIU', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2021-04-12', 'birth' => '1994-03-18'],
            12 => ['first' => 'Miguel Ángel', 'last' => 'Ortiz', 'second' => 'Herrera', 'branch' => 'JIU', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2022-10-03', 'birth' => '1989-06-25'],
            13 => ['first' => 'Patricia', 'last' => 'Mendoza', 'second' => 'Ríos', 'branch' => 'JIU', 'dept' => 'ADM', 'position' => 'AUX', 'schedule' => 'S40', 'app' => 'kiosk', 'agreement' => true, 'hired' => '2019-08-19', 'birth' => '1979-10-10'],
            14 => ['first' => 'Roberto Carlos', 'last' => 'Guzmán', 'second' => 'Luna', 'branch' => 'JIU', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'kiosk', 'agreement' => true, 'hired' => '2023-05-08', 'birth' => '1983-02-14'],
            15 => ['first' => 'Alejandra', 'last' => 'Vargas', 'second' => 'Ibarra', 'branch' => 'JIU', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'MIX', 'app' => 'kiosk', 'agreement' => true, 'hired' => '2024-01-15', 'birth' => '1998-12-24'],
            16 => ['first' => 'Jorge Luis', 'last' => 'Méndez', 'second' => 'Ortega', 'branch' => 'JIU', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2022-03-07', 'birth' => '1990-08-08'],
            17 => ['first' => 'Claudia', 'last' => 'Ruiz', 'second' => 'Peña', 'branch' => 'JIU', 'dept' => 'COB', 'position' => 'COB', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2021-09-20', 'birth' => '1987-01-29'],
            18 => ['first' => 'Óscar', 'last' => 'Núñez', 'second' => 'Cabrera', 'branch' => 'JIU', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2023-07-03', 'birth' => '1996-04-04'],
            19 => ['first' => 'Verónica', 'last' => 'Delgado', 'second' => 'Fuentes', 'branch' => 'CUA', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2020-05-11', 'birth' => '1985-07-07'],
            20 => ['first' => 'Arturo', 'last' => 'Sandoval', 'second' => 'Campos', 'branch' => 'CUA', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2022-12-05', 'birth' => '1993-10-16'],
            21 => ['first' => 'Mariana', 'last' => 'Estrada', 'second' => 'Valdez', 'branch' => 'CUA', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2021-02-22', 'birth' => '1994-09-02'],
            22 => ['first' => 'Eduardo', 'last' => 'Ponce', 'second' => 'Arellano', 'branch' => 'CUA', 'dept' => 'ADM', 'position' => 'AUX', 'schedule' => 'S40', 'app' => 'app', 'agreement' => true, 'hired' => '2023-09-11', 'birth' => '1999-03-30'],
            23 => ['first' => 'Lucía', 'last' => 'Cervantes', 'second' => 'Montes', 'branch' => 'CUA', 'dept' => 'COB', 'position' => 'COB', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2020-10-26', 'birth' => '1989-11-21'],
            24 => ['first' => 'Raúl', 'last' => 'Miranda', 'second' => 'Zamora', 'branch' => 'CUA', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'MIX', 'app' => 'app', 'agreement' => true, 'hired' => '2022-06-27', 'birth' => '1991-05-05'],
            25 => ['first' => 'Paola', 'last' => 'Salinas', 'second' => 'Bautista', 'branch' => 'CUA', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2024-04-01', 'birth' => '2000-01-12'],
            26 => ['first' => 'Francisco Javier', 'last' => 'Rosales', 'second' => 'Acosta', 'branch' => 'CUA', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2019-11-18', 'birth' => '1982-06-17'],
            27 => ['first' => 'Itzel', 'last' => 'Gallegos', 'second' => 'Quintero', 'branch' => 'PUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2021-08-02', 'birth' => '1995-02-28'],
            28 => ['first' => 'Tomás', 'last' => 'Ibarra', 'second' => 'Velasco', 'branch' => 'PUE', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2022-04-18', 'birth' => '1988-08-31'],
            29 => ['first' => 'Andrea', 'last' => 'Serrano', 'second' => 'Lozano', 'branch' => 'PUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S40', 'app' => 'app', 'agreement' => true, 'hired' => '2023-03-13', 'birth' => '1997-12-09'],
            30 => ['first' => 'Emilio', 'last' => 'Cortés', 'second' => 'Figueroa', 'branch' => 'PUE', 'dept' => 'ADM', 'position' => 'AUX', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2020-01-20', 'birth' => '1986-03-03'],
            31 => ['first' => 'Natalia', 'last' => 'Pacheco', 'second' => 'Cisneros', 'branch' => 'PUE', 'dept' => 'COB', 'position' => 'COB', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2021-10-11', 'birth' => '1992-10-14'],
            32 => ['first' => 'Iván', 'last' => 'Marín', 'second' => 'Esquivel', 'branch' => 'PUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'MIX', 'app' => 'app', 'agreement' => true, 'hired' => '2023-06-05', 'birth' => '1998-07-21'],
            33 => ['first' => 'Rebeca', 'last' => 'Olvera', 'second' => 'Treviño', 'branch' => 'PUE', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S44', 'app' => 'app', 'agreement' => false, 'hired' => 'rel:-15', 'birth' => '2001-04-26'],
            34 => ['first' => 'Julio César', 'last' => 'Contreras', 'second' => 'Bravo', 'branch' => 'VER', 'dept' => 'COB', 'position' => 'COB', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2020-06-15', 'birth' => '1987-09-19'],
            35 => ['first' => 'Karla', 'last' => 'Lara', 'second' => 'Espinoza', 'branch' => 'VER', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'app', 'agreement' => true, 'hired' => '2022-02-14', 'birth' => '1994-01-06'],
            36 => ['first' => 'Samuel', 'last' => 'Villarreal', 'second' => 'Ochoa', 'branch' => 'VER', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'app', 'agreement' => true, 'hired' => '2021-07-05', 'birth' => '1990-12-12'],
            37 => ['first' => 'Diana', 'last' => 'Cárdenas', 'second' => 'Pineda', 'branch' => 'VER', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S40', 'app' => 'app', 'agreement' => true, 'hired' => '2023-01-09', 'birth' => '1996-06-01'],
            38 => ['first' => 'Adrián', 'last' => 'Molina', 'second' => 'Tapia', 'branch' => 'VER', 'dept' => 'VEN', 'position' => 'ASE', 'schedule' => 'S48', 'app' => 'pending', 'agreement' => true, 'hired' => 'rel:-8', 'birth' => '1999-08-18'],
            39 => ['first' => 'Brenda', 'last' => 'Ochoa', 'second' => 'Castañeda', 'branch' => 'VER', 'dept' => 'OPE', 'position' => 'CAJ', 'schedule' => 'S44', 'app' => 'none', 'agreement' => false, 'hired' => 'rel:-6', 'birth' => '2002-02-02'],
            40 => ['first' => 'Gerardo', 'last' => 'Fuentes', 'second' => 'Saldaña', 'branch' => 'CUE', 'dept' => 'ADM', 'position' => 'AUX', 'schedule' => 'S40', 'app' => 'app', 'agreement' => 'paper', 'hired' => '2018-01-08', 'birth' => '1978-05-23'],
        ];
    }

    /**
     * Correo de prueba (sin acentos) en el dominio reservado `e2e.test`.
     *
     * @param  array<string, mixed>  $person  fila de `all()`
     */
    public static function email(array $person, int $index): string
    {
        $first = strtok($person['first'], ' ');
        $slug = fn (string $v) => strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v)));

        return $slug((string) $first).'.'.$slug($person['last']).sprintf('%02d', $index).'@e2e.test';
    }
}
