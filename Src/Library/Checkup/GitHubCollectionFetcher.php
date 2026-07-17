<?php

namespace GuiBranco\GStracciniBot\Library\Checkup;

use GuiBranco\Pancake\Response;

/**
 * Pages through a GitHub REST list endpoint, pacing requests so the
 * checkup job doesn't burn through the installation's rate-limit budget.
 * Sleeps `throttleSeconds` between every request, and additionally backs
 * off until the rate-limit reset time if the remaining quota gets low.
 */
class GitHubCollectionFetcher
{
    private const PER_PAGE = 100;
    private const RATE_LIMIT_SAFETY_THRESHOLD = 50;

    private int $throttleSeconds;

    public function __construct(int $throttleSeconds)
    {
        $this->throttleSeconds = max(0, $throttleSeconds);
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
     */
    public function fetchAllPages(string $token, string $url, ?string $wrapperKey = null): array
    {
        $items = [];
        $page = 1;
        $separator = str_contains($url, "?") ? "&" : "?";

        while (true) {
            $pagedUrl = $url . $separator . "per_page=" . self::PER_PAGE . "&page=" . $page;
            $response = doRequestGitHub($token, $pagedUrl, null, "GET");

            if ($response->getStatusCode() >= 300) {
                break;
            }

            $decoded = json_decode($response->getBody());
            $pageItems = $wrapperKey !== null ? ($decoded->$wrapperKey ?? []) : (is_array($decoded) ? $decoded : []);

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
