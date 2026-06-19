<?php

namespace Sisly\Coach\Exceptions;

class AnthropicException extends \RuntimeException
{
    public function __construct(
        string     $message = '',
        int        $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
