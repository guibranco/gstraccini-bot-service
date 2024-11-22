import requests

def copy_issue_to_repos(repositories):
    for repo in repositories:
        copy_issue(repo)

def copy_issue(repo):
    # Use GitHub API to copy the issue
    url = f'https://api.github.com/repos/{repo['full_name']}/issues'
    headers = {'Authorization': 'token YOUR_GITHUB_TOKEN'}
    issue_data = {
        'title': 'Issue Title',
        'body': 'Issue Body'
    }
    response = requests.post(url, headers=headers, json=issue_data)
    if response.status_code == 201:
        print(f'Issue copied to {repo['full_name']}')
    else:
        print(f'Failed to copy issue to {repo['full_name']}')

# Note: Replace 'YOUR_GITHUB_TOKEN' with an actual GitHub token
# This function assumes that the token has the necessary permissions
# to create issues in the repositories.