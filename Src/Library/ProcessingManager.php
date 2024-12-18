<?php

class ProcessingManager {
    private $table;
    private $logger;

    public function __construct(string $table, LoggerInterface $logger) {
        $this->table = $table;
        $this->logger = $logger;
    }

    public function process(callable $handler): void {
        $items = readTable($table);
        foreach ($items as $item) {
          echo "Sequence: {$item->Sequence}\n";
          echo "Delivery ID: {$item->DeliveryIdText}\n";
          $this->processItem($item, $handler);
          echo str_repeat("=-", 50) . "=\n";
        }
    }

    private function processItem($item, callable $handler): void {
        try {
            if (updateTable($this->table, $item->Sequence)) {
                $handler($item);
                return;
            }
          
            $message = sprintf(
                "Skipping item (Sequence: %d, Delivery ID: %s) since it was already handled.",
                $item->Sequence,
                $item->DeliveryIdText
            );
            $this->logger->info($message);
            echo $message;
        } catch (Exception $e) {
            $this->logger->error(sprintf(
                "Failed to process item (Sequence: %d): %s",
                $item->Sequence,
                $e->getMessage()
            ));
            throw $e;
        }
    }
}
