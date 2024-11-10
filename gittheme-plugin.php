<?php
/*
Plugin Name: GitHub Sync
Description: Sync GitHub repository with WordPress theme.
Version: 1.4
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

    <hr>
    <h2>Currently Connected Repository</h2>
    <p><strong>Repo Name:</strong> <?php echo esc_html(get_option('github_sync_selected_repo', 'None')); ?></p>
    <p><strong>Last Synced:</strong> <?php echo esc_html(get_option('github_sync_last_synced', 'Never')); ?></p>
    <?php endif; ?>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
            github_sync_disconnect(); 
        } ?>

    <hr>
    <h2>Sync Repositories</h2>
    <?php github_sync_repositories_ui(); ?>

    <hr>
    <h2>GitHub OAuth Settings</h2>
    <form method="post">
        <label for="github_client_id">Client ID:</label>
        <input type="text" id="github_client_id" name="github_client_id"
            value="<?php echo esc_attr(get_option('github_sync_client_id')); ?>" />
        <br><br>
        <label for="github_client_secret">Client Secret:</label>
        <input type="text" id="github_client_secret" name="github_client_secret"
            value="<?php echo esc_attr(get_option('github_sync_client_secret')); ?>" />
        <br><br>
        <input type="submit" name="save_github_credentials" value="Save Credentials" class="button button-primary" />
    </form>
</div>
<?php
}

// Register plugin settings
add_action('admin_init', 'github_sync_register_settings');
function github_sync_register_settings() {
    register_setting('github_sync_options_group', 'github_sync_client_id');
    register_setting('github_sync_options_group', 'github_sync_client_secret');
    register_setting('github_sync_options_group', 'github_sync_selected_repo');
    register_setting('github_sync_options_group', 'github_sync_last_synced');
}

// GitHub OAuth authentication URL
function github_sync_get_oauth_url() {
    $client_id = get_option('github_sync_client_id');
    $redirect_uri = admin_url('admin.php?page=github-sync');
    $scope = 'repo';
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
            github_sync_get_github_username($data['access_token']);
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
        $repo_name = sanitize_text_field($_POST['repo']);
        update_option('github_sync_selected_repo', $repo_name);
        github_sync_clone_repo($repo_name);
    }
}

// Clone or pull selected repository into WordPress theme folder
function github_sync_clone_repo($repo_name) {
    $username = get_option('github_sync_username');
    if (!$username) {
        echo '<p>Error: GitHub username not found. Please authenticate again.</p>';
        return;
    }

    $theme_directory = get_theme_root() . '/' . $repo_name . '/';
    $repo_url = 'https://github.com/' . $username . '/' . $repo_name . '.git';

    if (!is_dir($theme_directory)) {
        mkdir($theme_directory, 0755, true);
    }

    if (is_dir($theme_directory . '/.git')) {
        exec("cd " . escapeshellarg($theme_directory) . " && git pull 2>&1", $output, $return_var);
        if ($return_var === 0) {
            echo '<p>Repository updated successfully!</p>';
        } else {
            echo '<p>Error updating repository: ' . implode("\n", $output) . '</p>';
        }
    } else {
        exec("git clone " . escapeshellarg($repo_url) . " " . escapeshellarg($theme_directory) . " 2>&1", $output, $return_var);
        if ($return_var === 0) {
            echo '<p>Repository cloned successfully into theme folder!</p>';
        } else {
            echo '<p>Error cloning repository: ' . implode("\n", $output) . '</p>';
        }
    }

    // Save last synced time immediately after syncing
    update_option('github_sync_last_synced', current_time('mysql'));
}

// Disconnect GitHub (clear token and username)
function github_sync_disconnect() {
    delete_option('github_sync_token');
    delete_option('github_sync_username');
    delete_option('github_sync_selected_repo');
    delete_option('github_sync_last_synced');
    echo '<p>Your GitHub account has been disconnected.</p>';
}

// Handle OAuth callback
if (isset($_GET['page']) && $_GET['page'] === 'github-sync') {
    github_sync_handle_oauth_callback();
}

// Save GitHub Client ID and Client Secret
if (isset($_POST['save_github_credentials'])) {
    update_option('github_sync_client_id', sanitize_text_field($_POST['github_client_id']));
    update_option('github_sync_client_secret', sanitize_text_field($_POST['github_client_secret']));
    echo '<p>Credentials saved successfully.</p>';
}



// AJAX sync handler
add_action('wp_ajax_github_sync_update', 'github_sync_update');
function github_sync_update() {
    $repo_name = get_option('github_sync_selected_repo');
    if ($repo_name) {
        github_sync_clone_repo($repo_name);
    }
    wp_die();
}

// JavaScript for periodic AJAX requests
add_action('admin_footer', 'github_sync_js');
function github_sync_js() {
    ?>
<script>
setInterval(function() {
    jQuery.ajax({
        url: '<?php echo admin_url("admin-ajax.php"); ?>',
        method: 'POST',
        data: {
            action: 'github_sync_update'
        }
    });
}, 5000); // 300000ms = 5 minutes
</script>
<?php
}
?>