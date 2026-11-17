<?php

use App\Domain\Notifications\NotificationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NotificationsServiceProvider::class,
];
