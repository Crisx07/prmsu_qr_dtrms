(function () {
    const video = document.getElementById('qr-video');
    const canvas = document.getElementById('qr-canvas');
    const startButton = document.getElementById('qr-start');
    const stopButton = document.getElementById('qr-stop');
    const status = document.getElementById('qr-status');
    const input = document.getElementById('qr_value');
    const sourceInput = document.getElementById('qr_scan_source');
    const photoInput = document.getElementById('qr-photo');

    if (!video || !startButton || !stopButton || !status || !input) {
        return;
    }

    let stream = null;
    let scanning = false;
    let detector = null;
    let decoderMode = '';
    let animationFrameId = 0;

    const PHOTO_MAX_EDGES = [2400, 1800, 1200, 900, 600];
    const PHOTO_ROTATIONS = [0, 90, 180, 270];
    const isLocalhost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);

    const hasNativeDetector = () => 'BarcodeDetector' in window;
    const hasJsQrFallback = () => typeof window.jsQR === 'function' && canvas instanceof HTMLCanvasElement;

    const setStatus = (message) => {
        status.textContent = message;
    };

    const waitForFrame = () => new Promise((resolve) => {
        window.requestAnimationFrame(() => resolve());
    });

    const secureContextMessage = () => (
        'Live camera access is blocked because this page is not using HTTPS. Open the system using HTTPS or localhost for live scanning, or use Scan QR from Photo.'
    );

    const stopCamera = (message = 'Scanner stopped. You may scan again or use manual input.') => {
        scanning = false;
        decoderMode = '';
        detector = null;
        if (animationFrameId) {
            window.cancelAnimationFrame(animationFrameId);
            animationFrameId = 0;
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        video.srcObject = null;
        setStatus(message);
    };

    const submitScan = (value, source = 'camera') => {
        input.value = value;
        if (sourceInput instanceof HTMLInputElement) {
            sourceInput.value = source;
        }
        const form = input.form;
        if (form) {
            stopCamera('QR detected. Opening document record...');
            form.submit();
        }
    };

    const createNativeDetector = () => {
        if (!hasNativeDetector()) {
            return null;
        }

        try {
            return new window.BarcodeDetector({ formats: ['qr_code'] });
        } catch (error) {
            return null;
        }
    };

    const decodeWithNativeDetector = async (source) => {
        const nativeDetector = createNativeDetector();
        if (!nativeDetector || typeof nativeDetector.detect !== 'function') {
            return '';
        }

        try {
            const codes = await nativeDetector.detect(source);
            const code = codes.find((item) => typeof item.rawValue === 'string' && item.rawValue.trim() !== '');

            return code ? code.rawValue.trim() : '';
        } catch (error) {
            return '';
        }
    };

    const prepareCanvas = (width, height) => {
        if (!(canvas instanceof HTMLCanvasElement)) {
            return null;
        }

        canvas.width = Math.max(1, Math.floor(width));
        canvas.height = Math.max(1, Math.floor(height));
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) {
            return null;
        }

        context.clearRect(0, 0, canvas.width, canvas.height);

        return context;
    };

    const decodeImageDataWithJsQr = (imageData) => {
        if (!hasJsQrFallback()) {
            return '';
        }

        const code = window.jsQR(imageData.data, imageData.width, imageData.height, {
            inversionAttempts: 'attemptBoth',
        });

        return code && typeof code.data === 'string' ? code.data.trim() : '';
    };

    const decodeQrFromCanvas = (width, height, drawImage) => {
        if (!hasJsQrFallback()) {
            setStatus('QR decoding is not available because the QR decoder did not load. Paste the QR URL/token manually.');
            return '';
        }

        const context = prepareCanvas(width, height);
        if (!context) {
            setStatus('Unable to read the QR image in this browser. Paste the QR URL/token manually.');
            return '';
        }

        drawImage(context, canvas.width, canvas.height);

        try {
            return decodeImageDataWithJsQr(context.getImageData(0, 0, canvas.width, canvas.height));
        } catch (error) {
            return '';
        }
    };

    const clampByte = (value) => Math.max(0, Math.min(255, Math.round(value)));

    const grayscaleValue = (data, index) => (
        (0.299 * data[index]) + (0.587 * data[index + 1]) + (0.114 * data[index + 2])
    );

    const contrastGrayscaleImageData = (imageData) => {
        const result = new ImageData(new Uint8ClampedArray(imageData.data), imageData.width, imageData.height);
        const data = result.data;

        for (let index = 0; index < data.length; index += 4) {
            const gray = clampByte((grayscaleValue(data, index) - 128) * 1.75 + 128);
            data[index] = gray;
            data[index + 1] = gray;
            data[index + 2] = gray;
        }

        return result;
    };

    const otsuThreshold = (imageData) => {
        const histogram = new Uint32Array(256);
        const data = imageData.data;
        let total = 0;
        let sum = 0;

        for (let index = 0; index < data.length; index += 4) {
            const gray = clampByte(grayscaleValue(data, index));
            histogram[gray] += 1;
            total += 1;
            sum += gray;
        }

        let sumBackground = 0;
        let weightBackground = 0;
        let bestThreshold = 128;
        let bestVariance = 0;

        for (let threshold = 0; threshold < 256; threshold += 1) {
            weightBackground += histogram[threshold];
            if (weightBackground === 0) {
                continue;
            }

            const weightForeground = total - weightBackground;
            if (weightForeground === 0) {
                break;
            }

            sumBackground += threshold * histogram[threshold];
            const meanBackground = sumBackground / weightBackground;
            const meanForeground = (sum - sumBackground) / weightForeground;
            const varianceBetween = weightBackground * weightForeground * Math.pow(meanBackground - meanForeground, 2);

            if (varianceBetween > bestVariance) {
                bestVariance = varianceBetween;
                bestThreshold = threshold;
            }
        }

        return bestThreshold;
    };

    const thresholdImageData = (imageData, inverted = false) => {
        const threshold = otsuThreshold(imageData);
        const result = new ImageData(new Uint8ClampedArray(imageData.data), imageData.width, imageData.height);
        const data = result.data;

        for (let index = 0; index < data.length; index += 4) {
            const isLight = grayscaleValue(data, index) >= threshold;
            const value = (isLight !== inverted) ? 255 : 0;
            data[index] = value;
            data[index + 1] = value;
            data[index + 2] = value;
        }

        return result;
    };

    const invertedImageData = (imageData) => {
        const result = new ImageData(new Uint8ClampedArray(imageData.data), imageData.width, imageData.height);
        const data = result.data;

        for (let index = 0; index < data.length; index += 4) {
            data[index] = 255 - data[index];
            data[index + 1] = 255 - data[index + 1];
            data[index + 2] = 255 - data[index + 2];
        }

        return result;
    };

    const decodePhotoCanvasVariants = async (context, width, height) => {
        let imageData;
        try {
            imageData = context.getImageData(0, 0, width, height);
        } catch (error) {
            return '';
        }

        let value = decodeImageDataWithJsQr(imageData);
        if (value) {
            return value;
        }

        await waitForFrame();
        value = decodeImageDataWithJsQr(contrastGrayscaleImageData(imageData));
        if (value) {
            return value;
        }

        await waitForFrame();
        value = decodeImageDataWithJsQr(thresholdImageData(imageData));
        if (value) {
            return value;
        }

        await waitForFrame();
        value = decodeImageDataWithJsQr(invertedImageData(imageData));
        if (value) {
            return value;
        }

        await waitForFrame();

        return decodeImageDataWithJsQr(thresholdImageData(imageData, true));
    };

    const loadPhotoSource = async (file) => {
        if (typeof window.createImageBitmap === 'function') {
            try {
                const bitmap = await window.createImageBitmap(file, { imageOrientation: 'from-image' });
                return {
                    source: bitmap,
                    width: bitmap.width,
                    height: bitmap.height,
                    close: () => bitmap.close(),
                };
            } catch (error) {
                // Fall back to Image below for browsers without EXIF-aware ImageBitmap support.
            }
        }

        const imageUrl = URL.createObjectURL(file);
        const image = new Image();
        const loaded = new Promise((resolve, reject) => {
            image.onload = () => resolve();
            image.onerror = () => reject(new Error('The selected photo could not be opened.'));
        });

        image.src = imageUrl;
        await loaded;

        return {
            source: image,
            width: image.naturalWidth || image.width,
            height: image.naturalHeight || image.height,
            close: () => URL.revokeObjectURL(imageUrl),
        };
    };

    const photoMaxEdges = (sourceWidth, sourceHeight) => {
        const sourceEdge = Math.max(sourceWidth, sourceHeight, 1);
        const edges = [Math.min(sourceEdge, PHOTO_MAX_EDGES[0]), ...PHOTO_MAX_EDGES];
        const uniqueEdges = [];

        edges.forEach((edge) => {
            const normalized = Math.max(1, Math.round(Math.min(edge, sourceEdge)));
            if (!uniqueEdges.includes(normalized)) {
                uniqueEdges.push(normalized);
            }
        });

        return uniqueEdges;
    };

    const drawPhotoAttempt = (photo, maxEdge, rotation) => {
        const scale = Math.min(1, maxEdge / Math.max(photo.width, photo.height, 1));
        const scaledWidth = Math.max(1, Math.round(photo.width * scale));
        const scaledHeight = Math.max(1, Math.round(photo.height * scale));
        const quarterTurns = ((rotation % 360) + 360) % 360;
        const swapsDimensions = quarterTurns === 90 || quarterTurns === 270;
        const canvasWidth = swapsDimensions ? scaledHeight : scaledWidth;
        const canvasHeight = swapsDimensions ? scaledWidth : scaledHeight;
        const context = prepareCanvas(canvasWidth, canvasHeight);

        if (!context) {
            return null;
        }

        context.save();
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';

        if (quarterTurns === 90) {
            context.translate(canvasWidth, 0);
            context.rotate(Math.PI / 2);
        } else if (quarterTurns === 180) {
            context.translate(canvasWidth, canvasHeight);
            context.rotate(Math.PI);
        } else if (quarterTurns === 270) {
            context.translate(0, canvasHeight);
            context.rotate(-Math.PI / 2);
        }

        context.drawImage(photo.source, 0, 0, scaledWidth, scaledHeight);
        context.restore();

        return {
            context,
            width: canvasWidth,
            height: canvasHeight,
        };
    };

    const decodeQrPhotoWithJsQr = async (photo) => {
        if (!hasJsQrFallback()) {
            setStatus('Photo QR scanning is not available because the QR decoder did not load. Paste the QR URL/token manually.');
            return '';
        }

        const attemptedCanvases = new Set();
        let pass = 0;

        for (const maxEdge of photoMaxEdges(photo.width, photo.height)) {
            for (const rotation of PHOTO_ROTATIONS) {
                const attempt = drawPhotoAttempt(photo, maxEdge, rotation);
                if (!attempt) {
                    continue;
                }

                const canvasKey = `${attempt.width}x${attempt.height}:${rotation}`;
                if (attemptedCanvases.has(canvasKey)) {
                    continue;
                }
                attemptedCanvases.add(canvasKey);

                pass += 1;
                setStatus(`Reading QR from photo... pass ${pass}`);

                const value = await decodePhotoCanvasVariants(attempt.context, attempt.width, attempt.height);
                if (value) {
                    return value;
                }

                await waitForFrame();
            }
        }

        return '';
    };

    const scanQrPhoto = async (file) => {
        if (!file) {
            return;
        }
        if (file.type && !/^image\//i.test(file.type)) {
            setStatus('Choose a QR photo image file.');
            return;
        }

        stopCamera('Reading QR from photo...');

        let photo = null;
        try {
            photo = await loadPhotoSource(file);
            if (!photo.width || !photo.height) {
                throw new Error('The selected photo could not be opened.');
            }

            setStatus('Reading QR from photo with the browser QR reader...');
            let value = await decodeWithNativeDetector(photo.source);
            if (!value) {
                value = await decodeQrPhotoWithJsQr(photo);
            }

            if (value) {
                submitScan(value, 'photo');
                return;
            }

            setStatus('No QR code was found in that photo. Try a clearer, closer photo or paste the QR URL manually.');
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'QR photo scanning failed. Paste the QR URL manually.');
        } finally {
            if (photo && typeof photo.close === 'function') {
                photo.close();
            }
        }
    };

    const getCameraStream = async () => {
        const constraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
            audio: false,
        };

        try {
            return await navigator.mediaDevices.getUserMedia(constraints);
        } catch (error) {
            return navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        }
    };

    const detectWithBarcodeDetector = async () => {
        if (!scanning || decoderMode !== 'native' || !detector) {
            return;
        }

        try {
            const codes = await detector.detect(video);
            if (codes.length > 0) {
                const value = codes[0].rawValue || '';
                if (value.trim() !== '') {
                    submitScan(value, 'camera');
                    return;
                }
            }
        } catch (error) {
            if (hasJsQrFallback()) {
                decoderMode = 'jsqr';
                setStatus('Native QR reader paused. Continuing with device camera fallback scanner.');
                detectWithJsQr();
                return;
            }
            stopCamera('Scanner stopped: ' + error.message);
            return;
        }

        if (scanning) {
            animationFrameId = window.requestAnimationFrame(detectWithBarcodeDetector);
        }
    };

    const detectWithJsQr = () => {
        if (!scanning || decoderMode !== 'jsqr' || !(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        const width = video.videoWidth;
        const height = video.videoHeight;
        if (width > 0 && height > 0) {
            const value = decodeQrFromCanvas(width, height, (targetContext, canvasWidth, canvasHeight) => {
                targetContext.drawImage(video, 0, 0, canvasWidth, canvasHeight);
            });
            if (value) {
                submitScan(value, 'camera');
                return;
            }
        }

        if (scanning) {
            animationFrameId = window.requestAnimationFrame(detectWithJsQr);
        }
    };

    const startScanner = async () => {
        if (scanning) {
            return;
        }

        if (!window.isSecureContext && !isLocalhost) {
            setStatus(secureContextMessage());
            return;
        }

        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            setStatus('This browser cannot open the live camera. Use HTTPS or try another browser, scan from a QR photo, or paste the QR URL/token manually.');
            return;
        }

        if (!hasNativeDetector() && !hasJsQrFallback()) {
            setStatus('No QR decoder is available in this browser. Please paste the QR URL/token manually.');
            return;
        }

        try {
            stream = await getCameraStream();
            video.srcObject = stream;
            await video.play();
            scanning = true;

            detector = createNativeDetector();

            if (detector) {
                decoderMode = 'native';
                setStatus('Device camera is active. Point the camera at the document QR code.');
                detectWithBarcodeDetector();
                return;
            }

            if (hasJsQrFallback()) {
                decoderMode = 'jsqr';
                setStatus('Device camera is active. Point the camera at the document QR code.');
                detectWithJsQr();
                return;
            }

            stopCamera('Device camera opened, but no QR reader is available. Please paste the QR URL/token manually.');
        } catch (error) {
            stopCamera('Unable to start device camera: ' + error.message);
        }
    };

    if (!window.isSecureContext && !isLocalhost) {
        setStatus(secureContextMessage());
    } else if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        setStatus('Live camera access is not available in this browser. Use Scan QR from Photo or paste the QR URL/token manually.');
    } else {
        setStatus('Use Start Camera Scan for live scanning, or Scan QR from Photo if the browser blocks the camera.');
    }

    startButton.addEventListener('click', startScanner);
    stopButton.addEventListener('click', () => stopCamera());
    if (photoInput instanceof HTMLInputElement) {
        photoInput.addEventListener('change', () => {
            const file = photoInput.files && photoInput.files[0] ? photoInput.files[0] : null;
            scanQrPhoto(file);
            photoInput.value = '';
        });
    }
    window.addEventListener('beforeunload', () => stopCamera(''));
}());
