# Changelog

All notable changes to `protendai/nativephp-mobile-ocr` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-25

### Added
- `TextRecognized` carries `width` and `height`: the pixel size of the image the boxes refer to. Both are optional constructor parameters (null from older native code), so existing handlers keep working.
- `RecognitionFailed::CAMERA_PERMISSION_DENIED` and `RecognitionFailed::BUSY` constants, with `isPermissionDenied()` / `isBusy()`.
- JavaScript library (`resources/js/index.js`, with TypeScript types and `package.json`): `recognize()`, `capture()`, `scan()`, event listeners and a `rows()` helper for Inertia + Vue/React apps.
- iOS `NSCameraUsageDescription` declared in the manifest, so `capture()` and `scan()` work without app-side Info.plist edits.
- Tests for the facade, events, FakeBridge macros, JavaScript library and manifest/native consistency.
- `LICENSE` (MIT) and this changelog.

### Changed
- **iOS boxes are now pixels** of the upright image (top-left origin), the same as Android, instead of 0-1 fractions. `Layout::rows()` and `Layout::valueBeside()` are unit-agnostic, so code built on them is unaffected; code that read raw iOS fractions should divide by `width` / `height`.
- Large photos are downscaled before recognition (longest side at most 4096 px, EXIF orientation applied), on both platforms. Boxes refer to that image, whose size is reported as `width` / `height`.
- Only one `capture()` or `scan()` can be open at a time. A second call gets `RecognitionFailed` (`RecognitionFailed::BUSY`) for its own id instead of silently orphaning the first request.
- Captured photos and scanned pages are kept in a plugin folder in the app cache. Images left by earlier requests are removed when a later capture or scan starts (each stays at least a minute); copy `$path` if you need to keep it.
- Native sources moved to `resources/android/src/` and `resources/ios/Sources/`.
- Manifest: `android.min_version` 21 and `ios.min_version` 13.0 (the lowest the code needs), homepage and repository filled in.

### Fixed
- Android `capture()` no longer inserts into MediaStore: it crashed with a SecurityException on Android 8/9 after the permission prompt and left an empty file in the gallery. Photos now go to the app cache through the NativePHP core FileProvider (`${applicationId}.fileprovider`).
- Android: launching the camera after the permission prompt is guarded; any failure arrives as `RecognitionFailed` instead of crashing.
- Android: 50-108 MP photos no longer run the app out of memory (downscaled decode, retry smaller on `OutOfMemoryError`, `Throwable` caught).
- Android: the headless fragment is added with `commitNowAllowingStateLoss()`, so a call while the activity is stopping no longer throws.
- Android/iOS: temp files are deleted when the user cancels or the capture fails.
- iOS: a denied or restricted camera now sends `RecognitionFailed('Camera permission denied')`, the same as Android, instead of `ScanCancelled`.
- iOS: the camera and scanner are presented full screen, so they cannot be swiped away without a result event.
- Boost guidelines now cover `capture()`, `scan()`, every event and the layout helpers.

## [0.1.0] - 2026-10-11

### Added
- `Ocr::recognize()`, `Ocr::capture()`, `Ocr::scan()`, line positions and `Layout` helpers.
