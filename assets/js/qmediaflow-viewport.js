(function () {
    'use strict';

    const config = window.QMediaFlowViewport || {};
    const selector = 'img[data-qmediaflow-viewport="1"]';
    const revealMs = Math.max(0, Math.min(1500, Number(config.revealMs || 180)));
    const configuredRootMargin = typeof config.rootMargin === 'string' && config.rootMargin ? config.rootMargin : '250px 0px';
    const parsedMargin = parseInt(configuredRootMargin, 10);
    const baseMargin = Math.max(0, Math.min(2000, Number.isFinite(parsedMargin) ? parsedMargin : 250));
    const fastMargin = Math.max(baseMargin, Math.min(2000, Number(config.fastMargin || 400)));
    const slowMargin = Math.max(0, Math.min(baseMargin, Number(config.slowMargin || 100)));
    const saveDataMargin = Math.max(0, Math.min(slowMargin, Number(config.saveDataMargin || 60)));
    const velocityLookahead = Math.max(0, Math.min(2500, Number(config.velocityLookahead || 900)));
    const adaptive = config.adaptive !== false;
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
    const pending = new Set();
    let observer = null;
    let started = false;
    let currentObserverMargin = -1;
    let lastScrollY = window.scrollY || 0;
    let lastScrollAt = (window.performance && performance.now) ? performance.now() : Date.now();
    let scrollVelocity = 0;
    let scrollDirection = 1;
    let scrollTicking = false;

    function networkMargin() {
        if (!adaptive || !connection) return baseMargin;
        if (connection.saveData) return saveDataMargin;
        const type = String(connection.effectiveType || '').toLowerCase();
        if (type === 'slow-2g' || type === '2g') return slowMargin;
        if (type === '3g') return Math.min(baseMargin, Math.max(slowMargin, 180));
        if (type === '4g') return fastMargin;
        return baseMargin;
    }

    function finish(img) {
        if (!img || img.dataset.qmediaflowState === 'loaded') return;
        img.dataset.qmediaflowState = 'loaded';
        img.classList.add('qmediaflow-loaded');
        pending.delete(img);
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

    function activate(img, reason) {
        if (!img || img.dataset.qmediaflowState === 'loading' || img.dataset.qmediaflowState === 'loaded') return;
        img.dataset.qmediaflowState = 'loading';
        img.dataset.qmediaflowActivation = reason || 'viewport';

        const rect = img.getBoundingClientRect ? img.getBoundingClientRect() : null;
        if (rect && rect.top < window.innerHeight && rect.bottom > 0 && !img.getAttribute('fetchpriority')) {
            img.fetchPriority = 'high';
        }

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

        pending.delete(img);
        if (observer) observer.unobserve(img);
        revealWhenDecoded(img);
    }

    function observe(img) {
        if (!img || img.dataset.qmediaflowState === 'loaded' || img.dataset.qmediaflowState === 'loading') return;
        pending.add(img);
        if (!observer) {
            activate(img, 'fallback');
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

    function rebuildObserver() {
        if (!('IntersectionObserver' in window)) {
            observer = null;
            Array.from(pending).forEach(function (img) { activate(img, 'fallback'); });
            return;
        }
        const margin = networkMargin();
        if (observer && margin === currentObserverMargin) return;
        if (observer) observer.disconnect();
        currentObserverMargin = margin;
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting || entry.intersectionRatio > 0) activate(entry.target, 'viewport');
            });
        }, {
            root: null,
            rootMargin: margin + 'px 0px',
            threshold: 0.01
        });
        Array.from(pending).forEach(function (img) { observer.observe(img); });
    }

    function prefetchForVelocity() {
        scrollTicking = false;
        if (scrollVelocity < 1.2 || velocityLookahead < 1 || (connection && connection.saveData) || document.visibilityState === 'hidden') return;
        const extra = Math.min(velocityLookahead, Math.round(scrollVelocity * 360));
        const margin = networkMargin();
        let inspected = 0;
        Array.from(pending).some(function (img) {
            if (++inspected > 16 || !img.getBoundingClientRect) return inspected > 16;
            const rect = img.getBoundingClientRect();
            if (scrollDirection >= 0) {
                if (rect.top >= window.innerHeight && rect.top <= window.innerHeight + margin + extra) activate(img, 'velocity');
            } else if (rect.bottom <= 0 && rect.bottom >= -(margin + extra)) {
                activate(img, 'velocity');
            }
            return false;
        });
    }

    function onScroll() {
        const now = (window.performance && performance.now) ? performance.now() : Date.now();
        const y = window.scrollY || 0;
        const elapsed = Math.max(1, now - lastScrollAt);
        const delta = y - lastScrollY;
        scrollVelocity = Math.abs(delta) / elapsed;
        scrollDirection = delta >= 0 ? 1 : -1;
        lastScrollY = y;
        lastScrollAt = now;
        if (!scrollTicking) {
            scrollTicking = true;
            window.requestAnimationFrame(prefetchForVelocity);
        }
    }

    function start() {
        if (started) return;
        started = true;
        rebuildObserver();
        scan(document);

        if ('MutationObserver' in window && document.body) {
            const mutations = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(scan);
                });
            });
            mutations.observe(document.body, { childList: true, subtree: true });
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        if (connection && typeof connection.addEventListener === 'function') {
            connection.addEventListener('change', rebuildObserver);
        }
    }

    if (document.body) {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    }
})();
