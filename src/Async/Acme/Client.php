<?php

declare(strict_types=1);

namespace PRSW\Ingress\Async\Acme;

use AcmePhp\Core\AcmeClientInterface;
use AcmePhp\Core\Exception\AcmeCoreClientException;
use AcmePhp\Core\Exception\AcmeCoreServerException;
use AcmePhp\Core\Exception\Protocol\CertificateRequestFailedException;
use AcmePhp\Core\Exception\Protocol\CertificateRevocationException;
use AcmePhp\Core\Exception\Protocol\ChallengeFailedException;
use AcmePhp\Core\Exception\Protocol\ChallengeNotSupportedException;
use AcmePhp\Core\Exception\Protocol\ChallengeTimedOutException;
use AcmePhp\Core\Http\SecureHttpClient;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use AcmePhp\Core\Protocol\CertificateOrder;
use AcmePhp\Core\Protocol\ExternalAccount;
use AcmePhp\Core\Protocol\ResourcesDirectory;
use AcmePhp\Core\Protocol\RevocationReason;
use AcmePhp\Ssl\Certificate;
use AcmePhp\Ssl\CertificateRequest;
use AcmePhp\Ssl\CertificateResponse;
use AcmePhp\Ssl\Signer\CertificateRequestSigner;
use GuzzleHttp\Psr7\Utils;
use Psl\DateTime\Duration;
use Webmozart\Assert\Assert;

use function Psl\Async\sleep;

final class Client implements AcmeClientInterface
{
    private SecureHttpClient $uninitializedHttpClient;

    private ?SecureHttpClient $initializedHttpClient = null;

    private CertificateRequestSigner $csrSigner;

    private string $directoryUrl;

    private ?ResourcesDirectory $directory = null;

    private string $account;

    public function __construct(SecureHttpClient $httpClient, string $directoryUrl, ?CertificateRequestSigner $csrSigner = null)
    {
        $this->uninitializedHttpClient = $httpClient;
        $this->directoryUrl = $directoryUrl;
        $this->csrSigner = $csrSigner ?: new CertificateRequestSigner();
    }

    /**
     * @return array<string, mixed>
     */
    public function registerAccount(?string $email = null, ?ExternalAccount $externalAccount = null): array
    {
        $client = $this->getHttpClient();

        $payload = [
            'termsOfServiceAgreed' => true,
            'contact' => [],
        ];

        if ($email) {
            $payload['contact'][] = 'mailto:'.$email;
        }

        if ($externalAccount) {
            $payload['externalAccountBinding'] = $client->createExternalAccountPayload(
                $externalAccount,
                $this->getResourceUrl(ResourcesDirectory::NEW_ACCOUNT)
            );
        }

        $this->requestResource('POST', ResourcesDirectory::NEW_ACCOUNT, $payload);
        $account = $this->getResourceAccount();

        return $client->request('POST', $account, $client->signKidPayload($account, $account, null));
    }

    public function requestOrder(array $domains): CertificateOrder
    {
        Assert::allStringNotEmpty($domains, 'requestOrder::$domains expected a list of strings. Got: %s');

        $payload = [
            'identifiers' => array_map(
                static function ($domain) {
                    return [
                        'type' => 'dns',
                        'value' => $domain,
                    ];
                },
                array_values($domains)
            ),
        ];

        $client = $this->getHttpClient();
        $resourceUrl = $this->getResourceUrl(ResourcesDirectory::NEW_ORDER);
        $response = $client->request('POST', $resourceUrl, $client->signKidPayload($resourceUrl, $this->getResourceAccount(), $payload));
        if (!isset($response['authorizations']) || !$response['authorizations']) {
            throw new ChallengeNotSupportedException();
        }

        $authorizationsChallenges = [];
        $orderEndpoint = $client->getLastLocation();
        foreach ($response['authorizations'] as $authorizationEndpoint) {
            $authorizationsResponse = $client->request('POST', $authorizationEndpoint, $client->signKidPayload($authorizationEndpoint, $this->getResourceAccount(), null));
            $domain = (empty($authorizationsResponse['wildcard']) ? '' : '*.').$authorizationsResponse['identifier']['value'];
            foreach ($authorizationsResponse['challenges'] as $challenge) {
                $authorizationsChallenges[$domain][] = $this->createAuthorizationChallenge($authorizationsResponse['identifier']['value'], $challenge);
            }
        }

        return new CertificateOrder($authorizationsChallenges, $orderEndpoint, $response['status']);
    }

