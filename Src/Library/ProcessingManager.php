<?php

namespace GuiBranco\GStracciniBot\Library;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\Sync\ScheduledJob;
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

    /** @var array<int, ScheduledJob> */
    private array $scheduledJobs = [];

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

        $this->config = $config;
        $this->entity = $entity;
        $this->healthChecks = $healthChecks;
        $this->logger = $logger;
        $this->logStream = $logStream;

        $this->healthChecks->setHeaders([constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
    }

    /**
     * Registers a job that runs on its own schedule from this worker's loop
     * (e.g. the installations worker's monthly sync, the repositories
     * worker's hourly sync). It is checked after every batch, in every run
     * mode — the 55-second cron/HTTP loop, the single HTTP batch and the
     * daemon — and a failure is logged without stopping the worker.
     */
    public function addScheduledJob(ScheduledJob $job): void
    {
        $this->scheduledJobs[] = $job;
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
            try {
                $this->batch($handler);
            } catch (\Throwable $e) {
                $this->logger->log(
                    "Batch failed (Entity: {$this->entity}): {$e->getMessage()}",
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
                    "Batch failed: {$e->getMessage()}",
                    ['entity' => $this->entity, 'file' => $e->getFile(), 'line' => $e->getLine()],
                    $this->entity
                );
            }
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
        if (php_sapi_name() !== 'cli') {
            $this->healthChecks->start();
            try {
                $this->batch($handler);
            } catch (\Throwable $e) {
                $this->logger->log(
                    "Batch failed (Entity: {$this->entity}): {$e->getMessage()}",
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
                    "Batch failed: {$e->getMessage()}",
                    ['entity' => $this->entity, 'file' => $e->getFile(), 'line' => $e->getLine()],
                    $this->entity
                );
            }
            $this->healthChecks->end();
            return;
        }

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
        foreach ($this->scheduledJobs as $job) {
            echo "[" . date('Y-m-d H:i:s') . "] Scheduled job registered: {$job->describe()}.\n";
        }
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
        if (!empty($result) && ($this->config->debug->all === true || ($this->config->debug->{$this->entity} ?? false) === true)) {
            echo $result;
        }

        $this->runScheduledJobs();
    }

    /**
     * Gives every registered scheduled job the chance to run. Their output
     * isn't buffered like the batch's: a scheduled run is rare (hourly,
     * monthly) and its report is worth having in the worker's log
     * regardless of the entity's debug flag.
     */
    private function runScheduledJobs(): void
    {
        foreach ($this->scheduledJobs as $job) {
            try {
                $job->runIfDue(new DateTimeImmutable());
            } catch (\Throwable $e) {
                $this->logger->log(
                    "Scheduled job '{$job->getName()}' failed (Entity: {$this->entity}): {$e->getMessage()}",
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
                    "Scheduled job '{$job->getName()}' failed: {$e->getMessage()}",
                    ['entity' => $this->entity, 'job' => $job->getName(), 'file' => $e->getFile(), 'line' => $e->getLine()],
                    $this->entity
                );
            }
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
        $table = "github_{$this->entity}";

        $context = [
            'entity' => $this->entity,
            'table' => $table,
            'sequence' => $item->Sequence,
        ];
        if (!empty($item->RepositoryOwner ?? null)) {
            $context['owner'] = $item->RepositoryOwner;
        }
        if (!empty($item->RepositoryName ?? null)) {
            $context['repo'] = $item->RepositoryName;
        }
        if (!empty($item->Title ?? null)) {
            $context['title'] = $item->Title;
        }

        $itemDesc = "[{$table} #{$item->Sequence}]";
        if (!empty($item->RepositoryOwner ?? null) && !empty($item->RepositoryName ?? null)) {
            $itemDesc .= " {$item->RepositoryOwner}/{$item->RepositoryName}";
        }
        if (!empty($item->Title ?? null)) {
            $itemDesc .= ": {$item->Title}";
        }

        try {
            $this->logStream?->info(
                "Processing item {$itemDesc}",
                $context,
                $this->entity,
                $traceId
            );
            $handler($item);
            $finalizeResult = finalizeProcessing($table, $item->Sequence);
            if ($finalizeResult === true) {
                echo "Item processed!\n";
                $this->logStream?->info(
                    "Item processed {$itemDesc}",
                    $context,
                    $this->entity,
                    $traceId
                );
            } else {
                echo "Item updated by another process!\n";
                $this->logStream?->warning(
                    "Item finalized by another process {$itemDesc}",
                    $context,
                    $this->entity,
                    $traceId
                );
            }
        } catch (\Exception $e) {
            $this->logger->log(
                "Failed to process item {$itemDesc}: {$e->getMessage()}.",
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
                "Failed to process item {$itemDesc}: {$e->getMessage()}",
                [...$context, 'file' => $e->getFile(), 'line' => $e->getLine()],
                $this->entity,
                $traceId
            );
            throw $e;
        }
    }
}
