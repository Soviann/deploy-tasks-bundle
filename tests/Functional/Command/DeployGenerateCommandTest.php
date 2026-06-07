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
    /**
     * Throwaway value for the now-mandatory constructor argument; every test below
     * overrides the target via the --dir option, so the value itself is never exercised.
     */
    private const DEFAULT_DIR = 'src/DeployTasks/Task/';

    private CommandTester $tester;
    private string $outputDir;
    private string $relativeOutputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:generate:container'));
        $unique = \uniqid();
        $this->relativeOutputDir = 'var/generate-test-'.$unique.'/';
        $this->outputDir = self::projectDir().'/'.$this->relativeOutputDir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FilesystemTestHelper::cleanup($this->outputDir);
    }

    public function testGenerate(): void
    {
        $this->tester->execute(['--dir' => $this->relativeOutputDir, '--namespace' => 'App\\Test']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Generated new deploy task class', $display);
        self::assertStringContainsString('deploytasks:run --rerun-all --id=', $display);

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

        $this->tester->execute(['--dir' => $this->relativeOutputDir, '--namespace' => 'App\\Test']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertDirectoryExists($this->outputDir);
    }

    public function testGenerateFileAlreadyExists(): void
    {
        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $projectDir = \sys_get_temp_dir().'/generate-exists-guard-'.\uniqid();
        \mkdir($projectDir.'/tasks', 0755, true);

        $fixedNow = new \DateTimeImmutable('2026-04-17 12:00:00');
        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            defaultDirectory: self::DEFAULT_DIR,
            projectDir: $projectDir,
            nowProvider: static fn (): \DateTimeImmutable => $fixedNow,
        );
        $tester = new CommandTester($command);

        $expectedFile = $projectDir.'/tasks/DeployTask'.$fixedNow->format('YmdHis').'.php';
        \file_put_contents($expectedFile, '<?php // placeholder');

        try {
            $tester->execute(['--dir' => 'tasks/']);
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('already exists', $tester->getDisplay());
        } finally {
            @\unlink($expectedFile);
            @\rmdir($projectDir.'/tasks');
            @\rmdir($projectDir);
        }
    }

    public function testGenerateFailsWhenTargetDirectoryIsNotWritable(): void
    {
        // The DDEV-mounted `var/` path has a PHP chmod quirk — test inside tmpfs (/tmp) instead.
        // Create a projectDir with a readonly root so the command cannot mkdir or dumpFile inside it.
        $projectDir = \sys_get_temp_dir().'/generate-test-readonly-project-'.\uniqid();
        \mkdir($projectDir, 0o500, true);

        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            defaultDirectory: self::DEFAULT_DIR,
            projectDir: $projectDir,
        );
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'tasks/']);
            self::fail('Expected generator to fail when target directory is not writable.');
        } catch (IOException $e) {
            self::assertStringContainsString($projectDir, $e->getMessage());
        } finally {
            \chmod($projectDir, 0o755);
            \rmdir($projectDir);
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
            defaultDirectory: self::DEFAULT_DIR,
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
        self::assertStringContainsString('must be a relative path', $display);
    }

    public function testGenerateAllowsTraversalWithinProjectRoot(): void
    {
        $uniqueId = \uniqid();
        $projectDir = self::projectDir();
        // Relative path with internal traversal that stays within the project root.
        // --namespace is required because the dir segment "generate-test-XXXX" contains a hyphen,
        // which is not a valid PHP namespace character and would be rejected by dirToNamespace().
        $this->tester->execute(['--dir' => 'var/nested/deep/../generate-test-'.$uniqueId.'/', '--namespace' => 'App\\Nested\\Test']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Generated new deploy task class', $this->tester->getDisplay());

        // The command normalizes the path, so clean up at the resolved location.
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
        $command = new DeployTasksGenerateCommand(idGenerator: $idGenerator, defaultDirectory: self::DEFAULT_DIR);
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
        // --namespace is required because the dir name contains hyphens (from uniqid()), which dirToNamespace() rejects.
        $this->tester->execute(['--dir' => $this->relativeOutputDir.'/', '--namespace' => 'App\\Test']);

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
        $subdir = 'src/DeployTasks/Task_'.\uniqid().'/';
        $tmpProject = \sys_get_temp_dir().'/generate-ns-'.\uniqid();
        \mkdir($tmpProject, 0o755, true);

        $command = new DeployTasksGenerateCommand(
            new \Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator(),
            defaultDirectory: self::DEFAULT_DIR,
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

            self::assertMatchesRegularExpression('/namespace App\\\\DeployTasks\\\\Task[A-Za-z0-9_\\\\]*;/', $content);
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

        $projectDir = \sys_get_temp_dir().'/generate-template-project-'.\uniqid();
        \mkdir($projectDir, 0o755, true);

        try {
            $command = new DeployTasksGenerateCommand(
                new \Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator(),
                defaultDirectory: self::DEFAULT_DIR,
                templatePath: $template,
                projectDir: $projectDir,
            );
            $tester = new CommandTester($command);
            $tester->execute(['--dir' => 'tasks/']);

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            $files = \glob($projectDir.'/tasks/DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $content = (string) \file_get_contents($files[0]);
            self::assertStringContainsString('custom-template-marker', $content);
            self::assertStringNotContainsString('implements DeployTaskInterface', $content);
        } finally {
            @\unlink($template);
            FilesystemTestHelper::cleanup($projectDir);
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
            defaultDirectory: self::DEFAULT_DIR,
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

    /**
     * @param non-empty-string $dir
     * @param non-empty-string $expectedMessageFragment
     */
    #[DataProvider('pathTraversalPayloadsProvider')]
    public function testGenerateRejectsDirPathTraversal(string $dir, string $expectedMessageFragment, ?string $projectDir): void
    {
        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            defaultDirectory: self::DEFAULT_DIR,
            projectDir: $projectDir,
        );
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

        // Valid relative path inside the project → no failure expected (handled by testGenerate).
    }

    public function testHostileGeneratorOutputIsEscapedInGeneratedStub(): void
    {
        // Hostile generator returns a string that would break out of a surrounding single-quoted
        // PHP literal if injected raw. var_export must neutralise it so the rendered file is
        // syntactically valid and the string survives byte-for-byte.
        $hostiletaskId = "';system('rm -rf /'); //";

        $hostileGenerator = new class($hostiletaskId) implements TaskIdGeneratorInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function generate(string $className): string
            {
                return $this->id;
            }

            public static function generateStatic(string $className): ?string
            {
                return null;
            }
        };

        $projectDir = \sys_get_temp_dir().'/generate-hostile-'.\uniqid();
        \mkdir($projectDir.'/tasks', 0o755, true);

        $fixedNow = new \DateTimeImmutable('2099-01-01 00:00:00');
        $command = new DeployTasksGenerateCommand(
            idGenerator: $hostileGenerator,
            defaultDirectory: self::DEFAULT_DIR,
            projectDir: $projectDir,
            nowProvider: static fn (): \DateTimeImmutable => $fixedNow,
        );
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'tasks/']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($projectDir.'/tasks/DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $renderedFile = $files[0];

            // Assert the rendered file is syntactically valid PHP.
            $process = new \Symfony\Component\Process\Process(['php', '-l', $renderedFile]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), 'php -l must exit 0: '.$process->getOutput().$process->getErrorOutput());

            // Assert the hostile string survives byte-for-byte: var_export escapes it, so
            // the file must contain the escaped literal form rather than the raw string.
            // \var_export("';system('rm -rf /'); //", true) produces '\';system(\'rm -rf /\'); //'
            $content = (string) \file_get_contents($renderedFile);
            $escapedForm = \var_export($hostiletaskId, true);

            self::assertStringContainsString(
                $escapedForm,
                $content,
                'The var_export-escaped form of the hostile string must appear verbatim in the rendered stub.',
            );
            self::assertStringNotContainsString(
                $hostiletaskId,
                $content,
                'The raw (unescaped) hostile string must NOT appear in the rendered stub.',
            );
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

    public function testGenerateRejectsDirectoryWithInvalidNamespaceSegment(): void
    {
        // "my-tasks" contains a hyphen — dirToNamespace() rejects it because PHP namespaces
        // allow only letters, digits (after first char), and underscores.
        $projectDir = \sys_get_temp_dir().'/generate-ns-reject-'.\uniqid();
        \mkdir($projectDir, 0o755, true);

        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            defaultDirectory: self::DEFAULT_DIR,
            projectDir: $projectDir,
        );
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'src/my-tasks']);
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            $display = \preg_replace('/\s+/', ' ', $tester->getDisplay());
            self::assertNotNull($display);
            self::assertStringContainsString('cannot be turned into a valid PHP namespace', $display);
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
        }
    }

    public function testGenerateNamespaceOverrideSkipsDirToNamespace(): void
    {
        // "--namespace=App\Tasks" bypasses dirToNamespace() entirely — a dir with hyphens works fine.
        $projectDir = \sys_get_temp_dir().'/generate-ns-override-'.\uniqid();
        \mkdir($projectDir, 0o755, true);

        $idGenerator = self::getContainer()->get('deploy_tasks.id_generator');
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $idGenerator);

        $command = new DeployTasksGenerateCommand(
            idGenerator: $idGenerator,
            defaultDirectory: self::DEFAULT_DIR,
            projectDir: $projectDir,
        );
        $tester = new CommandTester($command);

        try {
            $tester->execute(['--dir' => 'src/my-tasks', '--namespace' => 'App\\Tasks']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $files = \glob($projectDir.'/src/my-tasks/DeployTask*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);

            $content = (string) \file_get_contents($files[0]);
            self::assertStringContainsString('namespace App\\Tasks;', $content);
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
        }
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
