<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Logger;

class ProcessingManager
{
    private $table;
    private $logger;

    public function __construct(string $table, Logger $logger)
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
        $details = json_encode($item);
        if ($details === false) {
            $details = json_last_error_msg();
        }

        try {
            if (updateTable($this->table, $item->Sequence)) {
                $handler($item);
                if(finalizeProcessing($this->table, $item->Sequence)) {
                    echo "Item processed!\n";
                } else {
                    echo "Item updated by another hook!\n";
                }
                return;
            }

            $message = "Skipping item (Table: {$this->table}, Sequence: {$item->Sequence}) since it was already handled.";
            $this->logger->log($message, $details);
            echo $message . "\n";
        } catch (\Exception $e) {
            $this->logger->log(
                "Failed to process item (Table: {$this->table}, Sequence: {$item->Sequence}): {$e->getMessage()}.",
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
