## protendai/nativephp-mobile-ocr

Free, offline text recognition for NativePHP Mobile v4: ML Kit on Android, Apple Vision on iOS.

- `Ocr::recognize(string $path, ?string $id = null): string` starts recognition of an image file and returns a request id immediately. It never blocks; the result arrives as an event.
- Listen with `#[On(TextRecognized::class)]` (`id`, `text`, `lines`) and `#[On(RecognitionFailed::class)]` (`id`, `message`) from `Native\Mobile\Attributes\On`. Always compare the event `id` with the id you stored, because other screens may start requests too.
- Get image paths from the camera plugin (`PhotoTaken` → `$path`) or the gallery (`MediaSelected` → `$files[0]['path']`). Never pass image bytes across the bridge.
- The plugin has native code: after installing, tell the user to rebuild with `php artisan native:run`. Jump alone is not enough.
- Tests: `assertOcrRequested(fn (array $p) => $p['path'] === ...)` and `assertNoOcrRequested()` are available on the FakeBridge / component harness.

@verbatim
<code-snippet name="Scan a photo and read the text" lang="php">
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Camera\PhotoTaken;
use Protendai\Ocr\Events\TextRecognized;
use Protendai\Ocr\Facades\Ocr;

#[On(PhotoTaken::class)]
public function onPhotoTaken(string $path): void
{
    $this->ocrRequestId = Ocr::recognize($path);
}

#[On(TextRecognized::class)]
public function onTextRecognized(string $id, string $text, array $lines = []): void
{
    if ($id === $this->ocrRequestId) {
        $this->text = $text;
    }
}
</code-snippet>
@endverbatim
