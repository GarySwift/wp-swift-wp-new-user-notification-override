<?php
/*
Plugin Name:    WP Swift: Override wp_new_user_notification()
Description:    Override the default email notifcation system
Version:        1.0
Author:         Gary Swift
Author URI:     https://github.com/GarySwift
Text Domain:    wp-swift-wp-new-user-notification-override
*/

if ( !function_exists('wp_new_user_notification') ) :
/**
 * Email login credentials to a newly-registered user.
 *
 * A new user registration notification is also sent to admin email.
 *
 * @since 2.0.0
 * @since 4.3.0 The `$plaintext_pass` parameter was changed to `$notify`.
 * @since 4.3.1 The `$plaintext_pass` parameter was deprecated. `$notify` added as a third parameter.
 * @since 4.6.0 The `$notify` parameter accepts 'user' for sending notification only to the user created.
 *
 * @global wpdb         $wpdb      WordPress database object for queries.
 * @global PasswordHash $wp_hasher Portable PHP password hashing framework instance.
 *
 * @param int    $user_id    User ID.
 * @param null   $deprecated Not used (argument deprecated).
 * @param string $notify     Optional. Type of notification that should happen. Accepts 'admin' or an empty
 *                           string (admin only), 'user', or 'both' (admin and user). Default empty.
 */
function wp_new_user_notification( $user_id, $deprecated = null, $notify = '' ) {
    if ( $deprecated !== null ) {
        _deprecated_argument( __FUNCTION__, '4.3.1' );
    }

    global $wpdb, $wp_hasher;
    $user = get_userdata( $user_id );
    $first_name = $user->first_name;

    // The blogname option is escaped with esc_html on the way into the database in sanitize_option
    // we want to reverse this for the plain text arena of emails.
    $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

    if ( 'user' !== $notify ) {
        $switched_locale = switch_to_locale( get_locale() );
        $message  = sprintf( __( '<p>New user registration on your site %s:</p>' ), $blogname );
        $message .= sprintf( __( '<p>Username: %s</p>' ), $user->user_login );
        $message .= sprintf( __( '<p>Email: %s</p>' ), $user->user_email );
        $message = new_user_wrap_email($message);

        @wp_mail( get_option( 'admin_email' ), sprintf( __( '[%s] New User Registration' ), $blogname ), $message );

        if ( $switched_locale ) {
            restore_previous_locale();
        }
    }

    // `$deprecated was pre-4.3 `$plaintext_pass`. An empty `$plaintext_pass` didn't sent a user notification.
    if ( 'admin' === $notify || ( empty( $deprecated ) && empty( $notify ) ) ) {
        return;
    }

    // Generate something random for a password reset key.
    $key = wp_generate_password( 20, false );

    /** This action is documented in wp-login.php */
    do_action( 'retrieve_password_key', $user->user_login, $key );

    // Now insert the key, hashed, into the DB.
    if ( empty( $wp_hasher ) ) {
        $wp_hasher = new PasswordHash( 8, true );
    }
    $hashed = time() . ':' . $wp_hasher->HashPassword( $key );
    $wpdb->update( $wpdb->users, array( 'user_activation_key' => $hashed ), array( 'user_login' => $user->user_login ) );

    $switched_locale = switch_to_locale( get_user_locale( $user ) );

    $message = '';
    if ($first_name != '') {
        $message .= sprintf(__('<p>Hi  %s,</p>'), $first_name);
    }
    else {
        $message .= __('<p>Welcome new user,<p>');
    }
    $message .= sprintf(__('<p>Thank you for registering with %s. Your username is shown below.</p>'), $blogname);
    $message .= sprintf(__('<p>Username: %s</p>'), $user->user_login);
    $message .= __('<p>To set your password, visit the following address:</p>');

    $user_login_rawurlencode = rawurlencode( $user->user_login );
    $options = get_option( 'wp_swift_user_notification_settings' );
    if (isset($options['wp_swift_user_notification_reset'])) {
        $reset_url = add_query_arg( array(
            'action' => 'rp',
            'key' => $key,
            'login' => $user_login_rawurlencode,
        ), home_url( $options['wp_swift_user_notification_reset']) );
        $message .=  $reset_url;
    }
    else {
        $message .= '<' . network_site_url("wp-login.php?action=rp&key=$key&login=" . $user_login_rawurlencode, 'login') . ">\r\n\r\n";
    }

    $message .= __('<p>You can log into the site using the link below:</p>');

    if (isset($options['wp_swift_user_notification_login'])) {
        $message .=  home_url( $options['wp_swift_user_notification_login']);
    }
    else {
        $message .= '<p>'.wp_login_url().'</p>' . "\r\n";
    }
    

    /*
     * Wrap in html wrapper
     */
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $message = new_user_wrap_email($message);
    wp_mail($user->user_email, sprintf(__('[%s] Your username and password info'), $blogname), $message, $headers);

    if ( $switched_locale ) {
        restore_previous_locale();
    }  
}
endif;

