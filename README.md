# protendai/nativephp-mobile-ocr

Free, on-device OCR (text recognition) for [NativePHP for Mobile](https://nativephp.com/docs/mobile) v4.

Point the back camera at a receipt, a slip or a label and get the text back. It uses Google ML Kit on Android and Apple Vision on iOS. Both run entirely on the phone, so there's no API key, no server and no internet connection.

It also handles the details that trip up OCR on real documents:

- **The camera actually opens on the back lens.** A camera app launched the usual way reopens whichever lens was used last, which is often the selfie camera.
- **Two-column layouts read correctly.** OCR engines read a receipt one column at a time, so "Units" and "297.18" come out far apart. The plugin gives you each line's position, plus helpers that put labels back next to their values.
- **An optional document scanner** finds the page edges and flattens the photo.

## Why

NativePHP v4 had no free way to read text from an image. This plugin fills that gap with the text recognition engines already built into each platform. They're wrapped in a small PHP API that fits the v4 SuperNative event model.

It was first built for an app that reads prepaid electricity tokens off till slips and receipts, and everything below was tested against real ones.

## Platform support

| | Android | iOS |
| --- | --- | --- |
| `recognize()` | ML Kit text recognition (bundled model) | Vision `VNRecognizeTextRequest` |
| `capture()` | Phone's camera app, back lens | `UIImagePickerController`, rear camera |
| `scan()` | ML Kit document scanner (Google Play services) | VisionKit document camera |
| Minimum (plugin code) | API 21 | iOS 13 |

Your app's own NativePHP minimums apply on top of these.

## Install

```bash
composer require protendai/nativephp-mobile-ocr
php artisan vendor:publish --tag=nativephp-plugins-provider   # once, if you haven't already
php artisan native:plugin:register protendai/nativephp-mobile-ocr
php artisan native:plugin:list                                 # check it shows up
```

The plugin ships native code, so rebuild the app afterwards:

```bash
php artisan native:run android   # or ios
```

`native:jump` isn't enough after installing, because the Kotlin and Swift code has to be compiled in.

Requirements: `nativephp/mobile` ^4.0. The plugin itself needs Android API 21+ and iOS 13+; NativePHP's own minimums are higher, so in practice any NativePHP v4 app works.

## Three ways in

| Method | What it does | Best for |
| --- | --- | --- |
| `Ocr::capture()` | Opens the camera on the **back lens**, takes a photo, reads it as taken | Most documents, receipts, and text shown on screens |
| `Ocr::scan()` | Opens the platform **document scanner** (edge detection, crop, clean-up), then reads the page | Crumpled or curled paper |
| `Ocr::recognize($path)` | Reads an image you already have | Gallery picks, photos from other plugins |

All three return a request id straight away. The work runs on a background thread, and the result arrives later as an event carrying the same id:

| Event | Payload |
| --- | --- |
| `Protendai\Ocr\Events\TextRecognized` | `id`, `text` (the engine's reading order), `lines` (strings), `boxes` (each line with its position, see below), `path` (the image that was read), `width` / `height` (pixel size of the image the boxes refer to) |
| `Protendai\Ocr\Events\RecognitionFailed` | `id`, `message` (see [Errors](#errors)) |
| `Protendai\Ocr\Events\ScanCancelled` | `id`: the user closed the camera or scanner without a picture (`capture()` / `scan()` only) |

If the device refuses a request outright (a missing file, or no camera at all), the method throws `Protendai\Ocr\Exceptions\OcrException` and no event follows.

Only one `capture()` or `scan()` can be open at a time. Calling either again while the camera or scanner is still open answers the new id with `RecognitionFailed` (`RecognitionFailed::BUSY`); the open one carries on. `recognize()` calls are queued and can overlap freely.

### capture() vs scan()

Try `capture()` first. In testing, a plain back-camera photo read **better** than the document scanner on receipts and on text photographed off a screen. The scanner's clean-up filters sharpen edges and boost contrast for paper, which can smudge small digits. Keep `scan()` for crumpled or curled paper, where flattening the page matters more than keeping fine detail. Better still, let your users choose.

## Usage (PHP)

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Protendai\Ocr\Events\RecognitionFailed;
use Protendai\Ocr\Events\ScanCancelled;
use Protendai\Ocr\Events\TextRecognized;
use Protendai\Ocr\Exceptions\OcrException;
use Protendai\Ocr\Facades\Ocr;
use Protendai\Ocr\Layout;

class ScanReceipt extends NativeComponent
{
    public bool $scanning = false;

    public ?string $ocrRequestId = null;

    public string $text = '';

    public ?string $total = null;

    public function scan(): void
    {
        try {
            $this->ocrRequestId = Ocr::capture();   // or Ocr::scan()
            $this->scanning = true;
        } catch (OcrException $e) {
            // no camera on this device
        }
    }

    #[On(TextRecognized::class)]
    public function onTextRecognized(string $id, string $text, array $lines = [], array $boxes = [], ?string $path = null, ?int $width = null, ?int $height = null): void
    {
        if ($id !== $this->ocrRequestId) {
            return; // a result for some other request
        }

        $this->scanning = false;
        $this->text = Layout::rows($boxes) ?? $text;            // labels next to their values
        $this->total = Layout::valueBeside($boxes, '/total/i'); // "1,400.00"
    }

    #[On(ScanCancelled::class)]
    public function onScanCancelled(string $id): void
    {
        if ($id === $this->ocrRequestId) {
            $this->scanning = false;
        }
    }

    #[On(RecognitionFailed::class)]
    public function onRecognitionFailed(string $id, string $message): void
    {
        if ($id === $this->ocrRequestId) {
            $this->scanning = false;
            // $message === RecognitionFailed::CAMERA_PERMISSION_DENIED: point the user at Settings
        }
    }
}
```

Handlers receive the payload by parameter name, so declare only what you need and give optional values defaults (`array $boxes = []`, `?int $width = null`). Payload keys you don't declare are ignored, which keeps handlers working as the payload grows.

In a **Livewire** component, use `#[OnNative(TextRecognized::class)]` from `Native\Mobile\Attributes\OnNative` instead of `#[On]`; the methods are the same.

You can pass your own id to any of the methods, for example `Ocr::capture('receipt-42')`.

For images you already have, such as gallery picks from `Camera::pickImages('image')` or a `PhotoTaken` path from the camera plugin, call `Ocr::recognize($path)` and handle the same events.

## Usage (JavaScript)

For Inertia + Vue/React front ends the plugin ships an ES module with the same API, at `vendor/protendai/nativephp-mobile-ocr/resources/js/index.js` (TypeScript types alongside). Import it by path, or add an alias in `vite.config.js`:

```js
// vite.config.js: resolve: { alias: { '@protendai/nativephp-mobile-ocr': '/vendor/protendai/nativephp-mobile-ocr/resources/js/index.js' } }
import { capture, scan, recognize, onTextRecognized, onRecognitionFailed, onScanCancelled, rows, Messages, OcrError } from '@protendai/nativephp-mobile-ocr';

let requestId = null;

const stopListening = onTextRecognized(({ id, text, boxes, width, height }) => {
    if (id !== requestId) return;
    console.log(rows(boxes) ?? text);
});

onRecognitionFailed(({ id, message }) => {
    if (id === requestId && message === Messages.CameraPermissionDenied) {
        // point the user at Settings
    }
});

onScanCancelled(({ id }) => { /* the user backed out */ });

try {
    requestId = await capture();          // or scan(), or recognize(path)
} catch (e) {
    if (e instanceof OcrError) console.warn(e.code, e.message);  // e.g. UNSUPPORTED
}

// later, e.g. in onUnmounted / a useEffect cleanup:
stopListening();
```

| Export | What it is |
| --- | --- |
| `recognize(path, id?)`, `capture(id?)`, `scan(id?)` | Same as the PHP methods; resolve to the request id, reject with `OcrError` (`code`, `message`) when the device refuses |
| `onTextRecognized(cb)`, `onRecognitionFailed(cb)`, `onScanCancelled(cb)`, `on(eventName, cb)` | Listen on NativePHP's `native-event` channel; each returns a function that stops listening |
| `Events` | The event class names, e.g. `Events.TextRecognized` (also usable with `On()` from `@nativephp/mobile`) |
| `Messages` | `CameraPermissionDenied`, `Busy` |
| `rows(boxes)` | The JavaScript twin of `Layout::rows()` |

## Reading receipts: positions and layout helpers

Each entry in `boxes` is one recognised line with its position: `['text' => 'Total', 'left' => 40, 'top' => 580, 'right' => 160, 'bottom' => 608]`. Positions are **pixels of the upright image that was read**, with a top-left origin, on both platforms. `width` and `height` give that image's size, so `left / width` is a 0–1 fraction if you need one. Large photos are downscaled before reading (longest side at most 4096 px), so compare boxes with `width` / `height` rather than with the file's own dimensions.

Before 1.0, iOS reported 0–1 fractions. The helpers below don't care about units, so code built on them works with either.

**`Layout::rows(array $boxes): ?string`** rebuilds the text row by row, left to right, the way a person reads it:

```
What the engine returns:        Layout::rows():
Energy Bought(kWh)              Energy Bought(kWh)   297.18
Tender Amount                   Tender Amount   1,400.00
Energy Charge                   Energy Charge   1,320.75
297.18
1,400.00
1,320.75
```

It returns `null` when there are no positions, so `Layout::rows($boxes) ?? $text` is always safe.

**`Layout::valueBeside(array $boxes, string $label, ?string $exclude = null): ?string`** returns the mostly-numeric line to the right of a label whose vertical centre is closest. Use it when rows don't line up: a slightly tilted photo, or a label that wraps onto two lines with its value printed between them:

```php
Layout::valueBeside($boxes, '/energy|bought|kwh/i', '/charge/i'); // "297.18"
```

Position alone can't decide when a photo is tilted by a whole line or more, because the value is then genuinely nearer the next label. Asking users to hold the phone level over the page fixes that.

Both helpers are plain PHP with no Laravel dependency, so you can unit-test your own parsing against recorded `boxes`.

## Permissions

- **Android `capture()`**: the plugin adds no permissions. If your app declares `android.permission.CAMERA` (the camera plugin does), Android only allows camera intents once it's granted, so `capture()` asks for it on first use. A refusal arrives as `RecognitionFailed` with the message `Camera permission denied` (`RecognitionFailed::CAMERA_PERMISSION_DENIED`). The photo goes straight into your app's cache through NativePHP's built-in FileProvider; nothing is written to the gallery.
- **Android `scan()`**: needs no permission. It runs in Google Play services, which downloads the scanner module the first time it's used, so that first scan needs a connection. If the module isn't available you get `RecognitionFailed` with a message starting `Scanner unavailable`, and can fall back to `capture()`.
- **iOS `capture()` and `scan()`**: the plugin adds `NSCameraUsageDescription` ("This app uses the camera to photograph documents and read the text on them."). To change the wording, set it in your app's `config/nativephp.php` under `permissions`, which wins over plugins. Camera access is asked for on first use; a refusal (or a camera turned off in Settings or by Screen Time) arrives as `RecognitionFailed` with `Camera permission denied`, the same as Android.
- **`recognize()`** needs no permissions.

## Errors

`RecognitionFailed` messages you may want to branch on:

| Message | When |
| --- | --- |
| `Camera permission denied` (`RecognitionFailed::CAMERA_PERMISSION_DENIED`) | The user refused camera access (`capture()`; on iOS also `scan()`) |
| `Another capture or scan is already in progress` (`RecognitionFailed::BUSY`) | `capture()` / `scan()` called while one is open |
| `No camera app available`, `Could not prepare the camera`, `Could not open the camera: …` | Android `capture()` could not open a camera app; fall back to the camera plugin |
| `Scanner unavailable: …`, `Could not start the scanner: …` | Android `scan()`: Play services or the scanner module is missing; fall back to `capture()` |
| `The camera returned no photo`, `The scanner returned no page` | The camera or scanner finished without an image |
| `Could not open image: …` | The file isn't an image the platform can decode |

Anything else is an engine error message, passed through as-is.

`OcrException` (thrown, no event follows) carries `errorCode`: `INVALID_PARAMETERS` (no path), `FILE_NOT_FOUND`, or `UNSUPPORTED` (no camera, or no document scanner on this iOS device).

## Files

`capture()` and `scan()` save the image in a plugin folder inside your app's cache (`ocr/`), and `TextRecognized::$path` points at it. Images left by earlier requests are deleted when a later capture or scan starts, and each one is kept for at least a minute, so read or copy `$path` in your `TextRecognized` handler if you want to keep it. A cancelled or failed capture deletes its file straight away. `recognize()` never touches the file you pass in.

## Platform notes

- **Android text recognition** uses the *bundled* ML Kit model (`com.google.mlkit:text-recognition`). The model ships inside your APK, adding a few MB, and works offline from the first launch, unlike the Play Services variant, which downloads it on first use. EXIF rotation is respected.
- **Android back lens:** `capture()` sends the "use the back camera" extras that Samsung, Google and most other camera apps understand. A camera app that ignores all of them may still open the last-used lens.
- **Large photos** are decoded upright (EXIF orientation applied) and downscaled so their longest side is at most 4096 px before recognition, which keeps 50–108 MP phone photos from running the app out of memory without losing fine print on normal photos.
- **iOS** uses Vision's `VNRecognizeTextRequest` at the `.accurate` level, plus VisionKit's document camera for `scan()`. Both are system frameworks, so there are no pods or Swift packages. Language correction is **off**, because it "corrects" long digit runs such as codes, tokens and account numbers into words.

## Testing

The plugin adds OCR assertions to NativePHP's `FakeBridge`, so component tests read in plain terms:

```php
use Native\Mobile\Testing\Native;
use Protendai\Ocr\Events\TextRecognized;

it('reads a receipt', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('00000000-0000-0000-0000-000000000001'));

    Native::test(ScanReceipt::class)
        ->call('scan')
        ->assertCaptureRequested()
        ->emitNative(TextRecognized::class, [
            'id' => '00000000-0000-0000-0000-000000000001',
            'text' => "Total\n1,400.00",
            'boxes' => [
                ['text' => 'Total', 'left' => 40, 'top' => 580, 'right' => 160, 'bottom' => 608],
                ['text' => '1,400.00', 'left' => 540, 'top' => 582, 'right' => 680, 'bottom' => 610],
            ],
        ])
        ->assertSet('total', '1,400.00');
});
```

- `assertCaptureRequested(?callable $filter = null)`: `Ocr.Capture` was called.
- `assertScanRequested(?callable $filter = null)`: `Ocr.Scan` was called.
- `assertOcrRequested(?callable $filter = null)`: `Ocr.Recognize` was called, optionally matching its `path` / `id` params.
- `assertNoOcrRequested()`: `Ocr.Recognize` was never called.

To deliver a result, emit the event with the id your component stored. Pin the id with `Str::createUuidsUsing()` if you let the plugin generate it.

**Tip:** record real `boxes` from your users' documents (log them in a debug build, or show the recognised text in the app so people can copy it to you) and turn them into fixtures. Synthetic layouts rarely match what a phone actually sees.

## Limitations

- Printed Latin-script text only. Handwriting and other scripts aren't supported in this version.
- One image per call. `scan()` is limited to one page.
- Accuracy depends on the photo: good light, a flat page and a level phone matter more than anything else.

## Support

Questions, bugs and feature requests: [GitHub issues](https://github.com/protendai/nativephp-mobile-ocr/issues). See the [changelog](CHANGELOG.md) for what changed between versions.

## License

MIT. See [LICENSE](LICENSE).
