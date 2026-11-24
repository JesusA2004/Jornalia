<?php

namespace Tests\Feature\Api\Concerns;

use PHPUnit\Framework\Assert;

/**
 * Espejo en PHP de las interfaces de packages/contracts/src/api.ts.
 *
 * Tipos: int, number, string, bool, datetime (ISO UTC con Z), date (YYYY-MM-DD),
 * uuid, enum:a|b, list:<forma o tipo>, array (mapa libre), o una forma anidada.
 * Prefijo `?` = admite null. Las claves deben coincidir EXACTAMENTE.
 */
final class ContractShapes
{
    private const PUNCH_TYPES = 'clock_in|clock_out|break_start|break_end';

    private const APPROVAL = 'pending|approved|rejected|cancelled';

    private const WORKDAY_STATUS = 'not_started|working|on_break|finished|rest_day|holiday|on_leave|absent|incomplete';

    private const AUTH_METHODS = 'device_biometric|device_credential|face_verification|web_session|system';

    private const ANOMALIES = 'clock_skew|late_sync|mocked_location|outside_geofence|low_accuracy|no_location|out_of_sequence|outside_schedule|rest_day|holiday|offline_not_allowed';

    /**
     * @return array<string, mixed>
     */
    public static function shapes(): array
    {
        $device = [
            'id' => 'int', 'device_uuid' => 'uuid', 'name' => 'string', 'platform' => 'enum:android|ios|web', 'model' => '?string',
            'status' => 'enum:pending|approved|revoked', 'pending_reason' => '?enum:device_change|new_device|relink', 'approved_at' => '?datetime', 'revoked_at' => '?datetime',
            'last_seen_at' => '?datetime', 'is_current' => 'bool',
        ];
        $ackDoc = ['id' => 'int', 'code' => 'string', 'title' => 'string', 'version' => 'string', 'body_markdown' => 'string', 'published_at' => 'datetime'];
        $profile = [
            'user' => ['id' => 'int', 'name' => 'string', 'email' => 'string'],
            'organization' => ['id' => 'int', 'name' => 'string', 'timezone' => 'string'],
            'employee' => ['?', [
                'id' => 'int', 'employee_number' => 'string', 'full_name' => 'string', 'first_name' => 'string',
                'position' => '?string', 'department' => '?string', 'branch' => '?string', 'work_center' => '?string',
                'hire_date' => 'date', 'supervisor' => '?string', 'timezone' => 'string',
            ]],
            'pending_acknowledgements' => ['list', $ackDoc],
            'signature_pin_set' => 'bool',
        ];
        $location = ['latitude' => 'number', 'longitude' => 'number', 'accuracy_meters' => '?number', 'captured_at' => 'datetime', 'is_mocked' => '?bool'];
        $punch = [
            'uuid' => 'uuid', 'type' => 'enum:'.self::PUNCH_TYPES, 'status' => 'enum:accepted|flagged',
            'source' => 'enum:mobile|mobile_offline|web|kiosk|import|system', 'server_time' => 'datetime', 'device_time' => '?datetime', 'received_at' => '?datetime', 'synced_at' => '?datetime',
            'clock_skew_seconds' => '?int', 'timezone' => 'string', 'auth_method' => 'enum:'.self::AUTH_METHODS,
            'captured_offline' => 'bool', 'anomalies' => 'list:enum:'.self::ANOMALIES, 'location' => ['?', $location],
            'hash' => 'string', 'sequence' => 'int', 'rectified' => 'bool',
        ];
        $shift = [
            'date' => 'date', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'kind' => 'enum:day|night|mixed',
            'break_minutes' => 'int', 'tolerance_minutes' => 'int', 'schedule_name' => 'string',
        ];
        $incidenceSummary = ['id' => 'int', 'type_code' => 'string', 'type_name' => 'string', 'status' => 'enum:'.self::APPROVAL];
        $workday = [
            'date' => 'date', 'status' => 'enum:'.self::WORKDAY_STATUS, 'shift' => ['?', $shift], 'punches' => ['list', $punch],
            'next_expected' => '?enum:'.self::PUNCH_TYPES, 'worked_minutes' => 'int', 'break_minutes' => 'int',
            'late_minutes' => 'int', 'excess_minutes' => 'int', 'auto_clock_out_at' => '?datetime', 'after_cutoff_minutes' => 'int',
            'incidences' => ['list', $incidenceSummary],
        ];
        $geofence = ['id' => 'int', 'name' => 'string', 'latitude' => 'number', 'longitude' => 'number', 'radius_meters' => 'int'];
        $policy = [
            'location_mode' => 'enum:free|record|required|geofence|device_only', 'allowed_auth_methods' => 'list:enum:'.self::AUTH_METHODS,
            'require_strong_biometric' => 'bool', 'allow_offline' => 'bool', 'offline_max_hours' => 'int',
            'geofences' => ['list', $geofence], 'face_verification_enabled' => 'bool',
            'location_tracking' => 'enum:on_punch|during_shift', 'tracking_paused_states' => 'list:enum:break|meal',
            'location_contingency_minutes' => ['optional', 'int'],
        ];
        $week = [
            'week_start' => 'date', 'worked_minutes' => 'int', 'scheduled_minutes' => 'int', 'legal_max_minutes' => 'int', 'overtime_minutes' => 'int',
            'days' => ['list', ['date' => 'date', 'status' => 'enum:'.self::WORKDAY_STATUS, 'worked_minutes' => 'int']],
        ];
        $rectification = [
            'id' => 'int', 'date' => 'date', 'punch_uuid' => '?uuid', 'kind' => 'enum:missing_punch|wrong_type|time_clarification|other',
            'proposed_type' => '?enum:'.self::PUNCH_TYPES, 'proposed_time' => '?datetime', 'reason' => 'string', 'status' => 'enum:'.self::APPROVAL,
            'reviewer' => '?string', 'review_comment' => '?string', 'reviewed_at' => '?datetime', 'created_at' => 'datetime',
            // Flujo de 5 pasos con firma del colaborador (A2).
            'stage' => 'enum:por_autorizar|por_firmar|por_validar|aplicada|rechazada', 'stage_label' => 'string',
            'original' => ['summary' => 'string', 'punch_uuid' => '?uuid', 'type' => '?enum:'.self::PUNCH_TYPES, 'time' => '?datetime'],
            'proposal' => ['summary' => 'string', 'type' => '?enum:'.self::PUNCH_TYPES, 'time' => '?datetime'],
            'evidences' => ['list', ['id' => 'int', 'name' => 'string', 'mime' => 'string', 'size' => 'int', 'sha256' => 'string', 'url' => 'string']],
            'is_late' => 'bool', 'authorized_at' => '?datetime', 'signature_due_at' => '?datetime',
            'signature' => ['?', ['signed_at' => 'datetime', 'method' => 'enum:pin|biometric', 'hash' => 'string']],
            'signature_statement' => '?string', 'signature_payload_sha256' => '?string', 'can_sign' => 'bool',
            'rejection_stage' => '?enum:por_autorizar|por_firmar|por_validar|vencida|declinada|cancelada', 'rejection_reason' => '?string',
            'applied_at' => '?datetime',
        ];
        $incidenceType = [
            'id' => 'int', 'code' => 'string', 'name' => 'string', 'category' => 'enum:absence|leave|disability|vacation|time|other',
            'requires_evidence' => 'bool', 'requires_approval' => 'bool', 'employee_requestable' => 'bool',
            'evidence_kind' => '?enum:imss_certificate|authorized_form|death_certificate|proof', 'evidence_label' => '?string',
            'requires_folio' => 'bool', 'omission_punch_type' => '?enum:clock_in|clock_out',
        ];
        $apiError = ['message' => 'string', 'code' => 'string', 'errors' => ['optional', 'array']];
        $locationSettings = [
            'window' => [
                'enabled' => 'bool', 'active_now' => 'bool', 'starts_at' => '?datetime', 'ends_at' => '?datetime',
                'next_start_at' => '?datetime', 'timezone' => 'string',
            ],
            'interval_s' => ['foreground' => 'int', 'background' => 'int'],
            'distance_m' => ['foreground' => 'int', 'background' => 'int'],
            'mode' => ['optional', 'enum:on_punch|during_shift'],
            'phase' => ['optional', 'enum:active|paused|off'],
            'paused_states' => ['optional', 'list:enum:break|meal'],
        ];
        $locationPoint = [
            'uuid' => 'uuid', 'latitude' => 'number', 'longitude' => 'number', 'accuracy_m' => '?number', 'heading' => '?number',
            'speed_mps' => '?number', 'altitude_m' => '?number', 'captured_at' => 'datetime', 'app_state' => 'enum:foreground|background',
            'is_mocked' => '?bool', 'received_at' => 'datetime',
        ];

        return [
            'LocationSettings' => $locationSettings,
            'LocationState' => ['last' => ['?', $locationPoint], 'settings' => $locationSettings],
            'Device' => $device,
            'Profile' => $profile,
            'LoginResponse' => ['token' => 'string', 'token_expires_at' => 'datetime', 'device_secret' => '?string', 'device' => $device, 'profile' => $profile],
            'RefreshResponse' => ['token' => 'string', 'token_expires_at' => 'datetime'],
            'ChallengeResponse' => ['nonce' => 'string', 'expires_at' => 'datetime'],
            'Punch' => $punch,
            'Workday' => $workday,
            'PunchReceipt' => ['punch' => $punch, 'receipt_code' => 'string', 'workday' => $workday],
            'AttendancePolicy' => $policy,
            'TodayResponse' => ['workday' => $workday, 'policy' => $policy, 'week' => $week, 'server_time' => 'datetime'],
            'HistoryDay' => [
                'date' => 'date', 'status' => 'enum:'.self::WORKDAY_STATUS, 'worked_minutes' => 'int', 'late_minutes' => 'int',
                'punches' => ['list', $punch], 'rectifications' => ['list', $rectification],
            ],
            'Rectification' => $rectification,
            'IncidenceType' => $incidenceType,
            'Incidence' => [
                'id' => 'int', 'type' => $incidenceType, 'starts_on' => 'date', 'ends_on' => 'date', 'reason' => '?string',
                'status' => 'enum:'.self::APPROVAL, 'has_evidence' => 'bool', 'reviewer' => '?string', 'review_comment' => '?string', 'created_at' => 'datetime',
                'folio' => '?string', 'rectification_id' => '?int', 'cancellation_reason' => '?string',
            ],
            'PendingDocument' => [
                'id' => 'int', 'code' => 'enum:CONVENIO_REGISTRO|AVISO_PRIVACIDAD', 'purpose' => 'enum:registration_agreement|privacy_notice',
                'title' => 'string', 'version' => 'string', 'body_markdown' => 'string', 'sha256' => 'string', 'published_at' => 'datetime',
                'pdf_url' => 'string', 'signature_statement' => 'string', 'signature_payload_sha256' => 'string',
            ],
            'DocumentSignature' => ['document_id' => 'int', 'code' => 'string', 'signed_at' => 'datetime', 'method' => 'enum:pin|biometric', 'hash' => 'string'],
            'SignaturePinStatus' => ['has_pin' => 'bool', 'set_at' => '?datetime'],
            'SignedDocument' => [
                'id' => 'int', 'code' => 'enum:CONVENIO_REGISTRO|AVISO_PRIVACIDAD', 'title' => 'string', 'version' => 'string',
                'body_markdown' => '?string', 'pdf_url' => 'string', 'signed_at' => 'datetime', 'signature_method' => 'enum:pin|biometric', 'hash' => 'string',
            ],
            'VacationBalance' => [
                'service_year' => 'int', 'period_starts_on' => 'date', 'period_ends_on' => 'date', 'legal_days' => 'int', 'extra_days' => 'int',
                'entitled_days' => 'int', 'used_days' => 'int', 'pending_days' => 'int', 'available_days' => 'int',
                'vacation_premium_percent' => 'decimal', 'rule_source' => 'string',
            ],
            'VacationRequest' => [
                'id' => 'int', 'starts_on' => 'date', 'ends_on' => 'date', 'days' => 'int', 'status' => 'enum:'.self::APPROVAL,
                'comment' => '?string', 'reviewer' => '?string', 'review_comment' => '?string', 'created_at' => 'datetime',
            ],
            'CalendarResponse' => [
                'month' => 'string',
                'entries' => ['list', [
                    'date' => 'date', 'kind' => 'enum:shift|rest_day|holiday|vacation|leave|disability|incidence', 'label' => 'string',
                    'starts_at' => '?datetime', 'ends_at' => '?datetime',
                ]],
            ],
            'AppNotification' => ['id' => 'uuid', 'kind' => 'string', 'category' => 'enum:message|reminder|request|attendance|system', 'priority' => 'enum:normal|high', 'title' => 'string', 'body' => 'string', 'data' => 'array', 'read_at' => '?datetime', 'created_at' => 'datetime'],
            'FaceVerificationStatus' => ['available' => 'bool', 'provider' => '?string', 'enrolled' => 'bool', 'consent_required' => 'bool'],
            'PaginationMeta' => ['current_page' => 'int', 'per_page' => 'int', 'total' => 'int', 'last_page' => 'int'],
            'ApiError' => $apiError,
            'SyncItem' => ['client_uuid' => 'string', 'result' => 'enum:created|duplicate|rejected', 'punch' => ['optional', $punch], 'error' => ['optional', $apiError]],
            'Health' => ['status' => 'enum:ok', 'time' => 'datetime'],
        ];
    }

