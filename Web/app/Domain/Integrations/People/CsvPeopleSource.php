<?php

namespace App\Domain\Integrations\People;

/**
 * Lee un CSV (UTF-8 o Windows-1252, separador coma o punto y coma) con
 * encabezados en español o inglés. Columnas mínimas: numero_empleado,
 * nombre, apellido_paterno, fecha_ingreso.
 */
final class CsvPeopleSource implements PeopleSource
{
    /** @var array<string, list<string>> */
    public const ALIASES = [
        'employee_number' => ['numero_empleado', 'no_empleado', 'num_empleado', 'employee_number', 'numero'],
        'first_name' => ['nombre', 'nombres', 'first_name'],
        'last_name' => ['apellido_paterno', 'primer_apellido', 'last_name', 'apellido'],
        'second_last_name' => ['apellido_materno', 'segundo_apellido', 'second_last_name'],
        'email' => ['correo', 'correo_electronico', 'email'],
        'phone' => ['telefono', 'celular', 'phone'],
        'birth_date' => ['fecha_nacimiento', 'birth_date'],
        'curp' => ['curp'],
        'rfc' => ['rfc'],
        'nss' => ['nss', 'numero_seguridad_social'],
        'starts_on' => ['fecha_ingreso', 'fecha_alta', 'starts_on', 'hired_on'],
        'branch_code' => ['sucursal', 'clave_sucursal', 'branch_code', 'branch'],
        'department_code' => ['departamento', 'clave_departamento', 'department_code', 'department'],
        'position_code' => ['puesto', 'clave_puesto', 'position_code', 'position'],
        'schedule_code' => ['horario', 'clave_horario', 'schedule_code', 'schedule'],
        'contract_type' => ['tipo_contrato', 'contrato', 'contract_type'],
        'work_modality' => ['modalidad', 'work_modality'],
        'external_id' => ['id_externo', 'id_people', 'external_id'],
    ];

    public const MAX_ROWS = 5000;

    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'csv';
    }

    public function externalSource(): string
    {
        return 'people';
    }

    public function isAvailable(): bool
    {
        return is_readable($this->path);
    }

    public function records(): iterable
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new PeopleSourceException('No se pudo leer el archivo.');
        }

        try {
            $first = fgets($handle);
            if ($first === false || trim($first) === '') {
                throw new PeopleSourceException('El archivo está vacío.');
            }
            $first = (string) preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), str_getcsv(trim($first), $delimiter, '"', ''));
            $map = $this->mapHeaders($headers);

            foreach (['employee_number', 'first_name', 'last_name', 'starts_on'] as $required) {
                if (! in_array($required, $map, true)) {
                    throw new PeopleSourceException('Falta la columna obligatoria «'.self::ALIASES[$required][0].'».');
                }
            }

            $line = 1;
            $count = 0;
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if (trim(implode('', array_map(fn ($c) => (string) $c, $row))) === '') {
                    continue;
                }
                if (++$count > self::MAX_ROWS) {
                    throw new PeopleSourceException('El archivo supera el máximo de '.self::MAX_ROWS.' filas.');
                }
                $assoc = [];
                foreach ($map as $index => $key) {
                    if ($key !== null) {
                        $value = $row[$index] ?? null;
                        $assoc[$key] = $value === null ? null : $this->toUtf8((string) $value);
                    }
                }
                yield PersonRecord::fromArray($line, $assoc);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array<int, string|null>
     */
    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $header) {
            $map[$i] = null;
            foreach (self::ALIASES as $key => $aliases) {
                if (in_array($header, $aliases, true)) {
                    $map[$i] = $key;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($this->toUtf8($header)));
        $header = strtr($header, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', '.' => '', 'º' => '', '°' => '']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }

    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
