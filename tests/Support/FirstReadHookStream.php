<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Support;

/**
 * Read-only stream wrapper that runs a callback the first time it is read.
 *
 * Used as the input stream of an interactive command, it interleaves a side
 * effect (e.g. a "concurrent" mutation of a file the command is about to act
 * on) at the exact moment the command reads its prompt answer — after the
 * command computed everything it computes pre-prompt, before it acts.
 *
 * Usage:
 *
 *     FirstReadHookStream::register("yes\n", fn () => ...mutate...);
 *     try {
 *         $stream = \fopen(FirstReadHookStream::PROTOCOL.'://answer', 'r');
 *         // feed $stream to the command's input, run the command
 *     } finally {
 *         FirstReadHookStream::unregister();
 *     }
 */
final class FirstReadHookStream
{
    public const PROTOCOL = 'first-read-hook';

    /**
     * Assigned by the streams layer when the stream is opened with a context.
     *
     * @var resource|null
     */
    public $context;

    private static string $data = '';
    private static ?\Closure $onFirstRead = null;

    private int $offset = 0;

    public static function register(string $data, \Closure $onFirstRead): void
    {
        self::$data = $data;
        self::$onFirstRead = $onFirstRead;
        \stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function unregister(): void
    {
        self::$onFirstRead = null;
        \stream_wrapper_unregister(self::PROTOCOL);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if (null !== self::$onFirstRead) {
            $hook = self::$onFirstRead;
            self::$onFirstRead = null;
            $hook();
        }

        $chunk = \substr(self::$data, $this->offset, $count);
        $this->offset += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= \strlen(self::$data);
    }

    public function stream_close(): void
    {
    }

    public function stream_stat(): false
    {
        return false;
    }
}
