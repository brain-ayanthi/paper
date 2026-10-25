<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Structural guard for owner-supplied tracking snippets.
 *
 * This cannot make arbitrary JavaScript "safe"—the super administrator is
 * deliberately trusting the submitted code. It does prevent the setting from
 * becoming a general raw-HTML/template injection surface and refuses insecure
 * external resources. Complete <script> tags plus noscript tracking fallbacks
 * are supported (for example GA, GTM, Meta Pixel).
 */
class TrustedScriptMarkup implements ValidationRule
{
    /** @var array<int, string> */
    private const ALLOWED_TAGS = ['script', 'noscript', 'iframe', 'img'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $snippet = (string) $value;

        // Stored text is emitted after Blade has compiled, but refusing server
        // template syntax makes that boundary explicit and future-proof.
        if (preg_match('/<\?(?:php|=)?|\{!!|\{\{|@(?:php|endphp|include|extends|section|yield|inject)\b/i', $snippet)) {
            $fail(__('settings.custom_scripts.validation.template_syntax'));

            return;
        }

        preg_match_all('/<\/?([a-z][a-z0-9:-]*)\b/i', $snippet, $matches);
        $tags = array_map('strtolower', $matches[1] ?? []);

        if ($tags === []
            || (! in_array('script', $tags, true) && ! in_array('noscript', $tags, true))) {
            $fail(__('settings.custom_scripts.validation.complete_tags'));

            return;
        }

        foreach ($tags as $tag) {
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $fail(__('settings.custom_scripts.validation.invalid_tag', ['tag' => $tag]));

                return;
            }
        }

        // Browser parsing of an incomplete script block can swallow the rest
        // of the document, so paired tags must remain structurally balanced.
        foreach (['script', 'noscript', 'iframe'] as $pairedTag) {
            preg_match_all('/<'.$pairedTag.'\b[^>]*>/i', $snippet, $openMatches);
            preg_match_all('/<\/\s*'.$pairedTag.'\s*>/i', $snippet, $closeMatches);

            if (count($openMatches[0]) !== count($closeMatches[0])) {
                $fail(__('settings.custom_scripts.validation.complete_tags'));

                return;
            }
        }

        // Tracking pixels/iframes belong in a noscript fallback. Keeping them
        // there avoids turning these fields into a visible iframe/image slot.
        if ((in_array('iframe', $tags, true) || in_array('img', $tags, true))
            && ! in_array('noscript', $tags, true)) {
            $fail(__('settings.custom_scripts.validation.fallback_only'));

            return;
        }

        preg_match_all('/<(script|iframe|img)\b([^>]*)>/is', $snippet, $openTags, PREG_SET_ORDER);
        foreach ($openTags as $tag) {
            $attributes = (string) ($tag[2] ?? '');
            if (! preg_match('/\bsrc\s*=/i', $attributes)) {
                continue;
            }

            if (! preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $attributes, $srcMatch)) {
                $fail(__('settings.custom_scripts.validation.quoted_source'));

                return;
            }

            $source = html_entity_decode(trim((string) $srcMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $isHttps = str_starts_with(strtolower($source), 'https://');
            $isLocal = preg_match('#^/(?!/)#', $source) === 1;

            if (! $isHttps && ! $isLocal) {
                $fail(__('settings.custom_scripts.validation.secure_source'));

                return;
            }
        }
    }
}
