<?php

namespace App\Services\Barcodes;

use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Renders a product code as an inline SVG barcode for label printing
 * (docs/features/hardware.md §6.2). Wraps picqer/php-barcode-generator.
 *
 * Auto-picks the symbology: a clean 13-digit numeric value renders as
 * EAN-13 (the retail standard), everything else as Code 128 (which
 * accepts any ASCII SKU). A value that fails its chosen symbology falls
 * back to Code 128 so a label never renders blank.
 */
class BarcodeRenderer
{
    private const QUIET_ZONE_MODULES = 10;

    public function __construct(private BarcodeGeneratorSVG $generator = new BarcodeGeneratorSVG()) {}

    /**
     * @param  string  $value   The code to encode (barcode or SKU).
     * @param  float   $height  SVG height in px (scaled by the label CSS).
     * @return string  An <svg> string, or '' when there's nothing to encode.
     */
    public function svg(string $value, float $widthFactor = 2, float $height = 30): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $type = $this->resolveType($value);

        try {
            return $this->prepareForPrinting(
                $this->generator->getBarcode($value, $type, $widthFactor, $height),
                $widthFactor
            );
        } catch (\Throwable $e) {
            // Fall back to Code 128 if the value didn't satisfy EAN-13.
            if ($type !== BarcodeGeneratorSVG::TYPE_CODE_128) {
                try {
                    return $this->prepareForPrinting(
                        $this->generator->getBarcode($value, BarcodeGeneratorSVG::TYPE_CODE_128, $widthFactor, $height),
                        $widthFactor
                    );
                } catch (\Throwable $e2) {
                    return '';
                }
            }

            return '';
        }
    }

    /**
     * Add the blank area scanners require on both sides of a barcode and make
     * browser/printer scaling keep the bars sharp. Picqer's SVG ends at the
     * first and last bars, so surrounding label padding alone is unreliable
     * on small roll labels.
     */
    private function prepareForPrinting(string $svg, float $widthFactor): string
    {
        $quietZone = $widthFactor * self::QUIET_ZONE_MODULES;

        $preparedSvg = preg_replace_callback(
            '/<svg width="(?<width>[0-9.]+)" height="(?<height>[0-9.]+)" viewBox="0 0 (?<viewWidth>[0-9.]+) (?<viewHeight>[0-9.]+)"(?<attributes>[^>]*)>/',
            static function (array $matches) use ($quietZone): string {
                $totalWidth = (float) $matches['viewWidth'] + ($quietZone * 2);
                $format = static fn (float $number): string => rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

                return sprintf(
                    '<svg width="%s" height="%s" viewBox="0 0 %s %s" preserveAspectRatio="none" shape-rendering="crispEdges"%s>',
                    $format($totalWidth),
                    $matches['height'],
                    $format($totalWidth),
                    $matches['viewHeight'],
                    $matches['attributes']
                );
            },
            $svg,
            1
        );

        // If the dependency changes its SVG header format, keep the original
        // output rather than shifting bars into an unexpanded view box.
        if ($preparedSvg === null || $preparedSvg === $svg) {
            return $svg;
        }

        $quietZoneValue = rtrim(rtrim(number_format($quietZone, 4, '.', ''), '0'), '.');

        return str_replace(
            '<g id="bars"',
            '<rect id="quiet-zone" width="100%" height="100%" fill="#fff"/><g id="bars" transform="translate('.$quietZoneValue.' 0)"',
            $preparedSvg
        );
    }

    private function resolveType(string $value): string
    {
        // EAN-13 needs exactly 12 or 13 digits (the 13th is the check digit,
        // which picqer computes/validates).
        if (preg_match('/^\d{12,13}$/', $value)) {
            return BarcodeGeneratorSVG::TYPE_EAN_13;
        }

        return BarcodeGeneratorSVG::TYPE_CODE_128;
    }
}