function new_user_wrap_email($message) {
    if (function_exists('wp_swift_wrap_email')) {
        return wp_swift_wrap_email($message);
    }
    else {
        return $message;
    }
}  

add_action( 'admin_menu', 'wp_swift_new_user_notification_settings_menu' );
add_action( 'admin_init', 'wp_swift_user_notification_settings_init' );
function wp_swift_new_user_notification_settings_menu() {
    $wp_swift_admin_menu=false;
    $page_title = 'New User Notifications Configuration';
    $menu_title = 'User Notifications';
    $capability = 'manage_options';
    $menu_slug  = 'wp-swift-new-user-notification-settings-menu';
    $function   = 'wp_swift_new_user_notification_settings_page';

    $options_page = add_options_page( 
        $page_title,
        $menu_title,
        $capability,
        $menu_slug,
        $function
    );
}

/*
 *
 */
function wp_swift_user_notification_settings_init(  ) { 

    register_setting( 'wp_swift_user_notification_page', 'wp_swift_user_notification_settings' );

    add_settings_section(
        'wp_swift_user_notification_plugin_page_section', 
        __( 'Set your preferences for the new user notification here', 'wp-swift-user-notification' ), 
        'wp_swift_user_notification_settings_section_callback',
        'wp_swift_user_notification_page'
    );

    add_settings_field( 
        'wp_swift_user_notification_login', 
        __( 'Custom Login Page', 'wp-swift-user-notification' ), 
        'wp_swift_user_notification_login_render',
        'wp_swift_user_notification_page', 
        'wp_swift_user_notification_plugin_page_section' 
    );
    add_settings_field( 
        'wp_swift_user_notification_reset', 
        __( 'Custom Password Reset Page', 'wp-swift-user-notification' ), 
        'wp_swift_user_notification_reset_render',
        'wp_swift_user_notification_page', 
        'wp_swift_user_notification_plugin_page_section' 
    );
}

/*
 *
 */
function wp_swift_user_notification_login_render(  ) { 

    $options = get_option( 'wp_swift_user_notification_settings' );
    $value = '';
    if (isset($options['wp_swift_user_notification_login'])) {
        $value = $options['wp_swift_user_notification_login'];
    }
    ?>
    <input type='text' name='wp_swift_user_notification_settings[wp_swift_user_notification_login]' value='<?php echo $value; ?>' size='50'>
    <br><small>Note: Use page slug only</small>
    <?php

}

/*
 *
 */
function wp_swift_user_notification_reset_render(  ) { 

    $options = get_option( 'wp_swift_user_notification_settings' );
    $value = '';
    if (isset($options['wp_swift_user_notification_reset'])) {
        $value = $options['wp_swift_user_notification_reset'];
    }
    ?>
    <input type='text' name='wp_swift_user_notification_settings[wp_swift_user_notification_reset]' value='<?php echo $value; ?>' size='50'>
    <br><small>Note: Use page slug only</small>
    <?php

}

/*
 *
 */
function wp_swift_user_notification_settings_section_callback(  ) { 

    echo __( 'You can add a custom password reset page here', 'wp-swift-form-builder' );

}
function wp_swift_new_user_notification_settings_page(  ) { 
    ?>
    <div id="form-builder-wrap" class="wrap">
    <h2>WP Swift: New User Notification</h2>
    <form action='options.php' method='post'>    
        <?php
        settings_fields( 'wp_swift_user_notification_page' );
        do_settings_sections( 'wp_swift_user_notification_page' );
        submit_button();
        ?>
    </form>
    </div>
    <?php
}