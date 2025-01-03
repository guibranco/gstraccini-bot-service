# Contributing to GStraccini-bot-service 🤖✨

Thank you for your interest in contributing to **GStraccini-bot-service**! 🎉 This repository serves as the core/handler of the GStraccini-bot, processing messages and managing data in SQL tables. Your contributions ensure the bot remains efficient and reliable.

## Getting Started 🚀

Follow these steps to start contributing:

1. **Fork the Repository** 🍴: Fork the repository to your GitHub account.
2. **Clone Your Fork** 🖥️: Clone your forked repository locally using:

   ```bash
   git clone https://github.com/your-username/gstraccini-bot-service.git
   ```

3. **Set Up Your Environment** 🛠️:

   - This project requires **PHP 8.3**.
   - Use **Docker Compose** for a fully functional local environment:

     ```bash
     docker-compose up
     ```

   - Ensure your integrations are set up by creating or editing the secrets in the `secrets/` directory.

4. **Create a Branch** 🌿: Create a branch for your feature or fix:

   ```bash
   git checkout -b feature/your-feature-name
   ```

## Development Guidelines ✍️

- **Code Standards** 🧹: Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards. Use linters to maintain code quality.
- **Testing** 🧪:

  - All new features and fixes **must include PHPUnit tests**.
  - Run the test suite to ensure everything works:

    ```bash
    vendor/bin/phpunit
    ```

- **Commit Messages** 📜: Use clear, concise, and descriptive commit messages. Follow the [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) specification.
- **Documentation** 📚: If your changes affect functionality, update or add relevant documentation.

## Submitting Changes 📨

1. **Push Your Branch** ⬆️: Push your changes to your forked repository:

   ```bash
   git push origin feature/your-feature-name
   ```

2. **Open a Pull Request** 🔄:

   - Go to the original repository and open a pull request (PR).
   - Provide a clear and detailed description of your changes.

3. **Work with Reviewers** 👥:
   - Be open to feedback and suggestions from reviewers.
   - Update your PR as needed.

## Code of Conduct 🌟

Please follow our [Code of Conduct](CODE_OF_CONDUCT.md) to maintain a welcoming and inclusive environment for everyone.

## Tips and Resources 💡

- **Local Environment Debugging** 🐛: Use the Docker Compose environment for testing integrations and debugging locally.
- **Testing Secrets** 🔑: Store integration credentials in the `secrets/` directory for local tests.
- **Reference the README** 📖: For additional context, see the [README.md](README.md).

## Need Help? 🤔

If you encounter any issues or have questions, don’t hesitate to:

- Open a [GitHub Discussion](https://github.com/guibranco/gstraccini-bot-service/discussions).
- File an issue in the repository.

Your contributions are highly valued and appreciated! Thank you for helping improve **GStraccini-bot-service**. 💖

Happy coding! 🚀
