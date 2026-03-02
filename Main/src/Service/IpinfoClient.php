<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IpinfoClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestStack $requestStack,
        private readonly ?string $ipinfoToken = null,
        private readonly ?string $ipinfoTestIp = null,
    ) {
    }

    /**
     * @return array{ip?:string, country?:string, region?:string, city?:string}
     */
    public function lookupCurrentRequest(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }

        $ip = $this->ipinfoTestIp ?: (string) $request->getClientIp();
        if ($ip === '' || $ip === 'unknown') {
            return [];
        }

        if (!$this->ipinfoToken) {
            // Fail open when token is missing; caller handles empty geo.
            return ['ip' => $ip];
        }

        $response = $this->httpClient->request('GET', sprintf('https://ipinfo.io/%s/json', urlencode($ip)), [
            'query' => [
                'token' => $this->ipinfoToken,
            ],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $data = $response->toArray(false);

        $result = [
            'ip' => isset($data['ip']) ? (string) $data['ip'] : $ip,
        ];

        if (isset($data['country']) && $data['country'] !== '') {
            $result['country'] = (string) $data['country'];
        }
        if (isset($data['region']) && $data['region'] !== '') {
            $result['region'] = (string) $data['region'];
        }
        if (isset($data['city']) && $data['city'] !== '') {
            $result['city'] = (string) $data['city'];
        }

        return $result;
    }
}
