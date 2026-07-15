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

    public function testStripsRightToLeftOverride(): void
    {
        // U+202E RIGHT-TO-LEFT OVERRIDE enables Trojan-Source-style visual
        // reordering of subsequent terminal output; it must not survive sanitization.
        // sanitize() removes the control character itself — it doesn't (and can't)
        // undo a reordering effect that only ever existed at render time.
        self::assertSame('safeexe.txt', ConsoleSanitizer::sanitize("safe\u{202E}exe.txt"));
    }

    public function testStripsBidiIsolateFormatCharacters(): void
    {
        // U+2066-U+2069 (LRI/RLI/FSI/PDI) are Unicode format characters (\p{Cf})
        // used to bracket bidi isolates; same Trojan-Source risk as U+202E.
        self::assertSame(
            'abcd',
            ConsoleSanitizer::sanitize("\u{2066}a\u{2067}b\u{2068}c\u{2069}d"),
        );
    }

    public function testStripsLineAndParagraphSeparators(): void
    {
        // U+2028 LINE SEPARATOR and U+2029 PARAGRAPH SEPARATOR are not caught by
        // the byte-oriented control-byte pass (they're multibyte in UTF-8).
        self::assertSame('ab', ConsoleSanitizer::sanitize("a\u{2028}\u{2029}b"));
    }

    public function testStripsC1ControlCharacter(): void
    {
        // U+0085 NEL (NEXT LINE) is a C1 control character, encoded as two bytes
        // in UTF-8 (0xC2 0x85) — invisible to the byte-oriented C0/DEL pass.
        self::assertSame('ab', ConsoleSanitizer::sanitize("a\u{0085}b"));
    }

    public function testStripsC1ControlSequenceIntroducer(): void
    {
        // U+009B CSI is the single-character equivalent of ESC-[ — the full C1
        // block U+0080-U+009F is stripped, not just NEL.
        self::assertSame('a[31mb', ConsoleSanitizer::sanitize("a\u{009B}[31mb"));
    }

    public function testStripsBidiOverrideEvenWhenInvalidBytesArePresent(): void
    {
        // A stray invalid byte must not disable the Unicode pass for the whole
        // string — otherwise appending one junk byte would be a trivial bypass
        // of the bidi defense. Invalid sequences are dropped, valid multibyte
        // text survives, and the U+202E override is still stripped.
        self::assertSame('café exe.txt', ConsoleSanitizer::sanitize("café\xFF \u{202E}exe.txt"));
    }

    public function testInvalidUtf8InputDoesNotNullTheString(): void
    {
        // An invalid UTF-8 byte sequence must not make the /u-mode Unicode pass
        // return null (which would silently discard the entire string). Invalid
        // bytes are dropped — a raw stray byte is never legitimate console text,
        // and a bare C1 byte like \x9B is a live CSI on 8-bit terminals — while
        // the byte-level C0/DEL pass and the rest of the text are unaffected.
        self::assertSame('ab', ConsoleSanitizer::sanitize("a\xFF\x07b"));
    }
}
