import AVFoundation
import Foundation
import ImageIO
import UIKit
import Vision
import VisionKit

/// On-device text recognition with Apple Vision (works offline, no dependencies).
/// Namespace: "Ocr.*"
enum OcrFunctions {

    static let textRecognized = "Protendai\\Ocr\\Events\\TextRecognized"
    static let recognitionFailed = "Protendai\\Ocr\\Events\\RecognitionFailed"
    static let scanCancelled = "Protendai\\Ocr\\Events\\ScanCancelled"

    static let busyMessage = "Another capture or scan is already in progress"
    static let permissionDeniedMessage = "Camera permission denied"

    /// Longest side, in pixels, of the image handed to Vision. Larger photos are downscaled.
    static let maxSide = 4096

    /// Open Apple's document camera (finds the page edges, flattens and crops it),
    /// then recognise the text on the scanned page.
    /// Parameters:
    ///   - id: (optional) string - request id echoed back on the result event
    /// Returns immediately; the result arrives as TextRecognized (with the page's path),
    /// RecognitionFailed, or ScanCancelled when the user backs out.
    class Scan: BridgeFunction {

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String ?? UUID().uuidString

            guard VNDocumentCameraViewController.isSupported else {
                return BridgeResponse.error(code: "UNSUPPORTED", message: "Document scanning is not supported on this device")
            }

