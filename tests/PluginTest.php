<?php

/**
 * Manifest and native code checks for protendai/nativephp-mobile-ocr. Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifest = json_decode(file_get_contents($this->pluginPath.'/nativephp.json'), true);
    $this->kotlin = file_get_contents($this->pluginPath.'/resources/android/OcrFunctions.kt');
    $this->swift = file_get_contents($this->pluginPath.'/resources/ios/OcrFunctions.swift');
});

describe('manifest', function () {
    it('is valid JSON with the required fields', function () {
        expect($this->manifest)->toBeArray()
            ->toHaveKeys(['name', 'namespace', 'bridge_functions', 'events'])
            ->and($this->manifest['name'])->toBe('protendai/nativephp-mobile-ocr')
            ->and($this->manifest['namespace'])->toBe('Ocr');
    });

    it('declares the Recognize bridge function on both platforms', function () {
        expect($this->manifest['bridge_functions'])->toBe([[
            'name' => 'Ocr.Recognize',
            'android' => 'com.protendai.plugins.ocr.OcrFunctions.Recognize',
            'ios' => 'OcrFunctions.Recognize',
            'description' => $this->manifest['bridge_functions'][0]['description'],
        ]]);
    });

    it('declares both result events and their classes exist', function () {
        expect($this->manifest['events'])->toBe([
            'Protendai\\Ocr\\Events\\TextRecognized',
            'Protendai\\Ocr\\Events\\RecognitionFailed',
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
        foreach (['TextRecognized', 'RecognitionFailed'] as $event) {
            $escaped = 'Protendai\\\\Ocr\\\\Events\\\\'.$event;

            expect($this->kotlin)->toContain($escaped)
                ->and($this->swift)->toContain($escaped);
        }
    });

    it('sends the payload keys the PHP events expect', function () {
        foreach (['"id"', '"text"', '"lines"', '"message"'] as $key) {
            expect($this->kotlin)->toContain($key)
                ->and($this->swift)->toContain($key);
        }
    });
});
