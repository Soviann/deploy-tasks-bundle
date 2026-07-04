<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksHostConfigCommand;
use Soviann\DeployTasksBundle\Helper\HostRunnerConfig;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DeployTasksHostConfigCommand::class)]
final class DeployHostConfigCommandTest extends FunctionalTestCase
{
    private string $tempProjectDir;
    private string $localShPath;

    protected function setUp(): void
    {
        // Isolated per-test project dir: this test's --write option writes
        // deploy-tasks-host.local.sh, which must never land in (or race on) the
        // real checkout's project root under parallel Infection mutant runs.
        $this->tempProjectDir = \sys_get_temp_dir().'/dtb-generate-'.\uniqid('', true);
        \mkdir($this->tempProjectDir, 0o755, true);

        self::useConfigurableKernel([], projectDir: $this->tempProjectDir);
        self::bootKernel();
        $this->localShPath = $this->tempProjectDir.'/deploy-tasks-host.local.sh';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempProjectDir);

        parent::tearDown();
    }

    public function testRendersProjectRelativeExportsForTheDefaultConfig(): void
    {
        $tester = $this->runCommand('deploytasks:host:config');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString("export DEPLOY_TASKS_HOST_DIR='deploy/host-tasks'", $display);
        self::assertStringContainsString("export DEPLOY_TASKS_HOST_STORAGE='.deploy-tasks-host.log'", $display);
        self::assertStringContainsString("export DEPLOY_TASKS_HOST_LOCK='.deploy-tasks-host.lock'", $display);
    }

    public function testWriteCreatesTheLocalShTheRunnerSources(): void
    {
        $tester = $this->runCommand('deploytasks:host:config', ['--write' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->localShPath);
        $contents = (string) \file_get_contents($this->localShPath);
        self::assertStringContainsString(HostRunnerConfig::GENERATED_MARKER, $contents);
        self::assertStringContainsString("export DEPLOY_TASKS_HOST_DIR='deploy/host-tasks'", $contents);
    }

    public function testWriteRefusesToOverwriteAHandWrittenFile(): void
    {
        \file_put_contents($this->localShPath, "# hand-written\nexport DEPLOY_TASKS_HOST_DIR='custom'\n");

        $tester = $this->runCommand('deploytasks:host:config', ['--write' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('# hand-written', (string) \file_get_contents($this->localShPath), 'Hand-written file must be preserved.');
    }
}
