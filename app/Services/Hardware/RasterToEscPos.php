<?php

namespace App\Services\Hardware;

/**
 * Converts a logo image to an ESC/POS raster bit-image (docs/features/
 * hardware.md §5.6) using the GS v 0 command. Lets a thermal receipt
 * print the store logo at the top instead of just the name.
 *
 * Best-effort: returns '' when GD is unavailable, the file is missing, or
 * conversion fails — the formatter then falls back to a text header, so a
 * dud logo never breaks a print.
 */
class RasterToEscPos
{
    /**
     * @param string $path     Absolute path to a PNG/JPG/GIF logo.
     * @param int    $maxWidth Max printed width in dots (rounded down to a
     *                         multiple of 8; e.g. 384 for 58mm, 512 for 80mm).
     * @param int    $threshold Luminance cutoff (0-255) for black vs white.
     */
    public function encode(string $path, int $maxWidth = 384, int $threshold = 128): string
    {
        if (! extension_loaded('gd') || ! is_file($path)) {
            return '';
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return '';
        }

        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return '';
        }

        try {
            return $this->convert($img, $maxWidth, $threshold);
        } catch (\Throwable $e) {
            return '';
        } finally {
            imagedestroy($img);
        }
    }

    private function convert(\GdImage $img, int $maxWidth, int $threshold): string
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);
        if ($srcW < 1 || $srcH < 1) {
            return '';
        }

        // Scale down to fit the printer; round width to a multiple of 8 so
        // each row packs into whole bytes. Never upscale.
        $targetW = min($maxWidth, $srcW);
        $targetW = max(8, (int) (floor($targetW / 8) * 8));
        $targetH = max(1, (int) round($srcH * ($targetW / $srcW)));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        // White background so transparent PNGs come out clean (white = not printed).
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

        $bytesPerRow = (int) ($targetW / 8);
        $data = '';

        for ($y = 0; $y < $targetH; $y++) {
            for ($bx = 0; $bx < $bytesPerRow; $bx++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $bx * 8 + $bit;
                    $rgb = imagecolorat($canvas, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    // Rec. 601 luma; dark pixel → printed → bit set (MSB first).
                    $luma = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                    if ($luma < $threshold) {
                        $byte |= (0x80 >> $bit);
                    }
                }
                $data .= chr($byte);
            }
        }

        imagedestroy($canvas);

        // GS v 0 m xL xH yL yH d1..dk  (m=0 normal density)
        $xL = $bytesPerRow & 0xFF;
        $xH = ($bytesPerRow >> 8) & 0xFF;
        $yL = $targetH & 0xFF;
        $yH = ($targetH >> 8) & 0xFF;

        return "\x1D\x76\x30\x00".chr($xL).chr($xH).chr($yL).chr($yH).$data;
    }
}
