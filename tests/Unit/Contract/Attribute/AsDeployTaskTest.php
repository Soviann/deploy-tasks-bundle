<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(AsDeployTask::class)]
final class AsDeployTaskTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attribute = new AsDeployTask(id: 'task.seed');

        self::assertSame('task.seed', $attribute->id);
        self::assertSame(0, $attribute->priority);
        self::assertNull($attribute->env);
        self::assertNull($attribute->timeout);
        self::assertNull($attribute->transactional);
        self::assertNull($attribute->description);
    }

    public function testCustomValues(): void
    {
        $attribute = new AsDeployTask(
            id: 'task.custom',
            priority: 10,
            env: ['prod', 'staging'],
            timeout: 60,
            transactional: true,
            description: 'Seeds the category table',
        );

        self::assertSame('task.custom', $attribute->id);
        self::assertSame(10, $attribute->priority);
        self::assertSame(['prod', 'staging'], $attribute->env);
        self::assertSame(60, $attribute->timeout);
        self::assertTrue($attribute->transactional);
        self::assertSame('Seeds the category table', $attribute->description);
    }

    public function testEnvAsString(): void
    {
        $attribute = new AsDeployTask(id: 'task.prod_only', env: 'prod');

        self::assertSame('prod', $attribute->env);
    }

    public function testEnvAsArray(): void
    {
        $attribute = new AsDeployTask(id: 'task.multi_env', env: ['dev', 'test']);

        self::assertSame(['dev', 'test'], $attribute->env);
    }

    public function testOfReturnsAttribute(): void
    {
        $task = new AttributedTestTask();
        $attribute = AsDeployTask::of($task);

        self::assertNotNull($attribute);
        self::assertSame('test.attributed', $attribute->id);
        self::assertSame(5, $attribute->priority);
        self::assertSame('Test task', $attribute->description);
    }

    public function testOfReturnsNullWhenNoAttribute(): void
    {
        $task = new UnattributedTestTask();
        $attribute = AsDeployTask::of($task);

        self::assertNull($attribute);
    }

    public function testOfCachesResultAcrossCalls(): void
    {
        $first = AsDeployTask::of(AttributedTestTask::class);
        $second = AsDeployTask::of(AttributedTestTask::class);

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    public function testOfCachesNullResultAcrossCalls(): void
    {
        $first = AsDeployTask::of(UnattributedTestTask::class);
        $second = AsDeployTask::of(UnattributedTestTask::class);

        self::assertNull($first);
        self::assertNull($second);
    }

    public function testOfCacheDistinguishesDifferentClasses(): void
    {
        $attributed = AsDeployTask::of(AttributedTestTask::class);
        $unattributed = AsDeployTask::of(UnattributedTestTask::class);

        self::assertNotNull($attributed);
        self::assertNull($unattributed);
    }

    public function testGroupsDefaultsToNull(): void
    {
        $attribute = new AsDeployTask(id: 'task.default');

        self::assertNull($attribute->groups);
    }

    public function testGroupsAcceptsString(): void
    {
        $attribute = new AsDeployTask(id: 'task.pre', groups: 'predeploy');

        self::assertSame('predeploy', $attribute->groups);
    }

    public function testGroupsAcceptsArray(): void
    {
        $attribute = new AsDeployTask(id: 'task.both', groups: ['predeploy', 'postdeploy']);

        self::assertSame(['predeploy', 'postdeploy'], $attribute->groups);
    }

    public function testGroupsOfReturnsNullWhenAttributeMissing(): void
    {
        self::assertNull(AsDeployTask::groupsOf(new UnattributedTestTask()));
    }

    public function testGroupsOfReturnsNullWhenAttributeHasNoGroups(): void
    {
        self::assertNull(AsDeployTask::groupsOf(new AttributedTestTask()));
    }

    public function testGroupsOfNormalisesStringToList(): void
    {
        self::assertSame(['predeploy'], AsDeployTask::groupsOf(new SingleGroupTestTask()));
    }

    public function testGroupsOfReturnsList(): void
    {
        self::assertSame(['predeploy', 'postdeploy'], AsDeployTask::groupsOf(new MultiGroupTestTask()));
    }

    public function testGroupsOfReIndexesNonSequentialArrayKeys(): void
    {
        // Simulate an array with non-sequential keys (e.g. produced by array_filter or unset).
        // array_values() in groupsOf() must re-index — without it the mutated code returns
        // the original keyed array, which is not a list.
        $task = new GroupsWithGapKeysTask();
        $result = AsDeployTask::groupsOf($task);

        self::assertNotNull($result);
        self::assertSame(['predeploy', 'postdeploy'], $result);
        // Keys must be 0 and 1 (list), not the string keys from the attribute.
        self::assertSame(0, \array_key_first($result));
        self::assertSame(1, \array_key_last($result));
    }

    public function testEnvsOfReturnsNullWhenAttributeMissing(): void
    {
        self::assertNull(AsDeployTask::envsOf(new UnattributedTestTask()));
    }

    public function testEnvsOfReturnsNullWhenAttributeHasNoEnv(): void
    {
        self::assertNull(AsDeployTask::envsOf(new AttributedTestTask()));
    }

    public function testEnvsOfNormalisesStringToList(): void
    {
        self::assertSame(['prod'], AsDeployTask::envsOf(new SingleEnvTestTask()));
    }

    public function testEnvsOfReturnsList(): void
    {
        self::assertSame(['dev', 'test'], AsDeployTask::envsOf(new MultiEnvTestTask()));
    }

    public function testEnvsOfReIndexesNonSequentialArrayKeys(): void
    {
        // Simulate an array with non-sequential keys (e.g. produced by array_filter or unset).
        // array_values() in envsOf() must re-index — without it the mutated code returns
        // the original keyed array, which is not a list.
        $task = new EnvWithGapKeysTask();
        $result = AsDeployTask::envsOf($task);

        self::assertNotNull($result);
        self::assertSame(['dev', 'prod'], $result);
        // Keys must be 0 and 1 (list), not the gap keys from the attribute.
        self::assertSame(0, \array_key_first($result));
        self::assertSame(1, \array_key_last($result));
    }

    public function testTimeoutOfReturnsDeclaredTimeout(): void
    {
        self::assertSame(120, AsDeployTask::timeoutOf(TimeoutDeclaringTask::class));
    }

    public function testTimeoutOfReturnsNullWhenAttributeAbsent(): void
    {
        self::assertNull(AsDeployTask::timeoutOf(PlainTaskWithoutAttribute::class));
    }

    public function testTimeoutOfReturnsNullWhenTimeoutNotDeclared(): void
    {
        self::assertNull(AsDeployTask::timeoutOf(TimeoutlessAttributedTask::class));
    }

    public function testRejectsIdWithDisallowedCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid task id/');

        new AsDeployTask(id: "evil/../\x1b[2Jid");
    }

    public function testAcceptsEmptyIdAsAutoDeductionSentinel(): void
    {
        self::assertSame('', (new AsDeployTask())->id);
    }

    public function testRejectsIdWithTrailingNewline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid task id/');

        new AsDeployTask(id: "abc\n");
    }

    #[DataProvider('invalidGroupNameProvider')]
    public function testConstructorRejectsGroupsContainingDisallowedCharacters(string $group): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid group name/');

        new AsDeployTask(id: 'task.bad-group', groups: $group);
    }

    public function testConstructorRejectsGroupWithTrailingNewline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid group name/');

        new AsDeployTask(id: 'task.bad-group', groups: "abc\n");
    }

    public function testConstructorRejectsBadEntryInsideGroupArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid group name/');

        new AsDeployTask(id: 'task.mixed', groups: ['predeploy', 'a/b']);
    }

    public function testConstructorRejectsDuplicateGroups(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate group "predeploy"/');

        new AsDeployTask(id: 'task.dup-group', groups: ['predeploy', 'predeploy']);
    }

    public function testConstructorRejectsGroupsDifferingOnlyByCase(): void
    {
        // (id, Predeploy) and (id, predeploy) are distinct slots to the registry
        // but one single key to a case-insensitive backend (MySQL *_ci collation,
        // APFS/NTFS file name) — the record of one slot would silently shadow
        // the other, so the declaration is rejected outright.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"Predeploy" and "predeploy".*letter case/');

        new AsDeployTask(id: 'task.case-group', groups: ['Predeploy', 'predeploy']);
    }

    public function testConstructorRejectsEmptyEnvArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/env cannot be an empty array/');

        new AsDeployTask(id: 'task.bad-env', env: []);
    }

    public function testConstructorRejectsBadEntryInsideEnvArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/env entries must be strings/');

        // @phpstan-ignore argument.type (intentional wrong type for validation test)
        new AsDeployTask(id: 'task.mixed-env', env: [123]);
    }

    public function testRejectsNegativeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid timeout/');

        new AsDeployTask(id: 'task.bad-timeout', timeout: -1);
    }

    public function testAcceptsZeroTimeoutAsLegalValue(): void
    {
        self::assertSame(0, (new AsDeployTask(id: 'task.zero-timeout', timeout: 0))->timeout);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGroupNameProvider(): iterable
    {
        yield 'slash separator' => ['a/b'];
        yield 'path traversal' => ['../etc/passwd'];
        yield 'whitespace' => ['pre deploy'];
        yield 'shell metacharacter' => ['pre;rm'];
        yield 'empty string' => [''];
    }
}

/** Task whose attribute has explicit non-sequential integer keys in the groups array. */
#[AsDeployTask(id: 'test.gap_keys', groups: [0 => 'predeploy', 5 => 'postdeploy'])]
final class GroupsWithGapKeysTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Gap keys task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

/** Task whose attribute has explicit non-sequential integer keys in the env array. */
#[AsDeployTask(id: 'test.env_gap_keys', env: [0 => 'dev', 5 => 'prod'])]
final class EnvWithGapKeysTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Env gap keys task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.single_env', env: 'prod')]
final class SingleEnvTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Single env';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.multi_env_local', env: ['dev', 'test'])]
final class MultiEnvTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Multi env';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.single_group', groups: 'predeploy')]
final class SingleGroupTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Single group';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.multi_group', groups: ['predeploy', 'postdeploy'])]
final class MultiGroupTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Multi group';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.attributed', priority: 5, description: 'Test task')]
final class AttributedTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Test task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

final class UnattributedTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Unattributed test task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.timeout_declaring', timeout: 120)]
final class TimeoutDeclaringTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Timeout declaring task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

final class PlainTaskWithoutAttribute implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Plain task without attribute';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.timeoutless')]
final class TimeoutlessAttributedTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Timeoutless attributed task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
