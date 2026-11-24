<?php

return [
    /*
    | Doble factor obligatorio para roles administrativos (PDF pantalla 18).
    | Sólo debe desactivarse en entornos sin usuarios reales.
    */
    'admin_two_factor' => (bool) env('SECURITY_ADMIN_TWO_FACTOR', true),

    /*
    | Revisión periódica de usuarios activos: días sin inicio de sesión a partir
    | de los cuales un usuario se marca para revisión.
    */
    'inactive_after_days' => (int) env('SECURITY_INACTIVE_AFTER_DAYS', 90),
];
