<?php

namespace App\Domain\Biometrics;

/**
 * Elige el motor según config/biometrics.php: sin URL o token → no disponible.
 */
final class FaceProviderFactory
{
    public static function make(): FaceVerificationProvider
    {
        $url = (string) config('biometrics.service.url');
        $token = (string) config('biometrics.service.token');

        if ($url === '' || $token === '') {
            return new UnavailableFaceProvider;
        }

        return new OpenSourceFaceProvider(
            $url,
            $token,
            (int) config('biometrics.service.timeout', 15),
            (int) config('biometrics.service.connect_timeout', 2),
            (int) config('biometrics.service.health_cache_seconds', 30),
        );
    }
}
