<?php

namespace Protendai\Ocr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecognitionFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $id  The id returned by Ocr::recognize()
     * @param  string  $message  Why recognition failed (missing file, unreadable image, engine error)
     */
    public function __construct(
        public string $id,
        public string $message,
    ) {}
}
