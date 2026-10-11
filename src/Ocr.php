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
}
