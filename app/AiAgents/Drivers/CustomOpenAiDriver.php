<?php

namespace App\AiAgents\Drivers;

use LarAgent\Drivers\OpenAi\OpenAiDriver;
use OpenAI;
use GuzzleHttp\Client as GuzzleClient;

class CustomOpenAiDriver extends OpenAiDriver
{
    /**
     * Re-initialize the client with SSL verification disabled for local development
     */
    public function __construct(array $provider = [])
    {
        // Call parent constructor to handle settings etc
        parent::__construct($provider);
        
        // Skip re-initialization if no API key is provided
        if (empty($provider['api_key'])) {
            return;
        }

        // Create a Guzzle client that bypasses SSL verification
        $httpClient = new GuzzleClient([
            'verify' => false,
        ]);

        // Re-inject the custom client into the OpenAI instance
        $this->client = OpenAI::factory()
            ->withApiKey($provider['api_key'])
            ->withOrganization($provider['organization'] ?? null)
            ->withProject($provider['project'] ?? null)
            ->withHttpClient($httpClient)
            ->make();
    }
}
