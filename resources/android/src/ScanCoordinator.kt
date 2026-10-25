package com.protendai.plugins.ocr

import android.Manifest
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Context
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
import androidx.core.content.FileProvider
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
 *
 * Only one capture or scan runs at a time: a second request while one is open is
 * answered straight away with RecognitionFailed (BUSY_MESSAGE) and the first one
 * carries on untouched.
 */
class ScanCoordinator : Fragment() {

    companion object {
        private const val TAG = "OcrScanCoordinator"
        private const val FRAGMENT_TAG = "OcrScanCoordinator"
        private const val PENDING_ID_KEY = "pending_scan_id"
        private const val PENDING_FILE_KEY = "pending_capture_file"

        /** Plugin images older than this are removed when the next capture or scan starts. */
        private const val KEEP_IMAGES_MS = 60_000L

        internal const val BUSY_MESSAGE = "Another capture or scan is already in progress"
        internal const val PERMISSION_DENIED_MESSAGE = "Camera permission denied"

        fun install(activity: FragmentActivity): ScanCoordinator {
            val fm = activity.supportFragmentManager
            var coordinator = fm.findFragmentByTag(FRAGMENT_TAG) as? ScanCoordinator
            if (coordinator == null) {
                coordinator = ScanCoordinator()
                // AllowingStateLoss: the bridge can call us while the activity is
                // stopping; a headless fragment has no UI state worth losing.
                fm.beginTransaction().add(coordinator, FRAGMENT_TAG).commitNowAllowingStateLoss()
            }
            return coordinator
        }

        /**
         * Where captured photos and scanned pages live: inside the app cache, which the
         * NativePHP FileProvider (`${applicationId}.fileprovider`, `<cache-path path=".">`)
         * already exposes, so the camera app can write there.
         */
        internal fun workDir(context: Context): File = File(context.cacheDir, "ocr").apply { mkdirs() }

        /** Remove images left by earlier captures and scans (each result's path stays valid for at least a minute). */
        internal fun removeOldImages(context: Context) {
            val cutoff = System.currentTimeMillis() - KEEP_IMAGES_MS
            try {
                workDir(context).listFiles()?.forEach { if (it.lastModified() < cutoff) it.delete() }
            } catch (e: Throwable) {
                Log.w(TAG, "Could not tidy old images: ${e.message}")
            }
        }

        /** A file name that is safe whatever the caller passed as the request id. */
        internal fun imageFile(context: Context, prefix: String, id: String): File {
            val safeId = id.replace(Regex("[^A-Za-z0-9._-]"), "_").take(64)
            return File(workDir(context), "$prefix-$safeId-${System.currentTimeMillis()}.jpg")
        }
    }

    private var pendingId: String? = null
    private var pendingFile: File? = null
    private lateinit var scannerLauncher: ActivityResultLauncher<IntentSenderRequest>
    private lateinit var captureLauncher: ActivityResultLauncher<Intent>
    private lateinit var permissionLauncher: ActivityResultLauncher<String>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        pendingId = savedInstanceState?.getString(PENDING_ID_KEY)
        pendingFile = savedInstanceState?.getString(PENDING_FILE_KEY)?.let(::File)

        permissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            val id = pendingId ?: return@registerForActivityResult
            if (granted) {
                launchCamera(id)
            } else {
                finishRequest()
                OcrFunctions.fail(requireActivity(), id, PERMISSION_DENIED_MESSAGE)
            }
        }

        captureLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            val activity = requireActivity()
            val id = pendingId ?: return@registerForActivityResult
            val file = pendingFile
            finishRequest()

            if (result.resultCode != Activity.RESULT_OK || file == null) {
                file?.delete()
                OcrFunctions.dispatch(activity, OcrFunctions.SCAN_CANCELLED, JSONObject().put("id", id))
                return@registerForActivityResult
            }

            if (!file.isFile || file.length() == 0L) {
                file.delete()
                OcrFunctions.fail(activity, id, "The camera returned no photo")
                return@registerForActivityResult
            }

            OcrFunctions.recognizeFile(activity, file, id)
        }

        scannerLauncher = registerForActivityResult(
            ActivityResultContracts.StartIntentSenderForResult()
        ) { result ->
            val activity = requireActivity()
            val id = pendingId ?: return@registerForActivityResult
            finishRequest()

            if (result.resultCode != Activity.RESULT_OK) {
                OcrFunctions.dispatch(activity, OcrFunctions.SCAN_CANCELLED, JSONObject().put("id", id))
                return@registerForActivityResult
            }

            val page = try {
                GmsDocumentScanningResult.fromActivityResultIntent(result.data)?.pages?.firstOrNull()
            } catch (e: Throwable) {
                null
            }
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
        pendingFile?.let { outState.putString(PENDING_FILE_KEY, it.absolutePath) }
    }

    /** Take ownership of the single capture/scan slot, or answer the request as busy. */
    private fun claim(activity: FragmentActivity, id: String): Boolean {
        if (pendingId != null) {
            OcrFunctions.fail(activity, id, BUSY_MESSAGE)
            return false
        }
        pendingId = id
        pendingFile = null
        return true
    }

    private fun finishRequest() {
        pendingId = null
        pendingFile = null
    }

    /**
     * Take a photo with the phone's camera app, asking for the back lens, then read it.
     * A camera app opened by intent reopens whichever lens was used last (often the
     * selfie camera); these extras ask for the back one. Samsung, Google and most
     * OEM camera apps honour at least one of them; the rest are simply ignored.
     */
    fun capture(id: String) {
        val activity = requireActivity()
        if (!claim(activity, id)) return

        try {
            removeOldImages(activity)

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
        } catch (e: Throwable) {
            Log.e(TAG, "Could not open the camera: ${e.message}", e)
            finishRequest()
            OcrFunctions.fail(activity, id, "Could not open the camera: ${e.message}")
        }
    }

    /**
     * The photo goes straight into the app cache through the core's FileProvider:
     * no MediaStore row (which needs storage permission on Android 8/9 and would
     * leave an empty file in the gallery on cancel), nothing the user has to clean up.
     */
    private fun launchCamera(id: String) {
        val activity = requireActivity()
        var file: File? = null

        try {
            file = imageFile(activity, "ocr-capture", id)
            val uri: Uri = FileProvider.getUriForFile(activity, "${activity.packageName}.fileprovider", file)
            pendingFile = file

            val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE).apply {
                putExtra(MediaStore.EXTRA_OUTPUT, uri)
                clipData = ClipData.newRawUri("", uri)
                addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
                // Back lens, in the dialects different camera apps understand.
                putExtra("android.intent.extras.CAMERA_FACING", 0)
                putExtra("android.intent.extras.LENS_FACING_BACK", 1)
                putExtra("android.intent.extra.USE_FRONT_CAMERA", false)
                putExtra("com.google.assistant.extra.USE_FRONT_CAMERA", false)
            }

            captureLauncher.launch(intent)
        } catch (e: ActivityNotFoundException) {
            Log.e(TAG, "No camera app: ${e.message}")
            file?.delete()
            finishRequest()
            OcrFunctions.fail(activity, id, "No camera app available")
        } catch (e: Throwable) {
            // Typically the FileProvider is missing or does not expose the cache directory.
            Log.e(TAG, "Could not prepare the camera: ${e.message}", e)
            file?.delete()
            finishRequest()
            OcrFunctions.fail(activity, id, "Could not prepare the camera")
        }
    }

    private fun copyToCache(activity: FragmentActivity, uri: Uri, id: String): File? {
        val file = imageFile(activity, "ocr-scan", id)
        return try {
            val input = activity.contentResolver.openInputStream(uri)
                ?: throw IllegalStateException("the page could not be opened")
            input.use { stream -> file.outputStream().use { output -> stream.copyTo(output) } }
            file
        } catch (e: Throwable) {
            Log.e(TAG, "Could not save the image: ${e.message}", e)
            file.delete()
            OcrFunctions.fail(activity, id, "Could not save the image: ${e.message}")
            null
        }
    }

    fun start(id: String) {
        val activity = requireActivity()
        if (!claim(activity, id)) return

        try {
            removeOldImages(activity)

            val options = GmsDocumentScannerOptions.Builder()
                .setScannerMode(GmsDocumentScannerOptions.SCANNER_MODE_FULL)
                .setPageLimit(1)
                .setGalleryImportAllowed(true)
                .setResultFormats(GmsDocumentScannerOptions.RESULT_FORMAT_JPEG)
                .build()

            GmsDocumentScanning.getClient(options)
                .getStartScanIntent(activity)
                .addOnSuccessListener { intentSender ->
                    try {
                        scannerLauncher.launch(IntentSenderRequest.Builder(intentSender).build())
                    } catch (e: Throwable) {
                        Log.e(TAG, "Could not start the scanner: ${e.message}", e)
                        finishRequest()
                        OcrFunctions.fail(activity, id, "Could not start the scanner: ${e.message}")
                    }
                }
                .addOnFailureListener { e ->
                    // Usually Google Play services is missing or still downloading the scanner module.
                    Log.e(TAG, "Scanner unavailable: ${e.message}", e)
                    finishRequest()
                    OcrFunctions.fail(activity, id, "Scanner unavailable: ${e.message}")
                }
        } catch (e: Throwable) {
            Log.e(TAG, "Scanner unavailable: ${e.message}", e)
            finishRequest()
            OcrFunctions.fail(activity, id, "Scanner unavailable: ${e.message}")
        }
    }
}
