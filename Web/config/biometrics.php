<?php

/*
| Verificación facial 1:1 con modelos open source (OpenCV YuNet + SFace,
| MiniFASNet). El motor corre en el microservicio interno services/face.
| Ver docs/seguridad/BIOMETRIA.md.
*/

return [
    'service' => [
        // Sin URL o sin token → UnavailableFaceProvider (la modalidad no se ofrece).
        'url' => env('FACE_SERVICE_URL'),
        'token' => env('FACE_SERVICE_TOKEN'),
        'timeout' => (int) env('FACE_SERVICE_TIMEOUT', 15),
        'connect_timeout' => (int) env('FACE_SERVICE_CONNECT_TIMEOUT', 2),
        // Segundos que se recuerda el resultado de /health.
        'health_cache_seconds' => (int) env('FACE_SERVICE_HEALTH_CACHE', 30),
    ],

    // Umbrales aplicados por el servicio (FACE_MATCH_THRESHOLD, FACE_LIVENESS_THRESHOLD
    // en services/face/.env). Laravel sólo los informa y audita.

    'challenge' => [
        'ttl_seconds' => (int) env('FACE_CHALLENGE_TTL', 30),
        'steps' => 2,
    ],

    'verification' => [
        // Vigencia del id de verificación para marcar (PunchRecorder).
        'ttl_seconds' => (int) env('FACE_VERIFICATION_TTL', 120),
        'min_frames' => 6,
        'max_frames' => 10,
        'max_frame_kb' => 1500,
    ],

    'lockout' => [
        'max_failures' => (int) env('FACE_MAX_FAILURES', 5),
        'window_minutes' => (int) env('FACE_FAILURE_WINDOW', 15),
    ],

    'enrollment' => [
        'min_frames' => 3,
        'max_frames' => 10,
        // Código del documento de consentimiento (policy_documents.code).
        'consent_document_code' => 'BIOMETRIA',
    ],
];
