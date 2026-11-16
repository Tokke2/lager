<?php
/**
 * Minimal, strikt SVG-sanering för uppladdade logotyper.
 */

declare(strict_types=1);

namespace TDL\Support;

/**
 * Minimal, strikt SVG-sanering. Vi behåller bara ritprimitive r och attribut
 * som behövs för en logotyp, och kastar allt som kan köra kod eller hämta
 * externa resurser.
 */
final class SvgSanerare
{
    private const TILLATNA_TAGGAR = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'defs', 'linearGradient', 'radialGradient', 'stop', 'use', 'title', 'desc', 'clipPath', 'mask',
    ];

    private const FORBJUDNA_TAGGAR = [
        'script', 'foreignObject', 'image', 'iframe', 'object', 'embed', 'audio', 'video',
        'animate', 'animateTransform', 'animateMotion', 'set', 'style', 'link', 'meta',
    ];

    private const TILLATNA_ATTRIBUT = [
        'xmlns', 'viewBox', 'width', 'height', 'fill', 'fill-rule', 'fill-opacity', 'stroke',
        'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray',
        'opacity', 'transform', 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'points', 'id', 'class', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits',
        'gradientTransform', 'href', 'xlink:href', 'preserveAspectRatio', 'clip-path', 'mask',
    ];

    public static function sanera(string $svg): ?string
    {
        if (strlen($svg) > 512 * 1024) {
            return null;
        }
        if (stripos($svg, '<!DOCTYPE') !== false && stripos($svg, '<!ENTITY') !== false) {
            return null; // XXE-försök
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$ok || $doc->documentElement === null) {
            return null;
        }
        $rot = $doc->documentElement;
        if (strtolower($rot->tagName) !== 'svg') {
            return null;
        }
        self::stada($rot);
        $ut = $doc->saveXML($rot);
        return $ut === false ? null : $ut;
    }

    private static function stada(\DOMElement $el): void
    {
        // Barn först (kopiera listan – vi ändrar i trädet)
        $barn = [];
        foreach ($el->childNodes as $nod) {
            $barn[] = $nod;
        }
        foreach ($barn as $nod) {
            if ($nod instanceof \DOMElement) {
                $tagg = $nod->tagName;
                if (in_array(strtolower($tagg), self::FORBJUDNA_TAGGAR, true)
                    || !in_array($tagg, self::TILLATNA_TAGGAR, true)) {
                    $el->removeChild($nod);
                    continue;
                }
                self::stada($nod);
            }
        }
        // Attribut
        $attribut = [];
        foreach ($el->attributes as $attr) {
            $attribut[] = $attr->name;
        }
        foreach ($attribut as $namn) {
            $värde = (string) $el->getAttribute($namn);
            $litet = strtolower($namn);
            if (!in_array($namn, self::TILLATNA_ATTRIBUT, true)) {
                $el->removeAttribute($namn);
                continue;
            }
            if ($litet === 'href' || $litet === 'xlink:href') {
                // Endast interna referenser (#id) tillåts
                if (!str_starts_with(trim($värde), '#')) {
                    $el->removeAttribute($namn);
                }
                continue;
            }
            if (preg_match('/javascript:|data:|https?:|url\s*\(\s*[\'"]?https?:/i', $värde)) {
                $el->removeAttribute($namn);
            }
        }
    }
}
