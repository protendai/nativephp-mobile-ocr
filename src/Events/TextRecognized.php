<?php

namespace Protendai\Ocr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Protendai\Ocr\Layout;

class TextRecognized
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $id  The id returned by Ocr::recognize()
     * @param  string  $text  All recognised text, in the engine's reading order
     * @param  array<int, string>  $lines  Each recognised line, in the engine's reading order
     * @param  array<int, array{text: string, left: int|float, top: int|float, right: int|float, bottom: int|float}>  $boxes
     *                                                                                                                       Each line with its position (pixels on Android, 0-1 on iOS; top-left origin on both)
     */
    public function __construct(
        public string $id,
        public string $text,
        public array $lines = [],
        public array $boxes = [],
    ) {}

    /**
     * The text rebuilt row by row, left to right, as a person reads a receipt.
     *
     * OCR engines read two-column layouts a column at a time, so "Units" and
     * "297.18" on the same printed row come out far apart in $text. This puts
     * every line whose vertical centre falls inside a row's band onto that row.
     * Falls back to $text when no positions were reported.
     */
    public function rowText(): string
    {
        return Layout::rows($this->boxes) ?? $this->text;
    }
}
