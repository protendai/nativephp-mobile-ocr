/**
 * protendai/nativephp-mobile-ocr: on-device OCR for NativePHP Mobile v4 (JavaScript API).
 *
 * For Inertia + Vue/React (or any JS) front ends. Mirrors the PHP facade:
 * every call returns a request id straight away, and the result arrives later
 * as a native event carrying the same id.
 *
 * @example
 * import { capture, onTextRecognized, onRecognitionFailed, onScanCancelled } from '@protendai/nativephp-mobile-ocr';
 *
 * let requestId = null;
 *
 * onTextRecognized(({ id, text, boxes, width, height }) => {
 *     if (id === requestId) console.log(text);
 * });
 *
 * requestId = await capture();
 */

const baseUrl = '/_native/api/call';

/** Fully-qualified PHP event class names dispatched by the plugin. */
export const Events = Object.freeze({
    TextRecognized: 'Protendai\\Ocr\\Events\\TextRecognized',
    RecognitionFailed: 'Protendai\\Ocr\\Events\\RecognitionFailed',
    ScanCancelled: 'Protendai\\Ocr\\Events\\ScanCancelled',
});

/** RecognitionFailed messages worth branching on. */
export const Messages = Object.freeze({
    CameraPermissionDenied: 'Camera permission denied',
    Busy: 'Another capture or scan is already in progress',
});

/** Thrown when the device refuses a request outright (no event will follow). */
export class OcrError extends Error {
    /**
     * @param {string} message
     * @param {string|null} [code] Device error code, e.g. FILE_NOT_FOUND, UNSUPPORTED
     */
    constructor(message, code = null) {
        super(message);
        this.name = 'OcrError';
        this.code = code;
    }
}

function newId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'ocr-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

/**
 * @private
 * @param {string} method Bridge function name, e.g. 'Ocr.Capture'
 * @param {object} params
 * @param {string} fallbackMessage
 */
async function bridgeCall(method, params, fallbackMessage) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ method, params }),
    });

    let result;
    try {
        result = await response.json();
    } catch (e) {
        throw new OcrError(fallbackMessage, 'INVALID_RESPONSE');
    }

    if (!result || result.status === 'error') {
        throw new OcrError(result?.message || fallbackMessage, result?.code ?? null);
    }

    const data = result.data;
    if (data && data.status === 'error') {
        throw new OcrError(data.message || fallbackMessage, data.code ?? null);
    }

    return data && data.data !== undefined ? data.data : data;
}

/**
 * Start recognising text in an image file already on the device.
 * Result: TextRecognized or RecognitionFailed with the returned id.
 *
 * @param {string} path Absolute path to a JPEG/PNG/HEIC on the device
 * @param {string} [id] Your own request id (one is generated otherwise)
 * @returns {Promise<string>} The request id
 * @throws {OcrError} When the device refuses (missing path, file not found)
 */
export async function recognize(path, id) {
    const requestId = id ?? newId();
    await bridgeCall('Ocr.Recognize', { path, id: requestId }, 'Text recognition could not start');

    return requestId;
}

/**
 * Take a photo with the back camera, then recognise it.
 * Result: TextRecognized, RecognitionFailed or ScanCancelled with the returned id.
 *
 * @param {string} [id] Your own request id (one is generated otherwise)
 * @returns {Promise<string>} The request id
 * @throws {OcrError} When the device has no camera
 */
export async function capture(id) {
    const requestId = id ?? newId();
    await bridgeCall('Ocr.Capture', { id: requestId }, 'The camera could not open');

    return requestId;
}

/**
 * Open the platform document scanner, then recognise the scanned page.
 * Result: TextRecognized, RecognitionFailed or ScanCancelled with the returned id.
 *
 * @param {string} [id] Your own request id (one is generated otherwise)
 * @returns {Promise<string>} The request id
 * @throws {OcrError} When the device cannot scan documents
 */
export async function scan(id) {
    const requestId = id ?? newId();
    await bridgeCall('Ocr.Scan', { id: requestId }, 'The scanner could not start');

    return requestId;
}

/**
 * Listen for one of the plugin's events. Same channel as `On()` from '@nativephp/mobile'.
 *
 * @param {string} eventName One of Events.*
 * @param {(payload: object) => void} callback
 * @returns {() => void} Call to stop listening
 */
export function on(eventName, callback) {
    const handler = (e) => {
        const name = String(e?.detail?.event ?? '').replace(/^(\\)+/, '');
        if (name === eventName) {
            callback(e.detail.payload);
        }
    };

    document.addEventListener('native-event', handler);

    return () => document.removeEventListener('native-event', handler);
}

/**
 * @param {(payload: {id: string, text: string, lines: string[], boxes: Array<{text: string, left: number, top: number, right: number, bottom: number}>, path: string|null, width?: number, height?: number}) => void} callback
 * @returns {() => void} Call to stop listening
 */
export function onTextRecognized(callback) {
    return on(Events.TextRecognized, callback);
}

/**
 * @param {(payload: {id: string, message: string}) => void} callback
 * @returns {() => void} Call to stop listening
 */
export function onRecognitionFailed(callback) {
    return on(Events.RecognitionFailed, callback);
}

/**
 * @param {(payload: {id: string}) => void} callback
 * @returns {() => void} Call to stop listening
 */
export function onScanCancelled(callback) {
    return on(Events.ScanCancelled, callback);
}

/**
 * Rebuild reading order row by row, left to right (same algorithm as PHP Layout::rows()).
 *
 * @param {Array<{text: string, left: number, top: number, right: number, bottom: number}>} boxes
 * @returns {string|null} null when no positions were reported
 */
export function rows(boxes) {
    const usable = (boxes || []).filter((b) => b && b.text !== undefined && b.top !== undefined && b.bottom !== undefined && b.left !== undefined);
    if (usable.length === 0) return null;

    usable.sort((a, b) => (a.top + a.bottom) - (b.top + b.bottom));

    const grouped = [];
    for (const box of usable) {
        const centre = (box.top + box.bottom) / 2;
        const row = grouped.find((r) => Math.abs(centre - r.centre) <= (r.bottom - r.top) * 0.45);
        if (row) {
            row.boxes.push(box);
        } else {
            grouped.push({ centre, top: box.top, bottom: box.bottom, boxes: [box] });
        }
    }

    grouped.sort((a, b) => a.centre - b.centre);

    return grouped
        .map((r) => r.boxes.sort((a, b) => a.left - b.left).map((b) => b.text).join('   '))
        .join('\n');
}

export const Ocr = Object.freeze({ recognize, capture, scan, on, onTextRecognized, onRecognitionFailed, onScanCancelled, rows, Events, Messages });

export default Ocr;