    public function reloadOrder(CertificateOrder $order): CertificateOrder
    {
        $client = $this->getHttpClient();
        $orderEndpoint = $order->getOrderEndpoint();
        $response = $client->request('POST', $orderEndpoint, $client->signKidPayload($orderEndpoint, $this->getResourceAccount(), null));

        if (!isset($response['authorizations']) || !$response['authorizations']) {
            throw new ChallengeNotSupportedException();
        }

        $authorizationsChallenges = [];
        foreach ($response['authorizations'] as $authorizationEndpoint) {
            $authorizationsResponse = $client->request('POST', $authorizationEndpoint, $client->signKidPayload($authorizationEndpoint, $this->getResourceAccount(), null));
            $domain = (empty($authorizationsResponse['wildcard']) ? '' : '*.').$authorizationsResponse['identifier']['value'];
            foreach ($authorizationsResponse['challenges'] as $challenge) {
                $authorizationsChallenges[$domain][] = $this->createAuthorizationChallenge($authorizationsResponse['identifier']['value'], $challenge);
            }
        }

        return new CertificateOrder($authorizationsChallenges, $orderEndpoint, $response['status']);
    }

    public function finalizeOrder(CertificateOrder $order, CertificateRequest $csr, int $timeout = 180, bool $returnAlternateCertificateIfAvailable = false): CertificateResponse
    {
        $endTime = time() + $timeout;
        $client = $this->getHttpClient();
        $orderEndpoint = $order->getOrderEndpoint();
        $response = $client->request('POST', $orderEndpoint, $client->signKidPayload($orderEndpoint, $this->getResourceAccount(), null));
        if (\in_array($response['status'], ['pending', 'processing', 'ready'])) {
            $humanText = ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----'];

            $csrContent = $this->csrSigner->signCertificateRequest($csr);
            $csrContent = trim(str_replace($humanText, '', $csrContent));
            $csrContent = trim($client->getBase64Encoder()->encode(base64_decode($csrContent)));

            $response = $client->request('POST', $response['finalize'], $client->signKidPayload($response['finalize'], $this->getResourceAccount(), ['csr' => $csrContent]));
        }

        // Waiting loop
        while (time() <= $endTime && (!isset($response['status']) || \in_array($response['status'], ['pending', 'processing', 'ready']))) {
            sleep(Duration::seconds(1));
            $response = $client->request('POST', $orderEndpoint, $client->signKidPayload($orderEndpoint, $this->getResourceAccount(), null));
        }

        if ('valid' !== $response['status']) {
            throw new CertificateRequestFailedException('The order has not been validated');
        }

        $response = $client->rawRequest('POST', $response['certificate'], $client->signKidPayload($response['certificate'], $this->getResourceAccount(), null));
        $responseHeaders = $response->getHeaders();

        if ($returnAlternateCertificateIfAvailable && isset($responseHeaders['Link'][1])) {
            $matches = [];
            preg_match('/<(http.*)>;rel="alternate"/', $responseHeaders['Link'][1], $matches);

            // If response headers include a valid alternate certificate link, return that certificate instead
            if (isset($matches[1])) {
                return $this->createCertificateResponse(
                    $csr,
                    $client->request('POST', $matches[1], $client->signKidPayload($matches[1], $this->getResourceAccount(), null), false)
                );
            }
        }

        return $this->createCertificateResponse($csr, Utils::copyToString($response->getBody()));
    }

    public function requestAuthorization(string $domain): array
    {
        $order = $this->requestOrder([$domain]);

        try {
            return $order->getAuthorizationChallenges($domain);
        } catch (AcmeCoreClientException $e) {
            throw new ChallengeNotSupportedException();
        }
    }

