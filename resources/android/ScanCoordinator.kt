package com.protendai.plugins.ocr

import android.app.Activity
import android.os.Bundle
import android.util.Log
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.google.mlkit.vision.documentscanner.GmsDocumentScannerOptions
import com.google.mlkit.vision.documentscanner.GmsDocumentScanning
import com.google.mlkit.vision.documentscanner.GmsDocumentScanningResult
import org.json.JSONObject
import java.io.File

/**
 * Headless fragment that owns the document scanner's activity-result launcher
 * (launchers must be registered before the activity starts, so a fragment that
 * lives for the activity's lifetime holds it).
 */
class ScanCoordinator : Fragment() {

    companion object {
        private const val TAG = "OcrScanCoordinator"
        private const val FRAGMENT_TAG = "OcrScanCoordinator"
        private const val PENDING_ID_KEY = "pending_scan_id"

        fun install(activity: FragmentActivity): ScanCoordinator {
            val fm = activity.supportFragmentManager
            var coordinator = fm.findFragmentByTag(FRAGMENT_TAG) as? ScanCoordinator
            if (coordinator == null) {
                coordinator = ScanCoordinator()
                fm.beginTransaction().add(coordinator, FRAGMENT_TAG).commitNow()
            }
            return coordinator
        }
    }

    private var pendingId: String? = null
    private lateinit var scannerLauncher: ActivityResultLauncher<IntentSenderRequest>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        pendingId = savedInstanceState?.getString(PENDING_ID_KEY)

        scannerLauncher = registerForActivityResult(
            ActivityResultContracts.StartIntentSenderForResult()
        ) { result ->
            val activity = requireActivity()
            val id = pendingId ?: return@registerForActivityResult
            pendingId = null

            if (result.resultCode != Activity.RESULT_OK) {
                OcrFunctions.dispatch(activity, OcrFunctions.SCAN_CANCELLED, JSONObject().put("id", id))
                return@registerForActivityResult
            }

            val page = GmsDocumentScanningResult.fromActivityResultIntent(result.data)?.pages?.firstOrNull()
            if (page == null) {
                OcrFunctions.fail(activity, id, "The scanner returned no page")
                return@registerForActivityResult
            }

            // The scanner's URI is temporary; copy the page somewhere PHP can keep reading it.
            val file = File(activity.cacheDir, "ocr-scan-$id.jpg")
            try {
                activity.contentResolver.openInputStream(page.imageUri)!!.use { input ->
                    file.outputStream().use { output -> input.copyTo(output) }
                }
            } catch (e: Exception) {
                Log.e(TAG, "Could not save the scanned page: ${e.message}", e)
                OcrFunctions.fail(activity, id, "Could not save the scanned page: ${e.message}")
                return@registerForActivityResult
            }

            OcrFunctions.recognizeFile(activity, file, id)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        pendingId?.let { outState.putString(PENDING_ID_KEY, it) }
    }

    fun start(id: String) {
        val activity = requireActivity()
        pendingId = id

        val options = GmsDocumentScannerOptions.Builder()
            .setScannerMode(GmsDocumentScannerOptions.SCANNER_MODE_FULL)
            .setPageLimit(1)
            .setGalleryImportAllowed(true)
            .setResultFormats(GmsDocumentScannerOptions.RESULT_FORMAT_JPEG)
            .build()

        GmsDocumentScanning.getClient(options)
            .getStartScanIntent(activity)
            .addOnSuccessListener { intentSender ->
                scannerLauncher.launch(IntentSenderRequest.Builder(intentSender).build())
            }
            .addOnFailureListener { e ->
                // Usually Google Play services is missing or still downloading the scanner module.
                Log.e(TAG, "Scanner unavailable: ${e.message}", e)
                pendingId = null
                OcrFunctions.fail(activity, id, "Scanner unavailable: ${e.message}")
            }
    }
}
