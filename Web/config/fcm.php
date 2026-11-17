<?php

/*
| Firebase Cloud Messaging (HTTP v1) para avisos push a la app móvil.
| La cuenta de servicio es un SECRETO: vive fuera del repositorio y de public/.
| Sin interruptor, proyecto o credencial válida, el push queda deshabilitado
| (estado honesto); la bandeja interna (GET /notifications) sigue funcionando.
| Ver docs/notificaciones/PUSH.md.
*/

return [
    'enabled' => (bool) env('FCM_HABILITADO', false),
    'project_id' => env('FCM_PROJECT_ID'),
    // Ruta absoluta al JSON de la cuenta de servicio (fuera del repo), o su contenido inyectado.
    'credentials_path' => env('FCM_CREDENCIALES_RUTA'),
    'credentials_json' => env('FCM_CREDENCIALES_JSON'),
    // Presupuesto total de un envío y límite de conexión (ms).
    'budget_ms' => (int) env('FCM_PRESUPUESTO_MS', 8000),
    'connect_ms' => (int) env('FCM_CONEXION_MS', 2000),
    // Bundle CA opcional (PEM). Nunca desactiva la verificación TLS.
    'ca' => env('FCM_CA'),
    // Canal Android de alta importancia creado por la app.
    'android_channel_id' => env('FCM_CANAL_ANDROID', 'avisos'),
];
