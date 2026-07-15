<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Psr\Clock\ClockInterface;
use Soviann\DeployTasksBundle\Helper\PathNormalizer;
use Soviann\DeployTasksBundle\Helper\SystemClock;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/** @internal */
#[AsCommand(
    name: 'deploytasks:generate:container',
    description: 'Generate a blank deploy task (PHP class, runs inside the Symfony container).',
)]
final class DeployTasksGenerateCommand extends Command
{
    /** One valid PHP identifier — the per-segment rule for namespaces derived from --dir or given via --namespace. */
    private const PHP_LABEL_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private readonly Filesystem $fs;

    public function __construct(
        private readonly TaskIdGeneratorInterface $idGenerator,
        private readonly string $defaultDirectory,
        private readonly string $rootNamespace,
        private readonly ?string $templatePath = null,
        private readonly ?string $projectDir = null,
        /** Override for deterministic timestamps in tests. */
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->fs = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Target directory for the generated file.',
                $this->defaultDirectory,
            )
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Override the namespace derived from --dir.')
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

        $now = $this->clock->now();
        $timestamp = $now->format('YmdHis');
        $className = 'DeployTask'.$timestamp;

        $taskId = $this->idGenerator->generate($className);
        $description = 'TODO: describe this task';

        /** @var string $dir */
        $dir = $input->getOption('dir');
        $userProvidedDir = $input->hasParameterOption('--dir');

        if (!$userProvidedDir && \str_starts_with($dir, '/')) {
            // Absolute configured generate.directory (typically
            // '%kernel.project_dir%/…' resolved by DI) — operator-trusted. The
            // namespace is derived from the project-relative remainder when the
            // directory sits under the project dir; outside it, --namespace is
            // required because no PSR-4 mapping can be inferred.
            $absoluteDir = PathNormalizer::normalize($dir).'/';
            // @infection-ignore-all UnwrapRtrim is equivalent here: normalize()
            // never returns a trailing slash, so the rtrim only guards the
            // filesystem-root edge case ('/' as project dir).
            $projectBase = null !== $this->projectDir ? \rtrim(PathNormalizer::normalize($this->projectDir), '/').'/' : null;
            // @infection-ignore-all UnwrapRtrim is equivalent here: the only
            // consumer, dirToNamespace(), rtrims its argument again.
            $canonical = null !== $projectBase && \str_starts_with($absoluteDir, $projectBase)
                ? \rtrim(\substr($absoluteDir, \strlen($projectBase)), '/')
                : null;
        } else {
            try {
                $absoluteDir = PathNormalizer::resolveRelativeDirWithin($dir, $this->projectDir);
            } catch (\InvalidArgumentException $e) {
                $io->error(\sprintf('Invalid --dir value "%s": %s', $dir, $e->getMessage()));

                return Command::FAILURE;
            }

            $canonical = PathNormalizer::normalize($dir);
        }

        $filePath = $absoluteDir.$className.'.php';

        if ($this->fs->exists($filePath)) {
            $io->error(\sprintf('File "%s" already exists.', $filePath));

            return Command::FAILURE;
        }

