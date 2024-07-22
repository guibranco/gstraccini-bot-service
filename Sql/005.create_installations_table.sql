CREATE TABLE `installations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `installation_id` BIGINT NOT NULL,
  `github_token` VARCHAR(255) NOT NULL,
  `refresh_token` VARCHAR(255) NOT NULL,
  `appveyor_token` VARCHAR(255),
  `sonarcloud_token` VARCHAR(255),
  `codeclimate_token` VARCHAR(255),
  `snyk_token` VARCHAR(255),
  PRIMARY KEY (`id`)
);