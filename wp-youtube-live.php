<?php
/**
 * Plugin Name: YouTube Live
 * Plugin URI: https://github.com/macbookandrew/wp-youtube-live
 * Description: Displays the current YouTube live video from a specified channel
 * Version: 1.7.22
 * Author: Andrew Minion
 * Author URI: https://andrewrminion.com/
 */

if (!defined('ABSPATH')) {
    exit;
}

define( 'WP_YOUTUBE_LIVE_VERSION', '1.7.22' );

/**
 * Include admin.
 */
include('inc/admin.php');

/**
 * Enqueue frontend scripts
 */
function youtube_live_scripts() {
    wp_register_script( 'wp-youtube-live', plugin_dir_url( __FILE__ ) . 'js/wp-youtube-live.min.js', array( 'jquery' ), WP_YOUTUBE_LIVE_VERSION, true );
    wp_register_style( 'wp-youtube-live', plugin_dir_url( __FILE__ ) . 'css/wp-youtube-live.css', array(), WP_YOUTUBE_LIVE_VERSION );
    wp_register_script( 'youtube-iframe-api', 'https://www.youtube.com/iframe_api', array(), NULL, true );
}
add_action( 'wp_enqueue_scripts', 'youtube_live_scripts' );

/**
 * Create shortcode
 * @param  array  $atts shortcode parameters
 * @return string HTML shortcode output
 */
function output_youtube_live( $atts ) {
    // enqueue assets
    wp_enqueue_script( 'wp-youtube-live' );
    wp_enqueue_style( 'wp-youtube-live' );
    wp_enqueue_script( 'youtube-iframe-api' );

    // get plugin settings
    $settings = get_option( 'youtube_live_settings' );

    // get shortcode attributes
    $shortcode_attributes = shortcode_atts( array (
        'width'             => esc_attr( $settings['default_width'] ),
        'height'            => esc_attr( $settings['default_height'] ),
        'autoplay'          => esc_attr( $settings['autoplay'] ),
        'showRelated'       => esc_attr( $settings['show_related'] ),
        'js_only'           => false,
        'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
        'auto_refresh'      => esc_attr( $settings['auto_refresh'] ),
        'fallback_behavior' => esc_attr( $settings['fallback_behavior'] ),
        'fallback_message'  => ( array_key_exists( 'no_stream_message', $settings ) ? esc_attr( $settings['no_stream_message'] ) : esc_attr( $settings['fallback_message'] ) ),
        'no_stream_message' => NULL,
        'fallback_playlist' => esc_attr( $settings['fallback_playlist'] ),
        'fallback_video'    => esc_attr( $settings['fallback_video'] ),
        'refreshInterval'   => apply_filters( 'wp_youtube_live_transient_timeout', '30' ),
    ), $atts );

    // handle legacy parameter
    if ( isset( $shortcode_attributes['no_stream_message'] ) ) {
        $shortcode_attributes['fallback_message'] = esc_attr( $shortcode_attributes['no_stream_message'] );
        unset( $shortcode_attributes['no_stream_message'] );
    }

    wp_add_inline_script( 'wp-youtube-live', 'var wpYouTubeLiveSettings = ' . wp_json_encode( $shortcode_attributes ), 'before' );

    return get_youtube_live_content( $shortcode_attributes );
}
add_shortcode( 'youtube_live', 'output_youtube_live' );

/**
 * Add ajax handlers
 */
add_action( 'wp_ajax_load_youtube_live', 'get_youtube_live_content' );
add_action( 'wp_ajax_nopriv_load_youtube_live', 'get_youtube_live_content' );

/**
 * Output YouTube Live content
 * @param  array  $request_options array of settings
 * @return string JSON or HTML content
 */
