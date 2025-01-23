# ![GStraccini-bot](https://raw.githubusercontent.com/guibranco/gstraccini-bot-website/main/src/images/logo.png)

🤖 :octocat: **GStraccini-bot** is a GitHub bot designed to keep your repository organized and healthy by automating tasks like managing pull requests, issues, comments, and commits. This allows you to focus on solving real problems.

[![Deploy via ftp](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml)
[![PHP Linting](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml)
[![JSON/YAML validation](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/json-yaml-lint.yml)
[![Shell checker](https://github.com/guibranco/gstraccini-bot/actions/workflows/shell-cheker.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/shell-cheker.yml)

---

## About the Bot

[GStraccini-bot](https://bot.straccini.com) automates essential repository tasks, managing pull requests, issues, comments, and commits to help maintain a clean, organized, healthy project environment. This lets you focus on development and problem-solving.

---

## About This Repository

This repository serves as the core for GStraccini-bot. It processes commands and actions, enabling the bot to automate your repository.

---

## Installation

To install the bot:

1. Visit the [GitHub Apps page](https://github.com/apps/gstraccini).
2. Install it for your account, organization, or selected repositories.

You can see an updated list of available commands by commenting `@gstraccini help` on a pull request.

---

## Available Commands

GStraccini-bot can handle various tasks. Here’s a list of commands:

### Commands

- `@gstraccini help`: Shows the help message with available commands.
- `@gstraccini add project <projectPath>`: Adds a project to the solution file (only for .NET projects).
- `@gstraccini appveyor build <type>`: Runs the AppVeyor build for the target commit and/or pull request.
- `@gstraccini appveyor bump version <component>`: Bumps the CI version in AppVeyor.
- `@gstraccini appveyor register`: Registers the repository in AppVeyor.
- `@gstraccini appveyor reset`: Resets the AppVeyor build number for the target repository.
- `@gstraccini bump version <version> <project>`: Bumps the .NET version in .csproj files.
- `@gstraccini cargo clippy`: Formats the Rust code using Cargo Clippy (only for Rust projects).
- `@gstraccini change runner <runner> <workflow> <jobs>`: Changes the GitHub action runner in a workflow file (.yml).
- `@gstraccini codacy bypass`: Bypasses the Codacy analysis for the target commit and/or pull request.
- `@gstraccini codacy reanalyze commit`: Reanalyzes the Codacy last commit in a pull request.
- `@gstraccini codeclimate bypass`: Bypasses the CodeClimate analysis for the target commit and/or pull request.
- `@gstraccini copy labels <repository>`: Copies the labels from another repository.
- `@gstraccini copy issue <repository>`: Copies an issue from one repository to another.
- `@gstraccini create labels <style> <categories>`: Creates the default labels in the repository.
- `@gstraccini csharpier`: Formats the C# code using CSharpier (only for .NET projects).
- `@gstraccini fix csproj`: Updates the .csproj file with the packages.config version of NuGet packages (only for .NET Framework projects).
- `@gstraccini npm check updates`: Updates dependencies in a package.json and package-lock.json.
- `@gstraccini npm dist`: Generates or regenerates the dist files.
- `@gstraccini prettier`: Formats the code using Prettier.
- `@gstraccini rerun checks <conclusion>`: Reruns the checks in the target pull request.
- `@gstraccini rerun workflows <conclusion>`: Reruns the workflows (actions) in the target pull request.
- `@gstraccini review`: Enables review for the target pull request.
- `@gstraccini update snapshot`: Updates test snapshots (npm test -- -u) (only for Node.js projects).

> [!Note]
> If you are not allowed to use the bot, a thumbs-down reaction will be added to your comment.

> [!Tip]
> You can trigger commands with a ✅ tick (beta feature).

---

## How It Works

GStraccini-bot uses several components to manage repositories:

- [API](https://github.com/guibranco/gstraccini-bot-api): The bot’s API project. Stats and configuration endpoints.
- [Docs](https://github.com/guibranco/gstraccini-bot-docs): The bot's documentation.
- [Handler](https://github.com/guibranco/gstraccini-bot-handler): Handles incoming webhooks.
- [Service](https://github.com/guibranco/gstraccini-bot-service): The bot's service project. Main worker that processes tasks
- [Website](https://github.com/guibranco/gstraccini-bot-website): Provides the bot's landing page and dashboard.
- [Workflows](https://github.com/guibranco/gstraccini-bot-workflows): Execute GitHub Actions.

---

## Cronjobs

GStraccini-bot runs automated tasks at regular intervals on its infrastructure:

- ![Branches](https://healthchecks.io/b/3/82d0dec5-3ec1-41cc-8a35-ef1da42899e5.svg) – 🕛 every 1 minute
- ![Comments](https://healthchecks.io/b/3/31b38cb0-f8bd-42b1-b662-d5905b22cd94.svg) – 🕛 every 1 minute
- ![Issues](https://healthchecks.io/b/3/05666a6b-d35f-4cb8-abc8-25584cc9029b.svg) – 🕛 every 1 minute
- ![Pull Requests](https://healthchecks.io/b/3/05c48393-c700-45b4-880f-59cb7b9b9f25.svg) – 🕛 every 1 minute
- ![Pushes](https://healthchecks.io/b/3/1e8724fa-8361-47d7-a4f6-901e8d4ff265.svg) – 🕛 every 1 minute
- ![Repositories](https://healthchecks.io/b/3/4ef0ee6c-38f8-4c79-b9f7-049438bd39a9.svg) – 🕛 every 1 minute
- ![Signature](https://healthchecks.io/b/3/8303206b-2f4c-4300-ac64-5e9cd342c164.svg) – 🕛 every 5 minutes

---

## Useful Links

- [GitHub Marketplace](https://github.com/marketplace/gstraccini-bot)
- [GitHub App](https://github.com/apps/gstraccini)
- [Repository on GitHub](https://github.com/guibranco/gstraccini-bot-service)
- [Bot's Dashboard](https://bot.straccini.com)
- [Bot's Documentation](https://docs.bot.straccini.com)
