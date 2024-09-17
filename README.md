# GStraccini GitHub Bot

🤖 :octocat: A GitHub bot that keeps your repository organized and healthy by managing pull requests, issues, comments, and commits so you can focus on solving the real problems.

[![Deploy via ftp](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml)
[![PHP Linting](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml)
[![JSON/YAML validation](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml)
[![Shell checker](https://github.com/guibranco/gstraccini-bot/actions/workflows/shell-cheker.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/shell-cheker.yml)

---

## About the bot

[GStraccini-bot](https://bot.straccini.com) keeps your repository organized and healthy by managing pull requests, issues, comments, and commits, so you can focus on solving the real problems.

---

## About this repository

This is the core repository for the bot. It processes every command and executes the actions.

---

## Installation

To install this bot, go to the [GitHub apps page](https://github.com/apps/gstraccini) and install it in your account, organization, or repositories.
The commands this bot can do are listed below, or you can always comment in a pull request with `@gstraccini help` to retrieve an updated list of commands, parameters, and descriptions.

---

## Commands

That's what I can do :neckbeard::

- `@gstraccini help`: Shows the help message with available commands.
- `@gstraccini hello`: Says hello to the invoker.
- `@gstraccini thank you`: Replies with you are welcome message to the invoker.
- `@gstraccini add project <projectPath>`: Adds a project to the solution file (only for **.NET** projects).
- `@gstraccini appveyor build <type>`: Runs the [AppVeyor](https://ci.appveyor.com) build for the target commit and/or pull request.
- `@gstraccini appveyor bump version <component>`: Bumps the CI version in [AppVeyor](https://ci.appveyor.com).
- `@gstraccini appveyor register`: Registers the repository in [AppVeyor](https://ci.appveyor.com).
- `@gstraccini appveyor reset`: Resets the [AppVeyor](https://ci.appveyor.com) build number for the target repository.
- `@gstraccini bump version <version> <project>`: Bumps the [.NET version](https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core) in .csproj files. :warning: (In development, it may not work as expected!)
- `@gstraccini change runner <runner> <workflow> <jobs>`: Changes the [GitHub action runner](https://docs.github.com/en/actions/using-github-hosted-runners/about-github-hosted-runners/about-github-hosted-runners#supported-runners-and-hardware-resources) in a workflow file (.yml). :warning: (In development, it may not work as expected!)
- `@gstraccini csharpier`: Formats the C# code using [CSharpier](https://csharpier.com) (only for **.NET** projects).
- `@gstraccini fix csproj`: Updates the `.csproj` file with the `packages.config` version of [NuGet packages](https://nuget.org) (only for **.NET Framework** projects). :warning: (In development, it may not work as expected!)
- `@gstraccini prettier`: Formats the code using [Prettier](https://prettier.io).
- `@gstraccini rerun failed checks`: This option reruns the failed checks in the target pull request.
- `@gstraccini rerun failed workflows`: This option reruns the failed workflows (action) in the target pull request. It is only available for GitHub Actions!
- `@gstraccini review`: Enable review for the target pull request. This is useful when the PR submitter wasn't on the watch list, the webhook was not captured, or some failed scenario occurred.
- `@gstraccini track`: Tracks the specified pull request. Queue a build, raise a **[dependabot](https://github.com/dependabot) recreate** comment to resolve conflicts, and synchronize merge branches. :warning: (In development, it may not work as expected!)
- `@gstraccini update snapshot`: Update test snapshots (`npm test -- -u`) (only for **Node.js** projects).

Multiple commands can be issued at the same time. Just respect each command pattern (with bot name prefix + command).

> [!NOTE]
>
> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.

> [!TIP]
>
> You can tick (✅) one item from the above list, and it will be triggered! (In beta).

---

## How it works

- [Core](https://github.com/guibranco/gstraccini-bot): The worker for the bot.
- [Webhooks handler](https://github.com/guibranco/gstraccini-bot-handler): The webhooks received/handler.
- [Website/Dashboard](https://github.com/guibranco/gstraccini-bot-website): The landing page and dashboard for the bot.

---

## Cronjobs

The bot handlers on this repository run on my infrastructure at the following intervals:

- ![GStraccini Bot - Branches](https://healthchecks.io/b/2/82d0dec5-3ec1-41cc-8a35-ef1da42899e5.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Comments](https://healthchecks.io/b/2/31b38cb0-f8bd-42b1-b662-d5905b22cd94.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Issues](https://healthchecks.io/b/2/05666a6b-d35f-4cb8-abc8-25584cc9029b.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Pull Requests](https://healthchecks.io/b/2/05c48393-c700-45b4-880f-59cb7b9b9f25.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Pushes](https://healthchecks.io/b/2/1e8724fa-8361-47d7-a4f6-901e8d4ff265.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Repositories](https://healthchecks.io/b/2/4ef0ee6c-38f8-4c79-b9f7-049438bd39a9.svg) - 🕐 every 1 minute
- ![GStraccini Bot - Signature](https://healthchecks.io/b/2/8303206b-2f4c-4300-ac64-5e9cd342c164.svg) - 🕐 every 5 minutes

---

## Links

- [GitHub Marketplace](https://github.com/marketplace/gstraccini-bot)
- [GitHub App](https://github.com/apps/gstraccini)
- [Repository on GitHub](https://github.com/guibranco/gstraccini-bot)
- [handler](https://bot.straccini.com/)

