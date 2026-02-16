<?php

namespace App\Service;

use Google\Cloud\Dialogflow\V2\Client\SessionsClient;
use Google\Cloud\Dialogflow\V2\QueryInput;
use Google\Cloud\Dialogflow\V2\TextInput;
use Google\Cloud\Dialogflow\V2\DetectIntentRequest;

class DialogflowService
{
    private $projectId;
    private $credentialsPath;

    public function __construct(string $projectId, string $credentialsPath)
    {
        $this->projectId = $projectId;
        $this->credentialsPath = $credentialsPath;
    }

    public function detectIntent(string $text, string $sessionId = null, string $languageCode = null)
    {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->credentialsPath);
        $sessionId = $sessionId ?: uniqid();
        $sessionsClient = new SessionsClient([
            'credentials' => $this->credentialsPath
        ]);
        $session = $sessionsClient->sessionName($this->projectId, $sessionId);

        $textInput = new TextInput();
        $textInput->setText($text);
        $textInput->setLanguageCode($languageCode ?? 'en');

        $queryInput = new QueryInput();
        $queryInput->setText($textInput);

        $request = new DetectIntentRequest();
        $request->setSession($session);
        $request->setQueryInput($queryInput);
        $response = $sessionsClient->detectIntent($request);
        $queryResult = $response->getQueryResult();
        $sessionsClient->close();

        // Return the full API response as an array
        return json_decode($response->serializeToJsonString(), true);
    }
}
