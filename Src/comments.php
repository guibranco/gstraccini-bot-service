<?php

require_once("config.php");

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;


require 'vendor/autoload.php';


function readComments()
{
    global $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase;

    $mysqli = new mysqli($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
    if ($mysqli->connect_errno) {
        die("Failed to connect to MySQL: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");
    $mysqli->query("SELECT * FROM github_comments WHERE Processed = 0");
}

function generateToken()
{
    global $gitHubAppId, $gitHubAppPrivateKey, $gitHubAppPublicKey;

    $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
    $algorithm = new Sha256();
    $signingKey = InMemory::plainText($gitHubAppPrivateKey);
    $base = new \DateTimeImmutable();
    $now = $base->setTime(date('H'), date('i'), date('s'));

    $token = $tokenBuilder
        ->issuedBy($gitHubAppId)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($algorithm, $signingKey);

    echo $token->toString();
}

generateToken();
