import os
import yaml
from github import Github

def get_version_from_file(file_path):
    with open(file_path, 'r') as file:
        content = yaml.safe_load(file)
        return content.get('version', '')

def post_comment(repo, pr_number, message):
    token = os.getenv('GITHUB_TOKEN')
    g = Github(token)
    repo = g.get_repo(repo)
    pr = repo.get_pull(pr_number)
    pr.create_issue_comment(message)

def main():
    repo_name = os.getenv('GITHUB_REPOSITORY')
    pr_number = int(os.getenv('GITHUB_REF').split('/')[-2])

    # Get the base and head versions of the appveyor.yml file
    base_version = get_version_from_file('appveyor.yml')
    head_version = get_version_from_file('appveyor.yml')

    if base_version != head_version:
        message = (
            f"Detected a version change from {base_version} to {head_version} in appveyor.yml. "
            "Please consider resetting the build number to maintain consistency."
        )
        post_comment(repo_name, pr_number, message)

if __name__ == '__main__':
    main()
