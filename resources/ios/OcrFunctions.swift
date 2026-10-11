import Foundation
import UIKit
import Vision

/// On-device text recognition with Apple Vision (works offline, no dependencies).
/// Namespace: "Ocr.*"
enum OcrFunctions {

    static let textRecognized = "Protendai\\Ocr\\Events\\TextRecognized"
    static let recognitionFailed = "Protendai\\Ocr\\Events\\RecognitionFailed"

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

                    let lines = (request.results as? [VNRecognizedTextObservation] ?? [])
                        .compactMap { $0.topCandidates(1).first?.string }

                    Self.send(OcrFunctions.textRecognized, [
                        "id": id,
                        "text": lines.joined(separator: "\n"),
                        "lines": lines,
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

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }

        private static func fail(_ id: String, _ message: String) {
            send(OcrFunctions.recognitionFailed, ["id": id, "message": message])
        }

        private static func send(_ event: String, _ payload: [String: Any]) {
            DispatchQueue.main.async {
                LaravelBridge.shared.send?(event, payload)
            }
        }
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
