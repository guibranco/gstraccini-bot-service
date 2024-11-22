def handle_command(command):
    if command.startswith('@gstraccini batch copy issue'):
        # Parse the command and extract rules
        rules = parse_rules(command)
        # Fetch repositories
        repositories = fetch_repositories()
        # Filter repositories based on rules
        filtered_repos = apply_filters(repositories, rules)
        # Copy issue to filtered repositories
        copy_issue_to_repos(filtered_repos)