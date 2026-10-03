# QMediaFlow viewport-aware progressive delivery

QMediaFlow v0.3.0 can keep below-the-fold responsive images on a lightweight placeholder until they approach the viewport, then activate the signed responsive derivative and reveal it after decode.

## Delivery sequence

1. WordPress renders the image and applies its normal performance attributes.
2. QMediaFlow only considers images already marked `loading="lazy"`.
3. Images marked `fetchpriority="high"` are never deferred.
4. The QMediaFlow LQIP/gradient/color placeholder remains visible while the real `src` and `srcset` are moved to `data-qmediaflow-*` attributes.
5. One shared `IntersectionObserver` watches all deferred images using a 250 px preload margin by default.
6. When an image approaches the viewport, QMediaFlow restores `<picture>` source candidates, `srcset`, and `src`, and switches the image to eager loading for that activation.
7. The reveal waits for `HTMLImageElement.decode()` where supported and then transitions from the soft placeholder to the sharp image.
8. The image is immediately unobserved after activation.
9. A small `MutationObserver` discovers dynamically inserted storefront/content images.

## LCP protection

QMediaFlow does not invent its own hero-image heuristic. It uses WordPress's existing lazy/eager/fetch-priority decisions as the authority. Images without `loading="lazy"`, and any image with `fetchpriority="high"`, keep their normal immediate source URLs and are not delayed by the viewport loader.

## No-JavaScript behavior

Deferred markup includes a `<noscript>` copy with the real source URLs restored. A tiny `<noscript>` style hides the JavaScript-only deferred copy, preventing duplicate layout when JavaScript is unavailable.

## Defaults

- Enabled: yes when progressive placeholders are enabled.
- Preload margin: 250 px before/after the viewport.
- Reveal transition: 180 ms.
- Blur: 12 px.
- Reduced motion: transition disabled via `prefers-reduced-motion`.

## Runtime overrides

Define these constants before WordPress loads:

```php
define( 'QMEDIAFLOW_VIEWPORT_LOADING', true );
define( 'QMEDIAFLOW_VIEWPORT_MARGIN', 250 );
define( 'QMEDIAFLOW_VIEWPORT_REVEAL_MS', 180 );
define( 'QMEDIAFLOW_VIEWPORT_BLUR_PX', 12 );
```

Limits are clamped in code: margin 0–2000 px, reveal 0–1500 ms, blur 0–32 px.

A specific image can opt out by rendering `data-qmediaflow-viewport="off"`. Developers can also use the `qmediaflow_viewport_defer` filter to disable deferral for a particular rendered image/context.

## Performance properties

The feature does not change signed derivative identity, cache namespace, generation locks, static immutable delivery, CDN rewriting, or object-store distribution. Warm QMediaFlow derivatives remain normal static-file requests. The observer and mutation observer are shared page-wide rather than allocated per image.
