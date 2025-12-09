<?php

namespace App\AiAgents;

use LarAgent\Agent;

class leedAgent extends Agent
{
    protected $model = 'gpt-4.1-nano';
    protected $history = 'session';
    protected $provider = 'default';
    protected $temperature = 0.7;
    protected $tools = [];

    protected $apiKey;

    public function __construct($sessionId, $apiKey)
    {
        parent::__construct($sessionId);

        $this->apiKey = $apiKey;

        config(['laragent.providers.default.api_key' => $apiKey]);
    }

    public function instructions()
    {
        return "Act as a friendly and efficient intake assistant for a roofing company.
Your job is to collect customer details naturally, one piece at a time.

---------------------------------------------
DATA TO COLLECT (in order)
---------------------------------------------
1. Full Name
2. Phone Number
3. Email
4. Service Needed
5. Complete Address
6. Preferred Appointment Date & Time

Collect ONE missing detail per message.";
    }

    public function prompt($message)
    {
        return $message;
    }

    // Move ask() logic here
    public function handleMessage($message)
    {
        return $this->ask($message);
    }
}