    public static function assert(mixed $value, string $shape): void
    {
        self::check($value, self::shapes()[$shape], $shape);
    }

    public static function check(mixed $value, mixed $spec, string $path): void
    {
        // Formas compuestas: ['?', forma], ['list', forma], ['optional', forma].
        if (is_array($spec) && array_is_list($spec) && count($spec) === 2 && is_string($spec[0])) {
            [$mod, $inner] = $spec;
            if ($mod === '?' || $mod === 'optional') {
                if ($value !== null) {
                    self::check($value, $inner, $path);
                }

                return;
            }
            Assert::assertIsArray($value, "{$path} debe ser lista");
            Assert::assertTrue(array_is_list($value), "{$path} debe ser lista");
            foreach ($value as $i => $item) {
                self::check($item, $inner, "{$path}[{$i}]");
            }

            return;
        }

        if (is_array($spec)) {
            Assert::assertIsArray($value, "{$path} debe ser objeto");
            $required = array_keys(array_filter($spec, fn ($s) => ! (is_array($s) && ($s[0] ?? null) === 'optional')));
            $allowed = array_keys($spec);
            $keys = array_keys($value);
            Assert::assertSame([], array_values(array_diff($required, $keys)), "{$path}: faltan claves");
            Assert::assertSame([], array_values(array_diff($keys, $allowed)), "{$path}: claves no previstas en el contrato");
            foreach ($value as $key => $item) {
                self::check($item, $spec[$key], "{$path}.{$key}");
            }

            return;
        }

        $nullable = str_starts_with($spec, '?');
        $type = ltrim($spec, '?');
        if ($value === null) {
            Assert::assertTrue($nullable, "{$path} no admite null");

            return;
        }

        if (str_starts_with($type, 'list:')) {
            Assert::assertIsArray($value, "{$path} debe ser lista");
            foreach ($value as $i => $item) {
                self::check($item, substr($type, 5), "{$path}[{$i}]");
            }

            return;
        }

        if (str_starts_with($type, 'enum:')) {
            Assert::assertContains($value, explode('|', substr($type, 5)), "{$path} fuera del enum");

            return;
        }

        match ($type) {
            'int' => Assert::assertIsInt($value, "{$path} debe ser entero"),
            'number' => Assert::assertTrue(is_int($value) || is_float($value), "{$path} debe ser número"),
            'string' => Assert::assertIsString($value, "{$path} debe ser string"),
            'bool' => Assert::assertIsBool($value, "{$path} debe ser booleano"),
            'array' => Assert::assertIsArray($value, "{$path} debe ser objeto"),
            'datetime' => Assert::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value, "{$path} debe ser ISO UTC con Z"),
            'date' => Assert::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $value, "{$path} debe ser YYYY-MM-DD"),
            'decimal' => Assert::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $value, "{$path} debe ser decimal en string"),
            'uuid' => Assert::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value, "{$path} debe ser UUID"),
        };
    }
}
