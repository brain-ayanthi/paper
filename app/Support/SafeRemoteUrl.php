<?php

namespace App\Support;

/**
 * Is this URL safe for the server itself to fetch?
 *
 * Security register SEC #29 (defect #85). The updater pulls a JSON feed and
 * then downloads whatever `download_url` that feed hands back — with no check
 * on the scheme or the host. The feed is remote and, per CLAUDE.md rule 1, one
 * of only three outbound calls the product makes, so it is a trust boundary,
 * not a configuration detail. A compromised (or DNS-hijacked, or MITM'd over
 * plain HTTP) feed could point the download at:
 *
 *   - `http://127.0.0.1:8080/…`  — the customer's own admin panels
 *   - `http://169.254.169.254/…` — cloud instance metadata, i.e. credentials
 *   - `http://192.168.0.1/…`     — anything on the LAN behind the firewall
 *   - `file:///etc/passwd`       — local files, via a non-HTTP scheme
 *
 * The response body lands on disk and its signature is checked before it is
 * installed, so this is a request-forgery primitive rather than direct code
 * execution — the server becomes a proxy into networks the attacker cannot
 * reach, and the timing/success of each fetch is an oracle for what is there.
 *
 * The rule is deliberately narrow: HTTPS only, to a public IP. Shared hosting
 * has no legitimate reason for the updater to talk to a private address.
 *
 * DNS is resolved here and the resolved addresses are what get judged, so a
 * hostname pointing at 127.0.0.1 is rejected too. This is not proof against a
 * DNS-rebinding race (the name is resolved again by the HTTP client), but that
 * requires control of the feed's DNS on top of everything else, and PHP's HTTP
 * stack gives us no pinning hook to close it properly.
 */
class SafeRemoteUrl
{
    /**
     * Private / loopback / link-local / reserved ranges, in CIDR form.
     *
     * @var array<int, string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // RFC1918
        '100.64.0.0/10',    // CGNAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local — includes cloud metadata
        '172.16.0.0/12',    // RFC1918
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.168.0.0/16',   // RFC1918
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved (includes 255.255.255.255)
    ];

    /**
     * True when the server may fetch this URL.
     *
     * Local development points the feed at a dev server, so `APP_ENV=local`
     * relaxes the check to "https or http, any host" — the guard exists to
     * protect a customer's production install, and enforcing it locally would
     * just mean nobody can test the updater.
     */
    public static function allowed(string $url): bool
    {
        return static::reject($url) === null;
    }

    /**
     * The reason this URL is not fetchable, or null when it is. Separate from
     * {@see allowed()} so the caller can log WHY without re-deriving it.
     */
    public static function reject(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'malformed url';
        }

        $scheme = strtolower($parts['scheme']);
        $host   = $parts['host'];

        if (app()->environment('local', 'testing')) {
            return in_array($scheme, ['http', 'https'], true) ? null : 'scheme not allowed';
        }

        if ($scheme !== 'https') {
            return 'https required';
        }

        // Bracketed IPv6 literal, or a name/IPv4.
        $host = trim($host, '[]');

        foreach (static::resolve($host) as $ip) {
            if (! static::isPublic($ip)) {
                return 'resolves to a non-public address';
            }
        }

        return null;
    }

    /**
     * Every address the host resolves to. A literal IP resolves to itself; a
     * name that resolves to nothing yields one impossible entry so the caller
     * fails closed rather than treating "no addresses" as "all fine".
     *
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $ips = [];
        foreach ($records as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));

        if ($ips === []) {
            // gethostbyname returns the input unchanged on failure.
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips === [] ? ['0.0.0.0'] : $ips;
    }

    private static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // PHP's own reserved-range filter covers IPv6 well enough; ::1 and
            // fc00::/7 are what matter, and both are caught here.
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        foreach (self::BLOCKED_V4 as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);

            if ((ip2long($subnet) & $mask) === ($long & $mask)) {
                return false;
            }
        }

        return true;
    }
}
