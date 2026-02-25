<?php

namespace App\Exceptions;

use RuntimeException;

class JwtException extends RuntimeException
{
    public function __construct(string $message = 'Invalid token.', protected int $status = 401)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
