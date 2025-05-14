<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\HealthChecks;
use GuiBranco\Pancake\Logger;
use InvalidArgumentException;
use RuntimeException;

class ProcessingManager
{
    private $config;
    private $entity;
    private $healthChecks;
    private $logger;

    /**
    * @param string $entity Entity name for processing
    * @param HealthChecks $healthChecks Health monitoring instance
    * @param Logger $logger Logger instance
    * @throws \InvalidArgumentException If entity name is invalid
    * @throws \RuntimeException If config loading fails
    */
    public function __construct(string $entity, HealthChecks $healthChecks, Logger $logger)
    {
        if (empty($entity)) {
            throw new InvalidArgumentException('Entity name cannot be empty');
        }

        $config = loadConfig();
        if ($config === false) {
            throw new RuntimeException('Failed to load configuration');
        }

        $this->config = $config;
        $this->entity = $entity;
        $this->healthChecks = $healthChecks;
        $this->logger = $logger;

        $this->healthChecks->setHeaders([constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
    }

    /**
     * Initialize processing with timeout.
     *
     * @param callable $handler Item processing callback
     * @param int $timeout Maximum processing time in seconds
     */
    public function initialize(callable $handler, int $timeout): void
    {
        $this->healthChecks->start();
        $endTime = time() + $timeout;
        while (true) {
            $this->batch($handler);
            if (time() >= $endTime) {
                break;
            }
            usleep(100000);
        }
        $this->healthChecks->end();
    }

    /**
     * Process a batch of items.
     *
     * @param callable $handler Item processing callback
     */
    private function batch(callable $handler): void
    {
        ob_start();
        $this->process($handler);
        $result = ob_get_clean();
        if ($result === false || empty($result)) {
            return;
        }
        if ($this->config->debug->all === true || $this->config->debug->{$this->entity} === true) {
            echo $result;
        }
    }

    private function process(callable $handler): void
    {
        $items = readTable("github_{$this->entity}");
        foreach ($items as $item) {
            echo "Sequence: {$item->Sequence}\n";
            echo "Delivery ID: {$item->DeliveryIdText}\n";
            $this->processItem($item, $handler);
            echo str_repeat("=-", 50) . "=\n";
        }
    }

    private function processItem($item, callable $handler): void
    {
        $details = json_encode($item);
        if ($details === false) {
            $details = json_last_error_msg();
        }

        try {
            $updateResult = updateTable("github_{$this->entity}", $item->Sequence);
            if ($updateResult === true) {
                $handler($item);
                $finalizeResult = finalizeProcessing("github_{$this->entity}", $item->Sequence);
                if($finalizeResult === true) {
                    echo "Item processed!\n";
                } else {
                    echo "Item updated by another process!\n";
                }
                return;
            }

            $message = "Skipping item (Entity: {$this->entity}, Sequence: {$item->Sequence}) since it was already handled.";
            $encoded = json_encode($item);
            $this->logger->log($message, $encoded === false ? json_last_error_msg() : $encoded);
            echo $message . "\n";
        } catch (\Exception $e) {
            $this->logger->log(
                "Failed to process item (Entity: {$this->entity}, Sequence: {$item->Sequence}): {$e->getMessage()}.",
                [
                    'error' => [
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ],
                    'item' => json_decode($details, true)
                ]
            );
            throw $e;
        }
    }
}
