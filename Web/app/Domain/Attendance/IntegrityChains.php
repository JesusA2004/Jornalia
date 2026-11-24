<?php

namespace App\Domain\Attendance;

use App\Models\AttendanceAdjustment;
use App\Models\ElectronicSignature;
use App\Models\Punch;
use InvalidArgumentException;

/**
 * Catálogo único de las cadenas de integridad append-only de una organización.
 *
 * Cada cadena es una tabla inmutable sellada con `HashChain` (secuencia,
 * hash anterior y HMAC). La verificación (`IntegrityVerifier`), el anclaje
 * externo diario (`IntegrityAnchorService`), el monitor de Cumplimiento y el
 * reporte de evidencia recorren esta lista, de modo que agregar una cadena
 * nueva la incluye en todos ellos a la vez.
 */
final class IntegrityChains
{
    /** Marcajes originales del colaborador (nunca se editan ni borran). */
    public const PUNCHES = 'punches';

    /** Ajustes generados por rectificaciones validadas por RH. */
    public const ADJUSTMENTS = 'adjustments';

    /** Firmas electrónicas (NIP o biometría) de rectificaciones, convenio y aviso. */
    public const SIGNATURES = 'signatures';

    /** @var array<string, class-string<Punch|AttendanceAdjustment|ElectronicSignature>> */
    public const MODELS = [
        self::PUNCHES => Punch::class,
        self::ADJUSTMENTS => AttendanceAdjustment::class,
        self::SIGNATURES => ElectronicSignature::class,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::PUNCHES => 'Marcajes originales',
        self::ADJUSTMENTS => 'Ajustes por rectificación',
        self::SIGNATURES => 'Firmas electrónicas',
    ];

    /** @var array<string, string> */
    public const SHORT_LABELS = [
        self::PUNCHES => 'Marcajes',
        self::ADJUSTMENTS => 'Ajustes',
        self::SIGNATURES => 'Firmas',
    ];

    /**
     * Columna con el instante (UTC) en que se selló cada eslabón.
     *
     * @var array<string, string>
     */
    public const RECORDED_AT = [
        self::PUNCHES => 'created_at',
        self::ADJUSTMENTS => 'created_at',
        self::SIGNATURES => 'signed_at',
    ];

    /**
     * Modelo Eloquent de la cadena.
     *
     * @return class-string<Punch|AttendanceAdjustment|ElectronicSignature>
     *
     * @throws InvalidArgumentException si la cadena no existe
     */
    public static function model(string $chain): string
    {
        return self::MODELS[$chain] ?? throw new InvalidArgumentException("Cadena de integridad desconocida: {$chain}");
    }

    public static function label(string $chain): string
    {
        return self::LABELS[$chain] ?? $chain;
    }

    public static function shortLabel(string $chain): string
    {
        return self::SHORT_LABELS[$chain] ?? $chain;
    }
}
