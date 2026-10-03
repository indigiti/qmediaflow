(function () {
    'use strict';

    const config = window.QMediaFlowViewport || {};
    const selector = 'img[data-qmediaflow-viewport="1"]';
    const revealMs = Math.max(0, Math.min(1500, Number(config.revealMs || 180)));
    const rootMargin = typeof config.rootMargin === 'string' && config.rootMargin ? config.rootMargin : '250px 0px';
    let observer = null;
    let started = false;

    function finish(img) {
        if (!img || img.dataset.qmediaflowState === 'loaded') return;
        img.dataset.qmediaflowState = 'loaded';
        img.classList.add('qmediaflow-loaded');
        if (revealMs > 0) {
            window.setTimeout(function () {
                if (img && img.style) img.style.willChange = 'auto';
            }, revealMs + 50);
        }
    }

    function finishOnLoad(img) {
        if (img.complete) {
            finish(img);
            return;
        }
        img.addEventListener('load', function () { finish(img); }, { once: true });
    }

    function revealWhenDecoded(img) {
        if (typeof img.decode === 'function') {
            img.decode().then(function () { finish(img); }, function () { finishOnLoad(img); });
            return;
        }
        finishOnLoad(img);
    }

    function activate(img) {
        if (!img || img.dataset.qmediaflowState === 'loading' || img.dataset.qmediaflowState === 'loaded') return;
        img.dataset.qmediaflowState = 'loading';

        const picture = img.closest ? img.closest('picture') : null;
        if (picture) {
            picture.querySelectorAll('source[data-qmediaflow-srcset]').forEach(function (source) {
                const srcset = source.getAttribute('data-qmediaflow-srcset');
                if (!srcset) return;
                source.setAttribute('srcset', srcset);
                source.removeAttribute('data-qmediaflow-srcset');
            });
        }

        const srcset = img.getAttribute('data-qmediaflow-srcset');
        const src = img.getAttribute('data-qmediaflow-src');
        if (srcset) {
            img.setAttribute('srcset', srcset);
            img.removeAttribute('data-qmediaflow-srcset');
        }
        if (src) {
            img.loading = 'eager';
            img.setAttribute('src', src);
            img.removeAttribute('data-qmediaflow-src');
        }

        if (observer) observer.unobserve(img);
        revealWhenDecoded(img);
    }

    function observe(img) {
        if (!img || img.dataset.qmediaflowState === 'loaded') return;
        if (!observer) {
            activate(img);
            return;
        }
        observer.observe(img);
    }

    function scan(root) {
        if (!root) return;
        if (root.nodeType === 1 && root.matches && root.matches(selector)) observe(root);
        if (!root.querySelectorAll) return;
        root.querySelectorAll(selector).forEach(observe);
    }

    function start() {
        if (started) return;
        started = true;
        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting || entry.intersectionRatio > 0) activate(entry.target);
                });
            }, {
                root: null,
                rootMargin: rootMargin,
                threshold: 0.01
            });
        }

        scan(document);

        if ('MutationObserver' in window && document.body) {
            const mutations = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(scan);
                });
            });
            mutations.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.body) {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    }
})();