            OcrFunctions.startCameraFlow(id: id, failurePrefix: "Nothing to present the scanner from") { presenter in
                let delegate = ScanDelegate(id: id)
                OcrFunctions.activeScan = delegate // the controller only holds its delegate weakly
                let scanner = VNDocumentCameraViewController()
                scanner.delegate = delegate
                // Full screen: a sheet could be swiped away without telling the delegate.
                scanner.modalPresentationStyle = .fullScreen
                presenter.present(scanner, animated: true)
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    /// Take a photo with the rear camera, then recognise it. Unlike Scan, the photo
    /// is not cropped or filtered, which keeps fine print sharp.
    /// Parameters:
    ///   - id: (optional) string - request id echoed back on the result event
    /// Returns immediately; the result arrives as TextRecognized, RecognitionFailed,
    /// or ScanCancelled when the user backs out.
    class Capture: BridgeFunction {

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String ?? UUID().uuidString

            guard UIImagePickerController.isSourceTypeAvailable(.camera) else {
                return BridgeResponse.error(code: "UNSUPPORTED", message: "No camera available")
            }

            OcrFunctions.startCameraFlow(id: id, failurePrefix: "Nothing to present the camera from") { presenter in
                let delegate = CaptureDelegate(id: id)
                OcrFunctions.activeCapture = delegate // the picker only holds its delegate weakly
                let picker = UIImagePickerController()
                picker.sourceType = .camera
                if UIImagePickerController.isCameraDeviceAvailable(.rear) {
                    picker.cameraDevice = .rear
                }
                picker.delegate = delegate
                picker.modalPresentationStyle = .fullScreen
                presenter.present(picker, animated: true)
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    // Main-thread state: the one capture or scan that may be open at a time.
    fileprivate static var pendingId: String?
    fileprivate static var activeScan: ScanDelegate?
    fileprivate static var activeCapture: CaptureDelegate?

    /// Claim the single capture/scan slot, check camera access (asking on first use),
    /// then present. Runs on the main thread; every failure arrives as an event.
    fileprivate static func startCameraFlow(id: String, failurePrefix: String, present: @escaping (UIViewController) -> Void) {
        DispatchQueue.main.async {
            if OcrFunctions.pendingId != nil {
                OcrFunctions.Recognize.fail(id, OcrFunctions.busyMessage)
                return
            }
            OcrFunctions.pendingId = id
            OcrFunctions.removeOldImages()

            let presentNow: () -> Void = {
                guard let presenter = OcrFunctions.topViewController() else {
                    OcrFunctions.finish()
                    OcrFunctions.Recognize.fail(id, failurePrefix)
                    return
                }
                present(presenter)
            }

            switch AVCaptureDevice.authorizationStatus(for: .video) {
            case .authorized:
                presentNow()
            case .notDetermined:
                AVCaptureDevice.requestAccess(for: .video) { granted in
                    DispatchQueue.main.async {
                        if granted {
                            presentNow()
                        } else {
                            OcrFunctions.finish()
                            OcrFunctions.Recognize.fail(id, OcrFunctions.permissionDeniedMessage)
                        }
                    }
                }
            default: // .denied, .restricted: the same event Android sends
                OcrFunctions.finish()
                OcrFunctions.Recognize.fail(id, OcrFunctions.permissionDeniedMessage)
            }
        }
    }

    /// Release the capture/scan slot. Main thread only.
    fileprivate static func finish() {
        pendingId = nil
        activeScan = nil
        activeCapture = nil
    }

    fileprivate static func topViewController() -> UIViewController? {
        let window = UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap { $0.windows }
            .first { $0.isKeyWindow }
        var top = window?.rootViewController
        while let presented = top?.presentedViewController { top = presented }
        return top
    }

    /// Where captured photos and scanned pages are written.
    fileprivate static func workDirectory() -> URL {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("ocr", isDirectory: true)
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }

    /// Remove images left by earlier captures and scans (each result's path stays valid for at least a minute).
    fileprivate static func removeOldImages() {
        let cutoff = Date().addingTimeInterval(-60)
        let fm = FileManager.default
        let files = (try? fm.contentsOfDirectory(at: workDirectory(), includingPropertiesForKeys: [.contentModificationDateKey])) ?? []
        for file in files {
            let modified = (try? file.resourceValues(forKeys: [.contentModificationDateKey]))?.contentModificationDate ?? .distantPast
            if modified < cutoff {
                try? fm.removeItem(at: file)
            }
        }
    }

    /// A file URL that is safe whatever the caller passed as the request id.
    fileprivate static func imageURL(prefix: String, id: String) -> URL {
        let allowed = CharacterSet(charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-")
        var safe = ""
        for scalar in id.unicodeScalars {
            safe.unicodeScalars.append(allowed.contains(scalar) ? scalar : "_")
        }
        let safeId = String(safe.prefix(64))
        let stamp = Int(Date().timeIntervalSince1970 * 1000)
        return workDirectory().appendingPathComponent("\(prefix)-\(safeId)-\(stamp).jpg")
    }

    /// Recognise text in an image file.
    /// Parameters:
    ///   - path: string - absolute path to a JPEG/PNG/HEIC on the device
    ///   - id: (optional) string - request id echoed back on the result event
    /// Returns immediately; the result arrives as TextRecognized or RecognitionFailed.
    class Recognize: BridgeFunction {

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let path = parameters["path"] as? String else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "path is required")
            }
            let id = parameters["id"] as? String ?? UUID().uuidString

            guard FileManager.default.fileExists(atPath: path) else {
                return BridgeResponse.error(code: "FILE_NOT_FOUND", message: "File not found: \(path)")
            }

            Self.run(path: path, id: id)

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }

        /// Decode the image upright (EXIF orientation applied) with its longest side
        /// at most `maxSide` pixels, without loading a full-size bitmap first.
        static func loadUpright(path: String) -> CGImage? {
            let url = URL(fileURLWithPath: path)
            guard let source = CGImageSourceCreateWithURL(url as CFURL, nil) else { return nil }

            let options: [CFString: Any] = [
                kCGImageSourceCreateThumbnailFromImageAlways: true,
                kCGImageSourceCreateThumbnailWithTransform: true,
                kCGImageSourceShouldCacheImmediately: true,
                kCGImageSourceThumbnailMaxPixelSize: OcrFunctions.maxSide,
            ]

            return CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary)
        }

        /// Recognise text in the image at `path` off the main thread and send the result.
        static func run(path: String, id: String) {
            DispatchQueue.global(qos: .userInitiated).async {
                guard let cgImage = autoreleasepool(invoking: { Self.loadUpright(path: path) }) else {
                    Self.fail(id, "Could not open image: unsupported or damaged file")
                    return
                }

                let width = cgImage.width
                let height = cgImage.height

                let request = VNRecognizeTextRequest { request, error in
                    if let error = error {
                        Self.fail(id, error.localizedDescription)
                        return
                    }

                    let observations = request.results as? [VNRecognizedTextObservation] ?? []
                    let lines = observations.compactMap { $0.topCandidates(1).first?.string }

                    // Where each line sits, so PHP can rebuild rows on two-column layouts.
                    // Vision's boxes are 0-1 with a bottom-left origin; convert to pixels of the
                    // (upright) image with a top-left origin, the same as Android.
                    let w = Double(width)
                    let h = Double(height)
                    let boxes: [[String: Any]] = observations.compactMap { observation in
                        guard let text = observation.topCandidates(1).first?.string else { return nil }
                        let box = observation.boundingBox
                        return [
                            "text": text,
                            "left": Int((Double(box.minX) * w).rounded()),
                            "top": Int(((1 - Double(box.maxY)) * h).rounded()),
                            "right": Int((Double(box.maxX) * w).rounded()),
                            "bottom": Int(((1 - Double(box.minY)) * h).rounded()),
                        ]
                    }

                    Self.send(OcrFunctions.textRecognized, [
                        "id": id,
                        "text": lines.joined(separator: "\n"),
                        "lines": lines,
                        "boxes": boxes,
                        "path": path,
                        "width": width,
                        "height": height,
                    ])
                }
                request.recognitionLevel = .accurate
                // Language correction "fixes" long digit runs into words. Keep it off for tokens and codes.
                request.usesLanguageCorrection = false

                // The image is already upright, so no orientation is passed.
                let handler = VNImageRequestHandler(cgImage: cgImage, options: [:])

                do {
                    try handler.perform([request])
                } catch {
                    Self.fail(id, error.localizedDescription)
                }
            }
        }

        static func fail(_ id: String, _ message: String) {
            send(OcrFunctions.recognitionFailed, ["id": id, "message": message])
        }

        static func send(_ event: String, _ payload: [String: Any]) {
            DispatchQueue.main.async {
                LaravelBridge.shared.send?(event, payload)
            }
        }
    }
}

/// Receives the document camera's result, saves the page and runs recognition on it.
fileprivate final class ScanDelegate: NSObject, VNDocumentCameraViewControllerDelegate {
    let id: String

    init(id: String) {
        self.id = id
    }

    func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFinishWith scan: VNDocumentCameraScan) {
        controller.dismiss(animated: true)
        defer { OcrFunctions.finish() }

        guard scan.pageCount > 0, let data = scan.imageOfPage(at: 0).jpegData(compressionQuality: 0.9) else {
            OcrFunctions.Recognize.fail(id, "The scanner returned no page")
            return
        }

        let url = OcrFunctions.imageURL(prefix: "ocr-scan", id: id)
        do {
            try data.write(to: url)
        } catch {
            OcrFunctions.Recognize.fail(id, "Could not save the scanned page: \(error.localizedDescription)")
            return
        }

        OcrFunctions.Recognize.run(path: url.path, id: id)
    }

