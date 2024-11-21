<?php
/*
Plugin Name: GitHub Sync (Wp Theme)
Description: Sync GitHub Wp Theme with wordpress
Version: 1.4
Author: Naeem Prasla
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
        echo '<p>No repositories found.</p>';
        return;
    }

    
    

    echo '<form method="post">';
    echo '<select name="repo" required>';



    foreach ($repos as $repo) {
        if($repo['name'] == get_option('github_sync_selected_repo') ){
            echo '<option value="' . esc_attr($repo['name']) . '"   selected   >' . esc_html($repo['name']) . '</option>';
        }
        
        echo '<option value="' . esc_attr($repo['name']) . '"      >' . esc_html($repo['name']) . '</option>';
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

}


// Add a custom interval (10 seconds).
add_filter('cron_schedules', function ($schedules) {
    $schedules['ten_seconds'] = ['interval' => 10, 'display' => __('Every 10 Seconds')];
    return $schedules;
});

// Schedule the cron job on activation.
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('github_theme_sync')) {
        wp_schedule_event(time(), 'ten_seconds', 'github_theme_sync');
    }
});

// Clear the cron job on deactivation.
register_deactivation_hook(__FILE__, function () {
    if ($timestamp = wp_next_scheduled('github_theme_sync')) {
        wp_unschedule_event($timestamp, 'github_theme_sync');
    }
});

// Sync the theme from GitHub and update the sync time.
add_action('github_theme_sync', function () {
    $repo_name = get_option('github_sync_selected_repo');
    if ($repo_name) {
        github_sync_clone_repo($repo_name);
        update_option('github_last_sync_time', current_time('mysql'));
    }
    wp_die();
});

// Add a dashboard widget to display the sync timer.
add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget('github_sync_widget', 'GitHub Theme Sync Status', function () {
        $last_sync = get_option('github_last_sync_time', 'No sync yet');
        echo '<p>Last Sync Time: <strong>' . esc_html($last_sync) . '</strong></p>';
        echo '<p>Next Sync in approximately 10 seconds.</p>';
    });
});


// Display the plugin options page
function github_sync_page() {
    ?>
<div class="wrap">

    <?php 
if (isset($_POST['save_github_credentials'])) {
        ?>
    <div class="message-box success">
        <p>Credentials saved successfully.</p>
    </div>
    <?php
}

?>

    <div class="github-authsettings gitbox">
        <h1>GitHub OAuth Settings</h1>
        <?php if(empty(get_option('github_sync_client_id')) && empty(get_option('github_sync_client_secret'))): ?>
        <p>Create Auth App On Your Github Account: <a href="https://github.com/settings/developers" target="_blank">Get
                Auth
                Details</a></p>
        <?php else: ?>
        <p>Github App Cliend ID and Secret is saved, Authourize your account and sync your theme repo</p>
        <?php endif; ?>
        <form method="post">
            <label for="github_client_id">Client ID:</label>
            <input type="text" id="github_client_id" name="github_client_id"
                value="<?php echo esc_attr(get_option('github_sync_client_id')); ?>" />
            <br><br>
            <label for="github_client_secret">Client Secret:</label>
            <input type="password" id="github_client_secret" name="github_client_secret"
                value="<?php echo esc_attr(get_option('github_sync_client_secret')); ?>" />
            <br><br>
            <input type="submit" name="save_github_credentials" value="Connect Git App" class="button button-primary" />
        </form>


    </div>


    <?php if(!empty(get_option('github_sync_client_id')) && !empty(get_option('github_sync_client_secret'))): ?>
    <div class="github-authourization gitbox">

        <h1>GitHub Sync Settings</h1>

        <?php if (!github_sync_is_connected()) : ?>
        <p>To sync your repositories, connect your GitHub account:</p>
        <a href="<?php echo github_sync_get_oauth_url(); ?>" class="button button-primary"> <span
                class="dashicons dashicons-lock"></span> Authorize GitHub</a>
        <?php else : ?>
        <p>Your GitHub account is connected. You can now sync your repositories.</p>
        <form method="post">
            <input type="submit" name="disconnect" value="Disconnect GitHub" class="button button-secondary" />
        </form>

    </div>
    <?php endif; ?>

    <div class="github-repuiList gitbox">
        <h1>Sync Repositories</h1>
        <?php if(empty(get_option('github_sync_selected_repo'))): ?>
        <p>Sync your Repository. </p>
        <?php else: ?>
        <p>Responsitory already Connected. </p>
        <?php endif; ?>

        <?php github_sync_repositories_ui(); ?>
    </div>


    <?php if(!empty(get_option('github_sync_selected_repo'))): ?>
    <div class="github-synced-theme gitbox">
        <h1>Currently Connected Repository</h1>
        <p><strong>Repo Name:</strong> <?php echo esc_html(get_option('github_sync_selected_repo', 'None')); ?></p>
        <p><strong>Last Synced:</strong> <?php echo esc_html(get_option('github_sync_last_synced', 'Never')); ?></p>
        <?php endif; ?>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
            github_sync_disconnect(); 
        } ?>


    </div>
    <?php endif; ?>



</div>
<?php
}


// Enqueue custom admin styles for the plugin
function github_sync_enqueue_styles() {
    // Ensure this is only for the plugin's settings page
    $screen = get_current_screen();
    if ($screen->id === 'toplevel_page_github-sync') {
        wp_enqueue_style('github-sync-styles', plugin_dir_url(__FILE__) . 'styles.css');
    }
}
add_action('admin_enqueue_scripts', 'github_sync_enqueue_styles');

?>