'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const root = path.resolve(__dirname, '..');

function read(relative) {
    return fs.readFileSync(path.join(root, relative), 'utf8');
}

const php = read('includes/class-viewport-loader.php');
const js = read('assets/js/qmediaflow-viewport.js');
const css = read('assets/css/qmediaflow-viewport.css');
const main = read('mediaflow.php');

execFileSync(process.execPath, ['--check', path.join(root, 'assets/js/qmediaflow-viewport.js')], { stdio: 'inherit' });

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(main.includes("class-viewport-loader.php"), 'Viewport loader must be required by plugin bootstrap.');
assert(main.includes('MediaFlow\\Viewport_Loader::boot();'), 'Viewport loader must boot with QMediaFlow features.');
assert(php.includes("loading=\(?:\"\|\\'\)lazy"), 'Server contract must gate deferral on WordPress loading=lazy.');
assert(php.includes("fetchpriority=\(?:\"\|\\'\)high"), 'Server contract must preserve fetchpriority=high images.');
assert(php.includes("data-qmediaflow-src"), 'Real src must be held outside src before viewport activation.');
assert(php.includes("data-qmediaflow-srcset"), 'Real srcset and picture candidates must be held before activation.');
assert(php.includes('TRANSPARENT_GIF'), 'Deferred img needs a zero-cost valid placeholder src.');
assert(php.includes('<noscript class=\"qmediaflow-noscript\">'), 'Deferred HTML requires a no-JS fallback.');
assert(php.includes("QMEDIAFLOW_VIEWPORT_MARGIN") && php.includes(': 250'), 'Viewport base preload margin must default to 250px.');
assert(php.includes("QMEDIAFLOW_VIEWPORT_FAST_MARGIN") && php.includes(': 400'), 'Fast-network preload margin must default to 400px.');
assert(php.includes("QMEDIAFLOW_VIEWPORT_SAVE_DATA_MARGIN") && php.includes(': 60'), 'Save-Data preload margin must remain conservative.');
assert(php.includes("QMEDIAFLOW_VIEWPORT_VELOCITY_LOOKAHEAD") && php.includes(': 900'), 'Fast-scroll lookahead must be bounded.');
assert(php.includes("QMEDIAFLOW_VIEWPORT_REVEAL_MS") && php.includes(': 180'), 'Reveal transition must default to 180ms.');
assert((js.match(/new IntersectionObserver/g) || []).length === 1, 'Exactly one shared IntersectionObserver implementation should serve the page.');
assert(js.includes("observer.unobserve(img)"), 'Activated images must be removed from observation.');
assert(js.includes("img.decode()"), 'Reveal should wait for image decode when supported.');
assert(js.includes("source[data-qmediaflow-srcset]"), 'Picture source candidates must activate with the img.');
assert(js.includes("MutationObserver"), 'Dynamically inserted storefront/content images should be discovered.');
assert(js.includes('navigator.connection') && js.includes('effectiveType') && js.includes('saveData'), 'Viewport margin must adapt to browser network hints.');
assert(js.includes('velocityLookahead') && js.includes('scrollVelocity') && js.includes('requestAnimationFrame'), 'Fast scrolling must receive bounded lookahead prefetching.');
assert(js.includes('const pending = new Set()'), 'Pending images must be tracked centrally for observer rebuilds.');
assert(js.includes("connection.addEventListener('change', rebuildObserver)"), 'Network changes must rebuild the shared observer.');
assert(css.includes('prefers-reduced-motion: reduce'), 'Reveal must respect reduced-motion preferences.');
assert(css.includes('qmediaflow-loaded'), 'CSS must provide a sharp loaded state.');

console.log('PASS: adaptive viewport LQIP activation, network/save-data policy, velocity lookahead, LCP protection and shared observer contracts');
