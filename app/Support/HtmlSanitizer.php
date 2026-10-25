<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allow-list HTML sanitizer for user-authored rich text that must be
 * emitted raw (`{!! ... !!}`) on public pages — the legal privacy/terms
 * pages today (SEC/code-review #1: the previous regex-based pass let
 * unquoted event handlers and entity-encoded `javascript:` URLs through).
 *
 * Regexes cannot sanitize HTML; browsers parse too leniently. This
 * parses with DOMDocument (ships with PHP everywhere, shared-hosting
 * safe) and rebuilds the fragment keeping only:
 *
 *   - the formatting tags the HugeRTE toolbar produces (plus <b>/<i>
 *     from paste-from-Word),
 *   - the `href` attribute on <a>, and only with an http/https/mailto
 *     scheme or a relative/fragment URL. Every other attribute on every
 *     tag is dropped, so no on* handler of any quoting style survives.
 *
 * DOM attribute values arrive entity-decoded, so `jav&#x61;script:`
 * tricks are seen — and refused — as the scheme they really are.
 */
class HtmlSanitizer
{
    /** Tags kept as-is (attribute-free); anything else is unwrapped. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 's', 'u', 'b', 'i',
        'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a',
    ];

    /**
     * Tags whose CONTENT is code or otherwise meaningless as text —
     * removed wholesale instead of unwrapped.
     */
    private const DROPPED_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'template', 'noscript', 'form', 'head', 'link', 'meta', 'base',
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument();

        // The meta prefix pins UTF-8 (loadHTML otherwise assumes
        // ISO-8859-1); NONET forbids any network fetch during parse.
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>',
            LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        $out = $doc->createDocumentFragment();
        foreach (iterator_to_array($body->childNodes) as $child) {
            $this->append($out, $child, $doc);
        }

        return trim($doc->saveHTML($out) ?: '');
    }

    /** Copy $node into $target keeping only allow-listed structure. */
    private function append(DOMNode $target, DOMNode $node, DOMDocument $doc): void
    {
        if ($node instanceof DOMText) {
            $target->appendChild($doc->createTextNode($node->data));

            return;
        }

        if (! $node instanceof DOMElement) {
            return; // comments, CDATA, processing instructions — dropped
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // Unknown wrapper (<div>, <span>, <table>…): keep the text
            // inside it, lose the tag itself.
            foreach (iterator_to_array($node->childNodes) as $child) {
                $this->append($target, $child, $doc);
            }

            return;
        }

        $clean = $doc->createElement($tag);

        if ($tag === 'a') {
            $href = $this->safeHref($node->getAttribute('href'));
            if ($href !== null) {
                $clean->setAttribute('href', $href);
                // External targets can't reach back into the app.
                $clean->setAttribute('rel', 'noopener noreferrer');
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->append($clean, $child, $doc);
        }

        $target->appendChild($clean);
    }

    /**
     * Allow http/https/mailto absolute URLs plus relative paths and
     * fragments. Anything with any other scheme — javascript:, data:,
     * vbscript:, or something exotic — is refused outright.
     */
    private function safeHref(string $href): ?string
    {
        // Control characters and whitespace inside the value are how
        // `java\tscript:` style payloads dodge scheme matching.
        $candidate = preg_replace('/[\x00-\x20]+/', '', $href);

        if ($candidate === null || $candidate === '') {
            return null;
        }

        if (preg_match('#^(?:https?:|mailto:)#i', $candidate)) {
            return $href;
        }

        // No scheme at all (no `:` before the first `/`, `?` or `#`)
        // means a relative URL or fragment — safe.
        if (! preg_match('#^[^/?\#]*:#', $candidate)) {
            return $href;
        }

        return null;
    }
}
