<?php

namespace App\Domain\Biometrics;

use RuntimeException;

/**
 * El motor facial no respondió o respondió algo inesperado. Nunca contiene imágenes.
 */
final class FaceServiceException extends RuntimeException {}
