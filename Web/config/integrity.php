<?php

return [
    /*
    | Clave HMAC para firmar anclas de integridad. Debe ser distinta de APP_KEY y
    | custodiarse fuera del servidor de base de datos. Generar con:
    |   php -r "echo bin2hex(random_bytes(32));"
    */
    'anchor_key' => env('INTEGRITY_ANCHOR_KEY', ''),

    /*
    | Clave HMAC que sella cada eslabón de las cadenas de marcajes y ajustes.
    | Si no se define se usa APP_KEY. Respáldela: sin ella no puede verificarse
    | la cadena histórica.
    */
    // `?:` y no el valor por defecto de env(): una línea vacía `INTEGRITY_CHAIN_KEY=` (como en
    // .env.example) devuelve '' y no null, y sin esto la cadena quedaría sin clave en lugar de
    // usar APP_KEY. Para instalaciones que ya definen la clave o no tienen la línea, no cambia nada.
    'chain_key' => env('INTEGRITY_CHAIN_KEY') ?: env('APP_KEY', ''),

    /* Disco donde se escriben las anclas externas (ver config/filesystems.php). */
    'anchor_disk' => env('INTEGRITY_ANCHOR_DISK', 'anchors'),

    /* Vigencia del desafío de marcaje (segundos). */
    'challenge_ttl' => (int) env('PUNCH_CHALLENGE_TTL', 120),

    /* Vigencia del token de la app móvil (minutos). */
    'mobile_token_ttl' => (int) env('MOBILE_TOKEN_TTL', 60 * 24 * 14),

    /* Autorizar automáticamente el primer dispositivo de cada usuario. */
    'auto_approve_first_device' => (bool) env('DEVICE_AUTO_APPROVE_FIRST', false),

    /* Notificaciones push vía Expo (requiere EXPO_ACCESS_TOKEN opcional). */
    'expo_push_enabled' => (bool) env('EXPO_PUSH_ENABLED', false),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
];
