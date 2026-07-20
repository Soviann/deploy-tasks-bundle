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
        $tester = $this->runConsoleCommand('deploytasks:host:install');

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

    public function testInstallScaffoldsTheConfiguredHostDirectory(): void
    {
        // host.directory is configurable; install must scaffold THAT directory,
        // not the hardcoded default — otherwise a host with a custom directory
        // gets a task dir no other command will ever look at.
        self::useConfigurableKernel(
            ['host' => ['directory' => 'ops/host-jobs']],
            projectDir: $this->tempProjectDir,
        );
        self::bootKernel();

        $tester = $this->runConsoleCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->tempProjectDir.'/ops/host-jobs/.gitkeep');
        self::assertFileDoesNotExist($this->tempProjectDir.'/deploy/host-tasks/.gitkeep');
        // Strip ALL whitespace before matching: a path in a display assertion
        // can wrap mid-token on narrow terminals (see GOTCHAS).
        $flat = (string) \preg_replace('/\s+/', '', $tester->getDisplay());
        self::assertStringContainsString('ops/host-jobs/.gitkeep:created', $flat);
        // The label must be exactly project-relative: no leading slash, no
        // absolute-prefix remnant (kills the off-by-one and unwrap mutants
        // on the substr() relativization).
        self::assertStringNotContainsString('/ops/host-jobs/.gitkeep:created', $flat);

        if ('/' === \DIRECTORY_SEPARATOR) {
            // The scaffolded directory is world-listable like the default one.
            FilesystemTestHelper::assertPermissions($this->tempProjectDir.'/ops/host-jobs', 0o755);
        }
    }

    public function testInstallDoesNotMistakeAPrefixSiblingForTheProjectDir(): void
    {
        // "{projectDir}-adjacent" shares the string prefix but is OUTSIDE the
        // project: the label must stay absolute. A prefix check without the
        // trailing slash would mangle it into a fake relative path.
        $siblingDir = $this->tempProjectDir.'-adjacent';

        try {
            self::useConfigurableKernel(
                ['host' => ['directory' => $siblingDir]],
                projectDir: $this->tempProjectDir,
            );
            self::bootKernel();

            $tester = $this->runConsoleCommand('deploytasks:host:install');

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            self::assertFileExists($siblingDir.'/.gitkeep');
            $flat = (string) \preg_replace('/\s+/', '', $tester->getDisplay());
            self::assertStringContainsString($siblingDir.'/.gitkeep:created', $flat);
        } finally {
            FilesystemTestHelper::cleanup($siblingDir);
        }
    }

    public function testInstallReportsAnAbsolutePathForAHostDirectoryOutsideTheProject(): void
    {
        // host.directory may point outside the project (absolute values pass
        // through un-anchored); the status line then shows the absolute path —
        // a project-relative label would be a lie.
        $outsideDir = \sys_get_temp_dir().'/dtb-outside-'.\uniqid('', true);

        try {
            self::useConfigurableKernel(
                ['host' => ['directory' => $outsideDir]],
                projectDir: $this->tempProjectDir,
            );
            self::bootKernel();

            $tester = $this->runConsoleCommand('deploytasks:host:install');

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            self::assertFileExists($outsideDir.'/.gitkeep');
            // Strip ALL whitespace: paths in display assertions wrap mid-token
            // on narrow terminals (see GOTCHAS).
            $flat = (string) \preg_replace('/\s+/', '', $tester->getDisplay());
            self::assertStringContainsString($outsideDir.'/.gitkeep:created', $flat);
        } finally {
            FilesystemTestHelper::cleanup($outsideDir);
        }
    }

    public function testRerunWithoutForceSkipsEveryStepAndLeavesFilesUntouched(): void
    {
        $this->runConsoleCommand('deploytasks:host:install');

        // Tamper with the runner: a plain re-run must not undo local edits.
        \file_put_contents($this->runnerPath, "# locally modified runner\n");
        $gitignoreBefore = (string) \file_get_contents($this->gitignorePath);

        $tester = $this->runConsoleCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertSame(3, \substr_count($display, ': skipped (exists)'), $display);
        self::assertStringContainsString('0 created, 3 skipped, 0 overwritten', $display);

        self::assertSame("# locally modified runner\n", (string) \file_get_contents($this->runnerPath));
        self::assertSame($gitignoreBefore, (string) \file_get_contents($this->gitignorePath));
    }

    public function testRerunWithForceOverwritesEveryStepWithoutDuplication(): void
    {
        $this->runConsoleCommand('deploytasks:host:install');

        // Tamper with the runner and the inside of the .gitignore block.
        \file_put_contents($this->runnerPath, "# stale runner\n");
        $gitignore = (string) \file_get_contents($this->gitignorePath);
        \file_put_contents($this->gitignorePath, \str_replace('/.deploy-tasks-host.log', '/tampered-entry', $gitignore));

        $tester = $this->runConsoleCommand('deploytasks:host:install', ['--force' => true]);

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

        $tester = $this->runConsoleCommand('deploytasks:host:install');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 created, 1 skipped, 0 overwritten', $display);
        self::assertSame($preExisting, (string) \file_get_contents($this->gitignorePath), 'Without --force the existing block must stay untouched.');

        $tester = $this->runConsoleCommand('deploytasks:host:install', ['--force' => true]);

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

        $tester = $this->runConsoleCommand('deploytasks:host:install');

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
            $tester = $this->runConsoleCommand('deploytasks:host:install');

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
