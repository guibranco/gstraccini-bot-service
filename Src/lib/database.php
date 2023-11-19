<?php 

function connectToDatabase()
{

    global $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase;

    $mysqli = new mysqli($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
    if ($mysqli->connect_errno) {
        die("Failed to connect to MySQL: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");

    return $mysqli;
}

function readComments()
{
    $mysqli = connectToDatabase();
    $result = $mysqli->query("SELECT * FROM github_comments WHERE Processed = 0 LIMIT 10");

    if (!$result) {
        return null;
    }

    $comments = array();

    while ($obj = $result->fetch_object()) {
        $comments[] = $obj;
    }

    $result->close();
    $mysqli->close();

    return $comments;
}

function updateComment($commentSequence)
{
    $mysqli = connectToDatabase();
    $sql = "UPDATE github_comments SET Processed = 1, ProcessedDate = NOW() WHERE Sequence = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $commentSequence);

    $stmt->execute();
    $stmt->close();
    $mysqli->close();
}
