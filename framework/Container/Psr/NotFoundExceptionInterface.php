<?php
declare(strict_types=1);

namespace Framework\Container\Psr;

/**
 * PSR-11 Compatible Not Found Exception Interface
 *
 * No entry was found in the container.
 */
interface NotFoundExceptionInterface extends ContainerExceptionInterface
{
}