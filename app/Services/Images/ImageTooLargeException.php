<?php

namespace App\Services\Images;

/**
 * Thrown from inside the HTTP transfer to abort a download that is over the
 * size cap — either because the server declared too big a Content-Length, or
 * because it kept sending past the limit without declaring one.
 *
 * Guzzle wraps anything thrown from `on_headers` / `progress` in its own
 * exception, so {@see RemoteImageFetcher} walks the `getPrevious()` chain
 * looking for this class rather than catching it directly.
 */
class ImageTooLargeException extends \RuntimeException
{
}
