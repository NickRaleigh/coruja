<?php

namespace Coruja\Seo;

/**
 * Yoast-style on-page analysis. Given the *rendered* HTML of a page plus a focus
 * keyphrase, returns a snippet preview, an SEO checklist and a readability
 * checklist, each with a 0-100 score.
 *
 * Deliberately dependency-free and heuristic. No stemming/morphology. Matching
 * is case-insensitive and whitespace-normalised, with an "all words present"
 * fallback when the exact phrase is not found.
 */
final class SeoAnalyzer
{
    private const GOOD = 'good';
    private const OK = 'ok';
    private const BAD = 'bad';

    /** @var list<string> */
    private const TRANSITIONS = [
        'accordingly', 'additionally', 'afterward', 'also', 'although', 'because', 'besides', 'but',
        'consequently', 'conversely', 'finally', 'first', 'firstly', 'furthermore', 'hence', 'however',
        'indeed', 'instead', 'likewise', 'meanwhile', 'moreover', 'nevertheless', 'nonetheless', 'next',
        'otherwise', 'second', 'similarly', 'since', 'so', 'still', 'then', 'therefore', 'though', 'thus',
        'ultimately', 'undoubtedly', 'whereas', 'while', 'yet',
        'for example', 'for instance', 'in addition', 'in conclusion', 'in contrast', 'in fact',
        'in other words', 'in particular', 'in short', 'in summary', 'of course', 'on the contrary',
        'on the other hand', 'as a result', 'that is', 'to summarize', 'as well as',
    ];

    /** @var array<string, bool> irregular past participles for the passive-voice heuristic */
    private const PARTICIPLES = [
        'done' => true, 'made' => true, 'given' => true, 'taken' => true, 'seen' => true, 'known' => true,
        'shown' => true, 'written' => true, 'built' => true, 'held' => true, 'kept' => true, 'told' => true,
        'sent' => true, 'brought' => true, 'bought' => true, 'caught' => true, 'taught' => true,
        'found' => true, 'left' => true, 'lost' => true, 'meant' => true, 'paid' => true, 'put' => true,
        'read' => true, 'run' => true, 'set' => true, 'cut' => true, 'hit' => true, 'let' => true,
        'met' => true, 'won' => true, 'said' => true, 'felt' => true, 'dealt' => true, 'drawn' => true,
        'grown' => true, 'thrown' => true, 'chosen' => true, 'driven' => true, 'spoken' => true,
        'broken' => true, 'frozen' => true, 'stolen' => true, 'begun' => true, 'become' => true,
    ];

    /**
     * @return array{
     *     keyphrase: string,
     *     snippet: array{title: string, titleWidth: int, description: string, url: string, robots: string},
     *     stats: array{words: int, sentences: int, readingEase: float},
     *     seo: array{score: int, checks: list<array{id: string, label: string, status: string, text: string}>},
     *     readability: array{score: int, checks: list<array{id: string, label: string, status: string, text: string}>}
     * }
     */
    public function analyze(string $html, string $keyphrase, string $url, string $baseUrl = ''): array
    {
        $keyphrase = trim(preg_replace('/\s+/', ' ', $keyphrase) ?? '');
        $doc = $this->parse($html, (string) (parse_url($baseUrl, \PHP_URL_HOST) ?: ''));

        $title = $doc['title'];
        $description = $doc['description'];
        $body = $doc['bodyText'];
        $words = $this->words($body);
        $wordCount = \count($words);
        $sentences = $this->sentences($body);

        $seo = $this->seoChecks($keyphrase, $title, $description, $url, $doc, $words, $wordCount);
        $readability = $this->readabilityChecks($body, $sentences, $words, $wordCount, $doc);

        return [
            'keyphrase' => $keyphrase,
            'snippet' => [
                'title' => $title,
                'titleWidth' => $this->pixelWidth($title),
                'description' => $description,
                'url' => rtrim($baseUrl, '/').$url,
                'robots' => $doc['robots'] ?: 'index, follow',
            ],
            'stats' => [
                'words' => $wordCount,
                'sentences' => \count($sentences),
                'readingEase' => $this->fleschReadingEase($words, $sentences),
            ],
            'seo' => ['score' => $this->score($seo), 'checks' => $seo],
            'readability' => ['score' => $this->score($readability), 'checks' => $readability],
        ];
    }

