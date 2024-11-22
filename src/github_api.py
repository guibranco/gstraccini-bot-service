import requests

def fetch_repositories():
    # Use GitHub API to fetch repositories
    url = 'https://api.github.com/user/repos'
    headers = {'Authorization': 'token YOUR_GITHUB_TOKEN'}
    response = requests.get(url, headers=headers)
    if response.status_code == 200:
        return response.json()
    else:
        return []

# Note: Replace 'YOUR_GITHUB_TOKEN' with an actual GitHub token
# This function assumes that the token has the necessary permissions
# to access the user's repositories.