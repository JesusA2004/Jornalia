<?php

namespace App\Domain\Integrations\People;

use RuntimeException;

/**
 * Registro de People que no pudo aplicarse (queda en la bitácora de la corrida).
 */
final class PeopleRecordException extends RuntimeException {}