    func documentCameraViewControllerDidCancel(_ controller: VNDocumentCameraViewController) {
        controller.dismiss(animated: true)
        OcrFunctions.finish()
        OcrFunctions.Recognize.send(OcrFunctions.scanCancelled, ["id": id])
    }

    func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFailWithError error: Error) {
        controller.dismiss(animated: true)
        OcrFunctions.finish()
        OcrFunctions.Recognize.fail(id, error.localizedDescription)
    }
}

/// Receives the camera photo, saves it and runs recognition on it.
fileprivate final class CaptureDelegate: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
    let id: String

    init(id: String) {
        self.id = id
    }

    func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
        picker.dismiss(animated: true)
        defer { OcrFunctions.finish() }

        guard let image = info[.originalImage] as? UIImage, let data = image.jpegData(compressionQuality: 0.9) else {
            OcrFunctions.Recognize.fail(id, "The camera returned no photo")
            return
        }

        let url = OcrFunctions.imageURL(prefix: "ocr-capture", id: id)
        do {
            try data.write(to: url)
        } catch {
            OcrFunctions.Recognize.fail(id, "Could not save the photo: \(error.localizedDescription)")
            return
        }

        OcrFunctions.Recognize.run(path: url.path, id: id)
    }

    func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
        picker.dismiss(animated: true)
        OcrFunctions.finish()
        OcrFunctions.Recognize.send(OcrFunctions.scanCancelled, ["id": id])
    }
}