function get_youtube_live_content( $request_options ) {
    // fix undefined errors in ajax context
    if ( ! is_array( $request_options ) ) {
        $request_options = array();
    }

    // load embed class
    require_once( 'inc/EmbedYoutubeLiveStreaming.php' );

    // get saved options
    $youtube_options = get_option( 'youtube_live_settings' );

    // merge request and saved options
    $request_options = wp_parse_args( $request_options, $youtube_options );

    // set up player
    $youtube_live = new EmbedYoutubeLiveStreaming( esc_attr( $youtube_options['youtube_live_channel_id'] ), esc_attr( $youtube_options['youtube_live_api_key'] ) );
    $youtube_live->subdomain = $youtube_options['subdomain']
        ? esc_attr( $youtube_options['subdomain'] )
        : 'www';
    $youtube_live->embed_width = wp_youtube_live_is_ajax()
        ? sanitize_key( wp_unslash( $_POST['width'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        : sanitize_key( $request_options['width'] );
    $youtube_live->embed_height = wp_youtube_live_is_ajax()
        ? sanitize_key( wp_unslash( $_POST['height'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        : sanitize_key( $request_options['height'] );
    $youtube_live->embed_autoplay = wp_youtube_live_is_ajax()
        ? sanitize_key( wp_unslash( $_POST['autoplay'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        : sanitize_key( $request_options['autoplay'] );
    $youtube_live->show_related = wp_youtube_live_is_ajax()
        ? sanitize_key( wp_unslash( $_POST['show_related'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        : sanitize_key( $request_options['showRelated'] );
    $youtube_live->completed_video_id = wp_youtube_live_is_ajax() && array_key_exists( 'completedVideoID', $_POST ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ? sanitize_key( wp_unslash( $_POST['completedVideoID'] ) )  // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        : '';

    if ( strlen( $youtube_live->completed_video_id ) > 0 ) {
        $youtube_live->isLive( true );
    }

    // start output
    $json_data = array();
    ob_start();
    if ( $youtube_options['fallback_behavior'] !== 'no_message' ) {
        echo '<div class="wp-youtube-live ' . ( $youtube_live->isLive ? 'live' : 'dead' ) . '">';
    }

    if ( $youtube_live->isLive ) {
        if ( $request_options['js_only'] !== 'true' || ( $request_options['js_only'] === 'true' && wp_youtube_live_is_ajax() ) ) {
            $is_live = true;
            #TODO: load a placeholder or nothing on initial page load?
            echo $youtube_live->embedCode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.
        }
    } else {
        $is_live = false;
        add_filter( 'oembed_result', 'wp_ytl_set_oembed_id' );
        add_filter( 'embed_defaults', 'wp_ytl_set_embed_size' );

        // set player parameters for playlist and video fallbacks
        $player_args = array(
            'autoplay'  => ( $youtube_live->embed_autoplay === 'true' ? '1' : '0' ),
            'rel'       => ( $youtube_live->show_related === 'true' ? '1' : '0' ),
        );

        if ( $request_options['fallback_behavior'] === 'upcoming' ) {
            $youtube_live->getVideoInfo( 'live', 'upcoming' );
            echo $youtube_live->embedCode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.
        } elseif ( $request_options['fallback_behavior'] === 'completed' ) {
            $youtube_live->getVideoInfo( 'live', 'completed' );
            echo $youtube_live->embedCode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.
        } elseif ( $request_options['fallback_behavior'] === 'channel' ) {
            $youtube_live->getVideoInfo( 'channel' );
            echo $youtube_live->embedCode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.
        } elseif ( $request_options['fallback_behavior'] === 'playlist' ) {
            add_filter( 'oembed_result', 'wp_ytl_add_player_attributes_result', 10, 3 );
            echo wp_oembed_get( esc_attr( $youtube_options['fallback_playlist'] ), $player_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( $request_options['fallback_behavior'] === 'video' && isset( $youtube_options['fallback_video'] ) ) {
            add_filter( 'oembed_result', 'wp_ytl_add_player_attributes_result', 10, 3 );
            echo wp_oembed_get( esc_attr( $youtube_options['fallback_video'] ), $player_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( $request_options['fallback_behavior'] === 'message' && $request_options['fallback_message'] !== 'no_message' ) {
            echo wp_kses_post( apply_filters( 'wp_youtube_live_no_stream_available', $request_options['fallback_message'] ) );
        }
    }

    // errors
    $error_message = '';
    if ( $youtube_live->getErrorMessage() ) {
        $error_message = '<p><strong>WP YouTube Live error:</strong></p>
        <ul>';
        foreach ( $youtube_live->getAllErrors() as $error ) {
            $error_message .= '<li><strong>Domain:</strong> ' . esc_url( $error['domain'] ) . '</li>
            <li><strong>Reason:</strong> ' . esc_attr( $error['reason'] ) . '</li>
            <li><strong>Message:</strong> ' . esc_attr( $error['message'] ) . '</li>
            <li><strong>Extended help:</strong> ' . wp_kses_post( $error['extendedHelp'] ) . '</li>';
        }
        if ( $youtube_options['fallback_behavior'] === 'video' && empty( $youtube_options['fallback_video'] ) ) {
            $error_message .= '<li>Please double-check that you have set a fallback video.</li>';
        }
        $error_message .= '</ul>';
        $json_data['error'] = $error_message;
    }

    // debugging
    if ( get_option( 'youtube_live_settings', 'debugging' ) && is_user_logged_in() ) {
        $debugging_code = var_export( $youtube_live, true ); // phpcs:igonre WordPress.PHP.DevelopmentFunctions.error_log_var_export
        echo '<!-- YouTube Live debugging: ' . "\n" . $debugging_code . "\n" . ' -->'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $json_data['error'] . $debugging_code;
    }

    if ( $youtube_options['fallback_behavior'] !== 'no_message' ) {
        echo '<span class="wp-youtube-live-error" style="display: none;">' . wp_kses_post( $error_message ) . '</span>
        </div>';
    }

    // return the content
    if ( wp_youtube_live_is_ajax() ) {
        if ( isset( $_POST['requestType'] ) && sanitize_key( wp_unslash( $_POST['requestType'] ) ) !== 'refresh' || $is_live ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $json_data['content'] = ob_get_clean();
        } else {
            ob_clean();
        }
        $json_data['live'] = ( $youtube_live->isLive ? true : false );
        if ( property_exists( $youtube_live->objectResponse, 'fromTransientCache' ) ) {
            $json_data['fromTransientCache'] = $youtube_live->objectResponse->fromTransientCache;
        }
        echo wp_json_encode( $json_data, JSON_FORCE_OBJECT );
        wp_die();
    } else {
        return ob_get_clean();
    }
}

/**
 * Add id to oembedded iframe
 * @param  string $html HTML oembed output
 * @return string HTML oembed output
 */
function wp_ytl_set_oembed_id( $html ) {
    $html = str_replace( '<iframe', '<iframe id="wpYouTubeLive"', $html );

    return $html;
}

/**
 * Set default oembed size for video/playlist fallback behavior
 * @param  array $size default oembed sizes
 * @return array moified oembed size
 */
function wp_ytl_set_embed_size( $size ) {
    $request_options = get_option( 'youtube_live_settings' );

    $size['width'] = ( wp_youtube_live_is_ajax() && array_key_exists( 'width', $_POST ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ? sanitize_key( wp_unslash( $_POST['width'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        : $request_options['default_width'] );
    $size['height'] = ( wp_youtube_live_is_ajax() && array_key_exists( 'height', $_POST ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ? sanitize_key( wp_unslash( $_POST['height'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        : $request_options['default_height'] );

    return $size;
}

add_action( 'wp_ajax_youtube_live_flush_cache', 'wp_ytl_flush_cache' );
/**
 * Flush transient cache.
 */
function wp_ytl_flush_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'error' => 'Access denied.' ), 403 );
        wp_die();
    }

    if ( delete_transient( 'wp-youtube-live-api-response' ) ) {
        wp_send_json_success( array( 'message' => 'Cleared cache.' ), 200 );
        wp_die();
    }

    wp_send_json_error( array( 'error' => 'Couldn’t clear cache.' ), 500 );
    wp_die();
}

/**
 * Check plugin and database version numbers
 */
function wp_ytl_check_version() {
    if ( WP_YOUTUBE_LIVE_VERSION !== get_option( 'youtube_live_version' ) ) {
        wp_ytl_plugin_activation();
    }
}
add_action( 'plugins_loaded', 'wp_ytl_check_version' );

/**
 * Handle database upgrades on activation/upgrade
 */
function wp_ytl_plugin_activation() {
    $request_options = get_option( 'youtube_live_settings', array() );

    // removed in v1.7.0
    if ( array_key_exists( 'show_channel_if_dead', $request_options ) && $request_options['show_channel_if_dead'] == 'true' ) {
        $request_options['fallback_behavior'] = 'channel';
    }
    unset( $request_options['show_channel_if_dead'] );

    // updated in v1.7.0
    if ( array_key_exists( 'fallback_video', $request_options ) && isset( $request_options['fallback_video'] ) ) {
        $request_options['fallback_behavior'] = 'video';
    }

    // added in v1.7.0
    if ( ! array_key_exists( 'autoplay', $request_options ) ) {
        $request_options['autoplay'] = true;
    }

    // added in v1.7.0
    if ( ! array_key_exists( 'show_relatetd', $request_options ) ) {
        $request_options['show_relatetd'] = false;
    }

    update_option( 'youtube_live_settings', $request_options );
    update_option( 'youtube_live_version', WP_YOUTUBE_LIVE_VERSION );
}
register_activation_hook( __FILE__, 'wp_ytl_plugin_activation' );

/**
 * Add autoplay and related parameters to oembedded videos
 * @param  string $data2html HTML embed code
 * @param  string $url       URL to be embedded
 * @param  array  $args      extra arguments passed to wp_oembed_get function
 * @return string HTML embed code
 */
function wp_ytl_add_player_attributes_result( $data2html, $url, $args ) {
    $player_settings = '';
    foreach ( $args as $key => $value ) {
        if ( is_null( $value ) ) {
            $value = 1;
        }
        $player_settings .= '&' . $key . '=' . $value;
    }

    $data2html = str_replace( '?feature=oembed', '?feature=oembed' . $player_settings, $data2html );

    return $data2html;
}

/**
 * Determine whether the current request is our ajax request.
 *
 * @since 1.8.0
 *
 * @return bool
 */
function wp_youtube_live_is_ajax() {
    return isset( $_POST['isAjax'] ) && (bool) sanitize_key( wp_unslash( $_POST['isAjax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

#TODO: add a notice about resaving settings on plugin activation
#FUTURE: add support for modestbranding URL paramater (hides YouTube logo)
