<?php

namespace Protendai\Ocr;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Testing\FakeBridge;
use Protendai\Ocr\Testing\OcrMacros;

class OcrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ocr::class, fn () => new Ocr);

        // Test sugar (assertOcrRequested() etc.), only under a test runner and on a core with a macroable FakeBridge.
        if ($this->app->runningUnitTests()
            && class_exists(FakeBridge::class)
            && method_exists(FakeBridge::class, 'macro')) {
            OcrMacros::register();
        }
    }
}
