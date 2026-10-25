<?php

namespace Protendai\Ocr;

use Illuminate\Support\Str;
use Protendai\Ocr\Exceptions\OcrException;

class Ocr
{
    /**
     * Start recognising text in an image file. Returns a request id straight away.
     *
     * The result arrives later as a TextRecognized or RecognitionFailed event carrying the same id,
     * so recognition never blocks the UI thread.
     *
     * @throws OcrException when the device refuses the request (no path, file missing), so no event will follow
     */
    public function recognize(string $path, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();

        if (! function_exists('nativephp_call')) {
            return $id;
        }

        $response = json_decode((string) nativephp_call('Ocr.Recognize', json_encode(['path' => $path, 'id' => $id])), true);

        if (is_array($response) && ($response['status'] ?? null) === 'error') {
            throw new OcrException($response['message'] ?? 'Text recognition could not start', $response['code'] ?? null);
        }

        return $id;
    }

    /**
     * Take a photo with the back camera, then recognise it. Returns a request id straight away.
     *
     * Opens the phone's camera app itself (Android) or the system camera (iOS),
     * asking for the back lens: camera apps opened the usual way reopen whichever
     * lens was used last, often the selfie camera. The photo is read as taken (no
     * crop or filters), which keeps fine print sharp. Camera permission is asked
     * for on first use where the platform needs it (iOS always; Android only when
     * the app declares android.permission.CAMERA); a refusal arrives as
     * RecognitionFailed with RecognitionFailed::CAMERA_PERMISSION_DENIED.
     *
     * Only one capture() or scan() can be open at a time: a second call while one
     * is open gets RecognitionFailed with RecognitionFailed::BUSY for its own id.
     *
     * The result arrives as TextRecognized (whose $path is the photo),
     * RecognitionFailed, or ScanCancelled when the user backs out.
     *
     * @throws OcrException when the device refuses the request, so no event will follow
     */
    public function capture(?string $id = null): string
    {
        return $this->start('Ocr.Capture', $id, 'The camera could not open');
    }

    /**
     * Open the platform document scanner, then recognise the text on the scanned page.
     * Returns a request id straight away.
     *
     * Android uses Google's ML Kit document scanner and iOS uses VisionKit's document
     * camera: both always use the back camera, find the page edges and flatten the
     * crop, which reads better than a raw photo. The scanner needs no camera
     * permission on Android; on iOS camera access is asked for on first use. On
     * Android, Google Play services downloads the scanner module the first time it
     * is used, so that first scan needs a connection. Like capture(), only one
     * capture or scan can be open at a time.
     *
     * The result arrives as TextRecognized (whose $path is the scanned page),
     * RecognitionFailed, or ScanCancelled when the user backs out.
     *
     * @throws OcrException when the device refuses the request, so no event will follow
     */
    public function scan(?string $id = null): string
    {
        return $this->start('Ocr.Scan', $id, 'The scanner could not start');
    }

    private function start(string $method, ?string $id, string $fallbackMessage): string
    {
        $id ??= (string) Str::uuid();

        if (! function_exists('nativephp_call')) {
            return $id;
        }

        $response = json_decode((string) nativephp_call($method, json_encode(['id' => $id])), true);

        if (is_array($response) && ($response['status'] ?? null) === 'error') {
            throw new OcrException($response['message'] ?? $fallbackMessage, $response['code'] ?? null);
        }

        return $id;
    }
}
