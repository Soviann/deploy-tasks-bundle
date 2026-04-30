<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Marker interface implemented by every exception thrown from this bundle.
 *
 * Catch this to handle any bundle-originated error uniformly:
 *
 *     try {
 *         $runner->run(...);
 *     } catch (DeployTasksExceptionInterface $e) {
 *         // bundle-thrown — bundle owns the contract
 *     }
 */
interface DeployTasksExceptionInterface extends \Throwable
{
}
