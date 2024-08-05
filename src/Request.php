<?php

namespace Ups;

use DateTime;
use Exception;
use GuzzleHttp\Client as Guzzle;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleXMLElement;
use Ups\Exception\InvalidResponseException;
use Ups\Exception\RequestException;
use \Illuminate\Support\Facades\Session;
use \Illuminate\Support\Facades\Config;
class Request implements RequestInterface, LoggerAwareInterface
{
    /**
     * @var string
     */
    protected $access;

    /**
     * @var string
     */
    protected $request;

    /**
     * @var string
     */
    protected $endpointUrl;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Guzzle
     */
    protected $client;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger = null)
    {
        if ($logger !== null) {
            $this->setLogger($logger);
        } else {
            $this->setLogger(new NullLogger);
        }

        $this->setClient();
    }

    private function getToken()
    {
        if (Session::has('ups_token') && Session::get('ups_token_time') > time()) {
            return Session::get('ups_token');
        }
        $publicKey = Config::get('ups.public_key');
        $privateKey = Config::get('ups.private_key');

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://wwwcie.ups.com/security/v1/oauth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($publicKey . ':' . $privateKey)
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $response = json_decode($response, true);
        Session::set('ups_token', $response['access_token']);
        Session::set('ups_token_time', time() + $response['expires_in'] - 10);
        return $response['access_token'];
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     *
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Creates a single instance of the Guzzle client
     *
     * @return null
     */
    public function setClient()
    {
        $this->client = new Guzzle();
    }

    /**
     * Send request to UPS.
     *
     * @param string $access The access request xml
     * @param string $request The request xml
     * @param string $endpointurl The UPS API Endpoint URL
     *
     * @throws Exception
     *                   todo: make access, request and endpointurl nullable to make the testable
     *
     * @return ResponseInterface
     */
    public function request($access, $request, $endpointurl)
    {
        $this->setAccess($access);
        $this->setRequest($request);
        $this->setEndpointUrl($endpointurl);

        // Log request
        $date = new DateTime();
        $id = $date->format('YmdHisu');
        $this->logger->info('Request To UPS API', [
            'id' => $id,
            'endpointurl' => $this->getEndpointUrl(),
        ]);

        $this->logger->debug('Request: ' . $this->getRequest(), [
            'id' => $id,
            'endpointurl' => $this->getEndpointUrl(),
        ]);

        try {
            $response = $this->client->post(
                $this->getEndpointUrl(),
                [
                    'body' => $this->getRequest(),
                    'headers' => [
                        'Content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
                        'Accept-Charset' => 'UTF-8',
                        'Authorization' => 'Bearer ' .  $this->getToken()
                    ],
                    'http_errors' => true,
                ]
            );

            $body = (string)$response->getBody();

            $this->logger->info('Response from UPS API', [
                'id' => $id,
                'endpointurl' => $this->getEndpointUrl(),
            ]);

            $this->logger->debug('Response: ' . $body, [
                'id' => $id,
                'endpointurl' => $this->getEndpointUrl(),
            ]);

            if ($response->getStatusCode() === 200) {
                $body = $this->convertEncoding($body);

                $xml = new SimpleXMLElement($body);
                if (isset($xml->Response) && isset($xml->Response->ResponseStatusCode)) {
                    if ($xml->Response->ResponseStatusCode == 1) {
                        $responseInstance = new Response();

                        return $responseInstance->setText($body)->setResponse($xml);
                    } elseif ($xml->Response->ResponseStatusCode == 0) {
                        throw new InvalidResponseException('Failure: ' . $xml->Response->Error->ErrorDescription . ' (' . $xml->Response->Error->ErrorCode . ')');
                    }
                } else {
                    throw new InvalidResponseException('Failure: response is in an unexpected format.');
                }
            }
        } catch (\GuzzleHttp\Exception\TransferException $e) { // Guzzle: All of the exceptions extend from GuzzleHttp\Exception\TransferException
            $this->logger->alert($e->getMessage(), [
                'id' => $id,
                'endpointurl' => $this->getEndpointUrl(),
            ]);

            throw new RequestException('Failure: ' . $e->getMessage());
        }
    }

    /**
     * @param $access
     *
     * @return $this
     */
    public function setAccess($access)
    {
        $this->access = $access;

        return $this;
    }

    /**
     * @return string
     */
    public function getAccess()
    {
        return $this->access;
    }

    /**
     * @param $request
     *
     * @return $this
     */
    public function setRequest($request)
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @return string
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param $endpointUrl
     *
     * @return $this
     */
    public function setEndpointUrl($endpointUrl)
    {
        $this->endpointUrl = $endpointUrl;

        return $this;
    }

    /**
     * @return string
     */
    public function getEndpointUrl()
    {
        return $this->endpointUrl;
    }

    /**
     * @param $body
     * @return string
     */
    protected function convertEncoding($body)
    {
        if (!function_exists('mb_convert_encoding')) {
            return $body;
        }

        $encoding = mb_detect_encoding($body);
        if ($encoding) {
            return mb_convert_encoding($body, 'UTF-8', $encoding);
        }

        return utf8_encode($body);
    }
}
