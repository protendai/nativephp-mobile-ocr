<?php

namespace Protendai\Ocr\Exceptions;

use RuntimeException;

/**
 * The device refused to start recognition (missing path, file not found). No result event will follow.
 */
class OcrException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
