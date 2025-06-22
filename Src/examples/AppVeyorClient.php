<?php

declare(strict_types=1);

use App\Services\AppVeyorClient;
use GuiBranco\Pancake\Logger;

// Example usage of the AppVeyorClient

// Set up logger
$loggerUrl = 'https://your-logging-service.com/';
$loggerApiKey = 'your-logger-api-key';
$loggerApiToken = 'your-logger-api-token';
$customUserAgent = 'MyApp/1.0 (https://example.com)';

$logger = new Logger($loggerUrl, $loggerApiKey, $loggerApiToken, $customUserAgent);

// Initialize the client
$apiKey = 'your-appveyor-api-key-here';
$userAgent = 'MyApp/1.0 (https://example.com)';

try {
    $appVeyorClient = new AppVeyorClient($apiKey, $userAgent, $logger);

    // Example 1: Get all projects
    echo "Fetching all projects...\n";
    $projectsResponse = $appVeyorClient->getProjects();

    if ($projectsResponse->getStatusCode() < 300) {
        $projects = json_decode($projectsResponse->getBody());
        echo "Found " . count($projects) . " projects\n";
    }

    // Example 2: Find a specific project by repository slug
    echo "Searching for project by repository slug...\n";
    $repositorySlug = 'my-repository-name';
    $project = $appVeyorClient->findProjectByRepositorySlug($repositorySlug);

    if ($project->error) {
        echo "Error: {$project->message}\n";
    } else {
        echo "Found project: {$project->name} (ID: {$project->projectId})\n";
    }

    // Example 3: Create a new project
    echo "Creating a new project...\n";
    $newProjectData = [
        'repositoryProvider' => 'GitHub',
        'repositoryName' => 'username/repository-name',
        'name' => 'My New Project'
    ];

    $createResponse = $appVeyorClient->createProject($newProjectData);

    if ($createResponse->getStatusCode() < 300) {
        echo "Project created successfully!\n";
    } else {
        echo "Failed to create project\n";
    }

    // Example 4: Update a project
    echo "Updating project configuration...\n";
    $updateData = [
        'projectId' => 12345,
        'name' => 'Updated Project Name',
        'settings' => [
            'configuration' => 'Release'
        ]
    ];

    $updateResponse = $appVeyorClient->updateProject($updateData);

    if ($updateResponse->getStatusCode() < 300) {
        echo "Project updated successfully!\n";
    } else {
        echo "Failed to update project\n";
    }

} catch (InvalidArgumentException $e) {
    echo "Configuration error: {$e->getMessage()}\n";
} catch (RuntimeException $e) {
    echo "Runtime error: {$e->getMessage()}\n";
} catch (Exception $e) {
    echo "Unexpected error: {$e->getMessage()}\n";
}
