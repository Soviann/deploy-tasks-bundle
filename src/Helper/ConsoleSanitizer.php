<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Strips terminal control characters from untrusted text before console output.
 *
 * Error messages can originate from third-party task classes or external
 * processes; raw control bytes (especially ESC) would let them inject ANSI
 * escape sequences into the deployer's terminal. Newlines and tabs survive;
 * everything else below 0x20, plus DEL, is removed.
 *
 * Beyond single-byte control bytes, Unicode format characters — notably
 * U+202E RIGHT-TO-LEFT OVERRIDE and the U+2066-U+2069 bidi isolates — enable
 * Trojan-Source-style visual reordering of terminal output, and the full C1
 * control block U+0080-U+009F (e.g. U+0085 NEL, U+009B CSI) plus the
 * U+2028/U+2029 line/paragraph separators are invisible to the byte-oriented
 * pass above (they're multibyte in UTF-8). These are stripped by a second,
 * `/u`-mode pass. `\p{Cf}` also removes zero-width joiners/non-joiners and
 * soft hyphen (U+00AD) — this mangles legitimate emoji sequences and some
 * scripts (e.g. Persian), an accepted trade-off: Trojan-Source defense takes
 * priority over cosmetic fidelity in terminal output.
 *
 * Invalid UTF-8 never disables that pass: gating it on whole-string validity
 * would let one stray junk byte smuggle a bidi override through. Instead,
 * ill-formed byte sequences are dropped up front (a stray byte is never
 * legitimate console text, and a bare 0x9B byte is a live CSI on 8-bit
 * terminals), then the Unicode pass runs unconditionally on the now-valid
 * remainder. The scrub is pure PCRE rather than mb_convert_encoding(), whose
 * invalid-input handling differs between native mbstring (substitutes with
 * mb_substitute_character) and symfony/polyfill-mbstring (drops via iconv
 * //IGNORE) — dropping via PCRE is deterministic on every install.
 *
 * Which method to call depends on the sink:
 * - {@see sanitizeForFormatter()} for formatter-interpreting sinks — writeln()
 *   with markup, table cells, $io->text()/definitionList() — where an untrusted
 *   `<href=…>` or style tag would otherwise spoof terminal hyperlinks/colors.
 * - {@see sanitize()} alone for sinks that already escape formatter tags
 *   themselves (SymfonyStyle block helpers: error(), warning(), note(), …) —
 *   escaping again there would double-escape.
 *
 * @internal
 */
final class ConsoleSanitizer
{
    /**
     * Matches one well-formed UTF-8 character (captured) or one ill-formed byte
     * (not captured) — replacing with `$1` keeps the former and drops the
     * latter. The W3C UTF-8 scrubbing pattern: rejects overlong encodings,
     * surrogate halves, and code points beyond U+10FFFF.
     */
    private const UTF8_SCRUB_PATTERN = '/([\x00-\x7F]'
        .'|[\xC2-\xDF][\x80-\xBF]'
        .'|\xE0[\xA0-\xBF][\x80-\xBF]'
        .'|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
        .'|\xED[\x80-\x9F][\x80-\xBF]'
        .'|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        .'|[\xF1-\xF3][\x80-\xBF]{3}'
        .'|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s';

    public static function sanitize(string $text): string
    {
        $text = (string) \preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);

        if (1 !== \preg_match('//u', $text)) {
            // Ill-formed UTF-8 would make the /u-mode pass below return null,
            // nulling the whole string. Drop the invalid bytes (never skip the
            // pass: a whole-string validity gate would let one junk byte carry
            // a bidi override past it) so the Unicode pass always runs.
            $text = (string) \preg_replace(self::UTF8_SCRUB_PATTERN, '$1', $text);
        }

        return (string) \preg_replace('/[\p{Cf}\x{2028}\x{2029}\x{0080}-\x{009F}]/u', '', $text);
    }

    /**
     * Combined sanitize + formatter-tag escape, so no caller can apply half the
     * pair. Order is load-bearing: escape() encodes a trailing backslash as NUL
     * bytes, which sanitize() would strip — sanitize first, escape last.
     */
    public static function sanitizeForFormatter(string $text): string
    {
        return OutputFormatter::escape(self::sanitize($text));
    }
}
