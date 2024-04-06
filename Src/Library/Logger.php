<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\GStracciniBot\Library\Configuration;
use GuiBranco\Pancake\Request;

class Logger
{
    private $configuration;

    private $request;

    public function __construct()
    {
        $configuration = new Configuration();
        $this->configuration = $configuration->getLogger();
        $this->request = new Request();
    }

    public function log($message, $details)
    {
        $headers = array(
            USER_AGENT,
            "Content-Type: application/json; charset=UTF-8",
            "X-API-KEY: " . $this->configuration["apiKey"],
            "X-API-TOKEN: " . $this->configuration["apiToken"]
        );

        $trace = debug_backtrace();
        $caller = $trace[1];

        $caller["message"] = $message;
        $caller["details"] = $details;
        $caller["object"] = print_r($caller["object"], true);
        $caller["args"] = print_r($caller["args"], true);

        $body = json_encode($caller);

        $this->request->post($this->configuration["url"] . "log-message", $body, $headers);
    }
}
