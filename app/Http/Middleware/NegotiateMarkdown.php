<?php

namespace App\Http\Middleware;

use Closure;
use DOMDocument;
use DOMNode;
use Illuminate\Http\Request;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Content-negotiates a Markdown rendering of HTML pages for AI agents.
 *
 * Browsers (and anything that does not explicitly ask for Markdown) keep getting
 * the normal HTML response. When a client sends `Accept: text/markdown`, we
 * convert the page's <main> content to Markdown so agents receive clean,
 * token-cheap text instead of scraping the full DOM. This mirrors Cloudflare's
 * "Markdown for Agents" feature for hosts that are not behind Cloudflare.
 */
class NegotiateMarkdown
{
    public function handle(Request $request, Closure $next): Response
    {
        // Livewire's update requests carry a checksum-verified JSON payload that
        // must never be modified. Short-circuit before touching the response so
        // the markdown conversion can never corrupt a Livewire round-trip.
        if ($request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        $response = $next($request);

        // Shared caches must key on Accept so an agent and a browser never get
        // served each other's representation. Advertise this regardless of which
        // representation we end up returning.
        $this->appendVaryAccept($response);

        if (! $this->wantsMarkdown($request) || ! $this->isConvertibleHtml($response)) {
            return $response;
        }

        $markdown = $this->toMarkdown((string) $response->getContent());

        $response->setContent($markdown);
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        // Heuristic token count (~4 chars/token); we have no model tokenizer, so
        // this is an estimate agents can use to budget context, not an exact count.
        $response->headers->set('X-Markdown-Tokens', (string) $this->estimateTokens($markdown));

        return $response;
    }

    private function wantsMarkdown(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/markdown');
    }

    private function isConvertibleHtml(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function toMarkdown(string $html): string
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style svg noscript form button iframe',
            'header_style' => 'atx',
            'hard_break' => true,
        ]);

        $markdown = $converter->convert($this->extractMainHtml($html));

        return trim($markdown)."\n";
    }

    /**
     * Pull the inner HTML of the page's <main> region (falling back to <body>,
     * then the raw document) so the Markdown carries the actual content without
     * the nav/header/footer chrome.
     */
    private function extractMainHtml(string $html): string
    {
        $dom = new DOMDocument;

        libxml_use_internal_errors(true);
        // The XML encoding hint forces libxml to treat the bytes as UTF-8.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();

        $region = $dom->getElementsByTagName('main')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0);

        if (! $region instanceof DOMNode) {
            return $html;
        }

        $inner = '';
        foreach ($region->childNodes as $child) {
            $inner .= (string) $dom->saveHTML($child);
        }

        return $inner;
    }

    private function estimateTokens(string $markdown): int
    {
        return (int) ceil(mb_strlen($markdown) / 4);
    }

    private function appendVaryAccept(Response $response): void
    {
        $vary = $response->headers->all('vary');

        foreach ($vary as $value) {
            if (str_contains(strtolower((string) $value), 'accept')) {
                return;
            }
        }

        $response->headers->set('Vary', 'Accept', false);
    }
}
