<?php

namespace Coruja\Tool;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The engine behind /seo-check: fetches an arbitrary URL (SSRF-guarded) and
 * runs a handful of surface-level SEO checks a visitor can see from outside.
 *
 * Not a full audit — deliberately shallow. It fetches the page once, reads the
 * HTML, times the response, and probes for a sitemap.
 */
final class SiteChecker
{
    private const PASS = 'pass';
    private const WARN = 'warn';
    private const FAIL = 'fail';

    private readonly HttpClientInterface $http;

    /** @param HttpClientInterface|null $http overridable for tests; production uses the SSRF-guarded default */
    public function __construct(?HttpClientInterface $http = null)
    {
        // NoPrivateNetworkHttpClient blocks requests (and redirects) to private,
        // loopback and link-local ranges — the core SSRF guard.
        $this->http = $http ?? new NoPrivateNetworkHttpClient(HttpClient::create([
            'headers' => ['User-Agent' => 'NickRaleighSEOCheck/1.0 (+https://nickraleigh.com/seo-check)'],
            'max_redirects' => 4,
            'timeout' => 6,
            'max_duration' => 10,
        ]));
    }

    /**
     * @return array{
     *     ok: bool, error?: string, url?: string, finalUrl?: string,
     *     score?: int, max?: int,
     *     checks?: list<array{id: string, label: string, status: string, detail: string}>
     * }
     */
    public function check(string $input): array
    {
        $url = $this->normalize($input);
        if (null === $url) {
            return ['ok' => false, 'error' => 'That does not look like a website address. Try something like example.com.'];
        }

        try {
            $response = $this->http->request('GET', $url);
            $status = $response->getStatusCode(); // triggers the request, follows redirects

            $ctype = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
            if ('' !== $ctype && !str_contains($ctype, 'html') && !str_contains($ctype, 'xml')) {
                return ['ok' => false, 'error' => 'That URL does not return a web page.'];
            }

            // Capped well below what a real page needs for these checks (everything
            // scored here lives in <head> or near the top of <body>) so a bloated
            // real-world page can't drag DOMDocument parsing into multi-second,
            // high-memory territory.
            $html = substr($response->getContent(false), 0, 800_000);
            $info = $response->getInfo();
            $finalUrl = (string) ($info['url'] ?? $url);
            $ttfb = (float) ($info['starttransfer_time'] ?? $info['total_time'] ?? 0);
            $headers = $response->getHeaders(false);
        } catch (HttpException $e) {
            return ['ok' => false, 'error' => $this->friendlyError($e->getMessage())];
        }

        try {
            $dom = $this->parse($html);
            $checks = $this->runChecks($dom, $finalUrl, $ttfb, $headers, $status);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'That page could not be analyzed. It may be too large or unusually structured.'];
        }
        $score = \count(array_filter($checks, static fn ($c) => self::PASS === $c['status']));

