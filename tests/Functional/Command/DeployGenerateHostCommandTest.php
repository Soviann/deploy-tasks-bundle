<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;

#[CoversClass(DeployTasksGenerateHostCommand::class)]
final class DeployGenerateHostCommandTest extends FunctionalTestCase
{
    /**
     * Throwaway directory for the mandatory constructor argument, funneled through
     * {@see makeCommand()}; every test sets the real target via the --dir option.
     */
    private const HOST_DIR = 'deploy/host-tasks';

    private CommandTester $tester;
    private string $outputDir;
    private string $relativeOutputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:generate:host'));
        $unique = \uniqid();
        $this->relativeOutputDir = 'var/generate-host-test-'.$unique.'/';
        $this->outputDir = self::projectDir().'/'.$this->relativeOutputDir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FilesystemTestHelper::cleanup($this->outputDir);
    }

    public function testGenerateFailsWhenTargetDirectoryIsNotWritable(): void
    {
        // The DDEV-mounted `var/` path has a PHP chmod quirk — test inside tmpfs (/tmp) instead.
        // Create a projectDir with a readonly root so the command cannot mkdir or dumpFile inside it.
        $projectDir = \sys_get_temp_dir().'/generate-host-test-readonly-project-'.\uniqid();
        \mkdir($projectDir, 0o500, true);

        $command = $this->makeCommand(projectDir: $projectDir);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'tasks/']);
            self::fail('Expected host generator to fail when target directory is not writable.');
        } catch (IOException $e) {
            self::assertStringContainsString($projectDir, $e->getMessage());
        } finally {
            \chmod($projectDir, 0o755);
            \rmdir($projectDir);
        }
    }

    public function testGenerateCreatesExecutableBashStubWithTimestampedName(): void
    {
        $this->tester->execute(['--dir' => $this->relativeOutputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Generated new host deploy task', $display);
        self::assertStringContainsString('bash bin/deploy-tasks-host.sh', $display);

        $files = \glob($this->outputDir.'deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(1, $files);

        $filename = \basename($files[0]);
        self::assertMatchesRegularExpression('/^deploy_task_\d{8}_\d{6}\.sh$/', $filename);

        $content = (string) \file_get_contents($files[0]);
        self::assertStringContainsString('#!/usr/bin/env bash', $content);
        self::assertStringContainsString('set -euo pipefail', $content);
        self::assertStringContainsString("IFS=\$'\\n\\t'", $content);
        self::assertStringContainsString('Exit 0 = success', $content);
        self::assertStringContainsString('APP_ENV, DATABASE_URL', $content);
        self::assertStringContainsString('docs/creating-tasks.md', $content);

        self::assertTrue(\is_executable($files[0]), 'Generated host task must have executable bit set.');
    }

    public function testGenerateCreatesDirectoryWhenAbsent(): void
    {
        self::assertDirectoryDoesNotExist($this->outputDir);

        $this->tester->execute(['--dir' => $this->relativeOutputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertDirectoryExists($this->outputDir);
    }

    public function testGenerateRefusesExistingFile(): void
    {
        $projectDir = \sys_get_temp_dir().'/generate-host-exists-guard-'.\uniqid();
        \mkdir($projectDir.'/tasks', 0755, true);

        $fixedNow = new \DateTimeImmutable('2026-04-17 12:00:00');
        $command = $this->makeCommand(
            projectDir: $projectDir,
            nowProvider: static fn (): \DateTimeImmutable => $fixedNow,
        );
        $tester = new CommandTester($command);

        $existing = $projectDir.'/tasks/deploy_task_'.$fixedNow->format('Ymd_His').'.sh';
        \file_put_contents($existing, '# placeholder');

        try {
            $tester->execute(['--dir' => 'tasks/']);
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('File already exists', $tester->getDisplay());
        } finally {
            @\unlink($existing);
            @\rmdir($projectDir.'/tasks');
            @\rmdir($projectDir);
        }
    }

    public function testGenerateRejectsAbsolutePathOutsideProjectRoot(): void
    {
        $this->tester->execute(['--dir' => '/tmp/outside-project/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('must be a relative path', $display);
    }

    public function testGenerateRejectsTraversalEscapingStartingPoint(): void
    {
        // The --dir allowlist catches leading `..` after canonicalisation before the
        // project-root guard; the input-level rejection message reflects that.
        $this->tester->execute(['--dir' => 'deploy/../../../../../../tmp/evil/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('Invalid --dir value', $display);
    }

    #[DataProvider('invalidDirPayloadsProvider')]
    public function testGenerateRejectsInvalidDirPayloads(string $dir): void
    {
        $this->tester->execute(['--dir' => $dir]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('Invalid --dir value', $display);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDirPayloadsProvider(): iterable
    {
        yield 'relative traversal escapes starting point' => ['../evil'];
        yield 'shell-metacharacter injection' => ['deploy;rm'];
        yield 'whitespace-padded segment' => ['deploy host/tasks'];
        yield 'dot segment' => ['deploy.tasks'];
    }

    public function testGenerateAllowsTraversalWithinProjectRoot(): void
    {
        $uniqueId = \uniqid();
        $projectDir = self::projectDir();
        // Relative path with internal traversal that stays within the project root.
        $this->tester->execute(['--dir' => 'var/nested-host/deep/../generate-host-test-'.$uniqueId.'/']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $resolvedDir = $projectDir.'/var/nested-host/generate-host-test-'.$uniqueId.'/';
        self::assertDirectoryExists($resolvedDir);

        FilesystemTestHelper::cleanup($resolvedDir);
        @\rmdir(\dirname($resolvedDir));
    }

    public function testGenerateSuccessMessageContainsAbsolutePath(): void
    {
        // Without a projectDir, the command writes relative to CWD.
        // After writing the file, $filePath is relative — we expect realpath() in the output.
        $tmpDir = \sys_get_temp_dir().'/generate-host-realpath-'.\uniqid();
        \mkdir($tmpDir, 0o755, true);

        // No projectDir — file is written relative to CWD (which we control via chdir).
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $cwd = \getcwd();
        self::assertNotFalse($cwd);

        try {
            \chdir($tmpDir);
            $tester->execute(['--dir' => 'host-tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($tmpDir.'/host-tasks/deploy_task_*.sh');
            self::assertNotFalse($files);
            self::assertCount(1, $files);

            $expectedAbsolutePath = \realpath($files[0]);
            self::assertNotFalse($expectedAbsolutePath);

            $display = \strip_tags($tester->getDisplay());
            // The success message must contain the absolute (realpath) path, not a relative one.
            self::assertStringContainsString($expectedAbsolutePath, $display);
        } finally {
            \chdir($cwd);

            $glob = \glob($tmpDir.'/host-tasks/*');
            $matches = false === $glob ? [] : $glob;

            foreach ($matches as $file) {
                \unlink($file);
            }

            @\rmdir($tmpDir.'/host-tasks');
            @\rmdir($tmpDir);
        }
    }

    public function testGenerateNormalisesTrailingSlashInDirOption(): void
    {
        $this->tester->execute(['--dir' => $this->relativeOutputDir.'/']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $files = \glob(\rtrim($this->outputDir, '/').'/deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertStringNotContainsString('//deploy_task_', $files[0]);
    }

    public function testGenerateWarnsWhenHostRunnerMissing(): void
    {
        $projectDir = \sys_get_temp_dir().'/generate-host-runner-missing-'.\uniqid();
        \mkdir($projectDir, 0o755, true);

        $command = $this->makeCommand(projectDir: $projectDir);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'host-tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $display = \preg_replace('/\s+/', ' ', $tester->getDisplay());
            self::assertNotNull($display);
            self::assertStringContainsString('Host runner not found', $display);
            self::assertStringContainsString('bin/deploy-tasks-host.sh', $display);
            self::assertStringContainsString('deploy-tasks-host.sh.dist', $display);
        } finally {
            $glob = \glob($projectDir.'/host-tasks/*');
            foreach (false === $glob ? [] : $glob as $file) {
                \unlink($file);
            }
            @\rmdir($projectDir.'/host-tasks');
            @\rmdir($projectDir);
        }
    }

    public function testGenerateDoesNotWarnWhenHostRunnerPresent(): void
    {
        $projectDir = \sys_get_temp_dir().'/generate-host-runner-present-'.\uniqid();
        \mkdir($projectDir.'/bin', 0o755, true);
        \file_put_contents($projectDir.'/bin/deploy-tasks-host.sh', "#!/usr/bin/env bash\n");

        $command = $this->makeCommand(projectDir: $projectDir);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'host-tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $display = \preg_replace('/\s+/', ' ', $tester->getDisplay());
            self::assertNotNull($display);
            self::assertStringNotContainsString('Host runner not found', $display);
        } finally {
            $glob = \glob($projectDir.'/host-tasks/*');
            foreach (false === $glob ? [] : $glob as $file) {
                \unlink($file);
            }
            @\rmdir($projectDir.'/host-tasks');
            @\unlink($projectDir.'/bin/deploy-tasks-host.sh');
            @\rmdir($projectDir.'/bin');
            @\rmdir($projectDir);
        }
    }

    public function testGeneratedFileIsReadableOnlyByOwnerAndGroup(): void
    {
        // Run under a fresh /tmp projectDir to dodge the DDEV-mounted var/ chmod quirk.
        $projectDir = \sys_get_temp_dir().'/generate-host-perms-'.\uniqid();
        \mkdir($projectDir, 0o755, true);

        $command = $this->makeCommand(projectDir: $projectDir);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'host-tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($projectDir.'/host-tasks/deploy_task_*.sh');
            self::assertNotFalse($files);
            self::assertCount(1, $files);

            FilesystemTestHelper::assertPermissions($files[0], 0o750);
        } finally {
            $glob = \glob($projectDir.'/host-tasks/*');
            $matches = false === $glob ? [] : $glob;

            foreach ($matches as $file) {
                \unlink($file);
            }

            @\rmdir($projectDir.'/host-tasks');
            @\rmdir($projectDir);
        }
    }

    /**
     * @param non-empty-string $dir
     * @param non-empty-string $expectedMessageFragment
     */
    #[DataProvider('pathTraversalPayloadsProvider')]
    public function testGenerateRejectsDirPathTraversal(string $dir, string $expectedMessageFragment, ?string $projectDir): void
    {
        $command = $this->makeCommand(projectDir: $projectDir);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => $dir]);
        } catch (\InvalidArgumentException) {
            // Boundary violation surfaces as an exception — counts as rejection.
            return;
        }

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString($expectedMessageFragment, $display);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, ?string}>
     */
    public static function pathTraversalPayloadsProvider(): iterable
    {
        // Absolute path: rejected by leading-slash guard before any canonicalisation.
        yield 'absolute path' => ['/etc/passwd', 'must be a relative path', null];

        // Parent traversal: normalises to a `..`-prefixed canonical → caught by allowlist.
        yield 'parent traversal' => ['../../escape', 'Invalid --dir value', null];

        // Mid-path traversal: `legit/../..` collapses to `..` → caught by allowlist.
        yield 'mid-path traversal' => ['legit/../../escape', 'Invalid --dir value', null];

        // Sibling-directory escape: `../myprojectX` starts with `..` → caught by allowlist.
        $siblingProject = \sys_get_temp_dir().'/myproject-'.\uniqid();
        yield 'sibling directory escape' => ['../myprojectX', 'Invalid --dir value', $siblingProject];

        // Valid relative path inside the project → no failure expected (handled by testGenerateCreatesExecutableBashStubWithTimestampedName).
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function makeCommand(
        ?string $projectDir = null,
        ?\Closure $nowProvider = null,
        string $hostDirectory = self::HOST_DIR,
    ): DeployTasksGenerateHostCommand {
        return new DeployTasksGenerateHostCommand(
            hostDirectory: $hostDirectory,
            projectDir: $projectDir,
            nowProvider: $nowProvider,
        );
    }
}
