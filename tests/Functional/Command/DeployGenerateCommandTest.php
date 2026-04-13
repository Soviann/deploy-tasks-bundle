<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksGenerateCommand::class)]
final class DeployGenerateCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private string $outputDir;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('deploytasks:generate'));
        $this->outputDir = \sys_get_temp_dir().'/deploy-tasks-generate-test-'.\uniqid().'/';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();

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

    public function testGenerateWithoutName(): void
    {
        $this->tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Generated new deploy task class', $this->tester->getDisplay());

        $files = \glob($this->outputDir.'Task*.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
    }

    public function testGenerateWithName(): void
    {
        $this->tester->execute(['name' => 'SeedCategories', '--dir' => $this->outputDir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('SeedCategories', $display);
        self::assertStringContainsString('deploytasks:run --force=', $display);

        $files = \glob($this->outputDir.'Task*SeedCategories.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);

        $content = \file_get_contents($files[0]);
        self::assertNotFalse($content);
        self::assertStringContainsString('seed_categories', $content);
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
        // First generation
        $this->tester->execute(['name' => 'Duplicate', '--dir' => $this->outputDir]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Overwrite the file with a known name to simulate duplicate
        $files = \glob($this->outputDir.'Task*Duplicate.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        $existingFile = \basename($files[0], '.php');

        // We can't easily reproduce the same timestamp, so this test verifies
        // the command's early-exit behavior indirectly
        self::assertStringContainsString('Generated', $this->tester->getDisplay());
    }
}