        return [
            'ok' => true,
            'url' => $url,
            'finalUrl' => $finalUrl,
            'score' => $score,
            'max' => \count($checks),
            'checks' => $checks,
        ];
    }

    private function normalize(string $input): ?string
    {
        $input = trim($input);
        if ('' === $input) {
            return null;
        }
        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if (false === $parts || empty($parts['host']) || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        // No credentials, no odd ports.
        if (isset($parts['user']) || isset($parts['port']) && !\in_array($parts['port'], [80, 443], true)) {
            return null;
        }
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $parts['host'])) {
            return null;
        }

        return $input;
    }

    private function parse(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return new \DOMXPath($dom);
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<array{id: string, label: string, status: string, detail: string}>
     */
    private function runChecks(\DOMXPath $xp, string $finalUrl, float $ttfb, array $headers, int $status): array
    {
        $attr = static fn (string $q): string => trim((string) ($xp->query($q)->item(0)?->nodeValue ?? ''));
        $c = [];

        // 1. HTTPS
        $c[] = str_starts_with($finalUrl, 'https://')
            ? $this->row('https', 'HTTPS enabled', self::PASS, 'The site is served securely over HTTPS.')
            : $this->row('https', 'No HTTPS', self::FAIL, 'The site loads over plain HTTP. Search engines and browsers penalise this.');

        // 2. Mobile viewport
        $viewport = strtolower($attr('//meta[@name="viewport"]/@content'));
        $c[] = match (true) {
            str_contains($viewport, 'width=device-width') => $this->row('viewport', 'Mobile viewport set', self::PASS, 'The page is set up to render correctly on phones.'),
            '' !== $viewport => $this->row('viewport', 'Viewport tag looks off', self::WARN, 'A viewport meta tag is present but does not use width=device-width.'),
            default => $this->row('viewport', 'No mobile viewport', self::FAIL, 'Without a viewport meta tag, the page will not scale properly on mobile.'),
        };

        // 3. Title tag
        $title = trim((string) ($xp->query('//title')->item(0)?->textContent ?? ''));
        $tlen = mb_strlen($title);
        $c[] = match (true) {
            0 === $tlen => $this->row('title', 'Missing title tag', self::FAIL, 'Every page needs a title tag. It is the clickable headline in search results.'),
            $tlen < 15 || $tlen > 70 => $this->row('title', 'Title tag present', self::WARN, sprintf('Found, but at %d characters it is %s for search results.', $tlen, $tlen < 15 ? 'short' : 'likely to be truncated')),
            default => $this->row('title', 'Title tag present', self::PASS, sprintf('A %d-character title tag was found.', $tlen)),
        };

        // 4. Meta description
        $desc = $attr('//meta[@name="description"]/@content');
        $dlen = mb_strlen($desc);
        $c[] = match (true) {
            0 === $dlen => $this->row('description', 'Meta description missing', self::FAIL, 'Google has to guess what the page is about in search results.'),
            $dlen < 70 || $dlen > 165 => $this->row('description', 'Meta description present', self::WARN, sprintf('Found, but at %d characters it is %s. Aim for 70 to 160.', $dlen, $dlen < 70 ? 'thin' : 'likely truncated')),
            default => $this->row('description', 'Meta description present', self::PASS, sprintf('A %d-character meta description was found.', $dlen)),
        };

        // 5. Structured data
        $hasJsonLd = $xp->query('//script[@type="application/ld+json"]')->length > 0;
        $hasMicrodata = $xp->query('//*[@itemscope]')->length > 0;
        $c[] = ($hasJsonLd || $hasMicrodata)
            ? $this->row('structured_data', 'Structured data found', self::PASS, 'The page includes markup that can drive rich results in search.')
            : $this->row('structured_data', 'No structured data', self::FAIL, 'No JSON-LD or microdata detected. You are likely missing out on rich results.');

        // 6. Server response time
        $ms = (int) round($ttfb * 1000);
        $c[] = match (true) {
            $ttfb <= 0 => $this->row('speed', 'Response time', self::WARN, 'Could not measure server response time reliably.'),
            $ttfb < 0.8 => $this->row('speed', 'Fast server response', self::PASS, sprintf('The server started responding in %dms.', $ms)),
            $ttfb < 2.0 => $this->row('speed', 'Slow server response', self::WARN, sprintf('The server took %dms to start responding. Under 800ms is the target.', $ms)),
            default => $this->row('speed', 'Very slow server response', self::FAIL, sprintf('The server took %dms to start responding. This hurts rankings and users.', $ms)),
        };

        // 7. XML sitemap
        $c[] = $this->hasSitemap($finalUrl, $headers)
            ? $this->row('sitemap', 'XML sitemap found', self::PASS, 'Search engines have a map of the pages you want indexed.')
            : $this->row('sitemap', 'No XML sitemap found', self::FAIL, 'No sitemap at /sitemap.xml or in robots.txt. Crawlers have to find pages on their own.');

        // 8. Indexable
        $robotsMeta = strtolower($attr('//meta[@name="robots"]/@content'));
        $xRobots = strtolower(implode(' ', $headers['x-robots-tag'] ?? []));
        $c[] = (str_contains($robotsMeta, 'noindex') || str_contains($xRobots, 'noindex'))
            ? $this->row('indexable', 'Page is set to noindex', self::FAIL, 'This page tells search engines not to index it. If that is not deliberate, it is a serious problem.')
            : $this->row('indexable', 'Page is indexable', self::PASS, 'Nothing is telling search engines to skip this page.');

        if ($status >= 400) {
            $c[] = $this->row('http_status', 'Page returned an error', self::FAIL, sprintf('The URL responded with HTTP %d.', $status));
        }

        return $c;
    }

    /** @param array<string, list<string>> $headers */
    private function hasSitemap(string $finalUrl, array $headers): bool
    {
        $parts = parse_url($finalUrl);
        if (empty($parts['host'])) {
            return false;
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'];

        try {
            $sm = $this->http->request('GET', $origin.'/sitemap.xml', ['timeout' => 4]);
            if (200 === $sm->getStatusCode() && str_contains(strtolower($sm->getContent(false)), '<urlset')) {
                return true;
            }
        } catch (HttpException) {
        }

        try {
            $robots = $this->http->request('GET', $origin.'/robots.txt', ['timeout' => 4]);
            if (200 === $robots->getStatusCode() && preg_match('/^\s*sitemap:\s*\S+/im', $robots->getContent(false))) {
                return true;
            }
        } catch (HttpException) {
        }

        return false;
    }

    private function friendlyError(string $raw): string
    {
        $raw = strtolower($raw);

        return match (true) {
            str_contains($raw, 'private') || str_contains($raw, 'blocked') => 'That address cannot be checked.',
            str_contains($raw, 'timeout') || str_contains($raw, 'timed out') => 'That site took too long to respond.',
            str_contains($raw, 'resolve') || str_contains($raw, 'name or service') => 'That domain could not be found.',
            str_contains($raw, 'ssl') || str_contains($raw, 'certificate') => 'That site has an SSL certificate problem, so it could not be checked.',
            default => 'Could not reach that URL.',
        };
    }

    /** @return array{id: string, label: string, status: string, detail: string} */
    private function row(string $id, string $label, string $status, string $detail): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
