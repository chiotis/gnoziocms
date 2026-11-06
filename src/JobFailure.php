<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What a JobRunner handler throws when its job did not succeed. later(): try again (a timeout, a rate limit), after at least the
 * given seconds, as long as attempts are left. final(): do not try again (a refused prompt, no credit, a missing entry). The message
 * is one plain sentence for the admin; it is stored as it is, so it must never hold a key or a person's data.
 */
final class JobFailure extends \RuntimeException
{
    private function __construct(string $message, public readonly bool $retry, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }

    public static function later(string $message, int $retryAfter = 0): self
    {
        return new self($message, true, max(0, $retryAfter));
    }

    public static function final(string $message): self
    {
        return new self($message, false, 0);
    }
}
