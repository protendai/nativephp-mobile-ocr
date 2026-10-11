# protendai/nativephp-mobile-ocr

Free, on-device OCR (text recognition) for [NativePHP for Mobile](https://nativephp.com/docs/mobile) v4.

Give it the path to a photo, get the text back. It uses Google ML Kit on Android and Apple Vision on iOS, both running entirely on the phone: no API key, no server, no internet connection needed.

## Why

NativePHP v4 had no free way to read text from an image. This plugin fills that gap with the text recognition engines already built into each platform, wrapped in a small PHP API that fits the v4 SuperNative event model.

It was built for Token Scanner, an app that reads Zimbabwean electricity tokens off paper till slips.

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

`native:jump` is not enough after installing, because the Kotlin and Swift code has to be compiled in.

Requirements: `nativephp/mobile` ^4.0, Android 8+ (API 26), iOS 18+.

## Usage

`Ocr::recognize()` starts recognition and returns a request id straight away. The work happens on a background thread, and the result comes back as an event carrying the same id.

It pairs naturally with the camera plugin, which hands you a file path:

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Facades\Camera;
use Protendai\Ocr\Events\RecognitionFailed;
use Protendai\Ocr\Events\TextRecognized;
use Protendai\Ocr\Facades\Ocr;

class ScanReceipt extends NativeComponent
{
    public bool $scanning = false;

    public ?string $ocrRequestId = null;

    public string $text = '';

    public function scan(): void
    {
        Camera::getPhoto();
    }

    #[On(PhotoTaken::class)]
    public function onPhotoTaken(string $path): void
    {
        $this->scanning = true;
        $this->ocrRequestId = Ocr::recognize($path);
    }

    #[On(TextRecognized::class)]
    public function onTextRecognized(string $id, string $text, array $lines = []): void
    {
        if ($id !== $this->ocrRequestId) {
            return; // a result for some other request
        }

        $this->scanning = false;
        $this->text = $text;
    }

    #[On(RecognitionFailed::class)]
    public function onRecognitionFailed(string $id, string $message): void
    {
        if ($id === $this->ocrRequestId) {
            $this->scanning = false;
        }
    }
}
```

You can pass your own id as the second argument: `Ocr::recognize($path, 'receipt-42')`.

Photos from the gallery work too: use `Camera::pickImages('image')` and pass `$files[0]['path']` from the `MediaSelected` event.

## Events

| Event | Payload |
| --- | --- |
| `Protendai\Ocr\Events\TextRecognized` | `id` (string), `text` (string, lines joined with `\n`), `lines` (array of strings, top to bottom) |
| `Protendai\Ocr\Events\RecognitionFailed` | `id` (string), `message` (string) |

If the path is missing or the file doesn't exist, `recognize()` gets an error back from the bridge immediately and no event fires.

## Platform notes

- **Android** uses the *bundled* ML Kit model (`com.google.mlkit:text-recognition`). The model ships inside your APK (it adds a few MB to the app) and works offline from the first launch, unlike the Play Services variant, which downloads the model on first use. EXIF rotation is respected.
- **iOS** uses Vision's `VNRecognizeTextRequest` at the `.accurate` level. It's a system framework, so there are no pods or Swift packages. Language correction is **off** (`usesLanguageCorrection = false`), because it "corrects" long digit runs such as codes, tokens and account numbers into words.
- The plugin asks for no permissions. If you use the camera, the camera plugin handles camera permission.

## Testing

The plugin adds OCR assertions to NativePHP's `FakeBridge`, so component tests read in plain terms:

```php
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Testing\Native;
use Protendai\Ocr\Events\TextRecognized;

it('reads a receipt', function () {
    Native::test(ScanReceipt::class)
        ->emitNative(PhotoTaken::class, ['path' => '/tmp/receipt.jpg'])
        ->assertOcrRequested(fn (array $params) => $params['path'] === '/tmp/receipt.jpg')
        ->assertSet('scanning', true);
});
```

- `assertOcrRequested(?callable $filter = null)`: `Ocr.Recognize` was called, optionally matching its `path` / `id` params.
- `assertNoOcrRequested()`: it was never called.

To deliver a result, emit `TextRecognized` with the id your component stored. Pin it with `Str::createUuidsUsing()` if you let the plugin generate it.

## Limitations

- Printed Latin-script text only. Handwriting and other scripts are not supported in this version.
- One image per call. Results come back as plain text and lines, without bounding boxes.
- Accuracy depends on the photo: good light, a flat page and a steady hand matter more than anything else.

## License

MIT
