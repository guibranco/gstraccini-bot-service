<?php

namespace GuiBranco\GStracciniBot\Handlers;

/**
 * Handles installation events shared by the HTTP webhook entry point
 * (Src/installations.php) and the queue worker (Src/Workers/installations.php).
 */
class InstallationsHandler implements IHandler
{
    /**
     * @param array<string,mixed> $installation
     */
    public function handleItem($installation): void
    {
        // Minimal implementation to avoid silently "processing" items with no effect.
        // This can be expanded to perform real processing as needed.
        $installationId = $installation['id'] ?? $installation['installation_id'] ?? 'unknown';

        error_log(sprintf(
            '[installations] Received installation payload (id: %s)',
            (string) $installationId
        ));
    }
}
