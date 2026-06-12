<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\TaskResult;
use Soviann\DeployTasksBundle\Tests\Fixtures\ArrayLogger;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\LoggerTestKernel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * End-to-end: the user's PSR-3 service (resolved via `soviann_deploy_tasks.logger`) actually
 * receives runtime records — companion to LoggerWiringTest which only checks definition
 * wiring at compile time.
 */
final class LoggerIntegrationTest extends FunctionalTestCase
{
    public function testConfiguredLoggerReceivesLifecycleRecords(): void
    {
        self::bootKernel();
        $this->cleanStorage();

        $this->runner()->runAll(new BufferedOutput());

        $logger = self::getContainer()->get('app.array_logger');
        \assert($logger instanceof ArrayLogger);

        self::assertTrue($logger->has('info', 'Deploy tasks run starting'));
        self::assertTrue($logger->has('info', 'Deploy task executed'));
        self::assertTrue($logger->has('info', 'Deploy tasks run finished'));
        // SimpleTask → result SUCCESS, SkippingTask → result SKIPPED; both funnel through
        // the same info message — the `result` context key distinguishes them.
        $executed = $logger->recordsMatching('info', 'Deploy task executed');
        $results = \array_map(static fn (array $r): mixed => $r['context']['result'] ?? null, $executed);
        self::assertContains(TaskResult::SUCCESS->value, $results);
        self::assertContains(TaskResult::SKIPPED->value, $results);
    }

    protected static function getKernelClass(): string
    {
        return LoggerTestKernel::class;
    }
}
