<?php

namespace GuiBranco\GStracciniBot\Library;

using GuiBranco\Pancake\ILogger;

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

            $message = sprintf(
                "Skipping item (Table: %s, Sequence: %d, Delivery ID: %s) since it was already handled.",
                $this->_table,
                $item->Sequence,
                $item->DeliveryIdText
            );
            $this->logger->log($message, $item);
            echo $message;
        } catch (Exception $e) {
            $this->logger->log(sprintf(
                "Failed to process item (Table: %s, Sequence: %d): %s",
                $this->_table,
                $item->Sequence,
                $e->getMessage()
            ), $item);
            throw $e;
        }
    }
}
