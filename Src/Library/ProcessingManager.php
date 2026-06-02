<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\HealthChecks;
use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\LogStream;
use InvalidArgumentException;
use RuntimeException;

class ProcessingManager
{
    private $config;
    private $entity;
    private $healthChecks;
    private $logger;
    private ?LogStream $logStream;

    /**
    * @param string $entity Entity name for processing
    * @param HealthChecks $healthChecks Health monitoring instance
    * @param Logger $logger Logger instance
    * @param LogStream|null $logStream Real-time log stream instance
    * @throws \InvalidArgumentException If entity name is invalid
    * @throws \RuntimeException If config loading fails
    */
    public function __construct(string $entity, HealthChecks $healthChecks, Logger $logger, ?LogStream $logStream = null)
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
        $this->logStream = $logStream;

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
     * Run as a long-lived daemon process.
     *
     * Loops indefinitely, processing batches every 100 ms and pinging healthchecks.io
     * every $healthCheckInterval seconds. Responds to SIGTERM / SIGINT for a graceful
     * shutdown and pauses automatically while an updating.lock file is present.
     *
     * Requires the pcntl extension (available by default on CLI PHP on Linux).
     *
     * @param callable $handler           Item processing callback
     * @param int      $healthCheckInterval Seconds between healthchecks.io pings (default 300)
     */
    public function run(callable $handler, int $healthCheckInterval = 300): void
    {
        if (!extension_loaded('pcntl')) {
            throw new RuntimeException('pcntl extension is required for daemon mode');
        }

        $running = true;
        pcntl_async_signals(true);
        $shutdown = function () use (&$running) {
            $ts = date('Y-m-d H:i:s');
            echo "[{$ts}] Shutdown signal received for '{$this->entity}'. Finishing current batch...\n";
            $running = false;
        };
        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);

        echo "[" . date('Y-m-d H:i:s') . "] Daemon started for '{$this->entity}'.\n";
        $this->logStream?->info("Daemon started", ['entity' => $this->entity], $this->entity);
        $this->healthChecks->start();
        $lastPing = time();

        while ($running) {
            if ($this->isSystemUpdating()) {
                echo "[" . date('Y-m-d H:i:s') . "] System updating — pausing '{$this->entity}'...\n";
                $this->logStream?->warning("System updating — daemon paused", ['entity' => $this->entity], $this->entity);
                sleep(5);
                continue;
            }

            try {
                $this->batch($handler);
            } catch (\Throwable $e) {
                $this->logger->log(
                    "Daemon batch failed (Entity: {$this->entity}): {$e->getMessage()}",
                    [
                        'error' => [
                            'message' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]
                    ]
                );
                $this->logStream?->error(
                    "Daemon batch failed: {$e->getMessage()}",
                    ['entity' => $this->entity, 'file' => $e->getFile(), 'line' => $e->getLine()],
                    $this->entity
                );
            }

            $now = time();
            if ($now - $lastPing >= $healthCheckInterval) {
                $this->healthChecks->end();
                $lastPing = $now;
            }

            usleep(100000);
        }

        $this->logStream?->info("Daemon stopped gracefully", ['entity' => $this->entity], $this->entity);
        echo "[" . date('Y-m-d H:i:s') . "] Daemon for '{$this->entity}' stopped gracefully.\n";
    }

    /**
     * Returns true while an updating.lock file is present and not yet expired.
     * Unlike checkSystemUpdating() in config.php this method never calls die().
     */
    private function isSystemUpdating(): bool
    {
        $lockFile    = 'updating.lock';
        $releaseFile = 'updating.release';

        if (!file_exists($lockFile)) {
            return false;
        }

        if (file_exists($releaseFile)) {
            @unlink($lockFile);
            @unlink($releaseFile);
            return false;
        }

        $fileTime = filemtime($lockFile);
        if ($fileTime === false || $fileTime < strtotime('-15 minutes')) {
            @unlink($lockFile);
            return false;
        }

        return true;
    }

    /**
     * Process a batch of items.
     *
     * @param callable $handler Item processing callback
     */
    private function batch(callable $handler): void
    {
        ob_start();
        try {
            $this->process($handler);
        } finally {
            $result = ob_get_clean();
        }
        if (empty($result)) {
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

        $traceId = $item->DeliveryIdText ?? null;

        try {
            $updateResult = updateTable("github_{$this->entity}", $item->Sequence);
            if ($updateResult === true) {
                $this->logStream?->info(
                    "Processing item",
                    ['entity' => $this->entity, 'sequence' => $item->Sequence],
                    $this->entity,
                    $traceId
                );
                $handler($item);
                $finalizeResult = finalizeProcessing("github_{$this->entity}", $item->Sequence);
                if ($finalizeResult === true) {
                    echo "Item processed!\n";
                    $this->logStream?->info(
                        "Item processed",
                        ['entity' => $this->entity, 'sequence' => $item->Sequence],
                        $this->entity,
                        $traceId
                    );
                } else {
                    echo "Item updated by another process!\n";
                    $this->logStream?->warning(
                        "Item finalized by another process",
                        ['entity' => $this->entity, 'sequence' => $item->Sequence],
                        $this->entity,
                        $traceId
                    );
                }
                return;
            }

            $message = "Skipping item (Entity: {$this->entity}, Sequence: {$item->Sequence}) since it was already handled.";
            $this->logger->log($message, $item);
            echo $message . "\n";
            $this->logStream?->warning(
                "Item skipped — already handled",
                ['entity' => $this->entity, 'sequence' => $item->Sequence],
                $this->entity,
                $traceId
            );
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
            $this->logStream?->error(
                "Failed to process item: {$e->getMessage()}",
                [
                    'entity' => $this->entity,
                    'sequence' => $item->Sequence,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                $this->entity,
                $traceId
            );
            throw $e;
        }
    }
}
