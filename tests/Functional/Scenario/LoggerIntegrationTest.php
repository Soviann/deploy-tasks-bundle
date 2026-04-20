<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Soviann\DeployTasksBundle\Tests\Fixtures\ArrayLogger;
use Soviann\DeployTasksBundle\Tests\Functional\LoggerTestKernel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * End-to-end: the user's PSR-3 service (resolved via `deploy_tasks.logger`) actually
 * receives runtime records — companion to LoggerWiringTest which only checks definition
 * wiring at compile time.
 */
final class LoggerIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    public function testConfiguredLoggerReceivesLifecycleRecords(): void
    {
        $kernel = new LoggerTestKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            $storage = $container->get(TaskStorageInterface::class);
            \assert($storage instanceof TaskStorageInterface);
            $storage->reset();

            $runner = $container->get(TaskRunner::class);
            \assert($runner instanceof TaskRunner);

            $runner->runAll(new BufferedOutput());

            $logger = $container->get('app.array_logger');
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
        } finally {
            $kernel->shutdown();
        }
    }
}
