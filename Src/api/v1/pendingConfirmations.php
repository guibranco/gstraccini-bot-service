<?php

// Pending confirmations endpoint - lists the bot comments waiting for a user
// confirmation (version bump decision, .infisicalignore approval) for the UI,
// scoped to the installations of a user.
//
// The bootstrap checks the API key, the configuration and the userId, and
// opens $mysqli; $userIdInt holds the user.
require_once __DIR__.'/includes/userApiBootstrap.php';

$requestError = null;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    $requestError = [405, 'Method Not Allowed'];
}

$allowedStatuses = explode(',', 'pending,resolved,cancelled,all');
$status          = ($_GET['status'] ?? 'pending');
if ($requestError === null && in_array($status, $allowedStatuses, true) === false) {
    $requestError = [400, 'Invalid status, expected one of: '.implode(', ', $allowedStatuses)];
}

if ($requestError !== null) {
    $mysqli->close();
    http_response_code($requestError[0]);
    echo json_encode(['error' => $requestError[1]]);
    exit;
}

// Closed items are history: only the last 100 are listed.
$sql = 'SELECT Sequence, Kind, RepositoryOwner, RepositoryName, InstallationId, PullRequestNumber,
        PullRequestTitle, PullRequestAuthor, CommentId, Url, Status, Resolution, ResolvedAt,
        ReminderCount, LastReminderAt, CreatedAt
    FROM pending_confirmations
    WHERE InstallationId IN (SELECT InstallationId FROM user_installations WHERE UserId = ? AND RemovedAt IS NULL)';
if ($status !== 'all') {
    $sql .= ' AND Status = ?';
}

$sql .= ' ORDER BY CreatedAt DESC LIMIT 100';


/**
 * Maps a row of the table to the JSON shape of the API.
 *
 * @param array $row The row.
 *
 * @return array
 */
function pendingConfirmationToJson(array $row): array
{
    $item                    = [];
    $item['sequence']        = (int) $row['Sequence'];
    $item['kind']            = $row['Kind'];
    $item['repositoryOwner'] = $row['RepositoryOwner'];
    $item['repositoryName']  = $row['RepositoryName'];
    $item['installationId']  = (int) $row['InstallationId'];
    $item['pullRequestNumber'] = (int) $row['PullRequestNumber'];
    $item['pullRequestTitle']  = $row['PullRequestTitle'];
    $item['pullRequestAuthor'] = $row['PullRequestAuthor'];
    $item['commentId']         = (int) $row['CommentId'];
    $item['url']               = $row['Url'];
    $item['status']            = $row['Status'];
    $item['resolution']        = $row['Resolution'];
    $item['resolvedAt']        = $row['ResolvedAt'];
    $item['reminderCount']     = (int) $row['ReminderCount'];
    $item['lastReminderAt']    = $row['LastReminderAt'];
    $item['createdAt']         = $row['CreatedAt'];

    return $item;

}//end pendingConfirmationToJson()


/**
 * Lists the confirmations of a user.
 *
 * @param mysqli  $mysqli The connection.
 * @param string  $sql    The query.
 * @param integer $userId The user.
 * @param string  $status The status to list, or `all`.
 *
 * @return array|null The confirmations, or null when the query failed.
 */
function fetchPendingConfirmations(mysqli $mysqli, string $sql, int $userId, string $status): ?array
{
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        return null;
    }

    $types  = 'i';
    $params = [$userId];
    if ($status !== 'all') {
        $types   .= 's';
        $params[] = $status;
    }

    $stmt->bind_param($types, ...$params);

    $confirmations = null;
    $result        = false;
    if ($stmt->execute() === true) {
        $result = $stmt->get_result();
    }

    if ($result !== false) {
        $confirmations = array_map('pendingConfirmationToJson', $result->fetch_all(MYSQLI_ASSOC));
        $result->close();
    }

    $stmt->close();

    return $confirmations;

}//end fetchPendingConfirmations()


try {
    $confirmations = fetchPendingConfirmations($mysqli, $sql, (int) $userIdInt, $status);
} catch (Throwable $e) {
    $confirmations = null;
} finally {
    $mysqli->close();
}

if ($confirmations === null) {
    sendUserApiError('Database unavailable');
    exit;
}

http_response_code(200);
echo json_encode($confirmations);
