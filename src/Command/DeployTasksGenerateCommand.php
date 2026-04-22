<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/** @internal */
#[AsCommand(
    name: 'deploytasks:generate:container',
    description: 'Generate a blank container-scope deploy task class.',
    aliases: ['deploytasks:generate'],
)]
final class DeployTasksGenerateCommand extends Command
{
    private readonly Filesystem $fs;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $nowProvider optional clock override for deterministic timestamps in tests
     */
    public function __construct(
        private readonly TaskIdGeneratorInterface $idGenerator,
        private readonly string $defaultDirectory = 'src/DeployTasks/Task/',
        private readonly ?string $templatePath = null,
        private readonly ?string $projectDir = null,
        private readonly ?\Closure $nowProvider = null,
    ) {
        $this->fs = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory for the generated file.', $this->defaultDirectory)
            ->setHelp(
                \sprintf(
                    <<<'EOT'
                        The <info>%%command.name%%</info> command generates a blank container-scope deploy task class:

                            <info>%%command.full_name%%</info>

                        This creates a file like <comment>%sDeployTask20260412143000.php</comment> with:
                          - A unique ID based on the current timestamp
                          - The <comment>#[AsDeployTask]</comment> attribute pre-configured
                          - A stub <comment>run()</comment> method ready to implement

                        You can specify a custom target directory with <comment>--dir</comment>:

                            <info>%%command.full_name%% --dir=src/Deploy/Task/</info>

                        The generated class implements <comment>DeployTaskInterface</comment> and is automatically
                        discovered by the bundle via autoconfiguration.

                        For host-scope tasks (bash scripts running outside the container), see
                        <info>deploytasks:generate:host</info>.
                        EOT,
                    $this->defaultDirectory,
                )
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = null !== $this->nowProvider ? ($this->nowProvider)() : new \DateTimeImmutable();
        $timestamp = $now->format('YmdHis');
        $className = 'DeployTask'.$timestamp;

        /** @var class-string $className */
        $taskId = $this->idGenerator->generate($className);
        $description = '';

        /** @var string $dir */
        $dir = $input->getOption('dir');
        $dir = \rtrim($dir, '/').'/';

        $canonical = Path::canonicalize($dir);

        if (\str_starts_with($canonical, '..') || 1 !== \preg_match('#^[A-Za-z0-9/_\-]+$#', $canonical)) {
            $io->error(\sprintf(
                'Invalid --dir value "%s": must be a relative path using only letters, digits, slash, underscore, dash, and must not traverse above its starting point.',
                $dir,
            ));

            return Command::FAILURE;
        }

        $absoluteDir = $dir;

        if (null !== $this->projectDir) {
            $absoluteDir = \str_starts_with($dir, '/') ? $dir : $this->projectDir.'/'.$dir;
            $absoluteDir = Path::canonicalize($absoluteDir).'/';

            if (!\str_starts_with($absoluteDir, $this->projectDir)) {
                $io->error(\sprintf('Directory "%s" is outside the project root.', $dir));

                return Command::FAILURE;
            }
        }

        $filePath = $absoluteDir.$className.'.php';

        if ($this->fs->exists($filePath)) {
            $io->error(\sprintf('File "%s" already exists.', $filePath));

            return Command::FAILURE;
        }

        $namespace = $this->dirToNamespace($dir);

        if (null !== $this->templatePath && \is_file($this->templatePath)) {
            $fileContent = (string) \file_get_contents($this->templatePath);
            $fileContent = \strtr($fileContent, [
                '{{ namespace }}' => $namespace,
                '{{ className }}' => $className,
                '{{ taskId }}' => $taskId,
                '{{ description }}' => $description,
            ]);
        } else {
            $fileContent = <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$namespace};

                use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
                use Soviann\DeployTasksBundle\DeployTaskInterface;
                use Soviann\DeployTasksBundle\TaskResult;
                use Symfony\Component\Console\Output\OutputInterface;

                #[AsDeployTask(description: '{$description}')]
                final class {$className} implements DeployTaskInterface
                {
                    public function getDescription(): string
                    {
                        return '{$description}';
                    }

                    public function run(OutputInterface \$output): TaskResult
                    {
                        // TODO: implement

                        return TaskResult::SUCCESS;
                    }
                }
                PHP;
        }

        $this->fs->mkdir($absoluteDir, 0755);
        $this->fs->dumpFile($filePath, $fileContent);

        $io->text([
            \sprintf('Generated new deploy task class to "<info>%s</info>"', $filePath),
            '',
            \sprintf('To run just this task for testing purposes, you can use <info>deploytasks:run --force --id=%s</info>', $taskId),
            '',
            'To see all registered tasks, use <info>deploytasks:status</info>.',
            '',
        ]);

        return Command::SUCCESS;
    }

    private function dirToNamespace(string $dir): string
    {
        $dir = \rtrim($dir, '/');
        $parts = \explode('/', $dir);

        $namespaceParts = \array_map(static function (string $part): string {
            return \ucfirst($part);
        }, $parts);

        if ('Src' === $namespaceParts[0]) {
            $namespaceParts[0] = 'App';
        }

        return \implode('\\', $namespaceParts);
    }
}
