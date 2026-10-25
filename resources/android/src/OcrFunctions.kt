package com.protendai.plugins.ocr

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.UUID
import java.util.concurrent.Executors

/**
 * On-device text recognition with the bundled ML Kit Latin model (works offline).
 * Namespace: "Ocr.*"
 */
object OcrFunctions {

    private const val TAG = "OcrFunctions"
    internal const val TEXT_RECOGNIZED = "Protendai\\Ocr\\Events\\TextRecognized"
    internal const val RECOGNITION_FAILED = "Protendai\\Ocr\\Events\\RecognitionFailed"
    internal const val SCAN_CANCELLED = "Protendai\\Ocr\\Events\\ScanCancelled"

    private val executor = Executors.newSingleThreadExecutor()

    /**
     * Recognise text in an image file.
     * Parameters:
     *   - path: string - absolute path to a JPEG/PNG/WebP (HEIC on Android 9+) on the device
     *   - id: (optional) string - request id echoed back on the result event
     * Returns immediately; the result arrives as TextRecognized or RecognitionFailed.
     */
    class Recognize(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["path"] as? String
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "path is required")
            val id = parameters["id"] as? String ?: UUID.randomUUID().toString()

            val file = File(path)
            if (!file.isFile) {
                return BridgeResponse.error("FILE_NOT_FOUND", "File not found: $path")
            }