        /** @var string|null $namespaceOverride */
        $namespaceOverride = $input->getOption('namespace');
        if (null !== $namespaceOverride && '' !== $namespaceOverride) {
            // Same per-segment rule as the --dir derivation: the value lands verbatim
            // in the generated file's `namespace` statement, so it must be a clean
            // identifier chain — never raw PHP.
            foreach (\explode('\\', $namespaceOverride) as $segment) {
                if (1 !== \preg_match(self::PHP_LABEL_PATTERN, $segment)) {
                    $io->error(\sprintf('Invalid --namespace value "%s": every segment must be a valid PHP identifier.', $namespaceOverride));

                    return Command::FAILURE;
                }
            }

            $namespace = $namespaceOverride;
        } else {
            if (null === $canonical) {
                $io->error(\sprintf('Cannot derive a namespace from "%s" (outside the project directory); pass --namespace explicitly.', $absoluteDir));

                return Command::FAILURE;
            }

            try {
                $namespace = $this->dirToNamespace($canonical);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $taskIdExport = \var_export($taskId, true);
        $descriptionExport = \var_export($description, true);

        if (null !== $this->templatePath) {
            if (!\is_file($this->templatePath)) {
                $io->error(\sprintf('Configured template "%s" does not exist (soviann_deploy_tasks.generate.template). Fix the path or remove the option to use the built-in stub.', $this->templatePath));

                return Command::FAILURE;
            }

            $fileContent = (string) \file_get_contents($this->templatePath);
            $fileContent = \strtr($fileContent, [
                '{{ namespace }}' => $namespace,
                '{{ className }}' => $className,
                '{{ taskId }}' => $taskIdExport,
                '{{ description }}' => $descriptionExport,
            ]);
        } else {
            $template = <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace __NAMESPACE__;

                use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
                use Soviann\DeployTasksBundle\DeployTaskInterface;
                use Soviann\DeployTasksBundle\TaskResult;
                use Symfony\Component\Console\Output\OutputInterface;

                // Available attribute knobs (uncomment + tweak as needed):
                // #[AsDeployTask(
                //     id: __TASK_ID__,       // optional; auto-derived from FQCN if omitted. Renaming this class rotates the auto-ID.
                //     priority: 0,           // higher runs first
                //     env: 'prod',           // or ['prod', 'staging']; null = all envs
                //     timeout: 60,           // seconds; hard kill for processes run via ProcessRunnerTrait::runProcess()
                //     slowTaskThreshold: 60, // seconds before the runner logs a slow-task warning; null = slow_task_threshold from config
                //     transactional: false,  // opt out of the per-task transaction (only read when storage.transaction_mode is per_task)
                //     groups: 'default',     // or ['a', 'b']; null = default slot
                // )]
                #[AsDeployTask(description: __DESCRIPTION__)]
                final class __CLASS_NAME__ implements DeployTaskInterface
                {
                    // Inject services here if needed:
                    // public function __construct(private readonly SomeService $service) {}

                    public function getDescription(): string
                    {
                        return __DESCRIPTION__;
                    }

                    public function run(OutputInterface $output): TaskResult
                    {
                        // TODO: implement. See docs/creating-tasks.md for the full task-author guide.

                        return TaskResult::SUCCESS;
                    }
                }
                PHP;

            $fileContent = \strtr($template, [
                '__NAMESPACE__' => $namespace,
                '__CLASS_NAME__' => $className,
                '__TASK_ID__' => $taskIdExport,
                '__DESCRIPTION__' => $descriptionExport,
            ]);
        }

        $this->fs->mkdir($absoluteDir, 0755);
        $this->fs->dumpFile($filePath, $fileContent);
        $this->fs->chmod($filePath, 0640);

        $displayPath = PathNormalizer::displayPath($filePath);

        $io->text([
            \sprintf('Generated new deploy task class to "<info>%s</info>"', $displayPath),
            '',
            \sprintf(
                'To run just this task for testing purposes, you can use <info>deploytasks:run --rerun-all --id=%s</info>',
                $taskId,
            ),
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

        foreach ($parts as $segment) {
            if (1 !== \preg_match(self::PHP_LABEL_PATTERN, $segment)) {
                throw new \InvalidArgumentException(\sprintf('Directory "%s" cannot be turned into a valid PHP namespace; use letters/underscores only.', $dir));
            }
        }

        $namespaceParts = \array_map(\ucfirst(...), $parts);

        // A target dir under src/ maps to the configured root namespace (default "App"),
        // mirroring Symfony's conventional "App\" => "src/" PSR-4 autoload mapping so the
        // generated class is autoloadable. See the soviann_deploy_tasks.generate.root_namespace option.
        if ('Src' === $namespaceParts[0]) {
            $namespaceParts[0] = $this->rootNamespace;
        }

        return \implode('\\', $namespaceParts);
    }
}
