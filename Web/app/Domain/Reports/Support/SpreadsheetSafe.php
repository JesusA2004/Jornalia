<?php

namespace App\Domain\Reports\Support;

/**
 * Prevención de inyección de fórmulas (CSV/Excel injection, OWASP): todo texto
 * que inicie con =, +, -, @, tabulador o retorno de carro se antepone con un
 * apóstrofo para que la hoja de cálculo lo trate como texto literal.
 */
final class SpreadsheetSafe
{
    public static function text(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
