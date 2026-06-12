<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

/**
 * Strips terminal control characters from untrusted text before console output.
 *
 * Error messages can originate from third-party task classes or external
 * processes; raw control bytes (especially ESC) would let them inject ANSI
 * escape sequences into the deployer's terminal. Newlines and tabs survive;
 * everything else below 0x20, plus DEL, is removed.
 *
 * @internal
 */
final class ConsoleSanitizer
{
    public static function sanitize(string $text): string
    {
        return (string) \preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
    }
}
