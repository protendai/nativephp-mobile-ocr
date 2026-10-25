<?php

use Illuminate\Support\Str;
use Native\Mobile\Testing\FakeBridge;
use Protendai\Ocr\Exceptions\OcrException;
use Protendai\Ocr\Ocr;
use Protendai\Ocr\Testing\OcrMacros;

describe('recognize()', function () {
    it('sends the path and the caller id to Ocr.Recognize and returns the id', function () {
        $id = (new Ocr)->recognize('/data/slip.jpg', 'slip-1');

        expect($id)->toBe('slip-1')
            ->and(RecordingBridge::lastCall())->toBe([
                'method' => 'Ocr.Recognize',
                'params' => ['path' => '/data/slip.jpg', 'id' => 'slip-1'],
            ]);
    });

    it('generates a uuid when no id is given and sends that same id', function () {
        $id = (new Ocr)->recognize('/data/slip.jpg');

        expect(Str::isUuid($id))->toBeTrue()
            ->and(RecordingBridge::lastCall()['params']['id'])->toBe($id);
    });

    it('throws OcrException with the device error code when the device refuses', function () {
        RecordingBridge::respondTo('Ocr.Recognize', ['status' => 'error', 'code' => 'FILE_NOT_FOUND', 'message' => 'File not found: /nope.jpg']);

        try {
            (new Ocr)->recognize('/nope.jpg');
            $this->fail('Expected OcrException');
        } catch (OcrException $e) {
            expect($e->getMessage())->toBe('File not found: /nope.jpg')
                ->and($e->errorCode)->toBe('FILE_NOT_FOUND');
        }
    });

    it('falls back to a generic message when the error has none', function () {
        RecordingBridge::respondTo('Ocr.Recognize', ['status' => 'error']);

        expect(fn () => (new Ocr)->recognize('/x.jpg'))->toThrow(OcrException::class, 'Text recognition could not start');
    });
});

describe('capture() and scan()', function () {
    it('calls the matching bridge function with only the id', function (string $method, string $bridge) {
        $id = (new Ocr)->{$method}('receipt-42');

        expect($id)->toBe('receipt-42')
            ->and(RecordingBridge::lastCall())->toBe(['method' => $bridge, 'params' => ['id' => 'receipt-42']]);
    })->with([
        'capture' => ['capture', 'Ocr.Capture'],
        'scan' => ['scan', 'Ocr.Scan'],
    ]);

    it('generates an id when none is given', function (string $method) {
        $id = (new Ocr)->{$method}();

        expect(Str::isUuid($id))->toBeTrue()
            ->and(RecordingBridge::lastCall()['params'])->toBe(['id' => $id]);
    })->with(['capture', 'scan']);

    it('throws OcrException when the device has no camera or scanner', function (string $method, string $bridge, string $fallback) {
        RecordingBridge::respondTo($bridge, ['status' => 'error', 'code' => 'UNSUPPORTED', 'message' => 'No camera available']);

        expect(fn () => (new Ocr)->{$method}())->toThrow(OcrException::class, 'No camera available');

        RecordingBridge::respondTo($bridge, ['status' => 'error', 'code' => 'UNSUPPORTED']);

        expect(fn () => (new Ocr)->{$method}())->toThrow(OcrException::class, $fallback);
    })->with([
        'capture' => ['capture', 'Ocr.Capture', 'The camera could not open'],
        'scan' => ['scan', 'Ocr.Scan', 'The scanner could not start'],
    ]);

    it('treats a started response as success', function () {
        RecordingBridge::respondTo('Ocr.Scan', ['status' => 'success', 'data' => ['id' => 'a', 'status' => 'started']]);

        expect((new Ocr)->scan('a'))->toBe('a');
    });
});

describe('facade', function () {
    it('documents every public method of the Ocr class', function () {
        $doc = (new ReflectionClass(\Protendai\Ocr\Facades\Ocr::class))->getDocComment();

        foreach ((new ReflectionClass(Ocr::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            expect($doc)->toContain(' '.$method->getName().'(');
        }
    });
});

describe('FakeBridge macros', function () {
    beforeEach(fn () => OcrMacros::register());

    it('asserts the OCR bridge calls in plain terms', function () {
        $bridge = new FakeBridge;
        $bridge->call('Ocr.Recognize', json_encode(['path' => '/tmp/a.jpg', 'id' => '1']));
        $bridge->call('Ocr.Capture', json_encode(['id' => '2']));
        $bridge->call('Ocr.Scan', json_encode(['id' => '3']));

        $bridge->assertOcrRequested(fn (array $p) => $p['path'] === '/tmp/a.jpg')
            ->assertCaptureRequested(fn (array $p) => $p['id'] === '2')
            ->assertScanRequested(fn (array $p) => $p['id'] === '3');

        expect(true)->toBeTrue();
    });

    it('asserts no recognition was requested', function () {
        (new FakeBridge)->assertNoOcrRequested();

        $bridge = new FakeBridge;
        $bridge->call('Ocr.Recognize', '{}');

        expect(fn () => $bridge->assertNoOcrRequested())->toThrow(\PHPUnit\Framework\AssertionFailedError::class);
    });
});