            recognizeFile(activity, file, id)

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /**
     * Open Google's document scanner (always the back camera, finds the page edges,
     * flattens and crops it), then recognise the text on the scanned page.
     * Parameters:
     *   - id: (optional) string - request id echoed back on the result event
     * Returns immediately; the result arrives as TextRecognized (with the page's path),
     * RecognitionFailed, or ScanCancelled when the user backs out.
     */
    class Scan(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String ?: UUID.randomUUID().toString()

            activity.runOnUiThread {
                try {
                    ScanCoordinator.install(activity).start(id)
                } catch (e: Throwable) {
                    Log.e(TAG, "Could not start the scanner: ${e.message}", e)
                    fail(activity, id, "Could not start the scanner: ${e.message}")
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /**
     * Take a photo with the phone's camera app, asking for the back lens, then
     * recognise it. Unlike Scan, the photo is not cropped or filtered, which keeps
     * fine print sharp (and reads better off screens).
     * Parameters:
     *   - id: (optional) string - request id echoed back on the result event
     * Returns immediately; the result arrives as TextRecognized, RecognitionFailed,
     * or ScanCancelled when the user backs out.
     */
    class Capture(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String ?: UUID.randomUUID().toString()

            activity.runOnUiThread {
                try {
                    ScanCoordinator.install(activity).capture(id)
                } catch (e: Throwable) {
                    Log.e(TAG, "Could not open the camera: ${e.message}", e)
                    fail(activity, id, "Could not open the camera: ${e.message}")
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /** Longest side, in pixels, of the image handed to ML Kit. Larger photos are downscaled. */
    private const val MAX_SIDE = 4096

    /**
     * Run ML Kit on an image file off the UI thread and dispatch the result.
     *
     * The image is decoded upright (EXIF orientation applied) and downscaled so its
     * longest side is at most MAX_SIDE, which keeps 50-108 MP photos from running the
     * app out of memory. Box coordinates are pixels of that decoded image, whose size
     * is reported as width/height.
     */
    internal fun recognizeFile(activity: FragmentActivity, file: File, id: String) {
        executor.execute {
            val bitmap = try {
                loadUpright(file)
            } catch (e: Throwable) {
                Log.e(TAG, "Could not open image: ${e.message}", e)
                fail(activity, id, "Could not open image: ${e.message ?: e.javaClass.simpleName}")
                return@execute
            }

            if (bitmap == null) {
                fail(activity, id, "Could not open image: unsupported or damaged file")
                return@execute
            }

            val width = bitmap.width
            val height = bitmap.height

            val recognizer = try {
                TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
            } catch (e: Throwable) {
                Log.e(TAG, "Recognition failed: ${e.message}", e)
                fail(activity, id, e.message ?: "Recognition failed")
                return@execute
            }

            try {
                recognizer.process(InputImage.fromBitmap(bitmap, 0))
                    .addOnSuccessListener { result ->
                        try {
                            val lines = JSONArray()
                            // Where each line sits, in image pixels, so PHP can rebuild rows on
                            // two-column layouts (receipts) that ML Kit reads column by column.
                            val boxes = JSONArray()
                            result.textBlocks.forEach { block ->
                                block.lines.forEach { line ->
                                    lines.put(line.text)
                                    val box = line.boundingBox ?: return@forEach
                                    boxes.put(JSONObject().apply {
                                        put("text", line.text)
                                        put("left", box.left)
                                        put("top", box.top)
                                        put("right", box.right)
                                        put("bottom", box.bottom)
                                    })
                                }
                            }

                            dispatch(activity, TEXT_RECOGNIZED, JSONObject().apply {
                                put("id", id)
                                put("text", result.text)
                                put("lines", lines)
                                put("boxes", boxes)
                                put("path", file.absolutePath)
                                put("width", width)
                                put("height", height)
                            })
                        } catch (e: Throwable) {
                            Log.e(TAG, "Recognition failed: ${e.message}", e)
                            fail(activity, id, e.message ?: "Recognition failed")
                        }
                    }
                    .addOnFailureListener { e ->
                        Log.e(TAG, "Recognition failed: ${e.message}", e)
                        fail(activity, id, e.message ?: "Recognition failed")
                    }
                    .addOnCompleteListener { recognizer.close() }
            } catch (e: Throwable) {
                Log.e(TAG, "Recognition failed: ${e.message}", e)
                recognizer.close()
                fail(activity, id, e.message ?: "Recognition failed")
            }
        }
    }

    /**
     * Decode [file] upright and no larger than MAX_SIDE on its longest side.
     * Returns null when the file is not an image Android can decode.
     */
    private fun loadUpright(file: File): Bitmap? {
        val path = file.absolutePath
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(path, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / sample > MAX_SIDE) sample *= 2

        val orientation = try {
            ExifInterface(path).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)
        } catch (e: Throwable) {
            ExifInterface.ORIENTATION_NORMAL
        }

        // A low-memory phone can still fail on a 4096 px image; halve and try again.
        var lastError: OutOfMemoryError? = null
        repeat(3) {
            try {
                val decoded = BitmapFactory.decodeFile(path, BitmapFactory.Options().apply { inSampleSize = sample })
                    ?: return null
                return applyOrientation(decoded, orientation)
            } catch (e: OutOfMemoryError) {
                lastError = e
                sample *= 2
            }
        }
        throw lastError ?: OutOfMemoryError("Image too large")
    }

    /** Rotate/flip a bitmap per its EXIF orientation so ML Kit and the boxes see it upright. */
    private fun applyOrientation(bitmap: Bitmap, orientation: Int): Bitmap {
        val matrix = Matrix()
        when (orientation) {
            ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> matrix.setScale(-1f, 1f)
            ExifInterface.ORIENTATION_ROTATE_180 -> matrix.setRotate(180f)
            ExifInterface.ORIENTATION_FLIP_VERTICAL -> { matrix.setRotate(180f); matrix.postScale(-1f, 1f) }
            ExifInterface.ORIENTATION_TRANSPOSE -> { matrix.setRotate(90f); matrix.postScale(-1f, 1f) }
            ExifInterface.ORIENTATION_ROTATE_90 -> matrix.setRotate(90f)
            ExifInterface.ORIENTATION_TRANSVERSE -> { matrix.setRotate(-90f); matrix.postScale(-1f, 1f) }
            ExifInterface.ORIENTATION_ROTATE_270 -> matrix.setRotate(-90f)
            else -> return bitmap
        }

        val upright = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
        if (upright !== bitmap) bitmap.recycle()
        return upright
    }

    internal fun fail(activity: FragmentActivity, id: String, message: String) {
        dispatch(activity, RECOGNITION_FAILED, JSONObject().apply {
            put("id", id)
            put("message", message)
        })
    }

    internal fun dispatch(activity: FragmentActivity, event: String, payload: JSONObject) {
        // Events must be dispatched on the main thread or they are silently dropped.
        Handler(Looper.getMainLooper()).post {
            NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
        }
    }
}
