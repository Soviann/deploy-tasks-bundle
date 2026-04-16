<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksGenerateCommand::class)]
final class DeployGenerateCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private string $outputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('deploytasks:generate'));
        $this->outputDir = \dirname(__DIR__, 3).'/var/generate-test-'.\uniqid().'/';
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

    public function testGenerate(): void
    {
        $this->tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Generated new deploy task class', $display);
        self::assertStringContainsString('deploytasks:run --force --id=', $display);

        $files = \glob($this->outputDir.'DeployTask*.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);

        $content = \file_get_contents($files[0]);
        self::assertNotFalse($content);
        self::assertStringContainsString('DeployTaskInterface', $content);
    }

    public function testGenerateCreatesDirectory(): void
    {
        self::assertDirectoryDoesNotExist($this->outputDir);

        $this->tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertDirectoryExists($this->outputDir);
    }

    public function testGenerateFileAlreadyExists(): void
    {
        // Pre-create the file the command will try to generate (same-second timestamp)
        $timestamp = \date('YmdHis');
        $expectedFile = $this->outputDir.'DeployTask'.$timestamp.'.php';
        \mkdir($this->outputDir, 0755, true);
        \file_put_contents($expectedFile, '<?php // placeholder');

        $this->tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('File already exists', $this->tester->getDisplay());
    }

    public function testGenerateRejectsAbsolutePathOutsideProjectRoot(): void
    {
        $this->tester->execute(['--dir' => '/tmp/outside-project/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('outside the project root', $this->tester->getDisplay());
    }

    public function testGenerateAllowsTraversalWithinProjectRoot(): void
    {
        $uniqueId = \uniqid();
        $dir = \dirname(__DIR__, 3).'/var/nested/deep/../generate-test-'.$uniqueId.'/';
        $this->tester->execute(['--dir' => $dir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Generated new deploy task class', $this->tester->getDisplay());

        // The command normalizes the path, so clean up at the resolved location
        $resolvedDir = \dirname(__DIR__, 3).'/var/nested/generate-test-'.$uniqueId.'/';
        $files = \glob($resolvedDir.'DeployTask*.php');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            \unlink($file);
        }

        \rmdir($resolvedDir);
        @\rmdir(\dirname($resolvedDir));
    }

    public function testGenerateRejectsTraversalOutsideProjectRoot(): void
    {
        $this->tester->execute(['--dir' => 'src/../../../../../../tmp/evil/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('outside the project root', $this->tester->getDisplay());
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
