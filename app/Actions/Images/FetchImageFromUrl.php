<?php

namespace App\Actions\Images;

use App\Services\Images\RemoteImageFetcher;

/**
 * Download one image from a URL and put it on the public disk.
 *
 * The thin wrapper the single-record editors use — "paste a link instead of
 * choosing a file" on the product and brand forms. The bulk screens talk to
 * {@see RemoteImageFetcher} directly because they need to hold one download
 * across several records; here there is exactly one record and one URL, so
 * fetch-store-clean up in a single call is all that's wanted.
 *
 * Returns a path or an error CODE (never a sentence, never an exception): the
 * caller turns the code into a translated validation message, which is the
 * only sensible place to decide how a failure should look.
 */
class FetchImageFromUrl
{
    public function __construct(private RemoteImageFetcher $fetcher) {}

    /**
     * @return array{path: ?string, error: ?string}
     */
    public function __invoke(string $url, string $dir = 'products'): array
    {
        try {
            $result = $this->fetcher->fetch($url);

            if (! $result->ok) {
                return ['path' => null, 'error' => (string) $result->error];
            }

            $path = $this->fetcher->store($result, $dir);

            return $path === null
                ? ['path' => null, 'error' => 'store_failed']
                : ['path' => $path, 'error' => null];
        } finally {
            $this->fetcher->cleanup();
        }
    }
}
