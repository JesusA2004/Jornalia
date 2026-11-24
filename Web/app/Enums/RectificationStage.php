<?php

namespace App\Enums;

/**
 * Etapa de una rectificación (PDF pantalla 05). El flujo es estrictamente lineal:
 * por_autorizar → por_firmar → por_validar → aplicada; cualquier etapa abierta
 * puede terminar en rechazada.
 */
enum RectificationStage: string
{
    case ToAuthorize = 'por_autorizar';
    case ToSign = 'por_firmar';
    case ToValidate = 'por_validar';
    case Applied = 'aplicada';
    case Rejected = 'rechazada';

    public function label(): string
    {
        return match ($this) {
            self::ToAuthorize => 'Por autorizar',
            self::ToSign => 'Por firmar colaborador',
            self::ToValidate => 'Por validar RH',
            self::Applied => 'Aplicada',
            self::Rejected => 'Rechazada',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::ToAuthorize, self::ToSign, self::ToValidate], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
