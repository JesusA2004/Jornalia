<?php

namespace App\Events;

use App\Models\Punch;
use Illuminate\Foundation\Events\Dispatchable;

final class PunchRecorded
{
    use Dispatchable;

    public function __construct(public readonly Punch $punch) {}
}
