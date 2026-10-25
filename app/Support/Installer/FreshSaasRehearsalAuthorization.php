<?php

namespace App\Support\Installer;

/** Private, non-production operator opt-in for preview installer acceptance. */
final class FreshSaasRehearsalAuthorization
{
    private function __construct(private readonly string $itemId, private readonly string $root) {}

    public static function fromCoreRoot(string $root): ?self
    {
        $path = rtrim($root, '/\\').'/.env';
        if (! is_file($path) || is_link($path) || filesize($path) > 2097152) return null;
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0002) !== 0) return null;
        $body = file_get_contents($path);
        if (! is_string($body)) return null;
        $read = static function (string $name) use ($body): ?string {
            preg_match_all('/(?:\A|\R)'.preg_quote($name, '/').'=([^\r\n]*)/', $body, $matches);
            if (count($matches[1]) !== 1) return null;
            return trim($matches[1][0], " \t\"'");
        };
        if ($read('APP_ENV') !== 'staging'
            || $read('POS_FRESH_SAAS_PREVIEW_REHEARSAL') !== 'true'
            || $read('POS_FRESH_SAAS_TEST_PURCHASE_BYPASS') !== 'true') return null;
        $itemId = $read('POS_FRESH_SAAS_REHEARSAL_ITEM_ID');
        return is_string($itemId) && preg_match('/\A[1-9][0-9]*\z/D', $itemId) === 1
            ? new self($itemId, $root) : null;
    }

    public function active(): bool
    {
        $current = self::fromCoreRoot($this->root);
        return $current !== null && hash_equals($this->itemId, $current->itemId);
    }

    public function itemId(): string
    {
        return $this->itemId;
    }
}