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
    public static function sanitize(string $text): string
    {
        return (string) \preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
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
