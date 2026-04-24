<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\Tests\Functional\DbalTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksCreateSchemaCommand::class)]
final class DeployCreateSchemaCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:create-schema'));
    }

    public function testCreateSchemaEmitsContextRichInfoMessage(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $display = $this->tester->getDisplay();

        // Must use $io->info(), not $io->success(): SymfonyStyle wraps info in [INFO] block.
        self::assertStringContainsString('[INFO]', $display);

        // Full message format matches the plan spec — strip line-wrapping whitespace for comparison.
        $normalized = (string) \preg_replace('/\s+/', ' ', $display);
        self::assertStringContainsString(
            'Storage table "deploy_task_executions" (columns: error, executed_at, id, status, task_group) was created on default.',
            $normalized,
        );
    }

    public function testColumnsListIsSortedAlphabetically(): void
    {
        $this->tester->execute([]);

        // Normalize whitespace to eliminate SymfonyStyle line-wrapping.
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());

        // Columns must appear in alphabetical order: error, executed_at, id, status, task_group.
        $pos = \strpos($display, 'columns: ');
        self::assertNotFalse($pos);

        $columnsStart = $pos + \strlen('columns: ');
        $columnsEnd = \strpos($display, ')', $columnsStart);
        self::assertNotFalse($columnsEnd);

        $columns = \substr($display, $columnsStart, $columnsEnd - $columnsStart);
        $columnList = \explode(', ', $columns);
        $sorted = $columnList;
        \sort($sorted);

        self::assertSame($sorted, $columnList, 'Columns must be sorted alphabetically.');
    }

    public function testDumpSqlDoesNotEmitInfoMessage(): void
    {
        $this->tester->execute(['--dump-sql' => true]);

        $display = $this->tester->getDisplay();

        self::assertStringNotContainsString('[INFO]', $display);
        self::assertStringNotContainsString('was created on', $display);
        self::assertStringContainsString('CREATE TABLE', $display);
    }

    protected static function getKernelClass(): string
    {
        return DbalTestKernel::class;
    }
}
