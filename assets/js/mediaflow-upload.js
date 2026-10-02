(function () {
    'use strict';

    const config = window.MediaFlowUploadConfig || null;
    if (!config || !config.workerUrl) return;

    const supported = new Set(config.supportedTypes || []);
    const nativeFetch = window.fetch ? window.fetch.bind(window) : null;
    const NativeXHR = window.XMLHttpRequest;
    const workerSlots = [];
    const waiters = [];
    let sequence = 0;

    function concurrencyLimit() {
        const configured = Math.max(1, Math.min(4, Number(config.maxWorkers || 2)));
        const hardware = typeof navigator !== 'undefined' && Number(navigator.hardwareConcurrency || 0) > 0
            ? Math.max(1, Math.ceil(Number(navigator.hardwareConcurrency) / 4))
            : configured;
        return Math.max(1, Math.min(configured, hardware, 4));
    }

    const MAX_WORKERS = concurrencyLimit();

    function humanBytes(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        return Math.ceil(bytes / 1024) + ' KB';
    }

    function showNotice(message) {
        if (window.console && console.error) console.error('[QMediaFlow]', message);
        const id = 'mediaflow-upload-notice';
        let notice = document.getElementById(id);
        if (!notice && document.body) {
            notice = document.createElement('div');
            notice.id = id;
            notice.className = 'notice notice-error is-dismissible';
            notice.setAttribute('role', 'alert');
            notice.style.margin = '12px 20px';
            const paragraph = document.createElement('p');
            notice.appendChild(paragraph);
            const target = document.querySelector('.wrap') || document.body;
            target.insertBefore(notice, target.firstChild);
        }
        if (notice && notice.firstChild) notice.firstChild.textContent = message;
        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') window.wp.a11y.speak(message, 'assertive');
    }

    function uploadEndpoint(url, formData) {
        let parsed;
        try { parsed = new URL(String(url || ''), window.location.href); } catch (error) { return false; }
        if (/\/wp-admin\/async-upload\.php$/.test(parsed.pathname)) return true;
        if (/\/wp-json\/wp\/v2\/media\/?$/.test(parsed.pathname)) return true;
        if ((parsed.searchParams.get('rest_route') || '').replace(/\/$/, '') === '/wp/v2/media') return true;
        if (/\/wp-admin\/admin-ajax\.php$/.test(parsed.pathname) && formData instanceof FormData) return formData.get('action') === 'upload-attachment';
        return false;
    }

    function fileNameAsWebP(name) {
        const clean = String(name || 'image').replace(/\.[^.]+$/, '') || 'image';
        return clean + '.webp';
    }

    function sourceType(file) {
        const declared = String(file.type || '').toLowerCase();
        if (declared) return declared;
        const match = String(file.name || '').toLowerCase().match(/\.([a-z0-9]+)$/);
        const extension = match ? match[1] : '';
        return ({ jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', avif: 'image/avif', heic: 'image/heic', heif: 'image/heif', gif: 'image/gif' })[extension] || '';
    }

    function imageFile(value) {
        if (typeof File === 'undefined' || !(value instanceof File)) return false;
        const type = sourceType(value);
        return type.indexOf('image/') === 0 || /\.(?:jpe?g|png|webp|avif|gif|heic|heif)$/i.test(String(value.name || ''));
    }

    function validateSource(file) {
        if (!supported.has(sourceType(file))) throw new Error((config.messages && config.messages.unsupported) || 'Unsupported image type.');
        if (file.size <= 0 || file.size > Number(config.maxSourceBytes)) {
            throw new Error(((config.messages && config.messages.tooLarge) || 'Original image is too large.') + ' Maximum: ' + humanBytes(Number(config.maxSourceBytes)) + '.');
        }
    }

    function ensureWorkerApis() {
        if (typeof Worker === 'undefined' || typeof OffscreenCanvas === 'undefined' || typeof createImageBitmap === 'undefined') {
            throw new Error('This browser does not provide the Web Worker image APIs required by QMediaFlow.');
        }
    }

    function createSlot() {
        ensureWorkerApis();
        const slot = { worker: new Worker(config.workerUrl), busy: false, current: null, dead: false };
        slot.worker.addEventListener('message', function (event) {
            const data = event.data || {};
            const pending = slot.current;
            if (!pending || pending.id !== data.id) return;
            slot.current = null;
            if (data.ok) pending.resolve(data);
            else pending.reject(new Error(data.message || 'Worker image processing failed.'));
        });
        slot.worker.addEventListener('error', function (event) {
            slot.dead = true;
            const error = new Error(event.message || 'QMediaFlow upload worker failed.');
            if (slot.current) { const pending = slot.current; slot.current = null; pending.reject(error); }
            try { slot.worker.terminate(); } catch (ignore) {}
        });
        workerSlots.push(slot);
        return slot;
    }

    function acquireSlot() {
        ensureWorkerApis();
        const idle = workerSlots.find(function (slot) { return !slot.busy && !slot.dead; });
        if (idle) { idle.busy = true; return Promise.resolve(idle); }
        const live = workerSlots.filter(function (slot) { return !slot.dead; }).length;
        if (live < MAX_WORKERS) { const slot = createSlot(); slot.busy = true; return Promise.resolve(slot); }
        return new Promise(function (resolve) { waiters.push(resolve); });
    }

    function releaseSlot(slot) {
        slot.busy = false;
        if (slot.dead) {
            const index = workerSlots.indexOf(slot);
            if (index >= 0) workerSlots.splice(index, 1);
        }
        if (waiters.length) {
            let next = workerSlots.find(function (candidate) { return !candidate.busy && !candidate.dead; });
            if (!next && workerSlots.filter(function (candidate) { return !candidate.dead; }).length < MAX_WORKERS) next = createSlot();
            if (next) { next.busy = true; waiters.shift()(next); }
        }
    }

    async function orientedBitmap(file) {
        try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); }
        catch (firstError) { return createImageBitmap(file); }
    }

    async function encodeBitmap(bitmap) {
        const slot = await acquireSlot();
        const id = 'mf-' + Date.now().toString(36) + '-' + (++sequence).toString(36);
        try {
            return await new Promise(function (resolve, reject) {
                slot.current = { id: id, resolve: resolve, reject: reject };
                slot.worker.postMessage({
                    id: id,
                    bitmap: bitmap,
                    options: {
                        maxWidth: Number(config.maxWidth),
                        maxHeight: Number(config.maxHeight),
                        targetBytes: Number(config.targetBytes),
                        hardBytes: Number(config.hardBytes)
                    }
                }, [bitmap]);
            });
        } finally {
            releaseSlot(slot);
        }
    }

    async function optimize(file) {
        validateSource(file);
        const bitmap = await orientedBitmap(file);
        const result = await encodeBitmap(bitmap);
        if (!(result.blob instanceof Blob) || result.blob.type !== 'image/webp') throw new Error((config.messages && config.messages.processing) || 'Browser image processing failed.');
        if (result.blob.size <= 0 || result.blob.size > Number(config.hardBytes)) throw new Error((config.messages && config.messages.finalSize) || 'Final image exceeds the upload size limit.');
        return new File([result.blob], fileNameAsWebP(file.name), { type: 'image/webp', lastModified: file.lastModified || Date.now() });
    }

    async function parallelMap(values, mapper, limit) {
        const results = new Array(values.length);
        let cursor = 0;
        async function runner() {
            while (true) {
                const index = cursor++;
                if (index >= values.length) return;
                results[index] = await mapper(values[index], index);
            }
        }
        const runners = [];
        for (let i = 0; i < Math.min(limit, values.length); i++) runners.push(runner());
        await Promise.all(runners);
        return results;
    }

    async function transformFormData(body) {
        if (!(body instanceof FormData)) return body;
        const entries = Array.from(body.entries());
        const imageEntries = [];
        entries.forEach(function (entry, index) { if (imageFile(entry[1])) imageEntries.push({ index: index, file: entry[1] }); });
        if (!imageEntries.length) return body;

        // Decode/encode concurrency is bounded before any bitmap is created. This
        // is the browser memory backpressure boundary for large multi-file forms.
        const converted = await parallelMap(imageEntries, function (item) { return optimize(item.file); }, MAX_WORKERS);
        const convertedByIndex = new Map();
        imageEntries.forEach(function (item, i) { convertedByIndex.set(item.index, converted[i]); });

        const output = new FormData();
        entries.forEach(function (entry, index) {
            const key = entry[0];
            const replacement = convertedByIndex.get(index);
            if (replacement) output.append(key, replacement, replacement.name);
            else output.append(key, entry[1]);
        });
        return output;
    }

    function markedHeaders(headers) {
        const result = new Headers(headers || {});
        result.set('X-MediaFlow-Upload', '1');
        result.set('X-QMediaFlow-Upload-Workers', String(MAX_WORKERS));
        return result;
    }

    if (nativeFetch) {
        window.fetch = function (input, init) {
            const isRequest = typeof Request !== 'undefined' && input instanceof Request;
            const requestUrl = isRequest ? input.url : input;
            const options = init ? Object.assign({}, init) : {};
            const body = options.body;
            if (!(body instanceof FormData) || !uploadEndpoint(requestUrl, body)) return nativeFetch(input, init);
            return transformFormData(body).then(function (processed) {
                options.body = processed;
                options.headers = markedHeaders(options.headers || (isRequest ? input.headers : undefined));
                return nativeFetch(input, options);
            }).catch(function (error) { showNotice(error && error.message ? error.message : String(error)); throw error; });
        };
    }

    if (NativeXHR && NativeXHR.prototype) {
        const originalOpen = NativeXHR.prototype.open;
        const originalSend = NativeXHR.prototype.send;
        NativeXHR.prototype.open = function (method, url) { this.__mediaflowUrl = url; return originalOpen.apply(this, arguments); };
        NativeXHR.prototype.send = function (body) {
            if (!(body instanceof FormData) || !uploadEndpoint(this.__mediaflowUrl, body)) return originalSend.call(this, body);
            const xhr = this;
            try { xhr.setRequestHeader('X-MediaFlow-Upload', '1'); xhr.setRequestHeader('X-QMediaFlow-Upload-Workers', String(MAX_WORKERS)); } catch (headerError) {}
            transformFormData(body).then(function (processed) { if (xhr.readyState === 1) originalSend.call(xhr, processed); }).catch(function (error) {
                showNotice(error && error.message ? error.message : String(error));
                if (xhr.readyState === 1) originalSend.call(xhr, body);
            });
            return undefined;
        };
    }
})();
