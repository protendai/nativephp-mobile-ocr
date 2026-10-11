<?php

namespace Protendai\Ocr\Testing;

use Native\Mobile\Testing\FakeBridge;

/**
 * OCR test vocabulary, registered as FakeBridge macros so app tests read in OCR terms:
 *
 *     Native::test(AddToken::class)
 *         ->emitNative(PhotoTaken::class, ['path' => '/tmp/slip.jpg'])
 *         ->assertOcrRequested(fn (array $p) => $p['path'] === '/tmp/slip.jpg');
 */
class OcrMacros
{
    public static function register(): void
    {
        /** Assert Ocr::recognize() was called, optionally matching its params (path, id). */
        FakeBridge::macro('assertOcrRequested', function (?callable $filter = null) {
            return $this->assertCalled('Ocr.Recognize', $filter);
        });

        FakeBridge::macro('assertNoOcrRequested', function () {
            return $this->assertNotCalled('Ocr.Recognize');
        });
    }
}
