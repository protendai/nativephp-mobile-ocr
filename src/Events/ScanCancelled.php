<?php

namespace Protendai\Ocr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScanCancelled
{
    use Dispatchable, SerializesModels;

    /**
     * The user closed the document scanner without scanning a page.
     *
     * @param  string  $id  The id returned by Ocr::scan()
     */
    public function __construct(
        public string $id,
    ) {}
}