    /* ---------------------------------------------------------------- parsing */

    /**
     * @return array{title: string, description: string, robots: string, bodyText: string,
     *               h1: string, subheadings: list<string>, firstParagraph: string,
     *               paragraphs: list<string>, imgAlts: list<string>, imgMissingAlt: int,
     *               internalLinks: int, outboundLinks: int}
     */
    private function parse(string $html, string $baseHost = ''): array
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($dom);

        $attr = static fn (string $q): string => (string) ($xp->query($q)->item(0)?->nodeValue ?? '');
        $title = trim((string) ($xp->query('//title')->item(0)?->textContent ?? ''));
        $description = trim($attr('//meta[@name="description"]/@content'));
        $robots = trim($attr('//meta[@name="robots"]/@content'));

        // Drop chrome that is not page content.
        foreach ($xp->query('//nav | //footer | //script | //style | //noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $h1 = trim((string) ($xp->query('//h1')->item(0)?->textContent ?? ''));

        $subheadings = [];
        foreach ($xp->query('//h2 | //h3 | //h4') as $h) {
            $t = trim($h->textContent);
            if ('' !== $t) {
                $subheadings[] = $t;
            }
        }

        $paragraphs = [];
        $firstParagraph = '';
        foreach ($xp->query('//p | //li') as $p) {
            $t = trim(preg_replace('/\s+/', ' ', $p->textContent) ?? '');
            if ('' === $t) {
                continue;
            }
            $paragraphs[] = $t;
            if ('' === $firstParagraph && mb_strlen($t) > 25) {
                $firstParagraph = $t;
            }
        }

        $imgAlts = [];
        $imgMissingAlt = 0;
        foreach ($xp->query('//img') as $img) {
            /** @var \DOMElement $img */
            $alt = trim($img->getAttribute('alt'));
            if ('' === $alt) {
                ++$imgMissingAlt;
            } else {
                $imgAlts[] = $alt;
            }
        }

        $internal = 0;
        $outbound = 0;
        foreach ($xp->query('//a[@href]') as $a) {
            /** @var \DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ('' === $href || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }
            if (preg_match('#^https?://#i', $href)) {
                $host = parse_url($href, \PHP_URL_HOST) ?: '';
                if ('' !== $baseHost && $host === $baseHost) {
                    ++$internal;
                } else {
                    ++$outbound;
                }
            } else {
                ++$internal;
            }
        }

        // Rebuild the copy from block elements in document order, so sentence and
        // paragraph boundaries survive (list items, headings, ... all get a stop).
        $blocks = [];
        foreach ($xp->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::p or self::li or self::blockquote or self::figcaption or self::dd]') as $el) {
            if ($xp->query('.//p | .//li | .//h1 | .//h2 | .//h3', $el)->length > 0) {
                continue; // skip wrappers that contain their own blocks
            }
            $t = trim(preg_replace('/\s+/', ' ', $el->textContent) ?? '');
            if ('' === $t) {
                continue;
            }
            $blocks[] = preg_match('/[.!?:]$/', $t) ? $t : $t.'.';
        }
        $bodyText = implode("\n", $blocks);

        return [
            'title' => $title,
            'description' => $description,
            'robots' => $robots,
            'bodyText' => $bodyText,
            'h1' => $h1,
            'subheadings' => $subheadings,
            'firstParagraph' => $firstParagraph,
            'paragraphs' => $paragraphs,
            'imgAlts' => $imgAlts,
            'imgMissingAlt' => $imgMissingAlt,
            'internalLinks' => $internal,
            'outboundLinks' => $outbound,
        ];
    }

    /* ------------------------------------------------------------------- SEO */

    /**
     * @param array<string, mixed> $doc
     * @param list<string>         $words
     *
     * @return list<array{id: string, label: string, status: string, text: string}>
     */
    private function seoChecks(string $keyphrase, string $title, string $description, string $url, array $doc, array $words, int $wordCount): array
    {
        $c = [];

        if ('' === $keyphrase) {
            $c[] = $this->check('keyphrase', 'Focus keyphrase', self::BAD, 'No focus keyphrase set. Add one to score the keyword checks.');
        } else {
            $kw = \count($this->significantWords($keyphrase));
            $c[] = $this->check('keyphrase', 'Focus keyphrase', $kw <= 4 ? self::GOOD : ($kw <= 6 ? self::OK : self::BAD),
                $kw <= 4 ? sprintf('Keyphrase is %d word%s.', $kw, 1 === $kw ? '' : 's') : 'Keyphrase is quite long; consider shortening.');

            [$st, $txt] = $this->match($keyphrase, $title, 'the SEO title');
            if (self::GOOD === $st) {
                $pos = mb_strpos($this->norm($title), $this->norm($keyphrase));
                if (false !== $pos && $pos > mb_strlen($this->norm($title)) / 2) {
                    $st = self::OK;
                    $txt = 'Keyphrase is in the title, but near the end. Move it toward the front.';
                }
            }
            $c[] = $this->check('kp_title', 'Keyphrase in title', $st, $txt);

            $c[] = $this->check('kp_description', 'Keyphrase in meta description', ...$this->match($keyphrase, $description, 'the meta description'));

            if ('/' === $url || '' === trim($url, '/')) {
                $c[] = $this->check('kp_slug', 'Keyphrase in URL', self::OK, 'Home page, so there is no slug to check.');
            } else {
                $slugText = str_replace(['-', '/', '_'], ' ', $url);
                $c[] = $this->check('kp_slug', 'Keyphrase in URL', ...$this->match($keyphrase, $slugText, 'the URL slug'));
            }

            $c[] = $this->check('kp_intro', 'Keyphrase in introduction', ...$this->match($keyphrase, (string) $doc['firstParagraph'], 'the first paragraph'));

            $subs = implode(' ', $doc['subheadings']);
            if ([] === $doc['subheadings']) {
                $c[] = $this->check('kp_subheading', 'Keyphrase in subheading', self::OK, 'No subheadings on the page to check.');
            } else {
                $c[] = $this->check('kp_subheading', 'Keyphrase in subheading', ...$this->match($keyphrase, $subs, 'a subheading'));
            }

            // Density.
            if ($wordCount < 100) {
                $c[] = $this->check('density', 'Keyphrase density', self::OK, 'Not enough copy to judge keyphrase density.');
            } else {
                $occ = $this->countOccurrences($keyphrase, implode(' ', $words));
                $density = $wordCount > 0 ? round($occ / $wordCount * 100, 1) : 0.0;
                if ($density < 0.3) {
                    $st = self::OK;
                    $txt = sprintf('Keyphrase density is %s%%. It could appear a little more often.', $density);
                } elseif ($density <= 2.5) {
                    $st = self::GOOD;
                    $txt = sprintf('Keyphrase density is %s%% (%d occurrence%s).', $density, $occ, 1 === $occ ? '' : 's');
                } elseif ($density <= 3.5) {
                    $st = self::OK;
                    $txt = sprintf('Keyphrase density is %s%%, which is getting high.', $density);
                } else {
                    $st = self::BAD;
                    $txt = sprintf('Keyphrase density is %s%%, which looks like keyword stuffing.', $density);
                }
                $c[] = $this->check('density', 'Keyphrase density', $st, $txt);
            }

            // Image alt.
            $totalImgs = \count($doc['imgAlts']) + $doc['imgMissingAlt'];
            if ($totalImgs > 0) {
                if ($doc['imgMissingAlt'] > 0) {
                    $c[] = $this->check('img_alt', 'Image alt attributes', self::BAD,
                        sprintf('%d image%s missing alt text.', $doc['imgMissingAlt'], 1 === $doc['imgMissingAlt'] ? ' is' : 's are'));
                } elseif ($this->contains($keyphrase, implode(' ', $doc['imgAlts']))) {
                    $c[] = $this->check('img_alt', 'Image alt attributes', self::GOOD, 'An image alt attribute contains the keyphrase.');
                } else {
                    $c[] = $this->check('img_alt', 'Image alt attributes', self::OK, 'Images have alt text, but none contains the keyphrase.');
                }
            }
        }

        // Length checks (keyphrase-independent).
        $tw = $this->pixelWidth($title);
        $c[] = $this->check('title_len', 'SEO title width', $this->band($tw, 400, 580, 300, 620),
            0 === $tw ? 'The SEO title is empty.' : sprintf('Title is ~%dpx (%d chars). Aim for 400 to 580px.', $tw, mb_strlen($title)));

        $dl = mb_strlen($description);
        $c[] = $this->check('desc_len', 'Meta description length',
            0 === $dl ? self::BAD : ($dl < 120 ? self::OK : ($dl <= 156 ? self::GOOD : self::OK)),
            0 === $dl ? 'No meta description.' : sprintf('%d characters. Aim for 120 to 156.', $dl));

        $c[] = $this->check('content_len', 'Content length',
            $wordCount >= 600 ? self::GOOD : ($wordCount >= 300 ? self::OK : self::BAD),
            sprintf('%d words. Aim for at least 300 (600+ is stronger).', $wordCount));

        $links = $doc['internalLinks'] + $doc['outboundLinks'];
        $c[] = $this->check('links', 'Links',
            $doc['internalLinks'] > 0 && $doc['outboundLinks'] > 0 ? self::GOOD : ($links > 0 ? self::OK : self::BAD),
            sprintf('%d internal, %d outbound link%s.', $doc['internalLinks'], $doc['outboundLinks'], 1 === $links ? '' : 's'));

        return $c;
    }

    /* ----------------------------------------------------------- readability */

    /**
     * @param list<string>         $sentences
     * @param list<string>         $words
     * @param array<string, mixed> $doc
     *
     * @return list<array{id: string, label: string, status: string, text: string}>
     */
    private function readabilityChecks(string $body, array $sentences, array $words, int $wordCount, array $doc): array
    {
        $c = [];

        if ($wordCount < 50) {
            return [$this->check('short', 'Readability', self::OK, 'Add more copy to run the readability analysis.')];
        }

        $ease = $this->fleschReadingEase($words, $sentences);
        $c[] = $this->check('flesch', 'Flesch reading ease',
            $ease >= 60 ? self::GOOD : ($ease >= 30 ? self::OK : self::BAD),
            sprintf('Score %s, %s.', $ease, $this->fleschLabel($ease)));

        $long = 0;
        foreach ($sentences as $s) {
            if (str_word_count($s) > 20) {
                ++$long;
            }
        }
        $pctLong = $sentences ? round($long / \count($sentences) * 100) : 0;
        $c[] = $this->check('sentence_len', 'Sentence length',
            $pctLong <= 25 ? self::GOOD : ($pctLong <= 35 ? self::OK : self::BAD),
            sprintf('%d%% of sentences are over 20 words (aim for under 25%%).', $pctLong));

        $longParas = 0;
        foreach ($doc['paragraphs'] as $p) {
            if (str_word_count($p) > 150) {
                ++$longParas;
            }
        }
        $c[] = $this->check('paragraph_len', 'Paragraph length',
            0 === $longParas ? self::GOOD : self::BAD,
            0 === $longParas ? 'No overly long paragraphs.' : sprintf('%d paragraph%s over 150 words.', $longParas, 1 === $longParas ? '' : 's'));

        $run = $this->longestRunBetweenSubheadings($body, $doc['subheadings']);
        if ($wordCount < 300) {
            $c[] = $this->check('subheadings', 'Subheading distribution', self::OK, 'Short page, so subheadings are optional.');
        } else {
            $c[] = $this->check('subheadings', 'Subheading distribution',
                $run <= 300 ? self::GOOD : self::BAD,
                $run <= 300 ? 'Subheadings break up the copy well.' : sprintf('~%d words without a subheading. Add one.', $run));
        }

        $passive = 0;
        foreach ($sentences as $s) {
            if ($this->looksPassive($s)) {
                ++$passive;
            }
        }
        $pctPassive = $sentences ? round($passive / \count($sentences) * 100) : 0;
        $c[] = $this->check('passive', 'Passive voice (estimate)',
            $pctPassive <= 10 ? self::GOOD : ($pctPassive <= 15 ? self::OK : self::BAD),
            sprintf('~%d%% of sentences may be passive (aim for under 10%%).', $pctPassive));

        $withTransition = 0;
        foreach ($sentences as $s) {
            if ($this->hasTransition($s)) {
                ++$withTransition;
            }
        }
        $pctTrans = $sentences ? round($withTransition / \count($sentences) * 100) : 0;
        $c[] = $this->check('transitions', 'Transition words',
            $pctTrans >= 30 ? self::GOOD : ($pctTrans >= 20 ? self::OK : self::BAD),
            sprintf('%d%% of sentences use a transition word (aim for 30%%+).', $pctTrans));

        return $c;
    }

    /* ------------------------------------------------------------- utilities */

    private function match(string $keyphrase, string $haystack, string $where): array
    {
        if ('' === trim($haystack)) {
            return [self::BAD, ucfirst($where).' is empty.'];
        }
        if ($this->contains($keyphrase, $haystack)) {
            return [self::GOOD, 'Keyphrase appears in '.$where.'.'];
        }
        if ($this->allWordsPresent($keyphrase, $haystack)) {
            return [self::OK, 'All keyphrase words appear in '.$where.', but not as a phrase.'];
        }

        return [self::BAD, 'Keyphrase is not in '.$where.'.'];
    }

    private function contains(string $keyphrase, string $haystack): bool
    {
        if ('' === $keyphrase) {
            return false;
        }

        return str_contains($this->norm($haystack), $this->norm($keyphrase));
    }

    private const STOPWORDS = [
        'a' => 1, 'an' => 1, 'and' => 1, 'or' => 1, 'the' => 1, 'of' => 1, 'for' => 1,
        'to' => 1, 'in' => 1, 'on' => 1, 'at' => 1, 'by' => 1, 'with' => 1, 'is' => 1,
    ];

    private function allWordsPresent(string $keyphrase, string $haystack): bool
    {
        $hay = $this->norm($haystack);
        foreach ($this->significantWords($keyphrase) as $w) {
            if (!str_contains($hay, $w)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> keyphrase words worth matching (no stopwords / one-letter tokens) */
    private function significantWords(string $keyphrase): array
    {
        $out = [];
        foreach ($this->words(mb_strtolower($keyphrase)) as $w) {
            $w = $this->norm($w);
            if ('' !== $w && \strlen($w) > 1 && !isset(self::STOPWORDS[$w])) {
                $out[] = $w;
            }
        }

        return $out ?: [$this->norm($keyphrase)];
    }

    private function countOccurrences(string $keyphrase, string $haystack): int
    {
        $needle = $this->norm($keyphrase);

        return '' === $needle ? 0 : substr_count($this->norm($haystack), $needle);
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** @return list<string> */
    private function words(string $s): array
    {
        preg_match_all("/[\p{L}\p{N}'’-]+/u", $s, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function sentences(string $s): array
    {
        $parts = preg_split('/(?<=[.!?])["\')\]]?\s+/u', trim($s)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($x) => str_word_count($x) > 0));
    }

    /**
     * @param list<string> $words
     * @param list<string> $sentences
     */
    private function fleschReadingEase(array $words, array $sentences): float
    {
        $w = \count($words);
        $s = max(1, \count($sentences));
        if (0 === $w) {
            return 0.0;
        }
        $syllables = 0;
        foreach ($words as $word) {
            $syllables += $this->syllables($word);
        }

        $score = 206.835 - 1.015 * ($w / $s) - 84.6 * ($syllables / $w);

        return round(max(0, min(100, $score)), 1);
    }

    private function fleschLabel(float $score): string
    {
        return match (true) {
            $score >= 90 => 'very easy to read',
            $score >= 70 => 'easy to read',
            $score >= 60 => 'fairly easy to read',
            $score >= 50 => 'plain English',
            $score >= 30 => 'fairly difficult',
            default => 'very difficult',
        };
    }

    private function syllables(string $word): int
    {
        $word = strtolower(preg_replace('/[^a-z]/i', '', $word) ?? '');
        if (strlen($word) <= 3) {
            return $word ? 1 : 0;
        }
        $word = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word) ?? $word;
        $word = preg_replace('/^y/', '', $word) ?? $word;
        preg_match_all('/[aeiouy]{1,2}/', $word, $m);

        return max(1, \count($m[0]));
    }

    private function hasTransition(string $sentence): bool
    {
        $s = ' '.$this->norm($sentence).' ';
        foreach (self::TRANSITIONS as $t) {
            if (str_contains($s, ' '.$t.' ') || str_starts_with($this->norm($sentence), $t.' ')) {
                return true;
            }
        }

        return false;
    }

    private function looksPassive(string $sentence): bool
    {
        $tokens = $this->words(mb_strtolower($sentence));
        $aux = ['am', 'is', 'are', 'was', 'were', 'be', 'been', 'being', "isn't", "aren't", "wasn't", "weren't"];
        foreach ($tokens as $i => $tok) {
            if (!\in_array($tok, $aux, true)) {
                continue;
            }
            for ($j = $i + 1; $j <= $i + 2 && $j < \count($tokens); ++$j) {
                $next = $tokens[$j];
                if (isset(self::PARTICIPLES[$next]) || (strlen($next) > 3 && str_ends_with($next, 'ed'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $subheadings */
    private function longestRunBetweenSubheadings(string $body, array $subheadings): int
    {
        if ([] === $subheadings) {
            return str_word_count($body);
        }
        $segments = [$body];
        foreach ($subheadings as $h) {
            $newSegments = [];
            foreach ($segments as $seg) {
                foreach (explode($h, $seg, 2) as $piece) {
                    $newSegments[] = $piece;
                }
            }
            $segments = $newSegments;
        }
        $max = 0;
        foreach ($segments as $seg) {
            $max = max($max, str_word_count($seg));
        }

        return $max;
    }

    private function pixelWidth(string $text): int
    {
        // Rough Arial-at-title-size estimate: narrow chars ~4px, wide ~10px, average ~7.7px.
        $w = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $w += match (true) {
                str_contains('iIl.,:;\'|!ftj', $ch) => 3.5,
                str_contains('mMwW—', $ch) => 11.0,
                ctype_upper($ch) => 8.5,
                ' ' === $ch => 3.8,
                default => 7.0,
            };
        }

        return (int) round($w);
    }

    private function band(int|float $value, int|float $goodLo, int|float $goodHi, int|float $okLo, int|float $okHi): string
    {
        if ($value >= $goodLo && $value <= $goodHi) {
            return self::GOOD;
        }
        if ($value >= $okLo && $value <= $okHi) {
            return self::OK;
        }

        return self::BAD;
    }

    /** @return array{id: string, label: string, status: string, text: string} */
    private function check(string $id, string $label, string $status, string $text): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'text' => $text];
    }

    /** @param list<array{status: string}> $checks */
    private function score(array $checks): int
    {
        if ([] === $checks) {
            return 0;
        }
        $points = ['good' => 9, 'ok' => 5, 'bad' => 2];
        $sum = 0;
        foreach ($checks as $c) {
            $sum += $points[$c['status']] ?? 5;
        }

        return (int) round($sum / (9 * \count($checks)) * 100);
    }
}
