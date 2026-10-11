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
    private const val TEXT_RECOGNIZED = "Protendai\\Ocr\\Events\\TextRecognized"
    private const val RECOGNITION_FAILED = "Protendai\\Ocr\\Events\\RecognitionFailed"

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

            // Decoding a full-size photo is slow; keep it off the PHP and UI threads.
            executor.execute {
                val image = try {
                    InputImage.fromFilePath(activity, Uri.fromFile(file)) // respects EXIF rotation
                } catch (e: Exception) {
                    Log.e(TAG, "Could not open image: ${e.message}", e)
                    fail(id, "Could not open image: ${e.message}")
                    return@execute
                }

                val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)

                recognizer.process(image)
                    .addOnSuccessListener { result ->
                        val lines = JSONArray()
                        result.textBlocks.forEach { block -> block.lines.forEach { lines.put(it.text) } }

                        dispatch(TEXT_RECOGNIZED, JSONObject().apply {
                            put("id", id)
                            put("text", result.text)
                            put("lines", lines)
                        })
                    }
                    .addOnFailureListener { e ->
                        Log.e(TAG, "Recognition failed: ${e.message}", e)
                        fail(id, e.message ?: "Recognition failed")
                    }
                    .addOnCompleteListener { recognizer.close() }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }

        private fun fail(id: String, message: String) {
            dispatch(RECOGNITION_FAILED, JSONObject().apply {
                put("id", id)
                put("message", message)
            })
        }

        private fun dispatch(event: String, payload: JSONObject) {
            // Events must be dispatched on the main thread or they are silently dropped.
            Handler(Looper.getMainLooper()).post {
                NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
            }
        }
    }
}
