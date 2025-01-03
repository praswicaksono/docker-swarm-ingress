<?php

declare(strict_types=1);

namespace PRSW\SwarmIngress;

use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\AcmeClientInterface;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Http\SecureHttpClientFactory;
use AcmePhp\Core\Http\ServerErrorHandler;
use AcmePhp\Core\Protocol\ExternalAccount;
use AcmePhp\Ssl\Generator\KeyPairGenerator;
use AcmePhp\Ssl\KeyPair;
use AcmePhp\Ssl\Parser\KeyParser;
use AcmePhp\Ssl\PrivateKey;
use AcmePhp\Ssl\PublicKey;
use AcmePhp\Ssl\Signer\DataSigner;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\ClientInterface;
use PRSW\Docker\Client;
use PRSW\SwarmIngress\Lock\LockInterface;
use PRSW\SwarmIngress\Lock\SwooleMutex;
use PRSW\SwarmIngress\Registry\Nginx\Registry;
use PRSW\SwarmIngress\Registry\RegistryInterface;
use PRSW\SwarmIngress\Registry\RegistryManager;
use PRSW\SwarmIngress\Registry\RegistryManagerInterface;
use PRSW\SwarmIngress\Store\FileStorage;
use PRSW\SwarmIngress\Store\StorageInterface;
use PRSW\SwarmIngress\TableCache\ConfigTable;
use PRSW\SwarmIngress\TableCache\ServiceTable;
use PRSW\SwarmIngress\TableCache\SslCertificateTable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ContainerDefinition
{
    /**
     * @return array<string, mixed>
     */
    public static function getDefinition(): array
    {
        return [
            'registry' => $_ENV['registry'] ?? 'nginx',
            'storage' => $_ENV['storage'] ?? 'file',
            'storage.options' => [
                'path' => __DIR__.'/../data',
            ],
            'docker.client.options' => [
                'max_duration' => -1,
                'timeout' => -1,
            ],
            'service.table.options' => [
                'table_row_size' => 1024,
                'upstream_size' => 512000,
            ],
            'nginx.options' => [
                'nginx_conf_path' => $_ENV['NGINX_CONF_PATH'] ?? '/app/data/nginx/nginx.conf',
                'nginx_vhost_dir' => $_ENV['NGINX_VHOST_DIR'] ?? '/app/data/nginx/sites-enabled',
                'nginx_vhost_ssl_key_path' => $_ENV['NGINX_VHOST_SSL_KEY_PATH'] ?? '/app/data/nginx/ssl/%s/private-key.pem',
                'nginx_vhost_ssl_certificate_path' => $_ENV['NGINX_VHOST_SSL_CERTIFICATE_PATH'] ?? '/app/data/nginx/ssl/%s/fullchain.pem',
                'options' => [
                    'client_max_body_size' => $_ENV['NGINX_CLIENT_MAX_BODY_SIZE'] ?? '16M',
                    'worker_connections' => $_ENV['NGINX_CLIENT_MAX_CONNECTIONS'] ?? 65535,
                ],
            ],
            'self_signed.options' => [
                'ca' => $_ENV['SELF_SIGNED_CA'],
            ],
            'acme.options' => [
                'email' => $_ENV['ACME_EMAIL'] ?? 'admin@localhost',
                'directory_url' => $_ENV['ACME_DIRECTORY_URL'] ?? 'https://acme-v02.api.letsencrypt.org/directory',
                'external_account' => [
                    'id' => $_ENV['ACME_EXTERNAL_ACCOUNT_ID'],
                    'key' => $_ENV['ACME_EXTERNAL_ACCOUNT_KEY'],
                ],
                'max_sanity_check_tries' => $_ENV['ACME_MAX_SANITY_CHECK_MAX_TRIES'] ?? 5,
                'sanity_check_interval' => $_ENV['ACME_SANITY_CHECK_INTERVAL'] ?? 60,
            ],
            // @phpstan-ignore-next-line
            RegistryInterface::class => static fn (ContainerInterface $c) => match (true) {
                'nginx' === $c->get('registry') => $c->get(Registry::class)
            },
            // @phpstan-ignore-next-line
            StorageInterface::class => static fn (ContainerInterface $c) => match (true) {
                'file' === $c->get('storage') => $c->get(FileStorage::class)
            },
            // @phpstan-ignore-next-line
            LockInterface::class => static fn (ContainerInterface $c) => match (true) {
                'file' === $c->get('storage') => $c->get(SwooleMutex::class)
            },
            ClientInterface::class => static fn (ContainerInterface $c) => new GuzzleHttpClient(),
            AcmeClientInterface::class => static function (ContainerInterface $c) {
                $options = $c->get('acme.options');
                $factory = new SecureHttpClientFactory(
                    $c->get(ClientInterface::class),
                    new Base64SafeEncoder(),
                    new KeyParser(),
                    new DataSigner(),
                    new ServerErrorHandler()
                );
                $httpClient = $factory->createSecureHttpClient($c->get(KeyPair::class));
                $acme = new AcmeClient($httpClient, $options['directory_url']);
                $externalAccount = null;
                if (null !== $options['external_account']['id'] && null !== $options['external_account']['key']) {
                    $externalAccount = new ExternalAccount($options['external_account']['id'], $options['external_account']['key']);
                }

                $acme->registerAccount($options['email'], $externalAccount);

                return $acme;
            },
            RegistryManagerInterface::class => static fn (ContainerInterface $c) => $c->get(RegistryManager::class),
            LoggerInterface::class => static function () {
                $output = new ConsoleOutput();
                match ((int) getenv('SHELL_VERBOSITY')) {
                    -2 => $output->setVerbosity(OutputInterface::VERBOSITY_SILENT),
                    -1 => $output->setVerbosity(OutputInterface::VERBOSITY_QUIET),
                    1 => $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE),
                    2 => $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE),
                    3 => $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG),
                    default => new ConsoleLogger($output),
                };

                return new ConsoleLogger($output);
            },
            Client::class => static fn (ContainerInterface $c) => Client::withHttpClient(options: $c->get('docker.client.options')),
            Environment::class => static function (ContainerInterface $c) {
                $loader = new FilesystemLoader(__DIR__.'/../templates');

                return new Environment($loader);
            },
            ServiceTable::class => static function (ContainerInterface $c) {
                $config = $c->get('service.table.options');

                return ServiceTable::createTable(
                    $c->get(StorageInterface::class),
                    $config['table_row_size'],
                    $config['upstream_size']
                );
            },
            SslCertificateTable::class => static fn (ContainerInterface $c) => SslCertificateTable::createTable(
                $c->get(StorageInterface::class)
            ),
            ConfigTable::class => static fn (ContainerInterface $c) => ConfigTable::createTable(
                $c->get(StorageInterface::class)
            ),
            KeyPair::class => static function (ContainerInterface $c) {
                /** @var ConfigTable $configTable */
                $configTable = $c->get(ConfigTable::class);

                if (!$configTable->exist('acme.private_key') || !$configTable->exist('acme.public_key')) {
                    $k = new KeyPairGenerator();
                    $pair = $k->generateKeyPair();
                    $configTable->set('acme.private_key', ['value' => $pair->getPrivateKey()->getDER()]);
                    $configTable->set('acme.public_key', ['value' => $pair->getPublicKey()->getDER()]);

                    return $pair;
                }

                return new KeyPair(
                    PublicKey::fromDER($configTable->get('acme.public_key', 'value')),
                    PrivateKey::fromDER($configTable->get('acme.private_key', 'value'))
                );
            },
        ];
    }
}
