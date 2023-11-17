# GStraccini-bot

🤖 :octocat: A GitHub bot for my projects

[![HealthCheck.io Badge](https://healthchecks.io/badge/7751e4f8-141e-4e04-86a0-c19cd9/XxN5wyTi/gstraccini-bot.svg)](https://github.com/apps/gstraccini) [![wakatime](https://wakatime.com/badge/github/guibranco/gstraccini-bot.svg)](https://wakatime.com/badge/github/guibranco/gstraccini-bot) [![Deploy via ftp](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/deploy.yml)
[![PHP Linting](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml/badge.svg)](https://github.com/guibranco/gstraccini-bot/actions/workflows/php-lint.yml)

---

# Commands

That's what I can do:
- `@gstraccini help`: Shows the help message with available commands.
- `@gstraccini hello world`: Says hello to the invoker.
- `@gstraccini thank you`: Replies with you are welcome message to the invoker.
- `@gstraccini fix csproj`: Fixes the csproj file with packages.config version of NuGet packages (only for **.NET Framework** projects).
- `@gstraccini csharpier`: Formats the C# code using CSharpier (only for **.NET** projects).

Multiple commands can be issued at same time, just respect each command pattern (with bot name prefix + command).

> **Warning**
> 
> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.
> The allowed invokers are configurable via `config.json` file.
