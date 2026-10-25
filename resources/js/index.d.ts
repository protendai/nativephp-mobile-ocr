export interface OcrBox {
    text: string;
    left: number;
    top: number;
    right: number;
    bottom: number;
}

export interface TextRecognizedPayload {
    id: string;
    text: string;
    lines: string[];
    /** Pixels of the upright image that was read, top-left origin (both platforms). */
    boxes: OcrBox[];
    path: string | null;
    /** Size of the image the boxes refer to (missing before plugin 1.0). */
    width?: number;
    height?: number;
}

export interface RecognitionFailedPayload {
    id: string;
    message: string;
}

export interface ScanCancelledPayload {
    id: string;
}

export declare const Events: Readonly<{
    TextRecognized: 'Protendai\\Ocr\\Events\\TextRecognized';
    RecognitionFailed: 'Protendai\\Ocr\\Events\\RecognitionFailed';
    ScanCancelled: 'Protendai\\Ocr\\Events\\ScanCancelled';
}>;

export declare const Messages: Readonly<{
    CameraPermissionDenied: 'Camera permission denied';
    Busy: 'Another capture or scan is already in progress';
}>;

export declare class OcrError extends Error {
    code: string | null;
    constructor(message: string, code?: string | null);
}

export declare function recognize(path: string, id?: string): Promise<string>;
export declare function capture(id?: string): Promise<string>;
export declare function scan(id?: string): Promise<string>;

export declare function on(eventName: string, callback: (payload: any) => void): () => void;
export declare function onTextRecognized(callback: (payload: TextRecognizedPayload) => void): () => void;
export declare function onRecognitionFailed(callback: (payload: RecognitionFailedPayload) => void): () => void;
export declare function onScanCancelled(callback: (payload: ScanCancelledPayload) => void): () => void;

export declare function rows(boxes: OcrBox[]): string | null;

export declare const Ocr: Readonly<{
    recognize: typeof recognize;
    capture: typeof capture;
    scan: typeof scan;
    on: typeof on;
    onTextRecognized: typeof onTextRecognized;
    onRecognitionFailed: typeof onRecognitionFailed;
    onScanCancelled: typeof onScanCancelled;
    rows: typeof rows;
    Events: typeof Events;
    Messages: typeof Messages;
}>;

export default Ocr;
