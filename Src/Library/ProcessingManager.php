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

    /** Whether the last batch ran a scheduled job (so the daemon pings HealthChecks right after it). */
    private bool $scheduledJobRanInLastBatch = false;

    /**
     * The item being handled right now, with its log context, so the shutdown
     * function can tell which item the process died on.
     *
     * @var array{desc: string, context: array, traceId: ?string}|null
     */
    private ?array $currentItem = null;

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

        register_shutdown_function([$this, 'reportAbortedItem']);
    }


    /**
     * Shutdown function: reports a process that ends in the middle of an item
     * (a fatal error such as memory exhaustion or a time limit, an exit()/die()
     * somewhere down the call stack) or with a fatal error between items.
     * None of these reach a catch block, so without this they leave no trace
     * but a check run stuck in progress.
     *
     * @return void
     */
    public function reportAbortedItem(): void
    {
        $error = error_get_last();
        $isFatal = $this->isFatalError($error);
        if ($this->currentItem === null && $isFatal === false) {
            return;
        }

        $itemDesc = ($this->currentItem['desc'] ?? '(between items)');
        $reason = 'the process exited (exit/die) before the item finished';
        if ($isFatal === true) {
            $reason = "{$error['message']} in {$error['file']}:{$error['line']}";
        }

        error_log("[gstraccini-bot] Worker '{$this->entity}' aborted on {$itemDesc}: {$reason}");

        try {
            $message = "Worker aborted (Entity: {$this->entity}) on {$itemDesc}: {$reason}";
            $this->logger->log($message, ['error' => $error, 'item' => $itemDesc]);

            $context = ($this->currentItem['context'] ?? null);
            if ($context === null) {
                $context = [];
                $context['entity'] = $this->entity;
                $context['item'] = $itemDesc;
            }

            $context['error'] = $reason;
            $traceId = ($this->currentItem['traceId'] ?? null);
            $this->logStream?->critical('Worker aborted on {item}: {error}', $context, $this->entity, $traceId);
        } catch (\Throwable $e) {
            error_log("[gstraccini-bot] Failed to report the aborted item: {$e->getMessage()}");
        }

    }//end reportAbortedItem()


    /**
     * Tells whether an error, as returned by error_get_last(), is one that ends the process.
     *
     * @param array|null $error The last error, or null when there was none.
     *
     * @return boolean Whether it is a fatal error.
     */
    private function isFatalError(?array $error): bool
    {
        $fatalTypes = (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
        $type = ($error['type'] ?? 0);
        return ($type & $fatalTypes) !== 0;

    }//end isFatalError()


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
                    'Batch of {entity} failed: {error}',
                    (new LogContext(entity: $this->entity))->withError($e)->toArray(),
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
                    'Batch of {entity} failed: {error}',
                    (new LogContext(entity: $this->entity))->withError($e)->toArray(),
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
        $this->logStream?->info('Daemon for {entity} started', ['entity' => $this->entity], $this->entity);
        $this->healthChecks->start();
        $lastPing = time();

        while ($running) {
            if ($this->isSystemUpdating()) {
                echo "[" . date('Y-m-d H:i:s') . "] System updating — pausing '{$this->entity}'...\n";
                $this->logStream?->warning('System updating — daemon for {entity} paused', ['entity' => $this->entity], $this->entity);
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
                    'Daemon batch of {entity} failed: {error}',
                    (new LogContext(entity: $this->entity))->withError($e)->toArray(),
                    $this->entity
                );
            }

            // A scheduled sync can take a while (GitHub calls, throttled); ping right after one
            // so the gap it opened in the regular cadence doesn't read as a dead worker.
            $now = time();
            if ($this->scheduledJobRanInLastBatch || $now - $lastPing >= $healthCheckInterval) {
                $this->healthChecks->end();
                $lastPing = $now;
            }

            usleep(100000);
        }

        $this->logStream?->info('Daemon for {entity} stopped gracefully', ['entity' => $this->entity], $this->entity);
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
            if (!empty($result) && ($this->config->debug->all === true || ($this->config->debug->{$this->entity} ?? false) === true)) {
                echo $result;
            }

            // In the finally so a failing item (processItem() rethrows) can't keep the syncs from running.
            $this->runScheduledJobs();
        }
    }

    /**
     * Gives every registered scheduled job the chance to run. Their output
     * isn't buffered like the batch's: a scheduled run is rare (hourly,
     * monthly) and its report is worth having in the worker's log
     * regardless of the entity's debug flag.
     */
    private function runScheduledJobs(): void
    {
        $this->scheduledJobRanInLastBatch = false;

        foreach ($this->scheduledJobs as $job) {
            try {
                if ($job->runIfDue(new DateTimeImmutable())) {
                    $this->scheduledJobRanInLastBatch = true;
                }
            } catch (\Throwable $e) {
                $this->scheduledJobRanInLastBatch = true;
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
                    "Scheduled job '{job}' of {entity} failed: {error}",
                    (new LogContext(entity: $this->entity, job: $job->getName()))->withError($e)->toArray(),
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
        $context['item'] = $itemDesc;

        $this->currentItem = [];
        $this->currentItem['desc'] = $itemDesc;
        $this->currentItem['context'] = $context;
        $this->currentItem['traceId'] = $traceId;

        try {
            $this->logStream?->info(
                'Processing item {item}',
                $context,
                $this->entity,
                $traceId
            );
            $handler($item);
            $finalizeResult = finalizeProcessing($table, $item->Sequence);
            if ($finalizeResult === true) {
                echo "Item processed!\n";
                $this->logStream?->info(
                    'Item processed {item}',
                    $context,
                    $this->entity,
                    $traceId
                );
            } else {
                echo "Item updated by another process!\n";
                $this->logStream?->warning(
                    'Item finalized by another process {item}',
                    $context,
                    $this->entity,
                    $traceId
                );
            }
        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError or Error thrown by a handler is a failed item too.
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
                'Failed to process item {item}: {error}',
                (new LogContext(...$context))->withError($e)->toArray(),
                $this->entity,
                $traceId
            );
            throw $e;
        } finally {
            $this->currentItem = null;
        }
    }
}
