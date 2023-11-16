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

function generateToken(){
    global $gitHubAppId, $gitHubAppPrivateKey;

    $private = openssl_pkey_get_private($gitHubAppPrivateKey);
    $public = openssl_pkey_get_public($private);

    $config = Configuration::forAsymmetricSigner(new RSA(), InMemory::plainText($private), InMemory::plainText($public));
    $now   = new \DateTimeImmutable();

    $token = $config->builder()
    ->issuedBy($gitHubAppId)
    ->issuedAt($now)
    ->expiresAt($now->modify('+10 minutes'))
    ->getToken($config->signer(), $config->signingKey());

    echo $token;
}

generateToken();
