<?php

namespace GuiBranco\GStracciniBot\Library;

class Configuration
{
    private $logger;

    public function __construct()
    {
        $this->loadLogger();
    }

    private function loadLogger()
    {
        global $loggerUrl, $loggerApiKey, $loggerApiToken;
        if (file_exists(__DIR__ . "/../secrets/logger.secrets.php")) {
            require_once __DIR__ . "/../secrets/logger.secrets.php";
            $this->logger = array(
                "url" => $loggerUrl,
                "apiKey" => $loggerApiKey,
                "apiToken" => $loggerApiToken
            );
        } else {
            $this->logger = array();
        }
    }

    public function getLogger()
    {
        return $this->logger;
    }
}