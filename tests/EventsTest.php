<?php

use Protendai\Ocr\Events\RecognitionFailed;
use Protendai\Ocr\Events\ScanCancelled;
use Protendai\Ocr\Events\TextRecognized;

/**
 * NativePHP builds events (and #[On] handler arguments) from the native payload by
 * parameter name, so every documented payload must fit the constructors.
 */
function fromPayload(string $class, array $payload): object
{
    $args = [];
    foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
        if (array_key_exists($param->getName(), $payload)) {
            $args[$param->getName()] = $payload[$param->getName()];
        }
    }

    return new $class(...$args);
}

it('builds TextRecognized from a full 1.0 payload', function () {
    $event = fromPayload(TextRecognized::class, [
        'id' => 'r1',
        'text' => "Total\n1,400.00",
        'lines' => ['Total', '1,400.00'],
        'boxes' => [
            ['text' => 'Total', 'left' => 40, 'top' => 580, 'right' => 160, 'bottom' => 608],
            ['text' => '1,400.00', 'left' => 540, 'top' => 582, 'right' => 680, 'bottom' => 610],
        ],
        'path' => '/cache/ocr/ocr-capture-r1.jpg',
        'width' => 3024,
        'height' => 4032,
    ]);

    expect($event->id)->toBe('r1')
        ->and($event->width)->toBe(3024)
        ->and($event->height)->toBe(4032)
        ->and($event->path)->toBe('/cache/ocr/ocr-capture-r1.jpg')
        ->and($event->rowText())->toBe('Total   1,400.00');
});

it('still builds TextRecognized from a pre-1.0 payload without width and height', function () {
    $event = fromPayload(TextRecognized::class, ['id' => 'r1', 'text' => 'hello', 'lines' => ['hello'], 'boxes' => [], 'path' => '/x.jpg']);

    expect($event->width)->toBeNull()
        ->and($event->height)->toBeNull()
        ->and($event->rowText())->toBe('hello');
});

it('builds TextRecognized from only the required keys', function () {
    $event = fromPayload(TextRecognized::class, ['id' => 'r1', 'text' => 'hello']);

    expect($event->lines)->toBe([])->and($event->boxes)->toBe([])->and($event->path)->toBeNull();
});

it('builds RecognitionFailed and recognises the documented messages', function () {
    $denied = fromPayload(RecognitionFailed::class, ['id' => 'a', 'message' => 'Camera permission denied']);
    $busy = fromPayload(RecognitionFailed::class, ['id' => 'b', 'message' => 'Another capture or scan is already in progress']);
    $other = fromPayload(RecognitionFailed::class, ['id' => 'c', 'message' => 'Could not open image: unsupported or damaged file']);

    expect($denied->isPermissionDenied())->toBeTrue()->and($denied->isBusy())->toBeFalse()
        ->and($busy->isBusy())->toBeTrue()->and($busy->isPermissionDenied())->toBeFalse()
        ->and($other->isBusy())->toBeFalse()->and($other->isPermissionDenied())->toBeFalse();
});

it('builds ScanCancelled from its payload', function () {
    expect(fromPayload(ScanCancelled::class, ['id' => 'z'])->id)->toBe('z');
});
