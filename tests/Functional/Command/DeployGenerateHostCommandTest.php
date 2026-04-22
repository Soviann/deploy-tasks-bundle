<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksGenerateHostCommand::class)]
final class DeployGenerateHostCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private string $outputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:generate:host'));
        $this->outputDir = \dirname(__DIR__, 3).'/var/generate-host-test-'.\uniqid().'/';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (\is_dir($this->outputDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->outputDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
            }
            \rmdir($this->outputDir);
        }
    }

    public function testGenerateFailsWhenTargetDirectoryIsNotWritable(): void
    {
        // The DDEV-mounted `var/` path has a PHP chmod quirk — test inside tmpfs (/tmp) instead.
        $dir = \sys_get_temp_dir().'/generate-host-test-readonly-'.\uniqid().'/';
        \mkdir($dir, 0o500, true);

        $command = new DeployTasksGenerateHostCommand();
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => $dir]);
            self::fail('Expected host generator to fail when target directory is not writable.');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/Failed to write .*deploy_task_/', $e->getMessage());
        } finally {
            \chmod($dir, 0o755);
            \rmdir($dir);
        }
    }

    public function testGenerateCreatesExecutableBashStubWithTimestampedName(): void
    {
        $this->tester->execute(['--dir' => $this->outputDir]);

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

        self::assertTrue(\is_executable($files[0]), 'Generated host task must have executable bit set.');
    }

    public function testGenerateCreatesDirectoryWhenAbsent(): void
    {
        self::assertDirectoryDoesNotExist($this->outputDir);

        $this->tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertDirectoryExists($this->outputDir);
    }

    public function testGenerateRefusesExistingFile(): void
    {
        $fixedNow = new \DateTimeImmutable('2026-04-17 12:00:00');
        $command = new DeployTasksGenerateHostCommand(
            nowProvider: static fn (): \DateTimeImmutable => $fixedNow,
        );
        $tester = new CommandTester($command);

        \mkdir($this->outputDir, 0755, true);
        $existing = $this->outputDir.'deploy_task_'.$fixedNow->format('Ymd_His').'.sh';
        \file_put_contents($existing, '# placeholder');

        $tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('File already exists', $tester->getDisplay());
    }

    public function testGenerateRejectsAbsolutePathOutsideProjectRoot(): void
    {
        $this->tester->execute(['--dir' => '/tmp/outside-project/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('outside the project root', $display);
    }

    public function testGenerateRejectsTraversalOutsideProjectRoot(): void
    {
        $this->tester->execute(['--dir' => 'deploy/../../../../../../tmp/evil/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('outside the project root', $display);
    }

    public function testGenerateAllowsTraversalWithinProjectRoot(): void
    {
        $uniqueId = \uniqid();
        $dir = \dirname(__DIR__, 3).'/var/nested-host/deep/../generate-host-test-'.$uniqueId.'/';
        $this->tester->execute(['--dir' => $dir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $resolvedDir = \dirname(__DIR__, 3).'/var/nested-host/generate-host-test-'.$uniqueId.'/';
        $files = \glob($resolvedDir.'deploy_task_*.sh');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            \unlink($file);
        }

        \rmdir($resolvedDir);
        @\rmdir(\dirname($resolvedDir));
    }

    public function testGenerateNormalisesTrailingSlashInDirOption(): void
    {
        $this->tester->execute(['--dir' => $this->outputDir.'/']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $files = \glob(\rtrim($this->outputDir, '/').'/deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertStringNotContainsString('//deploy_task_', $files[0]);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
