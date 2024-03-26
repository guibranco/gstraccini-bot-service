<?php

namespace GuiBranco\GStracciniBot\lib;

use GuiBranco\Pancake\Request;

class HealthChecks
{
    private $token;

    private $request;

    private $failed = false;

    public function __construct($token)
    {
        $this->token = $token;
        $this->request = new Request();
    }

    private function sendHealthCheck($type = null)
    {
        if (isset($_SERVER['REQUEST_METHOD'])) {
            return;
        }

        $headers = array("User-Agent: " . USER_AGENT);
        $url = "https://hc-ping.com/" . $this->token . ($type == null ? "" : $type);
        $this->request->get($url, $headers);
    }

    public function start()
    {
        $this->sendHealthCheck("/start");
    }

    public function end()
    {
        if ($this->failed) {
            $this->sendHealthCheck("/fail");
        } else {
            $this->sendHealthCheck();
        }
    }

    public function notifyError($message)
    {
        $data = array("message" => $message);
        sendError("HealthCheckIo->notifyError", json_encode($data));
        $this->sendHealthCheck("/fail");
        $this->failed = true;
    }
}