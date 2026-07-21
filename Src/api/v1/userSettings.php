<?php

// User settings endpoint - reads and updates the signed-in user's bot
// behavior toggles (auto-merge, auto-review, label creation, etc.),
// applied across all of that user's installations.

header('Content-Type: application/json');

$apiSecretsFile = __DIR__ . '/secrets/api.secrets.php';
if (file_exists($apiSecretsFile)) {
    require_once $apiSecretsFile;
}

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($providedKey) || !isset($apiKey) || !hash_equals($apiKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($workerDir) || !is_dir($workerDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Worker directory not configured']);
    exit;
}

require_once $workerDir . '/Library/DatabaseHandler.php';

$mySqlSecretsFile = $workerDir . '/secrets/mySql.secrets.php';
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

if (!isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not configured']);
    exit;
}

$userId = $_GET['userId'] ?? null;
if ($userId === null || !ctype_digit((string) $userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}
$userIdInt = (int) $userId;

$defaults = [
    'createLabels' => true,
    'notifyIssues' => true,
    'requireAcceptanceCriteriaChecklist' => true,
    'reminderIssues' => true,
    'reminderIssuesDays' => 10,
    'prTemplateDescription' => true,
    'autoReviewPr' => true,
    'autoMergePr' => true,
    'createIssue' => true,
    'notifyPullRequests' => true,
];

$dbHandler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);

try {
    $mysqli = $dbHandler->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $mysqli->prepare(
        "SELECT CreateLabels, NotifyIssues, RequireAcceptanceCriteriaChecklist, ReminderIssues, ReminderIssuesDays,
                PrTemplateDescription, AutoReviewPr, AutoMergePr, CreateIssue, NotifyPullRequests
         FROM user_settings WHERE UserId = ?"
    );
    $stmt->bind_param("i", $userIdInt);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $mysqli->close();

    if ($row === null) {
        http_response_code(200);
        echo json_encode($defaults);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'createLabels' => (bool) $row['CreateLabels'],
        'notifyIssues' => (bool) $row['NotifyIssues'],
        'requireAcceptanceCriteriaChecklist' => (bool) $row['RequireAcceptanceCriteriaChecklist'],
        'reminderIssues' => (bool) $row['ReminderIssues'],
        'reminderIssuesDays' => $row['ReminderIssuesDays'] !== null ? (int) $row['ReminderIssuesDays'] : null,
        'prTemplateDescription' => (bool) $row['PrTemplateDescription'],
        'autoReviewPr' => (bool) $row['AutoReviewPr'],
        'autoMergePr' => (bool) $row['AutoMergePr'],
        'createIssue' => (bool) $row['CreateIssue'],
        'notifyPullRequests' => (bool) $row['NotifyPullRequests'],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $createLabels = !empty($body['createLabels']) ? 1 : 0;
    $notifyIssues = !empty($body['notifyIssues']) ? 1 : 0;
    $requireAcceptanceCriteriaChecklist = !empty($body['requireAcceptanceCriteriaChecklist']) ? 1 : 0;
    $reminderIssues = !empty($body['reminderIssues']) ? 1 : 0;
    $reminderIssuesDays = isset($body['reminderIssuesDays']) && ctype_digit((string) $body['reminderIssuesDays'])
        ? (int) $body['reminderIssuesDays']
        : null;
    $prTemplateDescription = !empty($body['prTemplateDescription']) ? 1 : 0;
    $autoReviewPr = !empty($body['autoReviewPr']) ? 1 : 0;
    $autoMergePr = !empty($body['autoMergePr']) ? 1 : 0;
    $createIssue = !empty($body['createIssue']) ? 1 : 0;
    $notifyPullRequests = !empty($body['notifyPullRequests']) ? 1 : 0;

    $stmt = $mysqli->prepare(
        "INSERT INTO user_settings
            (UserId, CreateLabels, NotifyIssues, RequireAcceptanceCriteriaChecklist, ReminderIssues, ReminderIssuesDays,
             PrTemplateDescription, AutoReviewPr, AutoMergePr, CreateIssue, NotifyPullRequests)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            CreateLabels = VALUES(CreateLabels),
            NotifyIssues = VALUES(NotifyIssues),
            RequireAcceptanceCriteriaChecklist = VALUES(RequireAcceptanceCriteriaChecklist),
            ReminderIssues = VALUES(ReminderIssues),
            ReminderIssuesDays = VALUES(ReminderIssuesDays),
            PrTemplateDescription = VALUES(PrTemplateDescription),
            AutoReviewPr = VALUES(AutoReviewPr),
            AutoMergePr = VALUES(AutoMergePr),
            CreateIssue = VALUES(CreateIssue),
            NotifyPullRequests = VALUES(NotifyPullRequests)"
    );
    $stmt->bind_param(
        "iiiiiiiiiii",
        $userIdInt,
        $createLabels,
        $notifyIssues,
        $requireAcceptanceCriteriaChecklist,
        $reminderIssues,
        $reminderIssuesDays,
        $prTemplateDescription,
        $autoReviewPr,
        $autoMergePr,
        $createIssue,
        $notifyPullRequests
    );
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'updated']);
    exit;
}

$mysqli->close();
http_response_code(405);
header('Allow: GET, PUT');
echo json_encode(['error' => 'Method Not Allowed']);
