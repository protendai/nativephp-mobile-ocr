import Foundation
import UIKit
import Vision
import VisionKit

/// On-device text recognition with Apple Vision (works offline, no dependencies).
/// Namespace: "Ocr.*"
enum OcrFunctions {

    static let textRecognized = "Protendai\\Ocr\\Events\\TextRecognized"
    static let recognitionFailed = "Protendai\\Ocr\\Events\\RecognitionFailed"
    static let scanCancelled = "Protendai\\Ocr\\Events\\ScanCancelled"

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

            DispatchQueue.main.async {
                guard let presenter = OcrFunctions.topViewController() else {
                    Recognize.fail(id, "Nothing to present the scanner from")
                    return
                }

                let delegate = ScanDelegate(id: id)
                OcrFunctions.activeScan = delegate // the controller only holds its delegate weakly
                let scanner = VNDocumentCameraViewController()
                scanner.delegate = delegate
                presenter.present(scanner, animated: true)
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    fileprivate static var activeScan: ScanDelegate?

    fileprivate static func topViewController() -> UIViewController? {
        let window = UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap { $0.windows }
            .first { $0.isKeyWindow }
        var top = window?.rootViewController
        while let presented = top?.presentedViewController { top = presented }
        return top
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

        /// Recognise text in the image at `path` off the main thread and send the result.
        static func run(path: String, id: String) {
            DispatchQueue.global(qos: .userInitiated).async {
                guard let image = UIImage(contentsOfFile: path), let cgImage = image.cgImage else {
                    Self.fail(id, "Could not load image at \(path)")
                    return
                }

                let request = VNRecognizeTextRequest { request, error in
                    if let error = error {
                        Self.fail(id, error.localizedDescription)
                        return
                    }

                    let observations = request.results as? [VNRecognizedTextObservation] ?? []
                    let lines = observations.compactMap { $0.topCandidates(1).first?.string }

                    // Where each line sits, so PHP can rebuild rows on two-column layouts.
                    // Vision's boxes are 0-1 with a bottom-left origin; flip to top-left like Android.
                    let boxes: [[String: Any]] = observations.compactMap { observation in
                        guard let text = observation.topCandidates(1).first?.string else { return nil }
                        let box = observation.boundingBox
                        return [
                            "text": text,
                            "left": box.minX,
                            "top": 1 - box.maxY,
                            "right": box.maxX,
                            "bottom": 1 - box.minY,
                        ]
                    }

                    Self.send(OcrFunctions.textRecognized, [
                        "id": id,
                        "text": lines.joined(separator: "\n"),
                        "lines": lines,
                        "boxes": boxes,
                        "path": path,
                    ])
                }
                request.recognitionLevel = .accurate
                // Language correction "fixes" long digit runs into words. Keep it off for tokens and codes.
                request.usesLanguageCorrection = false

                let handler = VNImageRequestHandler(
                    cgImage: cgImage,
                    orientation: CGImagePropertyOrientation(image.imageOrientation),
                    options: [:]
                )

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
        defer { OcrFunctions.activeScan = nil }

        guard scan.pageCount > 0, let data = scan.imageOfPage(at: 0).jpegData(compressionQuality: 0.9) else {
            OcrFunctions.Recognize.fail(id, "The scanner returned no page")
            return
        }

        let url = FileManager.default.temporaryDirectory.appendingPathComponent("ocr-scan-\(id).jpg")
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
        OcrFunctions.activeScan = nil
        OcrFunctions.Recognize.send(OcrFunctions.scanCancelled, ["id": id])
    }

    func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFailWithError error: Error) {
        controller.dismiss(animated: true)
        OcrFunctions.activeScan = nil
        OcrFunctions.Recognize.fail(id, error.localizedDescription)
    }
}

private extension CGImagePropertyOrientation {
    init(_ orientation: UIImage.Orientation) {
        switch orientation {
        case .up: self = .up
        case .down: self = .down
        case .left: self = .left
        case .right: self = .right
        case .upMirrored: self = .upMirrored
        case .downMirrored: self = .downMirrored
        case .leftMirrored: self = .leftMirrored
        case .rightMirrored: self = .rightMirrored
        @unknown default: self = .up
        }
    }
}
