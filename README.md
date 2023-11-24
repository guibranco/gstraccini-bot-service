# GStraccini-bot

🤖 :octocat: A GitHub bot for my projects

[![HealthCheck.io Badge](https://healthchecks.io/badge/7751e4f8-141e-4e04-86a0-c19cd9/XxN5wyTi/gstraccini-bot.svg)](https://github.com/apps/gstraccini) [![wakatime](https://wakatime.com/badge/github/guibranco/gstraccini-bot.svg)](https://wakatime.com/badge/github/guibranco/gstraccini-bot) [![Deploy via ftp](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml)
[![PHP Linting](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml)

---

## Commands

That's what I can do :neckbeard::
- `@gstraccini help`: Shows the help message with available commands.
- `@gstraccini hello world`: Says hello to the invoker.
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
