<?php

namespace GuiBranco\GStracciniBot\Library\Checkup;

use GuiBranco\Pancake\Response;
use RuntimeException;

/**
 * Pages through a GitHub REST list endpoint, pacing requests so the
 * checkup job doesn't burn through the installation's rate-limit budget.
 * Sleeps `throttleSeconds` between every request, and additionally backs
 * off until the rate-limit reset time if the remaining quota gets low.
 *
 * By default a page that fails (any non-2xx status) ends the listing and
 * whatever was collected so far is returned — fine for the checkup, which
 * only ever inserts missing rows. A fetcher built with `failOnError` throws
 * instead, so callers that *remove* or *close* things based on what GitHub
 * didn't list (the sync workers) never mistake a partial list for the truth.
 */
class GitHubCollectionFetcher
{
    private const PER_PAGE = 100;
    private const RATE_LIMIT_SAFETY_THRESHOLD = 50;

    private int $throttleSeconds;
    private bool $failOnError;

    /**
     * @param int  $throttleSeconds Seconds to sleep between consecutive requests.
     * @param bool $failOnError     When true, a failed page throws instead of ending the listing early.
     */
    public function __construct(int $throttleSeconds, bool $failOnError = false)
    {
        $this->throttleSeconds = max(0, $throttleSeconds);
        $this->failOnError = $failOnError;
    }

    /**
     * Fetches every item of a paginated GitHub list endpoint.
     *
     * @param string $token Bearer token to authenticate the request.
     * @param string $url   Base URL (relative to the GitHub API root), without page/per_page query params.
     * @param string|null $wrapperKey Some endpoints (e.g. `installation/repositories`) return an
     *                                object with the list under a named property instead of a bare
     *                                JSON array. Pass that property name here; leave null for endpoints
     *                                that return a bare array.
     *
     * @return array<int, object> All items across every page.
     *
     * @throws RuntimeException When built with `failOnError` and a page comes back with a non-2xx status.
     */
    public function fetchAllPages(string $token, string $url, ?string $wrapperKey = null): array
    {
        $items = [];
        $page = 1;
        $separator = str_contains($url, "?") ? "&" : "?";

        while (true) {
            $pagedUrl = $url . $separator . "per_page=" . self::PER_PAGE . "&page=" . $page;
            $response = doRequestGitHub($token, $pagedUrl, null, "GET");

            $pageItems = $this->readPage($response, $pagedUrl, $wrapperKey);
            if (empty($pageItems)) {
                break;
            }

            $items = array_merge($items, $pageItems);
            $this->pace($response);

            if (count($pageItems) < self::PER_PAGE) {
                break;
            }

            $page++;
        }

        return $items;
    }

    /**
     * The items of one page, or an empty array when the page failed (and
     * the fetcher is lenient) or is empty.
     *
     * @return array<int, object>
     */
    private function readPage(Response $response, string $pagedUrl, ?string $wrapperKey): array
    {
        if ($response->getStatusCode() >= 300 || $response->getStatusCode() <= 0) {
            if ($this->failOnError) {
                throw new RuntimeException(
                    "GitHub listing failed for '{$pagedUrl}': HTTP status {$response->getStatusCode()}. " .
                    "Body: " . ($response->getBody() ?? $response->getMessage())
                );
            }
            return [];
        }

        $decoded = json_decode($response->getBody());
        if ($wrapperKey !== null) {
            return is_object($decoded) && is_array($decoded->$wrapperKey ?? null) ? $decoded->$wrapperKey : [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sleeps the configured throttle between calls, and additionally waits
     * out the rate-limit window if the remaining quota is running low.
     */
    public function pace(Response $response): void
    {
        $headers = array_change_key_case($response->getHeaders() ?? [], CASE_LOWER);
        $remaining = isset($headers["x-ratelimit-remaining"]) ? (int) $headers["x-ratelimit-remaining"] : null;
        $reset = isset($headers["x-ratelimit-reset"]) ? (int) $headers["x-ratelimit-reset"] : null;

        if ($remaining !== null && $remaining < self::RATE_LIMIT_SAFETY_THRESHOLD && $reset !== null) {
            $waitSeconds = max(0, $reset - time()) + 1;
            echo "Rate limit low ({$remaining} remaining) — sleeping {$waitSeconds}s until reset\n";
            sleep($waitSeconds);
            return;
        }

        if ($this->throttleSeconds > 0) {
            sleep($this->throttleSeconds);
        }
    }
}
