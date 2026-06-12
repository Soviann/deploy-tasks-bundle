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
}