    public function reloadAuthorization(AuthorizationChallenge $challenge): AuthorizationChallenge
    {
        $client = $this->getHttpClient();
        $challengeUrl = $challenge->getUrl();
        $response = (array) $client->request('POST', $challengeUrl, $client->signKidPayload($challengeUrl, $this->getResourceAccount(), null));

        return $this->createAuthorizationChallenge($challenge->getDomain(), $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function challengeAuthorization(AuthorizationChallenge $challenge, int $timeout = 180): array
    {
        $endTime = time() + $timeout;
        $client = $this->getHttpClient();
        $challengeUrl = $challenge->getUrl();
        $response = (array) $client->request('POST', $challengeUrl, $client->signKidPayload($challengeUrl, $this->getResourceAccount(), null));
        if ('pending' === $response['status'] || 'processing' === $response['status']) {
            $response = (array) $client->request('POST', $challengeUrl, $client->signKidPayload($challengeUrl, $this->getResourceAccount(), []));
        }

        // Waiting loop
        while (time() <= $endTime && (!isset($response['status']) || 'pending' === $response['status'] || 'processing' === $response['status'])) {
            sleep(Duration::seconds(1));
            $response = (array) $client->request('POST', $challengeUrl, $client->signKidPayload($challengeUrl, $this->getResourceAccount(), null));
        }

        if (isset($response['status']) && ('pending' === $response['status'] || 'processing' === $response['status'])) {
            throw new ChallengeTimedOutException($response);
        }
        if (!isset($response['status']) || 'valid' !== $response['status']) {
            throw new ChallengeFailedException($response);
        }

        return $response;
    }

    public function requestCertificate(string $domain, CertificateRequest $csr, int $timeout = 180, bool $returnAlternateCertificateIfAvailable = false): CertificateResponse
    {
        $order = $this->requestOrder(array_unique(array_merge([$domain], $csr->getDistinguishedName()->getSubjectAlternativeNames())));

        return $this->finalizeOrder($order, $csr, $timeout, $returnAlternateCertificateIfAvailable);
    }

    public function revokeCertificate(Certificate $certificate, ?RevocationReason $revocationReason = null): void
    {
        if (!$endpoint = $this->getResourceUrl(ResourcesDirectory::REVOKE_CERT)) {
            throw new CertificateRevocationException('This ACME server does not support certificate revocation.');
        }

        if (null === $revocationReason) {
            $revocationReason = RevocationReason::createDefaultReason();
        }

        openssl_x509_export(openssl_x509_read($certificate->getPEM()), $formattedPem);

        $formattedPem = str_ireplace('-----BEGIN CERTIFICATE-----', '', $formattedPem);
        $formattedPem = str_ireplace('-----END CERTIFICATE-----', '', $formattedPem);
        $client = $this->getHttpClient();
        $formattedPem = $client->getBase64Encoder()->encode(base64_decode(trim($formattedPem)));

        try {
            $client->request(
                'POST',
                $endpoint,
                $client->signKidPayload($endpoint, $this->getResourceAccount(), ['certificate' => $formattedPem, 'reason' => $revocationReason->getReasonType()]),
                false
            );
        } catch (AcmeCoreClientException|AcmeCoreServerException $e) {
            throw new CertificateRevocationException($e->getMessage(), $e);
        }
    }

    /**
     * Find a resource URL from the Certificate Authority.
     */
    public function getResourceUrl(string $resource): string
    {
        if (!$this->directory) {
            $this->directory = new ResourcesDirectory(
                $this->getHttpClient()->request('GET', $this->directoryUrl)
            );
        }

        return $this->directory->getResourceUrl($resource);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestResource(string $method, string $resource, array $payload, bool $returnJson = true): void
    {
        $client = $this->getHttpClient();
        $endpoint = $this->getResourceUrl($resource);

        $client->request(
            $method,
            $endpoint,
            $client->signJwkPayload($endpoint, $payload),
            $returnJson
        );
    }

    private function createCertificateResponse(CertificateRequest $csr, string $certificate): CertificateResponse
    {
        $certificateHeader = '-----BEGIN CERTIFICATE-----';
        $certificatesChain = null;

        foreach (array_reverse(explode($certificateHeader, $certificate)) as $pem) {
            if ('' !== \trim($pem)) {
                $certificatesChain = new Certificate($certificateHeader.$pem, $certificatesChain);
            }
        }

        return new CertificateResponse($csr, $certificatesChain);
    }

    private function getResourceAccount(): string
    {
        if (!$this->account) {
            $payload = [
                'onlyReturnExisting' => true,
            ];

            $this->requestResource('POST', ResourcesDirectory::NEW_ACCOUNT, $payload);
            $this->account = $this->getHttpClient()->getLastLocation();
        }

        return $this->account;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function createAuthorizationChallenge(string $domain, array $response): AuthorizationChallenge
    {
        $base64encoder = $this->getHttpClient()->getBase64Encoder();

        return new AuthorizationChallenge(
            $domain,
            $response['status'],
            $response['type'],
            $response['url'],
            $response['token'],
            $response['token'].'.'.$base64encoder->encode($this->getHttpClient()->getJWKThumbprint())
        );
    }

    private function getHttpClient(): SecureHttpClient
    {
        if (!$this->initializedHttpClient) {
            $this->initializedHttpClient = $this->uninitializedHttpClient;
            $this->initializedHttpClient->setNonceEndpoint($this->getResourceUrl(ResourcesDirectory::NEW_NONCE));
        }

        return $this->initializedHttpClient;
    }
}
