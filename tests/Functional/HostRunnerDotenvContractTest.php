<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

/**
 * Pins the host runner's bash `.env` parser (`_load_env` in
 * bin/deploy-tasks-host.sh.dist) against Symfony's Dotenv component on the
 * documented "supported subset" (see docs/host-tasks.md, "`.env` loading"
 * and "The host contract (pinned by tests)").
 *
 * Two kinds of cases:
 * - {@see provideSupportedDotenvSyntax()}: bash and Dotenv must agree.
 * - {@see provideDocumentedDivergences()}: bash intentionally diverges
 *   (docs/host-tasks.md, "Non-goals" — no expansion, no inline comments);
 *   both sides are pinned explicitly so either implementation changing
 *   breaks the test.
 *
 * No kernel needed: this exercises the standalone bash script via
 * Symfony Process, same harness as {@see HostRunnerTest}.
 */
final class HostRunnerDotenvContractTest extends TestCase
{
    private const RUNNER_SOURCE = __DIR__.'/../../bin/deploy-tasks-host.sh.dist';

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = \sys_get_temp_dir().'/deploy-tasks-host-dotenv-'.\uniqid('', true);
        \mkdir($this->workspace.'/bin', 0o755, true);
        \copy(self::RUNNER_SOURCE, $this->workspace.'/bin/deploy-tasks-host.sh');
        \chmod($this->workspace.'/bin/deploy-tasks-host.sh', 0o755);
    }

    protected function tearDown(): void
    {
        FilesystemTestHelper::cleanup($this->workspace);
    }

    #[DataProvider('provideSupportedDotenvSyntax')]
    public function testBashParserAgreesWithDotenvOnSupportedSyntax(string $envContents, string $key, string $expectedValue): void
    {
        $dotenvValues = (new Dotenv())->parse($envContents);
        self::assertArrayHasKey($key, $dotenvValues, 'Fixture must actually define the key under Dotenv.');
        self::assertSame($expectedValue, $dotenvValues[$key], 'Fixture expectation must match Dotenv::parse() itself.');

        self::assertSame($expectedValue, $this->probeBashValue($envContents, $key));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideSupportedDotenvSyntax(): iterable
    {
        yield 'plain' => ["FOO=bar\n", 'FOO', 'bar'];
        yield 'double quoted' => ["FOO=\"bar baz\"\n", 'FOO', 'bar baz'];
        yield 'single quoted' => ["FOO='bar baz'\n", 'FOO', 'bar baz'];
        yield 'export prefix' => ["export FOO=bar\n", 'FOO', 'bar'];
        yield 'full-line comment skipped' => ["# nope\nFOO=bar\n", 'FOO', 'bar'];
        yield 'empty value' => ["FOO=\n", 'FOO', ''];
    }

    #[DataProvider('provideDocumentedDivergences')]
    public function testBashParserDivergesFromDotenvAsDocumented(string $envContents, string $key, string $dotenvValue, string $bashValue): void
    {
        // docs/host-tasks.md, "Non-goals — the host runner stays small":
        // "richer .env parsing (expansion, inline comments, multiline) — use
        // deploy-tasks-host.local.sh for anything the parser can't express."
        $dotenvValues = (new Dotenv())->parse($envContents);
        self::assertSame($dotenvValue, $dotenvValues[$key], 'Dotenv side of the pinned divergence must hold.');

        self::assertSame($bashValue, $this->probeBashValue($envContents, $key));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideDocumentedDivergences(): iterable
    {
        yield 'variable expansion stays literal in bash' => [
            "BAR=baz\nFOO=\${BAR}\n",
            'FOO',
            'baz',
            '${BAR}',
        ];
        yield 'inline comment retained in bash value' => [
            "FOO=bar # c\n",
            'FOO',
            'bar',
            'bar # c',
        ];
    }

    public function testPreExistingRealEnvVarIsNotOverwrittenByFileValue(): void
    {
        // docs/host-tasks.md, "`.env` loading": "real environment variables
        // always take precedence: a variable already set in the process
        // environment before the runner starts ... is never overwritten by
        // any `.env` file." Pin both sides: Dotenv::populate() honours the
        // same rule by default (overrideExistingVars: false).
        $envContents = "FOO=from-file\n";

        // Dotenv::populate() only recognises a var as "already set" via
        // $_SERVER/$_ENV, not getenv(), so all three must be primed and
        // cleaned up to keep this hermetic.
        \putenv('FOO=from-real-env');
        $_SERVER['FOO'] = 'from-real-env';
        $_ENV['FOO'] = 'from-real-env';
        try {
            $dotenv = new Dotenv();
            $dotenv->populate($dotenv->parse($envContents));
            self::assertSame('from-real-env', \getenv('FOO'));
        } finally {
            \putenv('FOO');
            unset($_SERVER['FOO'], $_ENV['FOO']);
        }

        self::assertSame('from-real-env', $this->probeBashValue($envContents, 'FOO', preExistingEnv: ['FOO' => 'from-real-env']));
    }

    /**
     * Runs the host runner with a single `.env` file containing $envContents,
     * a probe task that dumps the requested variable, and returns what the
     * runner exported for it (or null if unset/unexported).
     *
     * @param array<string, string> $preExistingEnv extra process-env vars set before the runner starts
     */
    private function probeBashValue(string $envContents, string $key, array $preExistingEnv = []): ?string
    {
        \file_put_contents($this->workspace.'/.env', $envContents);

        $dir = $this->workspace.'/deploy/host-tasks';
        \mkdir($dir, 0o755, true);
        $marker = $this->workspace.'/probe.out';
        $probeBody = \sprintf(
            'if [ -z "${%1$s+x}" ]; then printf \'__UNSET__\' > %2$s; else printf \'%%s\' "$%1$s" > %2$s; fi',
            $key,
            \escapeshellarg($marker),
        );
        \file_put_contents($dir.'/20260101_000000_probe.sh', "#!/usr/bin/env bash\nset -euo pipefail\n".$probeBody."\n");
        \chmod($dir.'/20260101_000000_probe.sh', 0o755);

        $path = \getenv('PATH');
        $process = new Process(['bash', 'bin/deploy-tasks-host.sh'], $this->workspace, $preExistingEnv + ['PATH' => false !== $path ? $path : '']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        $result = \file_get_contents($marker);
        self::assertIsString($result);

        return '__UNSET__' === $result ? null : $result;
    }
}
