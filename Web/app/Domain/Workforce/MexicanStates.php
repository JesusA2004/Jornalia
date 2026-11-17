<?php

namespace App\Domain\Workforce;

/**
 * Entidades federativas (ISO 3166-2:MX) para festivos locales de centros de trabajo.
 */
final class MexicanStates
{
    public const ALL = [
        'MX-AGU' => 'Aguascalientes', 'MX-BCN' => 'Baja California', 'MX-BCS' => 'Baja California Sur', 'MX-CAM' => 'Campeche',
        'MX-CHP' => 'Chiapas', 'MX-CHH' => 'Chihuahua', 'MX-CMX' => 'Ciudad de México', 'MX-COA' => 'Coahuila',
        'MX-COL' => 'Colima', 'MX-DUR' => 'Durango', 'MX-GUA' => 'Guanajuato', 'MX-GRO' => 'Guerrero',
        'MX-HID' => 'Hidalgo', 'MX-JAL' => 'Jalisco', 'MX-MEX' => 'Estado de México', 'MX-MIC' => 'Michoacán',
        'MX-MOR' => 'Morelos', 'MX-NAY' => 'Nayarit', 'MX-NLE' => 'Nuevo León', 'MX-OAX' => 'Oaxaca',
        'MX-PUE' => 'Puebla', 'MX-QUE' => 'Querétaro', 'MX-ROO' => 'Quintana Roo', 'MX-SLP' => 'San Luis Potosí',
        'MX-SIN' => 'Sinaloa', 'MX-SON' => 'Sonora', 'MX-TAB' => 'Tabasco', 'MX-TAM' => 'Tamaulipas',
        'MX-TLA' => 'Tlaxcala', 'MX-VER' => 'Veracruz', 'MX-YUC' => 'Yucatán', 'MX-ZAC' => 'Zacatecas',
    ];

    /**
     * @return list<array{value:string, label:string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $code => $name) {
            $out[] = ['value' => $code, 'label' => $name];
        }
        usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Zonas horarias IANA de México.
     *
     * @return list<array{value:string, label:string}>
     */
    public static function timezones(): array
    {
        return array_map(fn (string $tz) => ['value' => $tz, 'label' => str_replace(['America/', '_'], ['', ' '], $tz)], \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'MX'));
    }
}
