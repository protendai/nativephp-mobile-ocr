<?php

/**
 * Manifest and native code checks for protendai/nativephp-mobile-ocr. Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifest = json_decode(file_get_contents($this->pluginPath.'/nativephp.json'), true);
    $this->kotlin = file_get_contents($this->pluginPath.'/resources/android/src/OcrFunctions.kt');
    $this->swift = file_get_contents($this->pluginPath.'/resources/ios/Sources/OcrFunctions.swift');
});

describe('manifest', function () {
    it('is valid JSON with the required fields', function () {
        expect($this->manifest)->toBeArray()
            ->toHaveKeys(['name', 'namespace', 'bridge_functions', 'events'])
            ->and($this->manifest['name'])->toBe('protendai/nativephp-mobile-ocr')
            ->and($this->manifest['namespace'])->toBe('Ocr');
    });

    it('declares the Recognize, Capture and Scan bridge functions on both platforms', function () {
        expect(array_map(fn ($f) => [$f['name'], $f['android'], $f['ios']], $this->manifest['bridge_functions']))->toBe([
            ['Ocr.Recognize', 'com.protendai.plugins.ocr.OcrFunctions.Recognize', 'OcrFunctions.Recognize'],
            ['Ocr.Capture', 'com.protendai.plugins.ocr.OcrFunctions.Capture', 'OcrFunctions.Capture'],
            ['Ocr.Scan', 'com.protendai.plugins.ocr.OcrFunctions.Scan', 'OcrFunctions.Scan'],
        ]);
    });

    it('declares every result event and their classes exist', function () {
        expect($this->manifest['events'])->toBe([
            'Protendai\\Ocr\\Events\\TextRecognized',
            'Protendai\\Ocr\\Events\\RecognitionFailed',
            'Protendai\\Ocr\\Events\\ScanCancelled',
        ]);

        foreach ($this->manifest['events'] as $event) {
            $file = $this->pluginPath.'/src/Events/'.class_basename($event).'.php';
            expect(file_get_contents($file))->toContain('class '.class_basename($event));
        }
    });

    it('uses the bundled ML Kit model, not the Play Services download', function () {
        expect($this->manifest['android']['dependencies']['implementation'])
            ->toContain('com.google.mlkit:text-recognition:16.0.1')
            ->not->toContain('com.google.android.gms:play-services-mlkit-text-recognition');
    });

    it('asks for no permissions (the camera plugin owns camera access)', function () {
        expect($this->manifest['android']['permissions'] ?? [])->toBe([])
            ->and($this->manifest['ios'])->not->toHaveKey('info_plist');
    });
});

describe('native code', function () {
    it('implements Capture with the back camera on both platforms', function () {
        $coordinator = file_get_contents($this->pluginPath.'/resources/android/src/ScanCoordinator.kt');

        expect($this->kotlin)->toContain('class Capture(private val activity: FragmentActivity) : BridgeFunction')
            ->and($coordinator)->toContain('MediaStore.ACTION_IMAGE_CAPTURE')
            ->toContain('"android.intent.extras.CAMERA_FACING", 0')
            ->toContain('"android.intent.extras.LENS_FACING_BACK", 1')
            ->toContain('RequestPermission()')
            ->and($this->swift)->toContain('class Capture: BridgeFunction')
            ->toContain('picker.cameraDevice = .rear');
    });

    it('implements Scan with the platform document scanners', function () {
        $coordinator = file_get_contents($this->pluginPath.'/resources/android/src/ScanCoordinator.kt');

        expect($this->kotlin)->toContain('class Scan(private val activity: FragmentActivity) : BridgeFunction')
            ->and($coordinator)->toContain('package com.protendai.plugins.ocr')
            ->toContain('GmsDocumentScanning.getClient')
            ->toContain('StartIntentSenderForResult')
            ->and($this->swift)->toContain('class Scan: BridgeFunction')
            ->toContain('VNDocumentCameraViewController');

        expect($this->manifest['android']['dependencies']['implementation'])
            ->toContain('com.google.android.gms:play-services-mlkit-document-scanner:16.0.0');
    });

    it('implements Recognize on Android with the manifest package', function () {
        expect($this->kotlin)
            ->toContain('package com.protendai.plugins.ocr')
            ->toContain('object OcrFunctions')
            ->toContain('class Recognize(private val activity: FragmentActivity) : BridgeFunction')
            ->toContain('TextRecognizerOptions.DEFAULT_OPTIONS')
            ->toContain('Looper.getMainLooper()');
    });

    it('implements Recognize on iOS with language correction off', function () {
        expect($this->swift)
            ->toContain('enum OcrFunctions')
            ->toContain('class Recognize: BridgeFunction')
            ->toContain('request.usesLanguageCorrection = false')
            ->toContain('DispatchQueue.main.async');
    });

    it('fires the same event classes the manifest declares', function () {
        foreach (['TextRecognized', 'RecognitionFailed', 'ScanCancelled'] as $event) {
            $escaped = 'Protendai\\\\Ocr\\\\Events\\\\'.$event;

            expect($this->kotlin)->toContain($escaped)
                ->and($this->swift)->toContain($escaped);
        }
    });

    it('sends the payload keys the PHP events expect', function () {
        foreach (['"id"', '"text"', '"lines"', '"boxes"', '"path"', '"message"'] as $key) {
            expect($this->kotlin)->toContain($key)
                ->and($this->swift)->toContain($key);
        }
    });
});
