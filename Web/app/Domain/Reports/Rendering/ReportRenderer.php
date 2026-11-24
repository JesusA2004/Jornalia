<?php

namespace App\Domain\Reports\Rendering;

use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Layouts\ReportLayout;

/**
 * Escribe un dataset en un formato de archivo (pdf, xlsx, csv). Todos los
 * renderizadores consumen el mismo dataset y respetan el layout asignado.
 */
interface ReportRenderer
{
    public function render(ReportDataset $dataset, ReportLayout $layout, string $path): void;
}
