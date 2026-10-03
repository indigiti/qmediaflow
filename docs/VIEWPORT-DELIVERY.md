# QMediaFlow viewport-aware progressive delivery

QMediaFlow v0.3.0 keeps below-the-fold responsive images on a lightweight placeholder until they approach the viewport, then activates the immutable responsive derivative and reveals it after decode.

## Delivery sequence

1. WordPress renders the image and applies its normal performance attributes.
2. QMediaFlow only considers images already marked `loading="lazy"`.
3. Images marked `fetchpriority="high"` are never deferred.
4. The QMediaFlow LQIP/gradient/color placeholder remains visible while the real `src`, `srcset`, and `<picture>` candidates move to `data-qmediaflow-*` attributes.
5. One shared `IntersectionObserver` watches all deferred images.
6. The observer margin adapts to browser network hints when supported.
7. Fast scrolling may activate a bounded number of images slightly farther ahead so they can decode before becoming visible.
8. QMediaFlow restores sources, switches the activated image to eager loading, waits for `HTMLImageElement.decode()` where supported, then reveals the sharp image.
9. The image is immediately unobserved after activation.
10. One shared `MutationObserver` discovers dynamically inserted storefront/content images.

## LCP protection

QMediaFlow does not replace WordPress's critical-image policy. Images without `loading="lazy"`, and any image with `fetchpriority="high"`, keep their immediate source URLs. An activated image that is already inside the visible viewport may receive `fetchPriority = 'high'` when the markup did not explicitly set a priority.

## Adaptive preload policy

Defaults:

| Condition | Preload distance |
| --- | ---: |
| Base / unknown network | 250 px |
| Fast (`4g`) network hint | 400 px |
| `3g` | up to 180 px |
| `2g` / `slow-2g` | 100 px |
| Save-Data | 60 px |

When scrolling faster than the internal velocity threshold, QMediaFlow can look ahead up to 900 px by default. That path is disabled under Save-Data and inspects at most a small bounded set of pending images per animation frame.

`navigator.connection` is not available in every browser. In that case QMediaFlow simply uses the configured base margin; correctness does not depend on the Network Information API.

## No-JavaScript behavior

Deferred markup includes a `<noscript>` copy with the real source URLs restored. A small `<noscript>` style hides the JavaScript-only deferred copy, preventing duplicate layout when JavaScript is unavailable.

## Reveal defaults

- Reveal transition: 180 ms.
- Blur: 12 px.
- Reduced motion: transition disabled via `prefers-reduced-motion`.
- Width/height attributes are preserved, so viewport activation does not intentionally introduce layout shift.

## Runtime overrides

Define before WordPress loads:

```php
define( 'QMEDIAFLOW_VIEWPORT_LOADING', true );
define( 'QMEDIAFLOW_VIEWPORT_ADAPTIVE', true );
define( 'QMEDIAFLOW_VIEWPORT_MARGIN', 250 );
define( 'QMEDIAFLOW_VIEWPORT_FAST_MARGIN', 400 );
define( 'QMEDIAFLOW_VIEWPORT_SLOW_MARGIN', 100 );
define( 'QMEDIAFLOW_VIEWPORT_SAVE_DATA_MARGIN', 60 );
define( 'QMEDIAFLOW_VIEWPORT_VELOCITY_LOOKAHEAD', 900 );
define( 'QMEDIAFLOW_VIEWPORT_REVEAL_MS', 180 );
define( 'QMEDIAFLOW_VIEWPORT_BLUR_PX', 12 );
```

Limits are clamped in code. A specific image can opt out with `data-qmediaflow-viewport="off"`. Developers can also use the `qmediaflow_viewport_defer` filter.

## Performance properties

The feature changes **fetch timing only**. It does not change signed derivative identity, cache namespace, generation locks, immutable publication, CDN rewriting, or object-store distribution. Warm QMediaFlow derivatives remain static-file requests.

The page uses one shared IntersectionObserver implementation, one central pending-image set, and one MutationObserver rather than allocating observers per image.
