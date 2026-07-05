<p align="center">
  <img src="https://raw.githubusercontent.com/guibranco/gstraccini-bot-website/main/src/images/logo-white.png" alt="GStraccini-bot" width="220" />
</p>

<h1 align="center">GStraccini-bot Service</h1>

<p align="center">
  🤖 :octocat: <strong>GStraccini-bot</strong> is a GitHub bot designed to keep your repository organized and healthy by automating tasks like managing pull requests, issues, comments, and commits. This allows you to focus on solving real problems.
</p>

<p align="center">
  <a href="https://github.com/guibranco/gstraccini-bot-service/actions/workflows/build.yml"><img src="https://github.com/guibranco/gstraccini-bot-service/actions/workflows/build.yml/badge.svg" alt="Build"></a>
  <a href="https://github.com/guibranco/gstraccini-bot-service/actions/workflows/deploy.yml"><img src="https://github.com/guibranco/gstraccini-bot-service/actions/workflows/deploy.yml/badge.svg" alt="Deploy via FTP"></a>
</p>

<p align="center">
  <a href="https://bot.straccini.com"><strong>Live Dashboard</strong></a> ·
  <a href="https://docs.bot.straccini.com"><strong>Documentation</strong></a> ·
  <a href="https://docs.bot.straccini.com/docs/commands"><strong>Commands Reference</strong></a> ·
  <a href="https://github.com/apps/gstraccini"><strong>Install the Bot</strong></a>
</p>

---

## 🤖 About the Bot

[GStraccini-bot](https://bot.straccini.com) automates essential repository tasks, managing pull requests, issues, comments, and commits to help maintain a clean, organized, healthy project environment. This lets you focus on development and problem-solving.

## 🌐 About This Repository

This repository contains the core daemon/workers for [GStraccini-bot](https://github.com/apps/gstraccini), available at **[bot.straccini.com](https://bot.straccini.com)**.

## 🚀 Installation

To install the bot:

1. Visit the [GitHub Apps page](https://github.com/apps/gstraccini).
2. Install it for your account, organization, or selected repositories.

You can see an updated list of available commands by commenting `@gstraccini help` on a pull request or issue, or by browsing the [documentation website](https://docs.bot.straccini.com).

## ⚡ Available Commands

GStraccini-bot can handle various tasks through chat commands — from managing pull requests to running linters, formatters, and CI/CD actions.

For the full, up-to-date list of commands and how to use them, see the **[Commands Reference](https://docs.bot.straccini.com/docs/commands)** on the documentation website.

> [!NOTE]
> If you are not allowed to use the bot, a thumbs-down reaction will be added to your comment.

## 🧩 How It Works

GStraccini-bot uses several components to manage repositories:

| Component | Description |
| --- | --- |
| [API](https://github.com/guibranco/gstraccini-bot-api) | The bot's API project. Stats and configuration endpoints. |
| [Docs](https://github.com/guibranco/gstraccini-bot-docs) | The bot's documentation ([website](https://docs.bot.straccini.com)). |
| [Handler](https://github.com/guibranco/gstraccini-bot-handler) | Handles incoming webhooks. |
| [Service](https://github.com/guibranco/gstraccini-bot-service) | The bot's service project. The main worker who processes tasks. |
| [Website](https://github.com/guibranco/gstraccini-bot-website) | Provides the bot's landing page and dashboard. |
| [Workflows](https://github.com/guibranco/gstraccini-bot-workflows) | Executes GitHub Actions. |

## ⏱️ Cronjobs

GStraccini-bot runs automated tasks at regular intervals on its infrastructure:

| Job | Status | Interval |
| --- | --- | --- |
| Branches | ![Branches](https://healthchecks.io/b/3/82d0dec5-3ec1-41cc-8a35-ef1da42899e5.svg) | 🕛 every 1 minute |
| Comments | ![Comments](https://healthchecks.io/b/3/31b38cb0-f8bd-42b1-b662-d5905b22cd94.svg) | 🕛 every 1 minute |
| Installations | ![Installations](https://healthchecks.io/b/3/ca0a08b4-8bd7-4d24-85ef-7b0623d8cb0b.svg) | 🕛 every 1 minute |
| Issues | ![Issues](https://healthchecks.io/b/3/05666a6b-d35f-4cb8-abc8-25584cc9029b.svg) | 🕛 every 1 minute |
| Pull Requests | ![Pull Requests](https://healthchecks.io/b/3/05c48393-c700-45b4-880f-59cb7b9b9f25.svg) | 🕛 every 1 minute |
| Pushes | ![Pushes](https://healthchecks.io/b/3/1e8724fa-8361-47d7-a4f6-901e8d4ff265.svg) | 🕛 every 1 minute |
| Repositories | ![Repositories](https://healthchecks.io/b/3/4ef0ee6c-38f8-4c79-b9f7-049438bd39a9.svg) | 🕛 every 1 minute |
| Users | ![Users](https://healthchecks.io/b/3/fb14f4cc-0ee1-4da2-94eb-bd5f3be46e9b.svg) | 🕛 every 1 minute |

## 🔗 Useful Links

- 🛒 [GitHub Marketplace](https://github.com/marketplace/gstraccini-bot)
- 📦 [GitHub App](https://github.com/apps/gstraccini)
- 💻 [Repository on GitHub](https://github.com/guibranco/gstraccini-bot-website)
- 📊 [Bot's Dashboard](https://bot.straccini.com)
- 📚 [Bot's Documentation](https://docs.bot.straccini.com)
- ⚡ [Commands Reference](https://docs.bot.straccini.com/docs/commands)
