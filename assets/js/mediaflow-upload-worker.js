'use strict';

const MIN_QUALITY = 0.25;
const MAX_QUALITY = 0.95;
const QUALITY_STEPS = 8;
const DIMENSION_STEP = 0.90;
const MIN_LONG_EDGE = 64;
const MAX_DIMENSION_PASSES = 48;

function constrainedSize(width, height, maxWidth, maxHeight) {
    const scale = Math.min(1, maxWidth / width, maxHeight / height);
    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale))
    };
}

async function encode(canvas, quality) {
    return canvas.convertToBlob({ type: 'image/webp', quality });
}

async function bestQuality(canvas, targetBytes) {
    const top = await encode(canvas, MAX_QUALITY);
    if (top.size <= targetBytes) {
        return { blob: top, quality: MAX_QUALITY, fits: true };
    }

    const bottom = await encode(canvas, MIN_QUALITY);
    if (bottom.size > targetBytes) {
        return { blob: bottom, quality: MIN_QUALITY, fits: false };
    }

    let lower = MIN_QUALITY;
    let upper = MAX_QUALITY;
    let bestBlob = bottom;
    let best = MIN_QUALITY;

    for (let i = 0; i < QUALITY_STEPS; i += 1) {
        const candidateQuality = (lower + upper) / 2;
        const candidate = await encode(canvas, candidateQuality);
        if (candidate.size <= targetBytes) {
            bestBlob = candidate;
            best = candidateQuality;
            lower = candidateQuality;
        } else {
            upper = candidateQuality;
        }
    }

    return { blob: bestBlob, quality: best, fits: true };
}

async function processImage(bitmap, options) {
    const targetBytes = Number(options.targetBytes);
    const hardBytes = Number(options.hardBytes);
    const maxWidth = Math.max(1, Number(options.maxWidth));
    const maxHeight = Math.max(1, Number(options.maxHeight));

    let size = constrainedSize(bitmap.width, bitmap.height, maxWidth, maxHeight);
    let finalResult = null;
    let finalSize = size;

    for (let pass = 0; pass < MAX_DIMENSION_PASSES; pass += 1) {
        const canvas = new OffscreenCanvas(size.width, size.height);
        const context = canvas.getContext('2d', { alpha: true });
        if (!context) {
            throw new Error('Canvas 2D is unavailable in the upload worker.');
        }
        context.drawImage(bitmap, 0, 0, size.width, size.height);

        finalResult = await bestQuality(canvas, targetBytes);
        finalSize = { width: size.width, height: size.height };
        if (finalResult.fits && finalResult.blob.size <= targetBytes) {
            return {
                blob: finalResult.blob,
                width: size.width,
                height: size.height,
                quality: finalResult.quality,
                passes: pass + 1
            };
        }

        const longEdge = Math.max(size.width, size.height);
        if (longEdge <= MIN_LONG_EDGE) {
            break;
        }

        const nextWidth = Math.max(1, Math.round(size.width * DIMENSION_STEP));
        const nextHeight = Math.max(1, Math.round(size.height * DIMENSION_STEP));
        if (nextWidth === size.width && nextHeight === size.height) {
            break;
        }
        size = { width: nextWidth, height: nextHeight };
    }

    if (finalResult && finalResult.blob.size <= hardBytes) {
        return {
            blob: finalResult.blob,
            width: finalSize.width,
            height: finalSize.height,
            quality: finalResult.quality,
            passes: MAX_DIMENSION_PASSES
        };
    }

    throw new Error('Unable to satisfy the final WebP byte ceiling.');
}

self.addEventListener('message', async (event) => {
    const data = event.data || {};
    if (!data.id || !data.bitmap) {
        return;
    }

    try {
        const result = await processImage(data.bitmap, data.options || {});
        self.postMessage({ id: data.id, ok: true, ...result });
    } catch (error) {
        self.postMessage({
            id: data.id,
            ok: false,
            message: error instanceof Error ? error.message : String(error)
        });
    } finally {
        if (data.bitmap && typeof data.bitmap.close === 'function') {
            data.bitmap.close();
        }
    }
});
