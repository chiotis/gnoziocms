<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Uploaded SVG drawings (audit S17): the file is parsed and written again with only the elements and attributes a drawing
 * needs. No script, no event attribute, no animation that sets one or a link, no `<foreignObject>`, no stylesheet that imports
 * or loads anything, and links only to a part of the same drawing (`#…`), or a raster picture inlined as `data:image/…` in
 * `<image>`. Values are read after the parser has decoded entities and character references, so `&#106;avascript:` is seen
 * as `javascript:`. A file with a DOCTYPE (entities), that does not parse, or whose root is not `<svg>` is refused.
 *
 * The Content-Security-Policy on `.svg` files (public/uploads/.htaccess, the nginx line in docs/security.md, checked on
 * System > Status) stays the second line of defence.
 */
final class SvgSanitizer
{
    public const NS = 'http://www.w3.org/2000/svg';
    public const XLINK = 'http://www.w3.org/1999/xlink';
    public const MAX_BYTES = 5_000_000;

    /** Elements kept, by local name, in the SVG namespace. Anything else is removed with what it holds. */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'switch', 'view', 'title', 'desc', 'metadata', 'a',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'image', 'style',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting',
        'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
        'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting',
        'feSpotLight', 'feTile', 'feTurbulence',
        'animate', 'animateTransform', 'animateMotion', 'mpath', 'set',
    ];

    /** Attributes kept (no namespace), besides `xlink:href`, `xml:space` and `xml:lang`. */
    private const ATTRIBUTES = [
        'id', 'class', 'style', 'lang', 'tabindex', 'role', 'focusable', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden',
        'version', 'baseProfile', 'width', 'height', 'x', 'y', 'viewBox', 'preserveAspectRatio', 'transform', 'href',
        'd', 'pathLength', 'points', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'dx', 'dy', 'rotate', 'textLength',
        'lengthAdjust', 'startOffset', 'method', 'spacing', 'side',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'color', 'display', 'visibility', 'overflow',
        'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits', 'maskContentUnits', 'filter', 'filterUnits', 'primitiveUnits',
        'marker-start', 'marker-mid', 'marker-end', 'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy', 'fr', 'offset', 'stop-color', 'stop-opacity',
        'patternUnits', 'patternContentUnits', 'patternTransform', 'vector-effect', 'paint-order', 'shape-rendering',
        'text-rendering', 'image-rendering', 'color-interpolation', 'color-interpolation-filters', 'color-rendering', 'isolation',
        'mix-blend-mode', 'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant', 'font-weight',
        'text-anchor', 'text-decoration', 'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'letter-spacing',
        'word-spacing', 'writing-mode', 'direction', 'unicode-bidi', 'flood-color', 'flood-opacity', 'lighting-color',
        'in', 'in2', 'result', 'mode', 'operator', 'k1', 'k2', 'k3', 'k4', 'values', 'type', 'tableValues', 'slope', 'intercept',
        'amplitude', 'exponent', 'stdDeviation', 'edgeMode', 'order', 'kernelMatrix', 'divisor', 'bias', 'targetX', 'targetY',
        'kernelUnitLength', 'preserveAlpha', 'surfaceScale', 'diffuseConstant', 'specularConstant', 'specularExponent', 'azimuth',
        'elevation', 'z', 'pointsAtX', 'pointsAtY', 'pointsAtZ', 'limitingConeAngle', 'scale', 'xChannelSelector',
        'yChannelSelector', 'radius', 'baseFrequency', 'numOctaves', 'seed', 'stitchTiles',
        'attributeName', 'attributeType', 'begin', 'dur', 'end', 'min', 'max', 'restart', 'repeatCount', 'repeatDur', 'from', 'to',
        'by', 'calcMode', 'keyTimes', 'keySplines', 'keyPoints', 'additive', 'accumulate', 'path', 'requiredFeatures',
        'requiredExtensions', 'systemLanguage',
    ];

    /** What an animation may change: never an event attribute, a link or a style that could load something. */
    private const ANIMATED_UNSAFE = ['href', 'xlink:href', 'style', 'from', 'to', 'values', 'by', 'attributeName'];

    /**
     * The drawing written again with only what is safe; null when it is refused (no parse, a DOCTYPE, not an SVG, too big).
     */
    public static function clean(string $xml): ?string
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $document->documentElement : null;
        if ($root === null || $document->doctype !== null || $root->localName !== 'svg' || $root->namespaceURI !== self::NS) {
            return null;
        }
        // Processing instructions (an xml-stylesheet) and comments go: they hold nothing a drawing needs.
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node !== $root) {
                $document->removeChild($node);
            }
        }
        self::element($root);
        $out = $document->saveXML($root);
        return is_string($out) ? $out . "\n" : null;
    }

    private static function element(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if ($child->namespaceURI !== self::NS || !in_array($child->localName, self::ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }
                self::element($child);
                continue;
            }
            if ($child instanceof \DOMText && !$child instanceof \DOMCdataSection) {
                if ($element->localName === 'style' && !self::safeCss($child->data)) {
                    $element->removeChild($child);
                }
                continue;
            }
            if ($child instanceof \DOMCdataSection && $element->localName === 'style' && self::safeCss($child->data)) {
                continue;
            }
            // Comments, processing instructions, CDATA outside a style, entity references.
            $element->removeChild($child);
        }
        self::attributes($element);
    }

    private static function attributes(\DOMElement $element): void
    {
        $remove = [];
        $name = $element->localName;
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var \DOMAttr $attribute */
            $ns = (string)$attribute->namespaceURI;
            $local = $attribute->localName;
            $value = $attribute->value;
            $qualified = match ($ns) {
                '' => $local,
                self::XLINK => 'xlink:' . $local,
                'http://www.w3.org/XML/1998/namespace' => 'xml:' . $local,
                default => '',
            };
            $keep = match (true) {
                $qualified === '' => false,
                $qualified === 'xml:space', $qualified === 'xml:lang' => true,
                $qualified === 'href', $qualified === 'xlink:href' => self::safeLink($value, $name),
                $ns !== '' => false,
                !in_array($local, self::ATTRIBUTES, true) => false,
                $local === 'style' => self::safeCss($value),
                $local === 'attributeName' => !self::animatesUnsafe($value),
                default => self::safeValue($value),
            };
            if (!$keep) {
                $remove[] = $attribute;
            }
        }
        foreach ($remove as $attribute) {
            $element->removeAttributeNode($attribute);
        }
        // An animation left with no attribute to change, or one it may not change, goes altogether.
        if (in_array($name, ['animate', 'set'], true) && (!$element->hasAttribute('attributeName') || self::animatesUnsafe($element->getAttribute('attributeName')))) {
            $element->parentNode?->removeChild($element);
        }
    }

    /** Links only within the drawing, and a raster picture inlined in `<image>`/`<feImage>`. */
    private static function safeLink(string $value, string $element): bool
    {
        $value = trim($value);
        if (preg_match('/^#[A-Za-z_][\w.:-]*$/', $value) === 1) {
            return true;
        }
        return in_array($element, ['image', 'feImage'], true)
            && preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,[A-Za-z0-9+/=\s]+$#i', $value) === 1;
    }

    private static function animatesUnsafe(string $attribute): bool
    {
        $attribute = strtolower(trim($attribute));
        return str_starts_with($attribute, 'on') || in_array($attribute, self::ANIMATED_UNSAFE, true) || str_contains($attribute, 'href');
    }

    /** A value with no script, no loading from elsewhere: `url()` only to `#…`. */
    private static function safeValue(string $value): bool
    {
        $plain = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $value) ?? '');
        if (str_contains($plain, 'javascript:') || str_contains($plain, 'vbscript:') || str_contains($plain, 'data:') || str_contains($plain, 'expression(')) {
            return false;
        }
        if (preg_match_all('/url\(([^)]*)\)/', $plain, $urls) > 0) {
            foreach ($urls[1] as $url) {
                if (preg_match('/^["\']?#/', $url) !== 1) {
                    return false;
                }
            }
        }
        return !str_contains($plain, 'url(') || preg_match_all('/url\(/', $plain) === count($urls[1] ?? []);
    }

    /** A stylesheet or a style attribute: no import, no escapes (which could hide one), and only local `url(#…)`. */
    private static function safeCss(string $css): bool
    {
        if (str_contains($css, '\\') || preg_match('/@import|@font-face|@namespace|behavior\s*:|-moz-binding|<\//i', $css) === 1) {
            return false;
        }
        return self::safeValue($css);
    }
}
