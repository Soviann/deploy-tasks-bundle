<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksInstallHostCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\Console\Command\Command;

#[CoversClass(DeployTasksInstallHostCommand::class)]
final class DeployInstallHostCommandTest extends FunctionalTestCase
{
    private const GITIGNORE_BLOCK = <<<'TXT'
        ###> soviann/deploy-tasks-bundle ###
        /.deploy-tasks-host.log
        /.deploy-tasks-host.lock
        /deploy-tasks-host.local.sh
        ###< soviann/deploy-tasks-bundle ###
        TXT;

    private string $tempProjectDir;
    private string $runnerPath;
    private string $gitkeepPath;
    private string $gitignorePath;

    protected function setUp(): void
    {
        // Isolated per-test project dir: the command writes bin/, deploy/, and
        // .gitignore under %kernel.project_dir%, which must never be the real
        // checkout root (parallel Infection runners would race on it).
        $this->tempProjectDir = \sys_get_temp_dir().'/dtb-install-'.\uniqid('', true);
        \mkdir($this->tempProjectDir, 0o755, true);

        self::useConfigurableKernel([], projectDir: $this->tempProjectDir);
        self::bootKernel();

        $this->runnerPath = $this->tempProjectDir.'/bin/deploy-tasks-host.sh';
        $this->gitkeepPath = $this->tempProjectDir.'/deploy/host-tasks/.gitkeep';
        $this->gitignorePath = $this->tempProjectDir.'/.gitignore';
    }

    protected function tearDown(): void
    {
        FilesystemTestHelper::cleanup($this->tempProjectDir);

        parent::tearDown();
    }

    public function testCleanRunCreatesAllThreeArtifacts(): void
    {
        $tester = $this->runCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertSame(3, \substr_count($display, ': created'), $display);
        self::assertStringContainsString('3 created, 0 skipped, 0 overwritten', $display);

        self::assertFileExists($this->runnerPath);
        self::assertSame($this->distContent(), (string) \file_get_contents($this->runnerPath));
        FilesystemTestHelper::assertPermissions($this->runnerPath, 0o755);

        self::assertFileExists($this->gitkeepPath);

        self::assertFileExists($this->gitignorePath);
        $gitignore = (string) \file_get_contents($this->gitignorePath);
        self::assertStringContainsString(self::GITIGNORE_BLOCK, $gitignore);
        self::assertSame(1, \substr_count($gitignore, '###> soviann/deploy-tasks-bundle ###'));
    }

    public function testRerunWithoutForceSkipsEveryStepAndLeavesFilesUntouched(): void
    {
        $this->runCommand('deploytasks:host:install');

        // Tamper with the runner: a plain re-run must not undo local edits.
        \file_put_contents($this->runnerPath, "# locally modified runner\n");
        $gitignoreBefore = (string) \file_get_contents($this->gitignorePath);

        $tester = $this->runCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertSame(3, \substr_count($display, ': skipped (exists)'), $display);
        self::assertStringContainsString('0 created, 3 skipped, 0 overwritten', $display);

        self::assertSame("# locally modified runner\n", (string) \file_get_contents($this->runnerPath));
        self::assertSame($gitignoreBefore, (string) \file_get_contents($this->gitignorePath));
    }

    public function testRerunWithForceOverwritesEveryStepWithoutDuplication(): void
    {
        $this->runCommand('deploytasks:host:install');

        // Tamper with the runner and the inside of the .gitignore block.
        \file_put_contents($this->runnerPath, "# stale runner\n");
        $gitignore = (string) \file_get_contents($this->gitignorePath);
        \file_put_contents($this->gitignorePath, \str_replace('/.deploy-tasks-host.log', '/tampered-entry', $gitignore));

        $tester = $this->runCommand('deploytasks:host:install', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertSame(3, \substr_count($display, ': overwritten'), $display);
        self::assertStringContainsString('0 created, 0 skipped, 3 overwritten', $display);

        self::assertSame($this->distContent(), (string) \file_get_contents($this->runnerPath));
        FilesystemTestHelper::assertPermissions($this->runnerPath, 0o755);

        $rewritten = (string) \file_get_contents($this->gitignorePath);
        self::assertStringContainsString(self::GITIGNORE_BLOCK, $rewritten);
        self::assertStringNotContainsString('/tampered-entry', $rewritten);
        self::assertSame(1, \substr_count($rewritten, '###> soviann/deploy-tasks-bundle ###'));
    }

    public function testPreExistingGitignoreBlockIsNotDuplicatedAndForceReplacesInPlace(): void
    {
        // The block already exists (e.g. hand-added or Flex-installed), with a
        // stale entry inside, sandwiched between foreign lines.
        $preExisting = "/vendor/\n\n"
            ."###> soviann/deploy-tasks-bundle ###\n"
            ."/stale-entry\n"
            ."###< soviann/deploy-tasks-bundle ###\n"
            ."\n/node_modules/\n";
        \file_put_contents($this->gitignorePath, $preExisting);

        $tester = $this->runCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 created, 1 skipped, 0 overwritten', $display);
        self::assertSame($preExisting, (string) \file_get_contents($this->gitignorePath), 'Without --force the existing block must stay untouched.');

        $tester = $this->runCommand('deploytasks:host:install', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $rewritten = (string) \file_get_contents($this->gitignorePath);
        self::assertSame(
            "/vendor/\n\n".self::GITIGNORE_BLOCK."\n\n/node_modules/\n",
            $rewritten,
            'With --force the block must be replaced in place, foreign lines preserved.',
        );
        self::assertSame(1, \substr_count($rewritten, '###> soviann/deploy-tasks-bundle ###'));
    }

    public function testForeignGitignoreContentIsPreserved(): void
    {
        \file_put_contents($this->gitignorePath, "/vendor/\n/var/\n");

        $tester = $this->runCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $gitignore = (string) \file_get_contents($this->gitignorePath);
        self::assertStringStartsWith("/vendor/\n/var/\n", $gitignore);
        self::assertStringContainsString(self::GITIGNORE_BLOCK, $gitignore);
    }

    public function testReadonlyBinDirectoryFailsWithIoError(): void
    {
        // The DDEV-mounted var/ path has a PHP chmod quirk — a /tmp projectDir
        // (as set up in setUp()) is required for a reliable readonly directory.
        \mkdir($this->tempProjectDir.'/bin', 0o500);

        try {
            $tester = $this->runCommand('deploytasks:host:install');

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('[ERROR]', $tester->getDisplay());
        } finally {
            \chmod($this->tempProjectDir.'/bin', 0o755);
        }
    }

    private function distContent(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3).'/bin/deploy-tasks-host.sh.dist');
    }
}
