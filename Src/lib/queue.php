<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendQueue($queueName, $headers, $data)
{
    global $config;
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    if (!$config->queue->enabled) {
        return false;
    }

    $payload = array(time(), $headers, $data);
    $payload = json_encode($payload);

    sendByLib($queueName, $payload);
}

function sendByLib($queueName, $payload)
{
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    try {
        $connection = new AMQPStreamConnection($rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost);

        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);

        $msg = new AMQPMessage($payload, array('delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
        $channel->basic_publish($msg, '', $queueName);

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        file_put_contents("rabbitmq.error_log", "[" . date("Y-m-d H:i:s e") . "] ERROR sending " . $queueName . ": " . $e->getMessage() . "\n", FILE_APPEND);
        if (!is_dir("logs")) {
            mkdir("logs");
        }
        file_put_contents("logs/" . $queueName . "_" . microtime(true) . "_" . uniqid() . ".json", $payload);
    }
}

function receiveQueue($queueName, $callback)
{
    global $config;
    global $rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost;

    if (!$config->queue->enabled) {
        return false;
    }

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
        $connection = new AMQPStreamConnection($rabbitMqHost, $rabbitMqPort, $rabbitMqUser, $rabbitMqPassword, $rabbitMqVhost);
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
        file_put_contents("rabbitmq.error_log", "[" . date("Y-m-d H:i:s e") . "] ERROR receiving " . $queueName . ": " . $e->getMessage() . "\n", FILE_APPEND);
    }
}
