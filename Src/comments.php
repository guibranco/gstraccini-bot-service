<?php

require_once("config.php");

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RSA;


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

    $config = Configuration::forAsymmetricSigner(new RSA(), InMemory::plainText($gitHubAppPrivateKey), InMemory::plainText($gitHubAppPublicKey));
    $now = new \DateTimeImmutable();

    $token = $config->builder()
        ->issuedBy($gitHubAppId)
        ->issuedAt($now->modify('-1 minute')
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($config->signer(), $config->signingKey());

    echo $token->toString();
}

generateToken();
