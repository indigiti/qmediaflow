'use strict';

const fs = require('fs');
const vm = require('vm');

class TestFile extends Blob {
    constructor(parts, name, options = {}) {
        super(parts, options);
        this.name = name;
        this.lastModified = options.lastModified || Date.now();
    }
}

class TestFormData {
    constructor() { this.items = []; }
    append(key, value, filename) {
        if (filename && value instanceof Blob && !(value instanceof TestFile)) {
            value = new TestFile([value], filename, { type: value.type });
        }
        this.items.push([key, value]);
    }
    entries() { return this.items[Symbol.iterator](); }
    get(key) {
        const found = this.items.find((item) => item[0] === key);
        return found ? found[1] : null;
    }
}

class TestWorker {
    constructor(url) { this.url = url; this.handlers = {}; }
    addEventListener(name, fn) { this.handlers[name] = fn; }
    postMessage(message) {
        const blob = new Blob([new Uint8Array(479999)], { type: 'image/webp' });
        queueMicrotask(() => this.handlers.message({ data: {
            id: message.id,
            ok: true,
            blob,
            width: 2200,
            height: 1467,
            quality: 0.81,
            passes: 2
        } }));
    }
    terminate() {}
}

class TestXHR {
    constructor() { this.readyState = 1; this.headers = {}; }
    open(method, url) { this.method = method; this.url = url; this.readyState = 1; }
    setRequestHeader(name, value) { this.headers[name] = value; }
    send(body) { this.sentBody = body; }
}

global.window = global;
global.document = { body: null, getElementById() { return null; }, querySelector() { return null; } };
global.location = { href: 'https://example.test/wp-admin/upload.php' };
global.File = TestFile;
global.FormData = TestFormData;
global.Worker = TestWorker;
global.OffscreenCanvas = function () {};
global.createImageBitmap = async () => ({ width: 3000, height: 2000, close() {} });
global.XMLHttpRequest = TestXHR;
let captured = null;
global.fetch = async (input, init) => { captured = { input, init }; return { ok: true }; };
global.MediaFlowUploadConfig = {
    workerUrl: 'https://example.test/wp-content/plugins/mediaflow/assets/js/mediaflow-upload-worker.js',
    maxWidth: 2560,
    maxHeight: 2560,
    maxSourceBytes: 25 * 1024 * 1024,
    targetBytes: 480000,
    hardBytes: 500000,
    supportedTypes: ['image/jpeg','image/png','image/webp','image/avif','image/heic','image/heif'],
    messages: {}
};

const source = fs.readFileSync(require('path').join(__dirname, '../assets/js/mediaflow-upload.js'), 'utf8');
vm.runInThisContext(source, { filename: 'mediaflow-upload.js' });

(async () => {
    const form = new TestFormData();
    form.append('name', 'caption');
    form.append('async-upload', new TestFile([new Uint8Array(1200000)], 'photo.jpg', { type: 'image/jpeg' }));
    await global.fetch('https://example.test/wp-admin/async-upload.php', { method: 'POST', body: form });

    if (!captured) throw new Error('fetch was not forwarded');
    const finalFile = captured.init.body.get('async-upload');
    if (!(finalFile instanceof TestFile)) throw new Error('processed file missing');
    if (finalFile.name !== 'photo.webp') throw new Error('final extension was not WebP');
    if (finalFile.type !== 'image/webp') throw new Error('final MIME was not WebP');
    if (finalFile.size !== 479999 || finalFile.size > 500000) throw new Error('final byte contract failed');
    if (captured.init.headers.get('X-MediaFlow-Upload') !== '1') throw new Error('upload marker header missing');
    if (captured.init.body.get('name') !== 'caption') throw new Error('non-file multipart fields were not preserved');

    console.log('PASS: fetch upload intercepted, image converted to WebP, marker set, multipart fields preserved');
})().catch((error) => { console.error('FAIL:', error); process.exit(1); });
