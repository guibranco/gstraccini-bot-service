# GStraccini GitHub Bot

🤖 :octocat: A GitHub bot that runs on pull request and pull requests comments.

[![HealthCheck.io Badge](https://healthchecks.io/badge/7751e4f8-141e-4e04-86a0-c19cd9/XxN5wyTi/gstraccini-bot.svg)](https://github.com/apps/gstraccini) [![wakatime](https://wakatime.com/badge/github/guibranco/gstraccini-bot.svg)](https://wakatime.com/badge/github/guibranco/gstraccini-bot) [![Deploy via ftp](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml)
[![PHP Linting](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml)
[![JSON/YAML validation](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml)

---

## Installation

To install this bot proceed to the [GitHub apps's page](https://github.com/apps/gstraccini) and install in your account/organization/repositories.
The commands that this bot can do are listed below, or you can always comment in a pull request with `@gstraccini help` to retrieve an updated list of commands, parameters and description.

---

## Commands

That's what I can do :neckbeard::

- `@gstraccini help`: Shows the help message with available commands.
- `@gstraccini hello`: Says hello to the invoker.
- `@gstraccini thank you`: Replies with you are welcome message to the invoker.
- `@gstraccini bump version <version> <project>`: Bumps the .NET version in .csproj files.
  - `version`: `[required]` The .NET version
  - `project`: `[optional]` The .csproj file to update. Suppressing this parameter will run the command in all .csproj in the repository/branch
- `@gstraccini change runner <runner> <workflow> <jobs>`: Changes the GitHub action runner in a workflow file (.yml)
  - `runner`: `[required]` The runner's name
  - `workflow`: `[required]` The workflow filename (with or without the .yml/.yaml extension)
  - `jobs`: `[optional]` The job's to apply this command. Suppressing this parameter will run the command in all jobs within the workflow
- `@gstraccini csharpier`: Formats the C# code using CSharpier (only for **.NET** projects).
- `@gstraccini fix csproj`: Fixes the csproj file with packages.config version of NuGet packages (only for **.NET Framework** projects).
- `@gstraccini review`: Enable review for the target pull request. This is useful when the PR submitter wasn't in the watch list before or the webhook was not captured or some failed scenario occurred.
- `@gstraccini track`: Tracks the specified pull request. Queue a build, raise **[dependabot](https://github.com/dependabot) recreate** comment to resolve conflicts and synchronize merge branches.

Multiple commands can be issued at the same time, just respect each command pattern (with bot name prefix + command).

> **Warning**
>
> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.
> The allowed invokers are configurable via the `config.json` file.

---

## How it works

This project is just part of the overall process.
Currently there is another (still private) repository that works with this one to provide all the necessary data and metadata for the actions.

### Webhooks

Once you install the [GStraccini-bot GitHub app](https://github.com/apps/gstraccini), GitHub will start sending some webhooks to a registered endpoint for some events (related to pull requests), once theses webhooks reaches the handler, it will be stored in SQL database tables to be processed later by this bot.

If you are interested in host your own instance, let me know, I will share with you (or if its is already public I need to remove this text from here) the database schemas and scripts and the procedure to create your own GitHub app to receive the events on your infrastructure.

### Cronjobs

The two bot-handlers on this repository (comments.php and pullRequests.php) runs on my own infrastructure with the following intervals:

- comments: every 1 minute
- pull requests: every 5 minutes
