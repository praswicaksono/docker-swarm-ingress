<?php

declare(strict_types=1);

namespace PRSW\Ingress\Registry;

interface RegistryManagerInterface
{
    public function init(): void;

    public function onContainerStart(Service $service): void;

    public function onContainerKill(Service $service): void;

    public function onServiceCreate(Service $service): void;

    public function onServiceRemove(Service $service): void;
}
