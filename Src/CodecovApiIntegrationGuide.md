# Codecov API V2 Integration Guide

This guide provides instructions on how to integrate with the Codecov API V2 to retrieve pull request and commit details using PHP.

## Prerequisites

1. **PHP Environment**: Ensure you have a PHP environment set up.
2. **Dependencies**: Install the Monolog library for logging purposes.
   ```bash
   composer require monolog/monolog
   ```

## Setup

1. **Codecov API Token**: Obtain your Codecov API token from your Codecov account settings.
2. **Service Class**: Use the `CodecovApiService` class located in the `Src` directory.

## Usage

1. **Initialize the Service**:
   ```php
   $service = new Src\CodecovApiService('your_codecov_api_token');
   ```

2. **Retrieve Pull Requests**:
   ```php
   $pullRequests = $service->getPullRequests('your_repo_id');
   ```

3. **Retrieve Commit Details**:
   ```php
   $commitDetails = $service->getCommitDetails('your_repo_id', 'your_commit_id');
   ```
