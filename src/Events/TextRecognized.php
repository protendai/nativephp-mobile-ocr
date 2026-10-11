<?php

namespace Protendai\Ocr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TextRecognized
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $id  The id returned by Ocr::recognize()
     * @param  string  $text  All recognised text, lines joined with "\n"
     * @param  array<int, string>  $lines  Each recognised line, top to bottom
     */
    public function __construct(
        public string $id,
        public string $text,
        public array $lines = [],
    ) {}
}
