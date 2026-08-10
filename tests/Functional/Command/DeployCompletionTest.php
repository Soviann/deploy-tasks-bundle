<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksShowCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DeployTasksShowCommand::class)]
#[CoversClass(DeployTasksSkipCommand::class)]
#[CoversClass(DeployTasksResetCommand::class)]
#[CoversClass(DeployTasksRunCommand::class)]
#[CoversClass(DeployTasksStatusCommand::class)]
#[CoversClass(DeployTasksRollupCommand::class)]
#[CoversClass(DeployTasksSkipHostCommand::class)]
#[CoversClass(DeployTasksResetHostCommand::class)]
final class DeployCompletionTest extends FunctionalTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::kernel());
    }

    public function testShowCommandCompletesTaskId(): void
    {
        $command = $this->application->find('deploytasks:show');
        $suggestions = $this->getCompletionSuggestions($command, 'deploytasks:show ');

        self::assertContains('test.simple', $suggestions);
        self::assertContains('test.multi_group', $suggestions);
    }

    public function testSkipCommandCompletesTaskIdAndGroupOption(): void
    {
        $command = $this->application->find('deploytasks:skip');

        $idSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:skip ');
        self::assertContains('test.simple', $idSuggestions);

        $groupSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:skip --group=');
        self::assertContains('predeploy', $groupSuggestions);
        self::assertContains('postdeploy', $groupSuggestions);
    }

    public function testResetCommandCompletesTaskIdAndGroupOption(): void
    {
        $command = $this->application->find('deploytasks:reset');

        $idSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:reset ');
        self::assertContains('test.simple', $idSuggestions);

        $groupSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:reset --group=');
        self::assertContains('predeploy', $groupSuggestions);
    }

    public function testRunCommandCompletesIdOptionAndGroupOption(): void
    {
        $command = $this->application->find('deploytasks:run');

        $idSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:run --id=');
        self::assertContains('test.simple', $idSuggestions);

        $groupSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:run --group=');
        self::assertContains('predeploy', $groupSuggestions);
    }

    public function testStatusCommandCompletesFilterStatusAndGroupOptions(): void
    {
        $command = $this->application->find('deploytasks:status');

        $statusSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:status --filter-status=');
        self::assertContains('RAN', $statusSuggestions);
        self::assertContains('FAILED', $statusSuggestions);
        self::assertContains('SKIPPED', $statusSuggestions);
        self::assertContains('PENDING', $statusSuggestions);

        $groupSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:status --group=');
        self::assertContains('predeploy', $groupSuggestions);
    }

    public function testRollupCommandCompletesGroupOption(): void
    {
        $command = $this->application->find('deploytasks:rollup');

        $groupSuggestions = $this->getCompletionSuggestions($command, 'deploytasks:rollup --group=');
        self::assertContains('predeploy', $groupSuggestions);
    }

    public function testHostSkipAndResetCommandCompleteTaskIds(): void
    {
        $hostTasksDir = \sys_get_temp_dir().'/deploy_host_tasks_completion_'.\uniqid();
        (new Filesystem())->mkdir($hostTasksDir);
        (new Filesystem())->touch($hostTasksDir.'/host_task_1.sh');

        $skipCommand = new DeployTasksSkipHostCommand($hostTasksDir, $hostTasksDir.'/log', $hostTasksDir.'/lock');
        $resetCommand = new DeployTasksResetHostCommand($hostTasksDir, $hostTasksDir.'/log', $hostTasksDir.'/lock');

        $skipSuggestions = $this->getCompletionSuggestions($skipCommand, 'deploytasks:host:skip ');
        $resetSuggestions = $this->getCompletionSuggestions($resetCommand, 'deploytasks:host:reset ');

        self::assertContains('host_task_1', $skipSuggestions);
        self::assertContains('host_task_1', $resetSuggestions);

        (new Filesystem())->remove($hostTasksDir);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @return list<string>
     */
    private function getCompletionSuggestions(Command $command, string $inputString): array
    {
        $tokens = \explode(' ', $inputString);
        if (isset($tokens[1]) && '' === $tokens[1]) {
            $tokens[1] = ' ';
        }

        $input = CompletionInput::fromTokens($tokens, 1);
        $input->bind($command->getDefinition());

        $suggestions = new CompletionSuggestions();
        $command->complete($input, $suggestions);

        return \array_values(\array_map(
            static fn (Suggestion $suggestion): string => $suggestion->getValue(),
            $suggestions->getValueSuggestions(),
        ));
    }
}
