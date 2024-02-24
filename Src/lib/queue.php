<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendQueue($queueName, $headers, $data)
{
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    $payload = array(time(), $headers, $data);
    $payload = json_encode($payload);

    sendByLib($queueName, $payload);
}

function sendByLib($queueName, $payload)
{
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    try {
        $connection = new AMQPStreamConnection(
            $rabbitMqHost,
            $rabbitMqPort,
            $rabbitMqUser,
            $rabbitMqPassword,
            $rabbitMqVhost
        );
        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);

        $msg = new AMQPMessage($payload, array('delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
        $channel->basic_publish($msg, '', $queueName);

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        saveErrorLog("sending", $queueName, $e);
        saveFailed($queueName, $payload);
    }
}

function receiveQueue($queueName, $callback)
{
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    receiveByLib($queueName, $callback);
}

function receiveByLib($queueName, $callback)
{
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    $startTime = time();

    try {
        $fn = function ($msg) use ($callback, $startTime) {
            $callback($startTime, $msg);
        };
        $connection = new AMQPStreamConnection(
            $rabbitMqHost,
            $rabbitMqPort,
            $rabbitMqUser,
            $rabbitMqPassword,
            $rabbitMqVhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            10.0,
            10.0,
            null,
            false,
            0,
            5.0
        );
        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->basic_consume($queueName, '', false, false, false, false, $fn);

        while ($channel->is_consuming()) {
            $channel->wait(null, true);
            if ($startTime + 60 < time()) {
                break;
            }
        }

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        saveErrorLog("receiving", $queueName, $e);
    }
}

function saveErrorLog($type, $queueName, $exception)
{
    $file = "rabbitmq.error_log";
    $date = date("Y-m-d H:i:s e");
    $error = "[" . $date . "] ERROR " . $type . " " . $queueName . ": " . $exception->getMessage() . "\n";
    file_put_contents($file, $error, FILE_APPEND);
}

function saveFailed($queueName, $payload)
{
    $dir = "failed";
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    $date = date("Y-m-d-H-i-s-e");
    file_put_contents($dir . "/" . $queueName . "_" . $date . "_" . uniqid() . ".json", $payload);
}
