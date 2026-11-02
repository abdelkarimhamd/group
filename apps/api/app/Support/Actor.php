<?php

namespace App\Support;

/**
 * Who performed an action: the system itself (jobs, schedulers, webhooks),
 * an admin user, or a customer. Recorded on audit logs and status history.
 */
final class Actor
{
    private function __construct(
        public readonly ActorType $type,
        public readonly ?int $id,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public static function admin(int $id): self
    {
        return new self(ActorType::Admin, $id);
    }

    public static function user(int $id): self
    {
        return new self(ActorType::User, $id);
    }
}
