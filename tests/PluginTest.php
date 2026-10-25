<?php

use Protendai\Ocr\Events\RecognitionFailed;

/**
 * Manifest and native code checks for protendai/nativephp-mobile-ocr. Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifest = json_decode(file_get_contents($this->pluginPath.'/nativephp.json'), true);
    $this->kotlin = file_get_contents($this->pluginPath.'/resources/android/src/OcrFunctions.kt');
    $this->coordinator = file_get_contents($this->pluginPath.'/resources/android/src/ScanCoordinator.kt');
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

    it('asks for no Android permissions and declares the iOS camera string', function () {
        expect($this->manifest['android']['permissions'] ?? [])->toBe([])
            ->and($this->manifest['ios']['info_plist'])->toHaveKey('NSCameraUsageDescription')
            ->and($this->manifest['ios']['info_plist']['NSCameraUsageDescription'])->toStartWith('This app');
    });

    it('carries the release metadata', function () {
        expect($this->manifest)
            ->version->toBe('1.0.0')
            ->license->toBe('MIT')
            ->homepage->toBe('https://github.com/protendai/nativephp-mobile-ocr')
            ->repository->toBe('https://github.com/protendai/nativephp-mobile-ocr')
            ->platforms->toBe(['android', 'ios'])
            ->and($this->manifest['keywords'])->toContain('nativephp-plugin')
            ->and($this->manifest['android']['min_version'])->toBe(21)
            ->and($this->manifest['ios']['min_version'])->toBe('13.0');

        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect($composer['name'])->toBe($this->manifest['name'])
            ->and($composer['license'])->toBe('MIT')
            ->and($composer['keywords'])->toContain('nativephp-plugin')
            ->and($composer['require'])->toHaveKey('nativephp/mobile')
            ->and($composer['support']['issues'])->toBe('https://github.com/protendai/nativephp-mobile-ocr/issues');
    });

    it('maps every bridge function to a class that exists in the native code', function () {
        $kotlin = implode("\n", array_map('file_get_contents', glob($this->pluginPath.'/resources/android/src/*.kt')));
        preg_match('/^package\s+([\w.]+)/m', $this->kotlin, $package);
        preg_match_all('/class (\w+)\(private val activity: FragmentActivity\) : BridgeFunction/', $kotlin, $kotlinClasses);
        preg_match_all('/class (\w+): BridgeFunction/', $this->swift, $swiftClasses);

        foreach ($this->manifest['bridge_functions'] as $function) {
            [$namespace, $name] = explode('.', $function['name']);

            expect($namespace)->toBe($this->manifest['namespace'])
                ->and($function['android'])->toBe($package[1].'.OcrFunctions.'.$name)
                ->and($kotlinClasses[1])->toContain($name)
                ->and($function['ios'])->toBe('OcrFunctions.'.$name)
                ->and($swiftClasses[1])->toContain($name);
        }

        expect(count($kotlinClasses[1]))->toBe(count($this->manifest['bridge_functions']))
            ->and(count($swiftClasses[1]))->toBe(count($this->manifest['bridge_functions']));
    });

    it('has a bridge function for every public facade method', function () {
        $bridged = array_map(fn ($f) => lcfirst(explode('.', $f['name'])[1]), $this->manifest['bridge_functions']);
        $methods = array_map(fn ($m) => $m->getName(), (new ReflectionClass(\Protendai\Ocr\Ocr::class))->getMethods(ReflectionMethod::IS_PUBLIC));

        expect($methods)->toEqualCanonicalizing($bridged);
    });

    it('keeps every Kotlin file in the plugin package', function () {
        foreach (glob($this->pluginPath.'/resources/android/src/*.kt') as $file) {
            expect(file_get_contents($file))->toStartWith('package com.protendai.plugins.ocr');
        }
    });
});

describe('native code', function () {
    it('implements Capture with the back camera on both platforms', function () {
        expect($this->kotlin)->toContain('class Capture(private val activity: FragmentActivity) : BridgeFunction')
            ->and($this->coordinator)->toContain('MediaStore.ACTION_IMAGE_CAPTURE')
            ->toContain('"android.intent.extras.CAMERA_FACING", 0')
            ->toContain('"android.intent.extras.LENS_FACING_BACK", 1')
            ->toContain('RequestPermission()')
            ->and($this->swift)->toContain('class Capture: BridgeFunction')
            ->toContain('picker.cameraDevice = .rear');
    });

    it('captures into the app cache through the core FileProvider, not MediaStore', function () {
        $core = $this->pluginPath.'/vendor/nativephp/mobile/resources/androidstudio/app/src/main';

        expect($this->coordinator)
            ->toContain('FileProvider.getUriForFile(activity, "${activity.packageName}.fileprovider", file)')
            ->toContain('File(context.cacheDir, "ocr")')
            ->not->toContain('EXTERNAL_CONTENT_URI')
            ->not->toContain('contentResolver.insert');

        // When the NativePHP core is around, check the authority and the cache path it exposes.
        if (is_dir($core)) {
            expect(file_get_contents($core.'/AndroidManifest.xml'))->toContain('android:authorities="${applicationId}.fileprovider"')
                ->and(file_get_contents($core.'/res/xml/file_paths.xml'))->toContain('<cache-path name="cache" path="." />');
        }
    });

    it('allows one capture or scan at a time, with the message PHP knows', function () {
        expect($this->coordinator)->toContain('BUSY_MESSAGE = "'.RecognitionFailed::BUSY.'"')
            ->toContain('PERMISSION_DENIED_MESSAGE = "'.RecognitionFailed::CAMERA_PERMISSION_DENIED.'"')
            ->toContain('if (pendingId != null)')
            ->and($this->swift)->toContain('busyMessage = "'.RecognitionFailed::BUSY.'"')
            ->toContain('permissionDeniedMessage = "'.RecognitionFailed::CAMERA_PERMISSION_DENIED.'"')
            ->toContain('if OcrFunctions.pendingId != nil');
    });

    it('sends a denied camera on iOS as RecognitionFailed, like Android', function () {
        expect($this->swift)->toContain('AVCaptureDevice.authorizationStatus(for: .video)')
            ->toContain('AVCaptureDevice.requestAccess(for: .video)')
            ->toContain('modalPresentationStyle = .fullScreen');
    });

    it('survives state loss and unexpected errors on Android', function () {
        expect($this->coordinator)->toContain('commitNowAllowingStateLoss()')
            ->not->toContain('.commitNow()')
            ->and($this->kotlin)->not->toContain('catch (e: Exception)')
            ->toContain('catch (e: Throwable)');
    });

    it('downscales large photos and applies EXIF rotation before recognition', function () {
        expect($this->kotlin)->toContain('inSampleSize')
            ->toContain('ExifInterface.TAG_ORIENTATION')
            ->toContain('MAX_SIDE = 4096')
            ->toContain('catch (e: OutOfMemoryError)')
            ->and($this->swift)->toContain('kCGImageSourceThumbnailMaxPixelSize')
            ->toContain('kCGImageSourceCreateThumbnailWithTransform: true')
            ->toContain('maxSide = 4096');
    });

    it('tidies its own temporary images', function () {
        expect($this->coordinator)->toContain('removeOldImages(activity)')
            ->toContain('file?.delete()')
            ->and($this->swift)->toContain('OcrFunctions.removeOldImages()');
    });

    it('implements Scan with the platform document scanners', function () {
        expect($this->kotlin)->toContain('class Scan(private val activity: FragmentActivity) : BridgeFunction')
            ->and($this->coordinator)->toContain('package com.protendai.plugins.ocr')
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
        foreach (['"id"', '"text"', '"lines"', '"boxes"', '"path"', '"width"', '"height"', '"message"'] as $key) {
            expect($this->kotlin)->toContain($key)
                ->and($this->swift)->toContain($key);
        }
    });
});
