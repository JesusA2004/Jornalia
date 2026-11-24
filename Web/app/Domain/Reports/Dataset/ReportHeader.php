<?php

namespace App\Domain\Reports\Dataset;

/**
 * Encabezado institucional común a todos los reportes y formatos.
 */
final readonly class ReportHeader
{
    public function __construct(
        public string $title,
        public string $description,
        public string $organizationName,
        public string $legalName,
        public ?string $rfc,
        public string $workCenter,
        public string $period,
        public string $generatedAt,
        public string $timezone,
        public string $generatedBy,
        public ?string $generatedByEmail,
        public ?string $folio,
        public string $filters,
        public ?string $observations,
        public ?string $logoPath,
    ) {}

    /** Logotipo como data URI para el PDF (null si no hay archivo legible). */
    public function logoDataUri(): ?string
    {
        if ($this->logoPath === null || ! is_file($this->logoPath)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($this->logoPath));
    }

    /**
     * Líneas (etiqueta, valor) para formatos tabulares.
     *
     * @return list<array{0:string,1:string}>
     */
    public function lines(): array
    {
        return [
            ['Reporte', $this->title],
            ['Razón social', $this->legalName],
            ['RFC', $this->rfc ?? 'Sin capturar'],
            ['Centro de trabajo', $this->workCenter],
            ['Periodo', $this->period],
            ['Fecha y hora de generación', $this->generatedAt.' ('.$this->timezone.')'],
            ['Generado por', $this->generatedBy.($this->generatedByEmail ? ' <'.$this->generatedByEmail.'>' : '')],
            ['Folio', $this->folio ?? 'Vista previa'],
            ['Filtros', $this->filters !== '' ? $this->filters : 'Sin filtros adicionales'],
        ];
    }
}
