package com.protendai.plugins.ocr

import android.net.Uri
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
     *   - path: string - absolute path to a JPEG/PNG on the device
     *   - id: (optional) string - request id echoed back on the result event
     * Returns immediately; the result arrives as TextRecognized or RecognitionFailed.
     */
    class Recognize(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["path"] as? String
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "path is required")
            val id = parameters["id"] as? String ?: UUID.randomUUID().toString()

            val file = File(path)
            if (!file.exists()) {
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
                } catch (e: Exception) {
                    Log.e(TAG, "Could not start the scanner: ${e.message}", e)
                    fail(activity, id, "Could not start the scanner: ${e.message}")
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /** Run ML Kit on an image file off the UI thread and dispatch the result. */
    internal fun recognizeFile(activity: FragmentActivity, file: File, id: String) {
        // Decoding a full-size photo is slow; keep it off the PHP and UI threads.
        executor.execute {
            val image = try {
                InputImage.fromFilePath(activity, Uri.fromFile(file)) // respects EXIF rotation
            } catch (e: Exception) {
                Log.e(TAG, "Could not open image: ${e.message}", e)
                fail(activity, id, "Could not open image: ${e.message}")
                return@execute
            }

            val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)

            recognizer.process(image)
                .addOnSuccessListener { result ->
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
                    })
                }
                .addOnFailureListener { e ->
                    Log.e(TAG, "Recognition failed: ${e.message}", e)
                    fail(activity, id, e.message ?: "Recognition failed")
                }
                .addOnCompleteListener { recognizer.close() }
        }
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
