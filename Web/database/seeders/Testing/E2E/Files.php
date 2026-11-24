<?php

namespace Database\Seeders\Testing\E2E;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Archivos pequeños y VÁLIDOS (PNG y PDF) para evidencias y documentos del
 * entorno E2E. Se generan en `storage/app/e2e/tmp` y se entregan como
 * `UploadedFile` de prueba para pasar por el mismo camino que una carga real
 * (validación de tipo MIME por contenido, hash SHA-256 y almacenamiento).
 */
final class Files
{
    private static ?string $dir = null;

    public static function directory(): string
    {
        if (self::$dir === null) {
            self::$dir = storage_path('app/e2e/tmp');
            if (! is_dir(self::$dir) && ! mkdir(self::$dir, 0775, true) && ! is_dir(self::$dir)) {
                throw new RuntimeException('No se pudo crear '.self::$dir);
            }
        }

        return self::$dir;
    }

    /**
     * Imagen PNG con un texto (p. ej. «Evidencia de prueba E2E»).
     */
    public static function png(string $name, string $caption): UploadedFile
    {
        $path = self::directory().DIRECTORY_SEPARATOR.self::safe($name, 'png');
        $img = function_exists('imagecreatetruecolor') ? imagecreatetruecolor(360, 160) : false;
        $bg = $img !== false ? imagecolorallocate($img, 245, 241, 232) : false;
        $fg = $img !== false ? imagecolorallocate($img, 34, 92, 84) : false;
        $gold = $img !== false ? imagecolorallocate($img, 175, 139, 81) : false;
        if ($img !== false && $bg !== false && $fg !== false && $gold !== false) {
            imagefilledrectangle($img, 0, 0, 359, 159, $bg);
            imagerectangle($img, 4, 4, 355, 155, $gold);
            imagestring($img, 5, 16, 24, 'Evidencia de prueba (E2E)', $fg);
            imagestring($img, 3, 16, 64, self::ascii(mb_substr($caption, 0, 48)), $fg);
            imagestring($img, 2, 16, 120, 'Documento ficticio: no es evidencia real.', $fg);
            imagepng($img, $path);
            imagedestroy($img);
        } else {
            // PNG mínimo válido (1×1) si GD no está disponible o falla.
            file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        }

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * PDF de una página con líneas de texto (estructura y tabla xref válidas).
     *
     * @param  list<string>  $lines
     */
    public static function pdf(string $name, string $title, array $lines = []): UploadedFile
    {
        $path = self::directory().DIRECTORY_SEPARATOR.self::safe($name, 'pdf');
        file_put_contents($path, self::pdfBytes($title, $lines));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * @param  list<string>  $lines
     */
    public static function pdfBytes(string $title, array $lines): string
    {
        $text = 'BT /F1 16 Tf 56 760 Td ('.self::pdfEscape($title).") Tj ET\n";
        $y = 730;
        foreach (['Documento de prueba para el entorno E2E. No es un documento oficial.', ...$lines] as $line) {
            $text .= 'BT /F1 11 Tf 56 '.$y.' Td ('.self::pdfEscape($line).") Tj ET\n";
            $y -= 18;
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($text)." >>\nstream\n".$text.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function pdfEscape(string $text): string
    {
        $latin = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin);
    }

    private static function ascii(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '' : $ascii;
    }

    private static function safe(string $name, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'archivo';

        return $base.'-'.bin2hex(random_bytes(4)).'.'.$extension;
    }
}
