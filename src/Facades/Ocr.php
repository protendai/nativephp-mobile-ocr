<?php

namespace Protendai\Ocr\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string recognize(string $path, ?string $id = null)
 * @method static string scan(?string $id = null)
 *
 * @see \Protendai\Ocr\Ocr
 */
class Ocr extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Protendai\Ocr\Ocr::class;
    }
}
