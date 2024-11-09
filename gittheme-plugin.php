<?php
/*
Plugin Name: GitHub Sync
Description: Sync GitHub repository with WordPress theme.
Version: 1.2
Author: Your Name
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Hook to create plugin options page in admin menu
add_action('admin_menu', 'github_sync_menu');
function github_sync_menu() {
    add_menu_page(
        'GitHub Sync', 
        'GitHub Sync', 
        'manage_options', 
        'github-sync', 
        'github_sync_page',
        'dashicons-github',
        6
    );
}

// Display the plugin options page
function github_sync_page() {
    ?>
<div class="wrap">
    <h1>GitHub Sync Settings</h1>

    <?php if (!github_sync_is_connected()) : ?>
    <p>To sync your repositories, connect your GitHub account:</p>
    <a href="<?php echo github_sync_get_oauth_url(); ?>" class="button button-primary">Connect to GitHub</a>
    <?php else : ?>
    <p>Your GitHub account is connected. You can now sync your repositories.</p>
    <form method="post">
        <input type="submit" name="disconnect" value="Disconnect GitHub" class="button button-secondary" />
    </form>
    <?php endif; ?>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
            github_sync_disconnect(); 
        } ?>

    <hr>
    <h2>Sync Repositories</h2>
    <?php github_sync_repositories_ui(); ?>
</div>
<?php
}

// Register plugin settings
add_action('admin_init', 'github_sync_register_settings');
function github_sync_register_settings() {
    register_setting('github_sync_options_group', 'github_sync_client_id');
    register_setting('github_sync_options_group', 'github_sync_client_secret');
}

// GitHub OAuth authentication URL
function github_sync_get_oauth_url() {
    $client_id = get_option('github_sync_client_id');
    $redirect_uri = admin_url('admin.php?page=github-sync'); // Redirect URI after OAuth
    $scope = 'repo';  // Define the scope for repositories
    return "https://github.com/login/oauth/authorize?client_id={$client_id}&redirect_uri={$redirect_uri}&scope={$scope}";
}

// Check if the user is already connected
function github_sync_is_connected() {
    return (bool) get_option('github_sync_token');
}

// Handle GitHub OAuth callback
function github_sync_handle_oauth_callback() {
    if (isset($_GET['code'])) {
        $client_id = get_option('github_sync_client_id');
        $client_secret = get_option('github_sync_client_secret');
        $code = sanitize_text_field($_GET['code']);

        $response = wp_remote_post('https://github.com/login/oauth/access_token', [
            'body' => [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
            ],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['access_token'])) {
            update_option('github_sync_token', $data['access_token']);
            // Fetch GitHub username and store it
            github_sync_get_github_username($data['access_token']);
            echo '<p>GitHub connected successfully! You can now sync your repositories.</p>';
        }
    }
}

// Get GitHub username using access token
function github_sync_get_github_username($access_token) {
    $response = wp_remote_get('https://api.github.com/user', [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
        ]
    ]);
    
    $user_data = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($user_data['login'])) {
        update_option('github_sync_username', $user_data['login']);
    }
}

// Fetch GitHub repositories
function github_sync_get_repositories() {
    $token = get_option('github_sync_token');
    if (!$token) {
        echo '<p>You need to authenticate with GitHub first.</p>';
        return [];
    }

    $response = wp_remote_get('https://api.github.com/user/repos', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
        ]
    ]);
    $repos = json_decode(wp_remote_retrieve_body($response), true);

    return $repos;
}

// Display UI to select repository for syncing
function github_sync_repositories_ui() {
    $repos = github_sync_get_repositories();
    if (empty($repos)) {
        echo '<p>No repositories found or you need to connect GitHub.</p>';
        return;
    }

    echo '<form method="post">';
    echo '<select name="repo" required>';
    foreach ($repos as $repo) {
        echo '<option value="' . esc_attr($repo['name']) . '">' . esc_html($repo['name']) . '</option>';
    }
    echo '</select>';
    echo '<input type="submit" value="Sync Repo">';
    echo '</form>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repo'])) {
        github_sync_clone_repo($_POST['repo']);
    }
}
// Clone or pull selected repository into WordPress theme folder
function github_sync_clone_repo($repo_name) {
    // Get the GitHub username from options
    $username = get_option('github_sync_username');
    if (!$username) {
        echo '<p>Error: GitHub username not found. Please authenticate again.</p>';
        return;
    }

    $theme_directory = get_theme_root() . '/your-theme-folder/';
    
    // Define the GitHub repository URL
    $repo_url = 'https://github.com/' . $username . '/' . $repo_name . '.git';

    // Set the path where the repository will be cloned
    $temp_clone_dir = sys_get_temp_dir() . '/github_repo_clone/';

    // Clone the repository into a temporary directory
    $clone_command = 'git clone ' . escapeshellarg($repo_url) . ' ' . escapeshellarg($temp_clone_dir);

    // Run the git clone command
    $output = [];
    $return_var = 0;
    exec($clone_command, $output, $return_var);

    if ($return_var !== 0) {
        echo '<p>Error cloning repository. Git command failed.</p>';
        return;
    }

    // If the clone was successful, move the contents to the theme folder
    try {
        // Move the repository contents into the theme directory (excluding the parent folder)
        $repo_folder = $temp_clone_dir . '/' . $repo_name . '-main'; // Adjust this based on your repo structure
        if (is_dir($repo_folder)) {
            // Use PHP's built-in function to move files/folders
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($repo_folder, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'mkdir' : 'copy');
                $dest = $theme_directory . DIRECTORY_SEPARATOR . $fileinfo->getSubPathName();
                if ($todo === 'mkdir' && !is_dir($dest)) {
                    mkdir($dest, 0777, true);
                } else if ($todo === 'copy') {
                    copy($fileinfo, $dest);
                }
            }
            echo '<p>Repository synced successfully!</p>';
        }
    } catch (Exception $e) {
        echo '<p>Error moving repository files: ' . $e->getMessage() . '</p>';
    }

    // Clean up: Remove the temporary cloned repo directory
    // Be sure to remove the directory if it exists
    $this->delete_directory($temp_clone_dir);
}

// Helper function to delete a directory recursively
function delete_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $file_path = $dir . DIRECTORY_SEPARATOR . $file;
        (is_dir($file_path)) ? delete_directory($file_path) : unlink($file_path);
    }
    
    return rmdir($dir);
}




// Disconnect GitHub (clear token and username)
function github_sync_disconnect() {
    delete_option('github_sync_token');
    delete_option('github_sync_username');
    echo '<p>Your GitHub account has been disconnected.</p>';
}

// Handle OAuth callback (after user authorizes the app)
if (isset($_GET['page']) && $_GET['page'] === 'github-sync') {
    github_sync_handle_oauth_callback();
}

?>