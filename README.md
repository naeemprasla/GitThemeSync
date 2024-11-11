# WordPress Theme Sync from GitHub
This WordPress plugin allows you to automatically sync a theme from a GitHub repository. Once set up, it can periodically update your selected theme from GitHub, keeping it up-to-date with your latest commits.

## Features
- GitHub Authentication: Connect your GitHub account to the plugin.
- Theme Synchronization: Choose a repository and sync it as a WordPress theme.
- Automatic Updates: Schedule updates to pull the latest changes from GitHub.
### Installation
- Download or clone this repository.
- Copy the github-sync folder to your WordPress plugins directory (wp-content/plugins/).
- Activate the plugin from the WordPress Admin Dashboard under Plugins.
### Prerequisites
- GitHub Account: Ensure you have a GitHub account and a repository containing your theme files.
- GitHub OAuth App: Create an OAuth App on GitHub to get the Client ID and Client Secret for authentication.
### Setting Up the GitHub OAuth App
- Go to Settings > Developer settings > OAuth Apps in your GitHub account.
- Click New OAuth App and fill in the details:
- Application name: WordPress Theme Sync
- Homepage URL: Your WordPress site URL
- Authorization callback URL: https://your-site.com/wp-admin/options-general.php?page=github-sync
- Save and copy the Client ID and Client Secret.
## Usage
#### Step 1: Configure the Plugin
- In WordPress, go to Settings > GitHub Theme Sync.
- Enter your GitHub Client ID and Client Secret.
- Click Save Changes.
#### Step 2: Authenticate with GitHub
- Click the Connect to GitHub button to authenticate.
- Authorize the plugin to access your GitHub repositories.
#### Step 3: Select and Sync a Repository
- After connecting to GitHub, choose a repository to sync as a theme.
- Click Sync Repository to clone the repository into the WordPress theme folder.
#### Step 4: Scheduled Updates
- The plugin will automatically update the theme every 5 sec. The last sync date and time are displayed on the settings page.

## Screenshot
- Dashboard: https://tinyurl.com/2y7pzowy
