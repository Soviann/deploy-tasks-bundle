<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Support;

use Symfony\Component\HttpKernel\Kernel;

/**
 * Base `framework` config shared by the throwaway test kernels.
 *
 * `handle_all_throwables` only exists from Symfony 6.2 on; on 5.4 LTS the option
 * is unknown and would fail config validation, so it is added conditionally.
 */
final class FrameworkTestConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function base(): array
    {
        $config = [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'php_errors' => ['log' => true],
        ];

        if (Kernel::VERSION_ID >= 60200) {
            $config['handle_all_throwables'] = true;
        }

        return $config;
    }
}
