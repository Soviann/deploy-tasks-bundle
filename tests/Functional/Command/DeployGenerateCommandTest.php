<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
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
        $application = new Application(self::kernel());
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
        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $fixedNow = new \DateTimeImmutable('2026-04-17 12:00:00');
        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            nowProvider: static fn (): \DateTimeImmutable => $fixedNow,
        );
        $tester = new CommandTester($command);

        $expectedFile = $this->outputDir.'DeployTask'.$fixedNow->format('YmdHis').'.php';
        \mkdir($this->outputDir, 0755, true);
        \file_put_contents($expectedFile, '<?php // placeholder');

        $tester->execute(['--dir' => $this->outputDir]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('File already exists', $tester->getDisplay());
    }

    public function testGenerateFailsWhenTargetDirectoryIsNotWritable(): void
    {
        // The DDEV-mounted `var/` path has a PHP chmod quirk — test inside tmpfs (/tmp) instead.
        $dir = \sys_get_temp_dir().'/generate-test-readonly-'.\uniqid().'/';
        \mkdir($dir, 0o500, true);

        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $command = new DeployTasksGenerateCommand(idGenerator: $idGenerator);
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => $dir]);
            self::fail('Expected generator to fail when target directory is not writable.');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/Failed to write .*DeployTask/', $e->getMessage());
        } finally {
            \chmod($dir, 0o755);
            \rmdir($dir);
        }
    }

    public function testGenerateRejectsAbsolutePathOutsideProjectRoot(): void
    {
        $this->tester->execute(['--dir' => '/tmp/outside-project/']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('outside the project root', $display);
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
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('outside the project root', $display);
    }

    public function testGenerateNormalisesTrailingSlashInDirOption(): void
    {
        // Pass the dir with an extra trailing slash — the file path must still have exactly one.
        // Kills UnwrapRtrim on line 70: if rtrim is removed, the path becomes `…//DeployTask…` and glob below misses it.
        $this->tester->execute(['--dir' => $this->outputDir.'/']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $files = \glob(\rtrim($this->outputDir, '/').'/DeployTask*.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertStringNotContainsString('//DeployTask', $files[0]);
    }

    public function testGeneratedFileContentUsesAppNamespaceWhenDirStartsWithSrc(): void
    {
        // Kills Identical mutation on `'Src' === $namespaceParts[0]` (line 182): when mutated to `'Src' !== ...`,
        // the namespace would stay `Src\*` instead of being rewritten to `App\*`.
        // Also kills UnwrapArrayMap / UnwrapUcFirst / UnwrapRtrim in `dirToNamespace` via the namespace assertions below.
        $command = new DeployTasksGenerateCommand(
            new \Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator(),
            projectDir: \sys_get_temp_dir(),
        );
        $subdir = 'src/DeployTasks/Task-'.\uniqid().'/';
        $tmpProject = \sys_get_temp_dir().'/generate-ns-'.\uniqid();
        \mkdir($tmpProject, 0o755, true);
        $cwd = \getcwd();
        \assert(false !== $cwd);

        try {
            \chdir($tmpProject);
            $tester = new CommandTester($command);
            $tester->execute(['--dir' => $subdir]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($tmpProject.'/'.$subdir.'DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $content = (string) \file_get_contents($files[0]);

            self::assertMatchesRegularExpression('/namespace App\\\\DeployTasks\\\\Task[A-Za-z0-9\\\\-]*;/', $content);
            self::assertStringNotContainsString('namespace Src\\', $content);
        } finally {
            \chdir($cwd);
            if (\is_dir($tmpProject)) {
                $rii = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpProject, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($rii as $f) {
                    \assert($f instanceof \SplFileInfo);
                    $f->isDir() ? \rmdir($f->getPathname()) : \unlink($f->getPathname());
                }
                \rmdir($tmpProject);
            }
        }
    }

    public function testGenerateUsesCustomTemplateWhenConfigured(): void
    {
        // Kills LogicalAndSingleSubExprNegation on line 93: a mutation that flips `null !== templatePath`
        // would cause the builtin template to be rendered even when a custom template file is present.
        $template = \sys_get_temp_dir().'/generate-template-'.\uniqid().'.tpl';
        \file_put_contents($template, "<?php // custom-template-marker {{ className }} {{ taskId }}\n");

        try {
            $command = new DeployTasksGenerateCommand(
                new \Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator(),
                templatePath: $template,
            );
            $tester = new CommandTester($command);
            $tester->execute(['--dir' => $this->outputDir]);

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            $files = \glob($this->outputDir.'DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $content = (string) \file_get_contents($files[0]);
            self::assertStringContainsString('custom-template-marker', $content);
            self::assertStringNotContainsString('implements DeployTaskInterface', $content);
        } finally {
            @\unlink($template);
        }
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
