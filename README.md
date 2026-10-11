# protendai/nativephp-mobile-ocr

Free, on-device OCR (text recognition) for [NativePHP for Mobile](https://nativephp.com/docs/mobile) v4.

Point the back camera at a receipt, a slip or a label and get the text back. It uses Google ML Kit on Android and Apple Vision on iOS. Both run entirely on the phone, so there's no API key, no server and no internet connection.

It also handles the details that trip up OCR on real documents:

- **The camera actually opens on the back lens.** A camera app launched the usual way reopens whichever lens was used last, which is often the selfie camera.
- **Two-column layouts read correctly.** OCR engines read a receipt one column at a time, so "Units" and "297.18" come out far apart. The plugin gives you each line's position, plus helpers that put labels back next to their values.
- **An optional document scanner** finds the page edges and flattens the photo.

## Why

NativePHP v4 had no free way to read text from an image. This plugin fills that gap with the text recognition engines already built into each platform. They're wrapped in a small PHP API that fits the v4 SuperNative event model.

It was built for Token Scanner, an app that reads Zimbabwean electricity tokens off till slips and receipts. Everything below was tested against real ones.

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

Requirements: `nativephp/mobile` ^4.0, Android 8+ (API 26), iOS 18+.

## Three ways in

| Method | What it does | Best for |
| --- | --- | --- |
| `Ocr::capture()` | Opens the camera on the **back lens**, takes a photo, reads it as taken | Most documents, receipts, and text shown on screens |
| `Ocr::scan()` | Opens the platform **document scanner** (edge detection, crop, clean-up), then reads the page | Crumpled or curled paper |
| `Ocr::recognize($path)` | Reads an image you already have | Gallery picks, photos from other plugins |

All three return a request id straight away. The work runs on a background thread, and the result arrives later as an event carrying the same id:

| Event | Payload |
| --- | --- |
| `Protendai\Ocr\Events\TextRecognized` | `id`, `text` (the engine's reading order), `lines` (strings), `boxes` (each line with its position, see below), `path` (the image that was read) |
| `Protendai\Ocr\Events\RecognitionFailed` | `id`, `message` |
| `Protendai\Ocr\Events\ScanCancelled` | `id`: the user closed the camera or scanner without a picture (`capture()` / `scan()` only) |

If the device refuses a request outright (a missing file, or no camera at all), the method throws `Protendai\Ocr\Exceptions\OcrException` and no event follows.

### capture() vs scan()

Try `capture()` first. In testing, a plain back-camera photo read **better** than the document scanner on receipts and on text photographed off a screen. The scanner's clean-up filters sharpen edges and boost contrast for paper, which can smudge small digits. Keep `scan()` for crumpled or curled paper, where flattening the page matters more than keeping fine detail. Better still, let your users choose.

## Usage

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
    public function onTextRecognized(string $id, string $text, array $lines = [], array $boxes = []): void
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
            $this->scanning = false; // e.g. "Camera permission denied"
        }
    }
}
```

You can pass your own id to any of the methods, for example `Ocr::capture('receipt-42')`.

For images you already have, such as gallery picks from `Camera::pickImages('image')` or a `PhotoTaken` path from the camera plugin, call `Ocr::recognize($path)` and handle the same events.

## Reading receipts: positions and layout helpers

Each entry in `boxes` is one recognised line with its position: `['text' => 'Total', 'left' => 40, 'top' => 580, 'right' => 160, 'bottom' => 608]`. Android reports pixels and iOS reports 0–1 fractions. Both use a top-left origin, and the helpers work with either.

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

- **Android `capture()`**: if your app declares `android.permission.CAMERA` (the camera plugin does), Android only allows camera intents once it's granted. `capture()` asks for it on first use. A refusal arrives as `RecognitionFailed` with the message `Camera permission denied`. The photo is removed from the gallery once it's been read.
- **Android `scan()`**: needs no permission. It runs in Google Play services, which downloads the scanner module the first time it's used, so that first scan needs a connection. If the module isn't available you get `RecognitionFailed` with a message starting `Scanner unavailable`, and can fall back to `capture()`.
- **iOS `capture()` and `scan()`**: your app needs `NSCameraUsageDescription`.
- **`recognize()`** needs no permissions.

## Platform notes

- **Android text recognition** uses the *bundled* ML Kit model (`com.google.mlkit:text-recognition`). The model ships inside your APK, adding a few MB, and works offline from the first launch, unlike the Play Services variant, which downloads it on first use. EXIF rotation is respected.
- **Android back lens:** `capture()` sends the "use the back camera" extras that Samsung, Google and most other camera apps understand. A camera app that ignores all of them may still open the last-used lens.
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

## License

MIT
