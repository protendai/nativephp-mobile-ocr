<?php

namespace Protendai\Ocr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecognitionFailed
{
    use Dispatchable, SerializesModels;

    /** The user refused camera access (capture() or scan()). */
    public const CAMERA_PERMISSION_DENIED = 'Camera permission denied';

    /** capture() or scan() was called while another capture or scan was still open; that one carries on. */
    public const BUSY = 'Another capture or scan is already in progress';

    /**
     * @param  string  $id  The id returned by Ocr::recognize(), Ocr::capture() or Ocr::scan()
     * @param  string  $message  Why it failed (camera permission denied, busy, unreadable image, engine error)
     */
    public function __construct(
        public string $id,
        public string $message,
    ) {}

    public function isPermissionDenied(): bool
    {
        return $this->message === self::CAMERA_PERMISSION_DENIED;
    }

    public function isBusy(): bool
    {
        return $this->message === self::BUSY;
    }
}
