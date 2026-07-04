<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksHostConfigCommand;
use Soviann\DeployTasksBundle\Helper\HostRunnerConfig;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Console\Command\Command;

#[CoversClass(DeployTasksHostConfigCommand::class)]
final class DeployHostConfigCommandTest extends FunctionalTestCase
{
    private string $localShPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->localShPath = self::projectDir().'/deploy-tasks-host.local.sh';

        // This test writes to the real bundle repo root (see FunctionalTestCase::projectDir()) —
        // the file must not pre-exist; guard + finally-cleanup keeps the tree clean either way.
        self::assertFileDoesNotExist($this->localShPath, 'deploy-tasks-host.local.sh must not pre-exist before this test.');
    }

    protected function tearDown(): void
    {
        if (\is_file($this->localShPath)) {
            \unlink($this->localShPath);
        }

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
