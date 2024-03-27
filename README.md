# GStraccini GitHub Bot

🤖 :octocat: A GitHub bot that runs on issues, pull requests, and pull request comments.

[![HealthCheck.io Badge](https://healthchecks.io/badge/7751e4f8-141e-4e04-86a0-c19cd9/XxN5wyTi/gstraccini-bot.svg)](https://github.com/apps/gstraccini)
[![Deploy via ftp](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml)
[![PHP Linting](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml)
[![JSON/YAML validation](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml)
[![Shell checker](https://github.com/guibranco/gstraccini-bot/actions/workflows/shell-cheker.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/shell-cheker.yml)

---

## Installation

To install this bot, proceed to the [GitHub apps' page](https://github.com/apps/gstraccini) and install it in your account/organization/repositories.
The commands that this bot can do are listed below, or you can always comment in a pull request with `@gstraccini help` to retrieve an updated list of commands, parameters, and descriptions.

---

## Commands

That's what I can do :neckbeard::
- `@gstraccini help`: Shows the help message with available commands.
- `@gstraccini hello`: Says hello to the invoker.
- `@gstraccini thank you`: Replies with you are welcome message to the invoker.
- `@gstraccini appveyor build <type>`: Runs the [AppVeyor](https://ci.appveyor.com) build for the target commit and/or pull request.
	- `type`: `[required]` Specifies if it should trigger a build in a `commit` or `pull request`.
- `@gstraccini appveyor register`: Registers the repository in [AppVeyor](https://ci.appveyor.com).
- `@gstraccini appveyor reset`: Resets the [AppVeyor](https://ci.appveyor.com) build number for the target repository.
- `@gstraccini bump version <version> <project>`: Bumps the [.NET version](https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core) in .csproj files. :warning: (in development - maybe not working as expected!)
	- `version`: `[required]` The .NET version
	- `project`: `[optional]` The `.csproj` file to update. Suppressing this parameter will run the command in all `.csproj` in the repository/branch.
- `@gstraccini change runner <runner> <workflow> <jobs>`: Changes the [GitHub action runner](https://docs.github.com/en/actions/using-github-hosted-runners/about-github-hosted-runners/about-github-hosted-runners#supported-runners-and-hardware-resources) in a workflow file (.yml). :warning: (in development - maybe not working as expected!)
	- `runner`: `[required]` The runner's name
	- `workflow`: `[required]` The workflow filename (with or without the .yml/.yaml extension).
	- `jobs`: `[optional]` The jobs to apply this command. Suppressing this parameter will run the command in all jobs within the workflow.
- `@gstraccini csharpier`: Formats the C# code using [CSharpier](https://csharpier.com) (only for **.NET** projects).
- `@gstraccini fix csproj`: Updates the `.csproj` file with the `packages.config` version of [NuGet packages](https://nuget.org) (only for **.NET Framework** projects). :warning: (in development - maybe not working as expected!)
- `@gstraccini prettier`: Formats the code using [Prettier](https://prettier.io).
- `@gstraccini review`: Enable review for the target pull request. This is useful when the PR submitter wasn't on the watch list, the webhook was not captured, or some failed scenario occurred.
- `@gstraccini track`: Tracks the specified pull request. Queue a build, raise a **[dependabot](https://github.com/dependabot) recreate** comment to resolve conflicts, and synchronize merge branches. :warning: (in development - maybe not working as expected!)
- `@gstraccini update snapshot`: Update test snapshots (`npm test -- -u`) (only for **Node.js** projects).


Multiple commands can be issued at the same time. Just respect each command pattern (with bot name prefix + command).

> **Warning**
> 
> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.

---

## How it works

This project is just part of the overall process.
Currently, there is another (still private) repository that works with this one to provide all the necessary data and metadata for the actions.

---

### Webhooks

Once you install the [GStraccini-bot GitHub app](https://github.com/apps/gstraccini), GitHub will start sending some webhooks to a registered endpoint for some events. Once these webhooks reach the handler, they will be stored in SQL database tables to be processed later by this bot.

If you are interested in hosting your own instance, let me know, and I will share with you the database schemas and scripts and the procedure to create your own GitHub app to receive the events on your infrastructure.

---

### Cronjobs

The bot handlers on this repository run on my own infrastructure with the following intervals:

- ![GStraccini Bot - Branches](https://healthchecks.io/b/2/82d0dec5-3ec1-41cc-8a35-ef1da42899e5.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Comments](https://healthchecks.io/b/2/31b38cb0-f8bd-42b1-b662-d5905b22cd94.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Issues](https://healthchecks.io/b/2/05666a6b-d35f-4cb8-abc8-25584cc9029b.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Pull Requests](https://healthchecks.io/b/2/05c48393-c700-45b4-880f-59cb7b9b9f25.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Signature](https://healthchecks.io/b/2/8303206b-2f4c-4300-ac64-5e9cd342c164.svg) - 🕐 every 5 minutes
