<?php

namespace Protendai\Ocr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScanCancelled
{
    use Dispatchable, SerializesModels;

    /**
     * The user closed the camera (capture()) or the document scanner (scan()) without taking a picture.
     *
     * @param  string  $id  The id returned by Ocr::capture() or Ocr::scan()
     */
    public function __construct(
        public string $id,
    ) {}
}
