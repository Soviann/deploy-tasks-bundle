<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;

#[CoversClass(DeployTasksGenerateCommand::class)]
final class DeployGenerateCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private string $outputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:generate:container'));
        $this->outputDir = self::projectDir().'/var/generate-test-'.\uniqid().'/';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FilesystemTestHelper::cleanup($this->outputDir);
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
        self::assertStringContainsString("description: 'TODO: describe this task'", $content);
        self::assertStringContainsString('Available attribute knobs', $content);
        self::assertStringContainsString('priority: 0', $content);
        self::assertStringContainsString('Inject services here if needed', $content);
        self::assertStringContainsString('docs/creating-tasks.md', $content);
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
        self::assertStringContainsString('already exists', $tester->getDisplay());
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
        } catch (IOException $e) {
            self::assertMatchesRegularExpression('/DeployTask\d+\.php/', $e->getMessage());
        } finally {
            \chmod($dir, 0o755);
            \rmdir($dir);
        }
    }

    public function testGenerateExistsGuardUsesResolvedAbsolutePath(): void
    {
        // With a relative --dir, the guard must resolve against the injected projectDir,
        // not the CWD — otherwise pre-existing files inside the resolved target are missed.
        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $projectDir = \sys_get_temp_dir().'/generate-absolute-guard-'.\uniqid();
        \mkdir($projectDir.'/src/Tasks', 0o755, true);

        $fixedNow = new \DateTimeImmutable('2026-04-18 10:00:00');
        $expectedFile = $projectDir.'/src/Tasks/DeployTask'.$fixedNow->format('YmdHis').'.php';
        \file_put_contents($expectedFile, '<?php // existing');

        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            projectDir: $projectDir,
            nowProvider: static fn (): \DateTimeImmutable => $fixedNow,
        );
        $tester = new CommandTester($command);

        $cwd = \getcwd();
        self::assertNotFalse($cwd);

        try {
            \chdir(\sys_get_temp_dir());
            $tester->execute(['--dir' => 'src/Tasks/']);
        } finally {
            \chdir($cwd);
            \unlink($expectedFile);
            \rmdir($projectDir.'/src/Tasks');
            \rmdir($projectDir.'/src');
            \rmdir($projectDir);
        }

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = \preg_replace('/\s+/', '', $tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString(\str_replace(' ', '', $expectedFile), $display);
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
        $projectDir = self::projectDir();
        $dir = $projectDir.'/var/nested/deep/../generate-test-'.$uniqueId.'/';
        $this->tester->execute(['--dir' => $dir]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Generated new deploy task class', $this->tester->getDisplay());

        // The command normalizes the path, so clean up at the resolved location
        $resolvedDir = $projectDir.'/var/nested/generate-test-'.$uniqueId.'/';
        self::assertDirectoryExists($resolvedDir);

        FilesystemTestHelper::cleanup($resolvedDir);
        @\rmdir(\dirname($resolvedDir));
    }

    public function testGenerateRejectsTraversalEscapingStartingPoint(): void
    {
        // The --dir allowlist catches leading `..` after canonicalisation before the
        // project-root guard; the input-level rejection message reflects that.
        $this->tester->execute(['--dir' => 'src/../../../../../../tmp/evil/']);

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
        yield 'php-open-tag injection' => ['<?php'];
        yield 'semicolon injection' => ['a;b'];
        yield 'whitespace-padded segment' => ['foo bar'];
        yield 'dot segment (namespaces disallow dots)' => ['src/Deploy.Tasks'];
    }

    public function testGenerateSuccessMessageContainsAbsolutePath(): void
    {
        // Without a projectDir, the command resolves the file relative to CWD.
        // After writing the file, $filePath is relative — we expect realpath() in the output.
        $tmpDir = \sys_get_temp_dir().'/generate-realpath-'.\uniqid();
        \mkdir($tmpDir, 0o755, true);

        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        // No projectDir — file is written relative to CWD (which we control via chdir).
        $command = new DeployTasksGenerateCommand(idGenerator: $idGenerator);
        $tester = new CommandTester($command);

        $cwd = \getcwd();
        self::assertNotFalse($cwd);

        try {
            \chdir($tmpDir);
            $tester->execute(['--dir' => 'tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($tmpDir.'/tasks/DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);

            $expectedAbsolutePath = \realpath($files[0]);
            self::assertNotFalse($expectedAbsolutePath);

            $display = \strip_tags($tester->getDisplay());
            // The success message must contain the absolute (realpath) path, not a relative one.
            self::assertStringContainsString($expectedAbsolutePath, $display);
        } finally {
            \chdir($cwd);

            $glob = \glob($tmpDir.'/tasks/*');
            $matches = false === $glob ? [] : $glob;

            foreach ($matches as $file) {
                \unlink($file);
            }

            @\rmdir($tmpDir.'/tasks');
            @\rmdir($tmpDir);
        }
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
        $subdir = 'src/DeployTasks/Task-'.\uniqid().'/';
        $tmpProject = \sys_get_temp_dir().'/generate-ns-'.\uniqid();
        \mkdir($tmpProject, 0o755, true);

        $command = new DeployTasksGenerateCommand(
            new \Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator(),
            projectDir: $tmpProject,
        );
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
            FilesystemTestHelper::cleanup($tmpProject);
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

    public function testGeneratedFileIsReadableOnlyByOwnerAndGroup(): void
    {
        // Run under a fresh /tmp projectDir to dodge the DDEV-mounted var/ chmod quirk.
        $projectDir = \sys_get_temp_dir().'/generate-perms-'.\uniqid();
        \mkdir($projectDir, 0o755, true);

        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            projectDir: $projectDir,
        );
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($projectDir.'/tasks/DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);

            FilesystemTestHelper::assertPermissions($files[0], 0o640);
        } finally {
            $glob = \glob($projectDir.'/tasks/*');
            $matches = false === $glob ? [] : $glob;

            foreach ($matches as $file) {
                \unlink($file);
            }

            @\rmdir($projectDir.'/tasks');
            @\rmdir($projectDir);
        }
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
