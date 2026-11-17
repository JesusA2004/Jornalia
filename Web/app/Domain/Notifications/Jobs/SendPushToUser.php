<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\PushMessage;
use App\Domain\Notifications\PushOutcome;
use App\Domain\Notifications\PushSender;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reintento diferido de un push con falla transitoria (máximo 3 intentos).
 * Sólo reintenta el push: la bandeja interna ya quedó registrada.
 */
final class SendPushToUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_ATTEMPTS = 3;

    /**
     * @param  array{title:string, body:string, data:array<string,string>, high_priority:bool}  $message
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $organizationId,
        public readonly array $message,
        public readonly int $attempt = 1,
    ) {}

    public function handle(PushSender $sender): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $outcome = $sender->toUser($user, $this->organizationId, PushMessage::fromArray($this->message));
        if ($outcome->status === PushOutcome::TRANSIENT && $this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->userId, $this->organizationId, $this->message, $this->attempt + 1)
                ->delay(now()->addSeconds(60 * $this->attempt));
        }
    }
}
