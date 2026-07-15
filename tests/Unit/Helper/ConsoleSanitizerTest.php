<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;

#[CoversClass(ConsoleSanitizer::class)]
final class ConsoleSanitizerTest extends TestCase
{
    public function testStripsEscapeAndControlBytes(): void
    {
        // Only control BYTES are removed; the printable "[2J" remainder of the
        // escape sequence survives — stripping ESC alone already neutralizes it.
        self::assertSame('clear[2Jtext', ConsoleSanitizer::sanitize("clear\x1b[2J\x00\x07text"));
    }

    public function testKeepsNewlinesAndTabs(): void
    {
        self::assertSame("line1\n\tline2", ConsoleSanitizer::sanitize("line1\n\tline2"));
    }

    public function testStripsCarriageReturn(): void
    {
        self::assertSame('ab', ConsoleSanitizer::sanitize("a\rb"));
    }

    public function testStripsDeleteByte(): void
    {
        self::assertSame('ab', ConsoleSanitizer::sanitize("a\x7fb"));
    }

    public function testPreservesMultibyteUtf8(): void
    {
        // The regex targets single control bytes only; UTF-8 continuation bytes
        // (>= 0x80) must never match, so multibyte text passes through unchanged.
        self::assertSame('café — 日本語', ConsoleSanitizer::sanitize('café — 日本語'));
    }

    public function testStripsControlBytesWithoutCorruptingAdjacentMultibyte(): void
    {
        self::assertSame('café[2J日本語', ConsoleSanitizer::sanitize("café\x1b[2J\x07日本語"));
    }

    public function testSanitizeForFormatterStripsControlBytesAndEscapesTags(): void
    {
        // The combined helper applies BOTH halves at once: control bytes are
        // stripped (ANSI injection) and formatter tags are escaped (terminal
        // hyperlink / color spoofing) — no caller can end up with half the pair.
        self::assertSame(
            '[31m\<href=https://evil.example\>click\</\>',
            ConsoleSanitizer::sanitizeForFormatter("\x1b[31m<href=https://evil.example>click</>"),
        );
    }

    public function testSanitizeForFormatterLeavesPlainTextUntouched(): void
    {
        self::assertSame(
            'Task failed: connection refused',
            ConsoleSanitizer::sanitizeForFormatter('Task failed: connection refused'),
        );
    }
}
