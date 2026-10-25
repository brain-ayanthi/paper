/**
 * Camera barcode scanner (docs/features/hardware.md §7.3).
 *
 * Wraps ZXing-JS (`@zxing/browser`) so tablets / phones / laptops with a
 * camera but no USB scanner can still scan. Decodes EAN-13, UPC-A, Code
 * 128, Code 39, and QR — everything the checkout needs.
 *
 *   const scanner = new CameraBarcodeScanner(videoEl);
 *   await scanner.start((text) => handle(text));
 *   // later: scanner.stop();
 */
import { BrowserMultiFormatReader } from '@zxing/browser';

export function cameraScanSupported() {
    return !!(typeof navigator !== 'undefined'
        && navigator.mediaDevices
        && navigator.mediaDevices.getUserMedia);
}

export class CameraBarcodeScanner {
    constructor(videoElement) {
        this.video = videoElement;
        this.reader = new BrowserMultiFormatReader();
        this.controls = null;
    }

    /** Available video inputs (best-effort; empty before permission). */
    async listCameras() {
        try {
            return await BrowserMultiFormatReader.listVideoInputDevices();
        } catch (_) {
            return [];
        }
    }

    /**
     * Start decoding into the bound <video>. Prefers a rear camera. The
     * callback fires once per successful decode; ZXing's per-frame
     * "no code found" errors are swallowed.
     *
     * @param {(text: string) => void} onScan
     * @param {string} [deviceId]
     */
    async start(onScan, deviceId = undefined) {
        let target = deviceId;
        if (!target) {
            const cams = await this.listCameras();
            const back = cams.find((d) => /back|rear|environment/i.test(d.label || ''));
            target = back?.deviceId ?? cams[0]?.deviceId ?? undefined;
        }

        this.controls = await this.reader.decodeFromVideoDevice(
            target,
            this.video,
            (result) => {
                if (result) {
                    const text = typeof result.getText === 'function' ? result.getText() : String(result);
                    if (text) onScan(text);
                }
            },
        );

        return this.controls;
    }

    /**
     * Release the camera. Idempotent, and safe to call on a scanner whose
     * start() threw part-way.
     *
     * ZXing's `controls.stop()` is the normal path, but it only exists once
     * decodeFromVideoDevice has resolved — and by then getUserMedia has
     * already handed us a live MediaStream. If start() rejects after that
     * point, or if the instance is orphaned by a double-open, `controls` is
     * null and nothing ever stops the tracks: the camera indicator stays lit
     * until the tab closes (SEC #75). So stop the tracks hanging off the
     * <video> too — that is the stream, whatever ZXing thinks it owns.
     */
    stop() {
        try { this.controls?.stop(); } catch (_) { /* noop */ }
        this.controls = null;

        try {
            const stream = this.video?.srcObject;
            if (stream && typeof stream.getTracks === 'function') {
                stream.getTracks().forEach((track) => {
                    try { track.stop(); } catch (_) { /* noop */ }
                });
            }
            if (this.video) this.video.srcObject = null;
        } catch (_) { /* noop */ }
    }
}
