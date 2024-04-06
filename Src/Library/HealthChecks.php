<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Request;

class HealthChecks
{
    public const BASE_URL = "https://hc-ping.com/";

    private const START_ENDPOINT = "/start";

    private const FAIL_ENDPOINT = "/fail";

    private $token;

    private $request;

    private $failed = false;

    private $headers;

    public function __construct($token)
    {
        $this->token = $token;
        $this->request = new Request();
        $this->headers = array("User-Agent: " . USER_AGENT);
    }

    public function start()
    {
        $this->request->get(self::BASE_URL . $this->token . self::START_ENDPOINT, $this->headers);
    }

    public function fail()
    {
        $this->failed = true;
        $this->request->get(self::BASE_URL . $this->token . self::FAIL_ENDPOINT, $this->headers);
    }

    public function error($errorMessage)
    {
        $this->failed = true;
        $this->request->post(self::BASE_URL . $this->token . self::FAIL_ENDPOINT, $errorMessage, $this->headers);

    }

    public function end()
    {
        if ($this->failed) {
            $this->request->get(self::BASE_URL . $this->token . self::FAIL_ENDPOINT, $this->headers);
            return;
        }
        $this->request->get(self::BASE_URL . $this->token, $this->headers);
    }

    public function resetState()
    {
        $this->failed = false;
    }
}
