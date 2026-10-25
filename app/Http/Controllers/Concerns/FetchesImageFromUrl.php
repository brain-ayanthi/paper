<?php

namespace App\Http\Controllers\Concerns;

use App\Actions\Images\FetchImageFromUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Or paste an image link" on a single-record editor.
 *
 * Shared by the product and brand forms — the only difference between them is
 * which field the link arrives in and which folder the file lands in.
 *
 * A link that can't be downloaded is reported as a VALIDATION error on that
 * field rather than a flash message or a silent skip: the customer typed it,
 * so the complaint belongs next to what they typed, and the rest of the form
 * they were filling in survives.
 */
trait FetchesImageFromUrl
{
    /**
     * Download the image named by `$field`, returning its stored path — or
     * null when the field is empty (nothing pasted, nothing to do).
     *
     * @throws ValidationException when the link cannot be downloaded
     */
    protected function imagePathFromUrl(Request $request, string $field, string $dir): ?string
    {
        $url = trim((string) $request->input($field, ''));

        if ($url === '') {
            return null;
        }

        ['path' => $path, 'error' => $error] = app(FetchImageFromUrl::class)($url, $dir);

        if ($path === null) {
            $key     = 'admin.image_upload.url_errors.'.$error;
            $message = __($key);

            throw ValidationException::withMessages([
                // A code we have no translation for still has to say something
                // useful — a raw lang key in a toast helps nobody.
                $field => $message === $key ? __('admin.image_upload.url_errors.generic') : $message,
            ]);
        }

        return $path;
    }
}
