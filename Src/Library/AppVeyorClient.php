<?php

declare(strict_types=1);

namespace App\Services;

use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;
use GuiBranco\Pancake\ILogger;
use InvalidArgumentException;
use RuntimeException;

/**
 * AppVeyor API Client
 * 
 * Provides methods to interact with the AppVeyor CI/CD API
 */
class AppVeyorClient
{
    private const BASE_URL = 'https://ci.appveyor.com/api/';
    private const CONTENT_TYPE_JSON = 'application/json';
    private const HTTP_SUCCESS_THRESHOLD = 300;

    private string $apiKey;
    private string $userAgent;
    private ILogger $logger;
    private Request $httpClient;

    /**
     * @param string $apiKey AppVeyor API key
     * @param string $userAgent User agent string for HTTP requests
     * @param ILogger $logger Logger instance
     * @param Request|null $httpClient HTTP client instance (optional)
     */
    public function __construct(
        string $apiKey,
        string $userAgent,
        ILogger $logger,
        ?Request $httpClient = null
    ) {
        $this->validateApiKey($apiKey);
        $this->validateUserAgent($userAgent);

        $this->apiKey = $apiKey;
        $this->userAgent = $userAgent;
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? new Request();
    }

    /**
     * Make a request to the AppVeyor API
     *
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array|null $data Request payload for POST/PUT requests
     * @param string $method HTTP method (GET, POST, PUT)
     * @return Response
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function makeRequest(string $endpoint, ?array $data = null, string $method = 'GET'): Response
    {
        $this->validateEndpoint($endpoint);
        $this->validateHttpMethod($method);

        $url = $this->buildUrl($endpoint);
        $headers = $this->buildHeaders();

        try {
            $response = $this->executeRequest($url, $headers, $data, $method);
            $this->handleErrorResponse($response);

            return $response;
        } catch (\Exception $e) {
            $errorDetails = new \stdClass();
            $errorDetails->endpoint = $endpoint;
            $errorDetails->method = $method;
            $errorDetails->error = $e->getMessage();

            $this->logger->log('AppVeyor API request failed', $errorDetails);
            throw new RuntimeException("Failed to execute AppVeyor API request: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Find a project by repository slug
     *
     * @param string $repositorySlug Repository slug to search for
     * @return object Project object or error object
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function findProjectByRepositorySlug(string $repositorySlug): object
    {
        $this->validateRepositorySlug($repositorySlug);

        $searchSlug = strtolower(trim($repositorySlug));

        try {
            $projectsResponse = $this->makeRequest('projects');
            $projects = $this->parseJsonResponse($projectsResponse);

            // Handle API error responses
            if (isset($projects->message) && !empty($projects->message)) {
                return $this->createErrorObject($projects->message);
            }

            // Ensure projects is an array
            if (!is_array($projects)) {
                throw new RuntimeException('Invalid projects response format');
            }

            $matchingProjects = $this->filterProjectsBySlug($projects, $searchSlug);

            if (empty($matchingProjects)) {
                return $this->createErrorObject("No project found with repository slug: {$repositorySlug}");
            }

            $project = reset($matchingProjects);
            $project->error = false;

            return $project;

        } catch (RuntimeException $e) {
            $errorDetails = new \stdClass();
            $errorDetails->repository_slug = $repositorySlug;
            $errorDetails->error = $e->getMessage();

            $this->logger->log('Failed to find project by repository slug', $errorDetails);
            return $this->createErrorObject($e->getMessage());
        }
    }

    /**
     * Get all projects
     *
     * @return Response
     */
    public function getProjects(): Response
    {
        return $this->makeRequest('projects');
    }

    /**
     * Create a new project
     *
     * @param array $projectData Project configuration data
     * @return Response
     */
    public function createProject(array $projectData): Response
    {
        return $this->makeRequest('projects', $projectData, 'POST');
    }

    /**
     * Update an existing project
     *
     * @param array $projectData Project configuration data
     * @return Response
     */
    public function updateProject(array $projectData): Response
    {
        return $this->makeRequest('projects', $projectData, 'PUT');
    }

    /**
     * Validate API key
     */
    private function validateApiKey(string $apiKey): void
    {
        if (empty(trim($apiKey))) {
            throw new InvalidArgumentException('API key cannot be empty');
        }
    }

    /**
     * Validate user agent
     */
    private function validateUserAgent(string $userAgent): void
    {
        if (empty(trim($userAgent))) {
            throw new InvalidArgumentException('User agent cannot be empty');
        }
    }

    /**
     * Validate endpoint
     */
    private function validateEndpoint(string $endpoint): void
    {
        if (empty(trim($endpoint))) {
            throw new InvalidArgumentException('Endpoint cannot be empty');
        }
    }

    /**
     * Validate HTTP method
     */
    private function validateHttpMethod(string $method): void
    {
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$method}");
        }
    }

    /**
     * Validate repository slug
     */
    private function validateRepositorySlug(string $repositorySlug): void
    {
        if (empty(trim($repositorySlug))) {
            throw new InvalidArgumentException('Repository slug cannot be empty');
        }
    }

    /**
     * Build full URL from endpoint
     */
    private function buildUrl(string $endpoint): string
    {
        return self::BASE_URL . ltrim($endpoint, '/');
    }

    /**
     * Build HTTP headers
     */
    private function buildHeaders(): array
    {
        return [
            $this->userAgent,
            "Authorization: Bearer {$this->apiKey}",
            'Content-Type: ' . self::CONTENT_TYPE_JSON
        ];
    }

    /**
     * Execute HTTP request based on method
     */
    private function executeRequest(string $url, array $headers, ?array $data, string $method): Response
    {
        $method = strtoupper($method);

        switch ($method) {
            case 'GET':
                return $this->httpClient->get($url, $headers);

            case 'POST':
                $payload = $data ? json_encode($data, JSON_THROW_ON_ERROR) : null;
                return $this->httpClient->post($url, $headers, $payload);

            case 'PUT':
                $payload = $data ? json_encode($data, JSON_THROW_ON_ERROR) : null;
                return $this->httpClient->put($url, $headers, $payload);

            default:
                throw new InvalidArgumentException("Unsupported HTTP method: {$method}");
        }
    }

    /**
     * Handle error responses
     */
    private function handleErrorResponse(Response $response): void
    {
        if ($response->getStatusCode() >= self::HTTP_SUCCESS_THRESHOLD) {
            $responseData = $response->toJson();

            $errorDetails = new \stdClass();
            $errorDetails->status_code = $response->getStatusCode();
            $errorDetails->response_data = $responseData;

            $this->logger->log('AppVeyor API returned error response', $errorDetails);
        }
    }

    /**
     * Parse JSON response safely
     */
    private function parseJsonResponse(Response $response): object
    {
        $body = $response->getBody();

        if (empty($body)) {
            throw new RuntimeException('Empty response body');
        }

        $decoded = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode JSON response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Filter projects by repository slug
     */
    private function filterProjectsBySlug(array $projects, string $searchSlug): array
    {
        $matchingProjects = array_filter($projects, function ($project) use ($searchSlug): bool {
            return isset($project->repositoryName) &&
                $searchSlug === strtolower($project->repositoryName);
        });

        return array_values($matchingProjects);
    }

    /**
     * Create error object
     */
    private function createErrorObject(string $message): object
    {
        $error = new \stdClass();
        $error->error = true;
        $error->message = $message;

        return $error;
    }
}