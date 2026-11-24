<?php

namespace App\Domain\Legal\Exceptions;

use RuntimeException;

/**
 * No hay versión normativa vigente para la fecha: el cálculo se detiene en lugar
 * de suponer parámetros legales (nunca se inventan topes de jornada).
 */
final class LegalVersionNotFound extends RuntimeException {}
