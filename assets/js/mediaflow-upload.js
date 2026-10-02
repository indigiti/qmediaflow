(function () {
    'use strict';

    const config = window.MediaFlowUploadConfig || null;
    if (!config || !config.workerUrl) {
        return;
    }

    const supported = new Set(config.supportedTypes || []);
    const nativeFetch = window.fetch ? window.fetch.bind(window) : null;
    const NativeXHR = window.XMLHttpRequest;
    const jobs = new Map();
    let worker = null;
    let sequence = 0;

    function humanBytes(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        return Math.ceil(bytes / 1024) + ' KB';
    }

    function showNotice(message) {
        if (window.console && console.error) {
            console.error('[MediaFlow]', message);
        }
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
        if (notice && notice.firstChild) {
            notice.firstChild.textContent = message;
        }
        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(message, 'assertive');
        }
    }

    function uploadEndpoint(url, formData) {
        let parsed;
        try {
            parsed = new URL(String(url || ''), window.location.href);
        } catch (error) {
            return false;
        }

        if (/\/wp-admin\/async-upload\.php$/.test(parsed.pathname)) {
            return true;
        }
        if (/\/wp-json\/wp\/v2\/media\/?$/.test(parsed.pathname)) {
            return true;
        }
        if ((parsed.searchParams.get('rest_route') || '').replace(/\/$/, '') === '/wp/v2/media') {
            return true;
        }
        if (/\/wp-admin\/admin-ajax\.php$/.test(parsed.pathname) && formData instanceof FormData) {
            return formData.get('action') === 'upload-attachment';
        }
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
        return ({
            jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp',
            avif: 'image/avif', heic: 'image/heic', heif: 'image/heif', gif: 'image/gif'
        })[extension] || '';
    }

    function imageFile(value) {
        if (typeof File === 'undefined' || !(value instanceof File)) return false;
        const type = sourceType(value);
        return type.indexOf('image/') === 0 || /\.(?:jpe?g|png|webp|avif|gif|heic|heif)$/i.test(String(value.name || ''));
    }

    function validateSource(file) {
        if (!supported.has(sourceType(file))) {
            throw new Error((config.messages && config.messages.unsupported) || 'Unsupported image type.');
        }
        if (file.size <= 0 || file.size > Number(config.maxSourceBytes)) {
            throw new Error(((config.messages && config.messages.tooLarge) || 'Original image is too large.') + ' Maximum: ' + humanBytes(Number(config.maxSourceBytes)) + '.');
        }
    }

    function getWorker() {
        if (worker) return worker;
        if (typeof Worker === 'undefined' || typeof OffscreenCanvas === 'undefined' || typeof createImageBitmap === 'undefined') {
            throw new Error('This browser does not provide the Web Worker image APIs required by MediaFlow.');
        }
        worker = new Worker(config.workerUrl);
        worker.addEventListener('message', function (event) {
            const data = event.data || {};
            const pending = jobs.get(data.id);
            if (!pending) return;
            jobs.delete(data.id);
            if (data.ok) pending.resolve(data);
            else pending.reject(new Error(data.message || 'Worker image processing failed.'));
        });
        worker.addEventListener('error', function (event) {
            const error = new Error(event.message || 'MediaFlow upload worker failed.');
            jobs.forEach(function (pending) { pending.reject(error); });
            jobs.clear();
            worker.terminate();
            worker = null;
        });
        return worker;
    }

    async function orientedBitmap(file) {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch (firstError) {
            // Older implementations either already honor EXIF orientation by
            // default or do not accept the options dictionary.
            return createImageBitmap(file);
        }
    }

    async function optimize(file) {
        validateSource(file);
        const bitmap = await orientedBitmap(file);
        const id = 'mf-' + Date.now().toString(36) + '-' + (++sequence).toString(36);
        const currentWorker = getWorker();
        const resultPromise = new Promise(function (resolve, reject) {
            jobs.set(id, { resolve: resolve, reject: reject });
        });

        currentWorker.postMessage({
            id: id,
            bitmap: bitmap,
            options: {
                maxWidth: Number(config.maxWidth),
                maxHeight: Number(config.maxHeight),
                targetBytes: Number(config.targetBytes),
                hardBytes: Number(config.hardBytes)
            }
        }, [bitmap]);

        const result = await resultPromise;
        if (!(result.blob instanceof Blob) || result.blob.type !== 'image/webp') {
            throw new Error((config.messages && config.messages.processing) || 'Browser image processing failed.');
        }
        if (result.blob.size <= 0 || result.blob.size > Number(config.hardBytes)) {
            throw new Error((config.messages && config.messages.finalSize) || 'Final image exceeds the upload size limit.');
        }

        return new File([result.blob], fileNameAsWebP(file.name), {
            type: 'image/webp',
            lastModified: file.lastModified || Date.now()
        });
    }

    async function transformFormData(body) {
        if (!(body instanceof FormData)) return body;
        const entries = Array.from(body.entries());
        if (!entries.some(function (entry) { return imageFile(entry[1]); })) {
            return body;
        }

        const output = new FormData();
        for (const entry of entries) {
            const key = entry[0];
            const value = entry[1];
            if (!imageFile(value)) {
                output.append(key, value);
                continue;
            }
            const converted = await optimize(value);
            output.append(key, converted, converted.name);
        }
        return output;
    }

    function markedHeaders(headers) {
        const result = new Headers(headers || {});
        result.set('X-MediaFlow-Upload', '1');
        return result;
    }

    if (nativeFetch) {
        window.fetch = function (input, init) {
            const isRequest = typeof Request !== 'undefined' && input instanceof Request;
            const requestUrl = isRequest ? input.url : input;
            const options = init ? Object.assign({}, init) : {};
            const body = options.body;
            if (!(body instanceof FormData) || !uploadEndpoint(requestUrl, body)) {
                return nativeFetch(input, init);
            }

            return transformFormData(body).then(function (processed) {
                options.body = processed;
                options.headers = markedHeaders(options.headers || (isRequest ? input.headers : undefined));
                return nativeFetch(input, options);
            }).catch(function (error) {
                showNotice(error && error.message ? error.message : String(error));
                throw error;
            });
        };
    }

    if (NativeXHR && NativeXHR.prototype) {
        const originalOpen = NativeXHR.prototype.open;
        const originalSend = NativeXHR.prototype.send;

        NativeXHR.prototype.open = function (method, url) {
            this.__mediaflowUrl = url;
            return originalOpen.apply(this, arguments);
        };

        NativeXHR.prototype.send = function (body) {
            if (!(body instanceof FormData) || !uploadEndpoint(this.__mediaflowUrl, body)) {
                return originalSend.call(this, body);
            }

            const xhr = this;
            try {
                xhr.setRequestHeader('X-MediaFlow-Upload', '1');
            } catch (headerError) {
                // WordPress upload requests are same-origin. If another script
                // has already moved the XHR state, normal server validation is
                // still the final guard.
            }

            transformFormData(body).then(function (processed) {
                if (xhr.readyState === 1) originalSend.call(xhr, processed);
            }).catch(function (error) {
                showNotice(error && error.message ? error.message : String(error));
                // Send the untouched body so WordPress receives a normal HTTP
                // response; MediaFlow's server validator will reject an image
                // that does not satisfy the WebP/500 KB contract.
                if (xhr.readyState === 1) originalSend.call(xhr, body);
            });
            return undefined;
        };
    }
})();
