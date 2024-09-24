<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

function getServers()
{
    global $rabbitMqConnectionStrings;

    if (empty($rabbitMqConnectionStrings)) {
        throw new Exception("RabbitMQ connection strings not found");
    }

    $servers = [];
    foreach ($rabbitMqConnectionStrings as $connectionString) {
        $url = parse_url($connectionString);
        $servers[] = [
            "host" => $url["host"],
            "port" => isset($url["port"]) ? $url["port"] : 5672,
            "user" => $url["user"],
            "password" => $url["pass"],
            "vhost" => ($url['path'] == '/' || !isset($url['path'])) ? '/' : substr($url['path'], 1)
        ];
    }

    return $servers;
}

function connect($servers)
{
    shuffle($servers);
    $options = [];
    if (count($servers) == 1) {
        $options = ['connection_timeout' => 10.0, 'read_write_timeout' => 10.0,];
    }

    return AMQPStreamConnection::create_connection($servers, $options);
}

function sendQueue($queueName, $payload)
{
    $payload = json_encode($payload);

    try {
        sendByLib($queueName, $payload);
    } catch (Exception $e) {
        handleSendingError($e, $queueName, $payload);
    }
}

function sendByLib($queueName, $payload)
{
    $connection = connect(getServers());
    if ($connection === false) {
        return;
    }

    $channel = $connection->channel();
    declareQueueAndDLX($channel, $queueName);
    $msgOptions = array('content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT);
    $msg = new AMQPMessage($payload, $msgOptions);
    $channel->basic_publish($msg, '', $queueName);

    $channel->close();
    $connection->close();
}

function receiveQueue($timeout, $queueName, $callback)
{
    $servers = getServers();
    $timeoutPerServer = $timeout / count($servers);
    foreach ($servers as $server) {
        receiveByLib([$server], $timeoutPerServer, $queueName, $callback);
    }
}

function receiveByLib($server, $timeout, $queueName, $callback, $isRetry = false)
{
    $startTime = time();

    try {
        $fn = function ($msg) use ($callback, $timeout, $startTime) {
            $callback($timeout, $startTime, $msg);
        };
        $connection = connect($server);
        if ($connection === false) {
            return;
        }
        $channel = $connection->channel();
        declareQueueAndDLX($channel, $queueName);
        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queueName, 'consumer-webhooks', false, false, false, false, $fn);

        while ($channel->is_consuming()) {
            $channel->wait(null, true);
            if ($startTime + $timeout < time()) {
                break;
            }
        }

        $channel->close();
        $connection->close();
    } catch (Exception $exception) {
        if (!$isRetry) {
            logQueueError("retrying receiving", $queueName, $exception, $server[0]["host"], $server[0]["port"]);
            sleep(5);
            return receiveByLib($server, $timeout, $queueName, $callback, true);
        }
        logQueueError("receiving", $queueName, $exception, $server[0]["host"], $server[0]["port"]);
    }
}

function handleSendingError($exception, $queueName, $payload)
{
    $dir = "failed";
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    $file = $dir . "/" . $queueName . "_" . microtime(true) . "_" . uniqid() . ".json";
    file_put_contents($file, $queueName . PHP_EOL . $payload);
    logQueueError("sending", $queueName, $exception);
}


function logQueueError($type, $queueName, $exception, $host = null, $port = null)
{
    $message = $type . " " . $queueName;
    if ($host != null) {
        $message .= " (" . $host . ($port != null ? ":" . $port : "") . ")";
    }
    $message .= ": " . $exception->getMessage() . "\n";

    global $logger;
    $logger->log($message, $exception->getTraceAsString());
    $data = json_encode(array("queueName" => $queueName, "message" => $exception->getMessage()));
    global $hc;
    if (isset($hc)) {
        $hc->error($data);
    }
}

function declareQueueAndDLX($channel, $queueName)
{
    $channel->queue_declare(
        $queueName,
        false,
        true,
        false,
        false,
        false,
        new AMQPTable(
            array(
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $queueName . '-retry'
            )
        )
    );
    $channel->queue_declare(
        $queueName . '-retry',
        false,
        true,
        false,
        false,
        false,
        new AMQPTable(
            array(
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $queueName,
                'x-message-ttl' => 1000 * 60 * 10
            )
        )
    );
}
