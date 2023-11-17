<?php

require_once("config.php");
require_once("functions.php");

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

require 'vendor/autoload.php';

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

function generateAppToken()
{
    global $gitHubAppId, $gitHubAppPrivateKey;

    $tokenBuilder = new Builder(new JoseEncoder(), ChainedFormatter::default());
    $algorithm = new Sha256();
    $signingKey = InMemory::plainText($gitHubAppPrivateKey);
    $base = new \DateTimeImmutable();
    $now = $base->setTime(date('H'), date('i'), date('s'));

    $token = $tokenBuilder
        ->issuedBy($gitHubAppId)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+5 minutes'))
        ->getToken($algorithm, $signingKey);

    return $token->toString();
}

function generateInstallationToken($installationId, $repositoryName)
{
    $gitHubAppToken = generateAppToken();

    $data = new \stdClass();
    $data->repository = $repositoryName;
    $response = requestGitHub($gitHubAppToken, "app/installations/" . $installationId . "/access_tokens", $data);

    $json = json_decode($response["body"]);
    return $json->token;
}

function requestGitHub($gitHubToken, $url, $data = null)
{

    $baseUrl = "https://api.github.com/";

    $headers = array();
    $headers[] = "User-Agent: " . USER_AGENT;
    $headers[] = "Content-type: application/json";
    $headers[] = "Authorization: Bearer " . $gitHubToken;

    $fields = array(
        CURLOPT_URL => $baseUrl . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => 1,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_HTTPHEADER => $headers
    );

    if ($data !== null) {
        $fields[CURLOPT_POST] = true;
        $fields[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    $curl = curl_init();

    curl_setopt_array($curl, $fields);

    $response = curl_exec($curl);

    if ($response === false) {
        echo htmlspecialchars($url);
        echo "\r\n";
        die(curl_error($curl));
    }

    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $headers = getHeaders($header);
    $body = substr($response, $headerSize);
    curl_close($curl);

    return array("headers" => $headers, "body" => $body);
}

function handleComment($comment)
{
    $commentBody = strtolower($comment->CommentBody);
    $token = generateInstallationToken($comment->InstallationId, $comment->RepositoryName);

    $reactionUrl = "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/comments/" . $comment->CommentId . "/reactions";
    $commentUrl = "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/" . $comment->IssueNumber . "/comments";

    if ($comment->CommentUser !== "guibranco") {
        requestGitHub($token, $reactionUrl, array("content" => "-1"));
        return;
    }

    requestGitHub($token, $reactionUrl, array("content" => "eyes"));

    if ($commentBody == "@gstraccini hello world") {
        requestGitHub($token, $commentUrl, array("body" => "Hello @" . $comment->CommentUser . "!"));
        requestGitHub($token, $reactionUrl, array("content" => "heart"));
        return;
    }

    if ($commentBody == "@gstraccini thank you") {
        requestGitHub($token, $commentUrl, array("body" => "You're welcome @" . $comment->CommentUser . "!"));
        requestGitHub($token, $reactionUrl, array("content" => "+1"));
        return;
    }

    if ($commentBody == "@gstraccini help"){
        requestGitHub($token, $commentUrl, array("body"=> "That's what I can do: \r\n - @gstraccini hello world \r\n - @gstraccini thank you \r\n - @gstraccini help"));
        requestGitHub($token, $reactionUrl, array("content"=> "rocker"));
        return;
    }
    
    requestGitHub($token, $commentUrl, array("body" => "I'm sorry @" . $comment->CommentUser . ", I can't do that."));
    requestGitHub($token, $reactionUrl, array("content" => "+1"));
    return;
}


function main()
{
    $comments = readComments();
    foreach ($comments as $comment) {
        handleComment($comment);
        updateComment($comment->Sequence);
    }
}

sendHealthCheck("/start");
main();
sendHealthCheck();
