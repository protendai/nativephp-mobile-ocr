package com.protendai.plugins.ocr

import android.Manifest
import android.app.Activity
import android.content.ContentValues
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.provider.MediaStore
import android.util.Log
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.google.mlkit.vision.documentscanner.GmsDocumentScannerOptions
import com.google.mlkit.vision.documentscanner.GmsDocumentScanning
import com.google.mlkit.vision.documentscanner.GmsDocumentScanningResult
import org.json.JSONObject
import java.io.File

/**
 * Headless fragment that owns the activity-result launchers for the document
 * scanner and the camera capture (launchers must be registered before the
 * activity starts, so a fragment that lives for the activity's lifetime holds them).
 */
class ScanCoordinator : Fragment() {

    companion object {
        private const val TAG = "OcrScanCoordinator"
        private const val FRAGMENT_TAG = "OcrScanCoordinator"
        private const val PENDING_ID_KEY = "pending_scan_id"
        private const val PENDING_URI_KEY = "pending_capture_uri"

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
    private var pendingCaptureUri: Uri? = null
    private lateinit var scannerLauncher: ActivityResultLauncher<IntentSenderRequest>
    private lateinit var captureLauncher: ActivityResultLauncher<Intent>
    private lateinit var permissionLauncher: ActivityResultLauncher<String>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        pendingId = savedInstanceState?.getString(PENDING_ID_KEY)
        pendingCaptureUri = savedInstanceState?.getString(PENDING_URI_KEY)?.let(Uri::parse)

        permissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            val id = pendingId ?: return@registerForActivityResult
            if (granted) {
                launchCamera(id)
            } else {
                pendingId = null
                OcrFunctions.fail(requireActivity(), id, "Camera permission denied")
            }
        }

        captureLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            val activity = requireActivity()
            val id = pendingId ?: return@registerForActivityResult
            val uri = pendingCaptureUri
            pendingId = null
            pendingCaptureUri = null

            if (result.resultCode != Activity.RESULT_OK || uri == null) {
                uri?.let { activity.contentResolver.delete(it, null, null) }
                OcrFunctions.dispatch(activity, OcrFunctions.SCAN_CANCELLED, JSONObject().put("id", id))
                return@registerForActivityResult
            }

            val file = copyToCache(activity, uri, id) ?: return@registerForActivityResult
            // The photo was only needed for reading; keep it out of the user's gallery.
            activity.contentResolver.delete(uri, null, null)
            OcrFunctions.recognizeFile(activity, file, id)
        }

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
            val file = copyToCache(activity, page.imageUri, id) ?: return@registerForActivityResult
            OcrFunctions.recognizeFile(activity, file, id)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        pendingId?.let { outState.putString(PENDING_ID_KEY, it) }
        pendingCaptureUri?.let { outState.putString(PENDING_URI_KEY, it.toString()) }
    }

    /**
     * Take a photo with the phone's camera app, asking for the back lens, then read it.
     * A camera app opened by intent reopens whichever lens was used last (often the
     * selfie camera); these extras ask for the back one. Samsung, Google and most
     * OEM camera apps honour at least one of them; the rest are simply ignored.
     */
    fun capture(id: String) {
        val activity = requireActivity()
        pendingId = id

        // An app that declares CAMERA must hold it before launching a camera intent.
        val declared = try {
            activity.packageManager.getPackageInfo(activity.packageName, PackageManager.GET_PERMISSIONS)
                .requestedPermissions?.contains(Manifest.permission.CAMERA) == true
        } catch (e: Exception) {
            false
        }

        if (declared && ContextCompat.checkSelfPermission(activity, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
            return
        }

        launchCamera(id)
    }

    private fun launchCamera(id: String) {
        val activity = requireActivity()

        val uri = activity.contentResolver.insert(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            ContentValues().apply {
                put(MediaStore.Images.Media.DISPLAY_NAME, "ocr-capture-$id.jpg")
                put(MediaStore.Images.Media.MIME_TYPE, "image/jpeg")
            }
        )

        if (uri == null) {
            pendingId = null
            OcrFunctions.fail(activity, id, "Could not prepare the camera")
            return
        }

        pendingCaptureUri = uri

        val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE).apply {
            putExtra(MediaStore.EXTRA_OUTPUT, uri)
            addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
            // Back lens, in the dialects different camera apps understand.
            putExtra("android.intent.extras.CAMERA_FACING", 0)
            putExtra("android.intent.extras.LENS_FACING_BACK", 1)
            putExtra("android.intent.extra.USE_FRONT_CAMERA", false)
            putExtra("com.google.assistant.extra.USE_FRONT_CAMERA", false)
        }

        try {
            captureLauncher.launch(intent)
        } catch (e: Exception) {
            Log.e(TAG, "No camera app: ${e.message}", e)
            activity.contentResolver.delete(uri, null, null)
            pendingId = null
            pendingCaptureUri = null
            OcrFunctions.fail(activity, id, "No camera app available")
        }
    }

    private fun copyToCache(activity: FragmentActivity, uri: Uri, id: String): File? {
        val file = File(activity.cacheDir, "ocr-scan-$id.jpg")
        return try {
            activity.contentResolver.openInputStream(uri)!!.use { input ->
                file.outputStream().use { output -> input.copyTo(output) }
            }
            file
        } catch (e: Exception) {
            Log.e(TAG, "Could not save the image: ${e.message}", e)
            OcrFunctions.fail(activity, id, "Could not save the image: ${e.message}")
            null
        }
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
