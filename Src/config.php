<?php

ini_set("default_charset", "UTF-8");
ini_set("date.timezone", "America/Sao_Paulo");
mb_internal_encoding("UTF-8");

$mySqlSecretsFile = "mySql.secrets.php";
if (file_exists($mySqlSecretsFile)) {
  require_once $mySqlSecretsFile;
}

