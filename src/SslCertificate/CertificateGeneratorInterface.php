<?php

declare(strict_types=1);

namespace PRSW\SwarmIngress\SslCertificate;

interface CertificateGeneratorInterface
{
    public function createNewCertificate(string $domain): void;

    public function renew(string $domain): void;
}