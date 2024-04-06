<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Request;

class HealthChecks
{
    const BASE_URL = "https://hc-ping.com/";

    private $token;

    private $request;

    private $failed = false;

    public function __construct($token)
    {
        $this->token = $token;
        $this->request = new Request();
    }

    public function start()
    {
        $this->request->get(self::BASE_URL . $this->token . "/start", array(USER_AGENT));
    }

    public function fail()
    {
        $this->failed = true;
        $this->request->get(self::BASE_URL . $this->token . "/fail", array(USER_AGENT));
    }

    public function error($errorMessage)
    {
        $this->failed = true;
        $this->request->post(self::BASE_URL . $this->token . "/fail", $errorMessage, array(USER_AGENT));

    }

    public function end()
    {
        if ($this->failed) {
            $this->request->get(self::BASE_URL . $this->token . "/fail", array(USER_AGENT));
            return;
        }
        $this->request->get(self::BASE_URL . $this->token, array(USER_AGENT));
    }

    public function resetState()
    {
        $this->failed = false;
    }
}
