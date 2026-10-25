## protendai/nativephp-mobile-ocr

Free, offline text recognition and document scanning for NativePHP Mobile v4 on iOS and Android: ML Kit on Android, Apple Vision on iOS.

### Methods (facade `Protendai\Ocr\Facades\Ocr`)

All three return a request id immediately and never block; the result arrives later as an event carrying that id. Pass your own id or let the plugin generate a UUID.

- `Ocr::capture(?string $id = null): string` opens the camera on the back lens, takes a photo and reads it as taken. Best default for receipts, slips and text on screens.
- `Ocr::scan(?string $id = null): string` opens the platform document scanner (edge detection, crop, clean-up), then reads the page. For crumpled or curled paper. Android downloads the scanner module through Play services on first use.
- `Ocr::recognize(string $path, ?string $id = null): string` reads an image already on the device (gallery pick, camera plugin `PhotoTaken` path). Never pass image bytes across the bridge.
- All throw `Protendai\Ocr\Exceptions\OcrException` (`->errorCode`: `FILE_NOT_FOUND`, `INVALID_PARAMETERS`, `UNSUPPORTED`) when the device refuses outright; no event follows then.
- Only one `capture()` / `scan()` can be open at a time. A second call gets `RecognitionFailed` with `RecognitionFailed::BUSY` for its own id; the first carries on.

### Events (listen with `#[On(...)]` from `Native\Mobile\Attributes\On` in EDGE components, or `#[OnNative(...)]` in Livewire)

- `Protendai\Ocr\Events\TextRecognized`: `id`, `text` (engine reading order), `lines` (string[]), `boxes` (each line: `text`, `left`, `top`, `right`, `bottom` in pixels of the upright image, top-left origin, both platforms), `path` (the image read), `width`, `height` (size of the image the boxes refer to; may be null from older plugin versions, so keep them optional). `$event->rowText()` rebuilds rows.
- `Protendai\Ocr\Events\RecognitionFailed`: `id`, `message`. `RecognitionFailed::CAMERA_PERMISSION_DENIED` ('Camera permission denied') and `RecognitionFailed::BUSY` are stable messages to branch on.
- `Protendai\Ocr\Events\ScanCancelled`: `id`. The user closed the camera or scanner (`capture()` / `scan()` only).
- Always compare the event `id` with the id you stored: other screens may start requests too. Declare optional payload parameters with defaults (`array $boxes = []`, `?int $width = null`).

### Layout helpers (`Protendai\Ocr\Layout`, plain PHP, unit-agnostic)

- `Layout::rows(array $boxes): ?string` rebuilds two-column layouts (label | value) row by row; returns null without positions, so use `Layout::rows($boxes) ?? $text`.
- `Layout::valueBeside(array $boxes, string $labelRegex, ?string $excludeRegex = null, float $reach = 2.5): ?string` returns the numeric line to the right of a label, tolerant of tilt and wrapped labels.

### Permissions and files

- iOS: the plugin adds `NSCameraUsageDescription` (override the wording in `config('nativephp.permissions')`). Camera access is requested on first `capture()` / `scan()`.
- Android: no permissions are added. If the app declares `android.permission.CAMERA` (e.g. the camera plugin), `capture()` asks for it on first use.
- Images from `capture()` / `scan()` live in the app cache and are removed by a later capture or scan; copy `$path` in your `TextRecognized` handler if you want to keep it.
- The plugin has native code: after installing or updating, rebuild with `php artisan native:run`. `native:jump` is not enough.

### Testing

FakeBridge macros: `assertCaptureRequested()`, `assertScanRequested()`, `assertOcrRequested(fn (array $p) => $p['path'] === ...)`, `assertNoOcrRequested()`. Deliver results with `->emitNative(TextRecognized::class, [...])` using the id your component stored (pin generated ids with `Str::createUuidsUsing()`).

@verbatim
<code-snippet name="Capture a document and read it (EDGE component)" lang="php">
use Native\Mobile\Attributes\On;
use Protendai\Ocr\Events\RecognitionFailed;
use Protendai\Ocr\Events\ScanCancelled;
use Protendai\Ocr\Events\TextRecognized;
use Protendai\Ocr\Exceptions\OcrException;
use Protendai\Ocr\Facades\Ocr;
use Protendai\Ocr\Layout;

public ?string $ocrRequestId = null;

public function read(): void
{
    try {
        $this->ocrRequestId = Ocr::capture();
    } catch (OcrException $e) {
        // no camera on this device
    }
}

#[On(TextRecognized::class)]
public function onTextRecognized(string $id, string $text, array $lines = [], array $boxes = [], ?string $path = null, ?int $width = null, ?int $height = null): void
{
    if ($id !== $this->ocrRequestId) {
        return;
    }

    $this->text = Layout::rows($boxes) ?? $text;
    $this->total = Layout::valueBeside($boxes, '/total/i');
}

#[On(RecognitionFailed::class)]
public function onRecognitionFailed(string $id, string $message): void
{
    if ($id === $this->ocrRequestId && $message === RecognitionFailed::CAMERA_PERMISSION_DENIED) {
        // ask the user to allow camera access in Settings
    }
}

#[On(ScanCancelled::class)]
public function onScanCancelled(string $id): void
{
    // the user backed out
}
</code-snippet>

<code-snippet name="JavaScript (Inertia + Vue/React)" lang="js">
import { capture, onTextRecognized, onRecognitionFailed, rows } from '@protendai/nativephp-mobile-ocr';

let requestId = null;

const stop = onTextRecognized(({ id, text, boxes }) => {
    if (id === requestId) console.log(rows(boxes) ?? text);
});

requestId = await capture(); // throws OcrError when there is no camera
</code-snippet>
@endverbatim
