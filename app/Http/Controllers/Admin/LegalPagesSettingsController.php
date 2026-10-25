<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Privacy policy + terms of service editor.
 *
 * Some payment gateways (Razorpay, Paystack, Flutterwave, etc.) require
 * a public privacy policy and terms-of-service URL during onboarding;
 * this gives the merchant a place to author them and a stable public
 * URL to point at. Content is stored as markdown on the company row and
 * rendered to HTML on the public-facing routes.
 */
class LegalPagesSettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(): View
    {
        $this->authorize('settings.view');

        return view('admin.settings.legal-pages', [
            'company' => Company::current() ?? new Company(),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('settings.demo.locked'),
                route('admin.settings.legal-pages.edit'),
            );
        }

        $data = $request->validate([
            // 64 KB caps — plenty for a policy / TOS and a hard stop
            // before a stray paste blows up the row size.
            'privacy_policy'   => ['nullable', 'string', 'max:65535'],
            'terms_of_service' => ['nullable', 'string', 'max:65535'],
        ]);

        $company = Company::current();
        abort_unless($company !== null, 404);

        // No `updated_by` here — `company` doesn't carry that column
        // (unlike sales / purchases / etc.). Matches what every other
        // settings controller does.
        $company->forceFill([
            'privacy_policy'   => $this->sanitize($data['privacy_policy']   ?? null),
            'terms_of_service' => $this->sanitize($data['terms_of_service'] ?? null),
        ])->save();

        return $this->jsonOrRedirect(
            $request,
            __('settings.legal_pages.flash.saved'),
            route('admin.settings.legal-pages.edit'),
        );
    }

    /**
     * DOM-based allow-list sanitize (App\Support\HtmlSanitizer). The
     * old regex + strip_tags pass here let unquoted `on*=` handlers and
     * entity-encoded `javascript:` hrefs straight onto the PUBLIC
     * /privacy and /terms pages, which emit this raw — stored XSS for
     * every visitor (code-review #1).
     */
    private function sanitize(?string $html): ?string
    {
        if ($html === null) return null;

        // Treat an editor "empty" doc as null so the public 404 still
        // fires after the user clears it. HugeRTE can emit a few
        // different shapes for "the user typed nothing" — covers the
        // bare placeholder paragraph and the &nbsp; variants.
        $stripped = trim(strip_tags(str_replace(['&nbsp;', "\xC2\xA0"], ' ', trim($html))));
        if ($stripped === '') return null;

        return (new HtmlSanitizer())->sanitize($html);
    }
}
