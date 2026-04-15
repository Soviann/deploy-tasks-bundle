<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\TaskIdGeneratorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:generate', description: 'Generate a blank deploy task class.')]
final class DeployTasksGenerateCommand extends Command
{
    public function __construct(
        private readonly TaskIdGeneratorInterface $idGenerator,
        private readonly string $defaultDirectory = 'src/DeployTasks/Task/',
        private readonly ?string $templatePath = null,
        private readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Optional descriptive suffix for the class name (e.g. SeedCategories).')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory for the generated file.', $this->defaultDirectory)
            ->setHelp(
                \sprintf(
                    <<<'EOT'
                        The <info>%%command.name%%</info> command generates a blank deploy task class:

                            <info>%%command.full_name%%</info>

                        This creates a file like <comment>%sTask20260412143000.php</comment> with:
                          - A unique ID based on the current timestamp
                          - The <comment>#[AsDeployTask]</comment> attribute pre-configured
                          - A stub <comment>run()</comment> method ready to implement

                        You can add a descriptive suffix to the class name:

                            <info>%%command.full_name%% SeedCategories</info>

                        This generates <comment>Task20260412143000SeedCategories.php</comment>.

                        You can specify a custom target directory with <comment>--dir</comment>:

                            <info>%%command.full_name%% SeedCategories --dir=src/Deploy/Task/</info>

                        The generated class implements <comment>DeployTaskInterface</comment> and is automatically
                        discovered by the bundle via autoconfiguration.
                        EOT,
                    $this->defaultDirectory,
                )
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $name */
        $name = $input->getArgument('name');

        $timestamp = \date('YmdHis');
        $className = 'Task'.$timestamp;

        if (null !== $name) {
            $name = \ucfirst($name);

            if (\str_ends_with($name, 'Task')) {
                $name = \substr($name, 0, -4);
            }

            $className .= $name;
        }

        /** @var class-string $className */
        $taskId = $this->idGenerator->generate($className);
        $description = null !== $name ? \ucfirst(\str_replace('_', ' ', $this->toSnakeCase($name))) : '';

        /** @var string $dir */
        $dir = $input->getOption('dir');
        $dir = \rtrim($dir, '/').'/';

        if (null !== $this->projectDir) {
            $absoluteDir = \str_starts_with($dir, '/') ? $dir : $this->projectDir.'/'.$dir;
            $normalized = $this->normalizePath($absoluteDir);

            if (!\str_starts_with($normalized, $this->projectDir)) {
                $io->error(\sprintf('Directory "%s" is outside the project root.', $dir));

                return Command::FAILURE;
            }
        }

        $filePath = $dir.$className.'.php';

        if (\file_exists($filePath)) {
            $io->error(\sprintf('File already exists: %s', $filePath));

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

                use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
                use Soviann\DeployTasks\Contract\DeployTaskInterface;
                use Soviann\DeployTasks\Contract\TaskResult;
                use Symfony\Component\Console\Output\OutputInterface;

                #[AsDeployTask(id: '{$taskId}', description: '{$description}')]
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

        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
        }

        \file_put_contents($filePath, $fileContent);

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

    private function toSnakeCase(string $name): string
    {
        $snake = (string) \preg_replace('/[A-Z]/', '_$0', $name);

        return \strtolower(\ltrim($snake, '_'));
    }

    /**
     * Resolves `.` and `..` segments without requiring the path to exist.
     */
    private function normalizePath(string $path): string
    {
        $parts = \explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ('.' === $part || '' === $part) {
                continue;
            }

            if ('..' === $part) {
                \array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return '/'.\implode('/', $normalized);
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
