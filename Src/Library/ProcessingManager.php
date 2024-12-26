<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\ILogger;

class ProcessingManager
{
    private $table;
    private $logger;

    public function __construct(string $table, ILogger $logger)
    {
        $this->table = $table;
        $this->logger = $logger;
    }

    public function process(callable $handler): void
    {
        $items = readTable($this->table);
        foreach ($items as $item) {
            echo "Sequence: {$item->Sequence}\n";
            echo "Delivery ID: {$item->DeliveryIdText}\n";
            $this->processItem($item, $handler);
            echo str_repeat("=-", 50) . "=\n";
        }
    }

    private function processItem($item, callable $handler): void
    {
        try {
            if (updateTable($this->table, $item->Sequence)) {
                $handler($item);
                return;
            }

            $this->logger->log("Skip", "");
            $message = sprintf(
                "Skipping item (Table: %s, Sequence: %d) since it was already handled.",
                $this->table,
                $item->Sequence
            );
            $this->logger->log($message, json_encode($item));
            echo $message;
        } catch (\Exception $e) {
            $this->logger->log("Exception Skip", $e->getMessage());
            $this->logger->log(sprintf(
                "Failed to process item (Table: %s, Sequence: %d): %s",
                $this->table,
                $item->Sequence,
                $e->getMessage()
            ), json_encode($item));
            throw $e;
        }
    }
}
