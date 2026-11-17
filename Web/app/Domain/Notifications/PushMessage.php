<?php

namespace App\Domain\Notifications;

/**
 * Mensaje push listo para FCM. `data`: sólo claves y valores de texto.
 */
final readonly class PushMessage
{
    public const TITLE = 'MR LANA Asistencias';

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data,
        public bool $highPriority = true,
    ) {}

    /**
     * @return array{title:string, body:string, data:array<string,string>, high_priority:bool}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'data' => $this->data, 'high_priority' => $this->highPriority];
    }

    /**
     * @param  array{title:string, body:string, data:array<string,string>, high_priority:bool}  $value
     */
    public static function fromArray(array $value): self
    {
        return new self($value['title'], $value['body'], $value['data'], $value['high_priority']);
    }
}
