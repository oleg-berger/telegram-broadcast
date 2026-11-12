<?php
declare(strict_types=1);

namespace Broadcast\Domain;

final readonly class RecipientSnapshot
{
    /** @param list<array{contact_id:int,telegram_user_id:?int,phone:string,channel:string,address:string,language:string}> $recipients */
    public function __construct(public string $id, public int $createdAt, public array $recipients) {}
}
