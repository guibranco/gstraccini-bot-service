import os
from github import Github

def create_github_issue(repo, title, body):
    issue = repo.create_issue(title=title, body=body)
    return issue

def main():
    token = os.getenv('GITHUB_TOKEN')
    if not token:
        raise ValueError("GITHUB_TOKEN environment variable is not set")

    g = Github(token)
    repo = g.get_repo(os.getenv('GITHUB_REPOSITORY'))

    issues_file = ".github/scripts/issues_to_create.txt"
    if not os.path.exists(issues_file):
        print("No issues to create")
        return

    with open(issues_file, "r") as file:
        lines = file.readlines()

    for i in range(0, len(lines), 3):
        file_info = lines[i].strip().split(": ")[1]
        line_number = lines[i+1].strip().split(": ")[1]
        comment_info = lines[i+2].strip().split(": ")[1]
        issue_title = comment_info.split(' ', 1)[1]
        context = f"File: {file_info}\nLine: {line_number}\nComment: {comment_info}"
        create_github_issue(repo, issue_title, context)

if __name__ == "__main__":
    main()