<?php

namespace Database\Seeders;

use App\Domain\Legal\HolidayGenerator;
use App\Domain\Legal\LegalFramework;
use App\Models\LegalVersion;
use App\Models\VacationEntitlementRule;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Siembra la normativa versionada desde `database/data/legal/parametros_legales.json`
 * (investigada en fuentes primarias, ver docs/legal/MATRIZ_CUMPLIMIENTO.md).
 * Idempotente: actualiza parámetros sólo si cambió el archivo fuente.
 */
final class LegalFrameworkSeeder extends Seeder
{
    public function run(HolidayGenerator $holidays): void
    {
        $path = database_path('data/legal/parametros_legales.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || empty($data['versions'])) {
            throw new RuntimeException('parametros_legales.json inválido.');
        }

        foreach ($data['versions'] as $v) {
            $parameters = array_diff_key($v, array_flip(['code', 'name', 'valid_from', 'valid_to', 'verification_status', 'sources', 'notes']));
            LegalVersion::query()->updateOrCreate(['code' => $v['code']], [
                'name' => $v['name'],
                'valid_from' => $v['valid_from'],
                'valid_to' => $v['valid_to'],
                'verification_status' => $v['verification_status'],
                'parameters' => $parameters,
                'sources' => $v['sources'] ?? [],
                'notes' => $v['notes'] ?? null,
                'checksum' => LegalFramework::checksum($parameters),
            ]);
        }

        foreach ($data['vacation_table'] as $row) {
            VacationEntitlementRule::query()->updateOrCreate(
                ['code' => 'LFT-VAC-2023', 'from_year' => $row['from_year']],
                [
                    'valid_from' => $data['vacation_table_valid_from'] ?? '2023-01-01',
                    'valid_to' => null,
                    'to_year' => $row['to_year'],
                    'days' => $row['days'],
                    'source' => 'LFT art. 76 (DOF 27-12-2022)',
                ],
            );
        }

        $from = (int) date('Y') - 3;
        $holidays->generate($data['holidays'], $from, $from + 8);
    }
}
