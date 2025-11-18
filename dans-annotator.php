<?php
/**
 * Plugin Name: Dan's Annotator
 * Description: Page annotation system — with threaded comments.
 * Version: 1.0.0
 * Author: DanL
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dans-annotator
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANNOTATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANNOTATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ANNOTATE_PLUGIN_DIR . 'includes/admin.php';
require_once ANNOTATE_PLUGIN_DIR . 'includes/rest.php';

class Annotate_Plugin {

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_activation_redirect' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'admin_bar_toggle' ), 100 );
        add_action( 'admin_notices', array( $this, 'maybe_show_admin_notices' ) );
        add_action( 'wp_footer', array( $this, 'render_shell' ) );
        add_action( 'annotate_weekly_cleanup', array( $this, 'run_auto_cleanup' ) );
        add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) );
    }

    public function activate() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $threads_table = $wpdb->prefix . 'annotate_threads';
        $comments_table = $wpdb->prefix . 'annotate_comments';
        $tags_table = $wpdb->prefix . 'annotate_tags';
        $collaborators_table = $wpdb->prefix . 'annotate_collaborators';
        $collaborators_table_escaped = esc_sql( $collaborators_table );
        $collaborators_table_escaped = esc_sql( $collaborators_table );

        $sql = "CREATE TABLE $threads_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_url VARCHAR(2083) NOT NULL,
            selector TEXT NOT NULL,
            created_by_id BIGINT(20) UNSIGNED NOT NULL,
            created_by_is_collaborator TINYINT(1) DEFAULT 0,
            is_closed TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_url (post_url(191))
        ) $charset_collate;";

        dbDelta( $sql );

        $sql = "CREATE TABLE $comments_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT(20) UNSIGNED NOT NULL,
            parent_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_by_id BIGINT(20) UNSIGNED NOT NULL,
            created_by_is_collaborator TINYINT(1) DEFAULT 0,
            content LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id)
        ) $charset_collate;";

        dbDelta( $sql );

        $sql = "CREATE TABLE $tags_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id BIGINT(20) UNSIGNED NOT NULL,
            target_id BIGINT(20) UNSIGNED NOT NULL,
            target_is_collaborator TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY comment_id (comment_id),
            KEY target_id (target_id)
        ) $charset_collate;";

        dbDelta( $sql );

        $sql = "CREATE TABLE $collaborators_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email_encrypted LONGTEXT NOT NULL,
            display_name VARCHAR(255) DEFAULT '',
            last_activity_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta( $sql );

        annotate_seed_settings_defaults();
        $this->maybe_schedule_cleanup_event();
        update_option( 'annotate_do_activation_redirect', 1, false );
        update_option( 'annotate_show_donate_notice', 1, false );
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'annotate_weekly_cleanup' );
    }

    public function init() {
        $this->maybe_handle_collaborator_session();
        $this->maybe_schedule_cleanup_event();
    }

    public function add_weekly_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['annotate_weekly'] ) ) {
            $schedules['annotate_weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => esc_html__( 'Once Weekly (Dan\'s Annotator)', 'dans-annotator' ),
            );
        }
        return $schedules;
    }

    protected function maybe_schedule_cleanup_event() {
        if ( ! wp_next_scheduled( 'annotate_weekly_cleanup' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'annotate_weekly', 'annotate_weekly_cleanup' );
        }
    }

    public function enqueue_assets() {
        $can_use = function_exists( 'annotate_has_active_actor' ) ? annotate_has_active_actor() : is_user_logged_in();
        if ( ! $can_use ) {
            return;
        }

        $is_collaborator = function_exists( 'annotate_is_collaborator_session' ) ? annotate_is_collaborator_session() : false;
        $active_actor    = function_exists( 'annotate_get_active_actor_context' ) ? annotate_get_active_actor_context() : null;
        $current_actor   = array(
            'id' => $active_actor ? intval( $active_actor['id'] ) : 0,
            'type' => $active_actor ? $active_actor['type'] : '',
            'is_collaborator' => $active_actor ? ! empty( $active_actor['is_collaborator'] ) : false,
        );
        $disable_controls = ( $active_actor && ! empty( $active_actor['is_collaborator'] ) );

        wp_enqueue_style( 'annotate-css', ANNOTATE_PLUGIN_URL . 'assets/annotate.css', array(), '1.0.0' );
        wp_enqueue_script( 'annotate-js', ANNOTATE_PLUGIN_URL . 'assets/annotate.js', array( 'jquery' ), '1.0.0', true );

        wp_localize_script( 'annotate-js', 'AnnotateData', array(
            'root' => esc_url_raw( rest_url( 'annotate/v1' ) ),
            'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'current_user_id' => get_current_user_id(),
                'current_actor' => $current_actor,
                'is_collaborator_session' => $is_collaborator,
                'disable_collaborator_controls' => $disable_controls,
                'assets_url' => ANNOTATE_PLUGIN_URL . 'assets/',
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'i18n' => array(
                    'state_on'               => esc_html__( 'On', 'dans-annotator' ),
                    'state_off'              => esc_html__( 'Off', 'dans-annotator' ),
                    'view_all_label'         => esc_html__( 'View All', 'dans-annotator' ),
                    /* translators: %d: number of hidden items in the "View All" list. */
                    'view_all_hidden_label'  => esc_html__( 'View All (%d hidden)', 'dans-annotator' ),
                    'thread_open_subtitle'   => esc_html__( 'Discuss this element', 'dans-annotator' ),
                    'thread_closed_subtitle' => esc_html__( 'Thread is closed', 'dans-annotator' ),
                    'alert_write_comment'    => esc_html__( 'Please write a comment', 'dans-annotator' ),
                    'snippet_no_comments'    => esc_html__( 'No comments yet', 'dans-annotator' ),
                    'snippet_unknown_user'   => esc_html__( 'User', 'dans-annotator' ),
                    'view_all_default_subtitle' => esc_html__( 'Everything on this page', 'dans-annotator' ),
                    /* translators: %d: number of selectors that no longer exist on the page. */
                    'view_all_missing_pill'  => esc_html__( '%d missing selectors', 'dans-annotator' ),
                'confirm_close_message'  => esc_html__( 'Really close this thread? No new comments can be added.', 'dans-annotator' ),
                'confirm_delete_message' => esc_html__( 'Really delete this thread? This cannot be undone.', 'dans-annotator' ),
                'error_close_thread'     => esc_html__( 'Could not close thread.', 'dans-annotator' ),
                'error_delete_thread'    => esc_html__( 'Failed to delete thread.', 'dans-annotator' ),
                'collab_disconnect_confirm' => esc_html__( 'Disconnect from this session? You will need to reopen the link from your email.', 'dans-annotator' ),
                'collab_disconnect_success' => esc_html__( 'Disconnected. Please revisit the email link to continue annotating.', 'dans-annotator' ),
            ),
        ) );
    }

    public function admin_bar_toggle( $wp_admin_bar ) {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $toggle_args = array(
            'id'    => 'annotate-toggle',
            'title' => '<span class="annotate-toggle-label">Dan\'s Annotator: <strong id="annotate-toggle-state">Off</strong></span>',
            'href'  => '#',
            'meta'  => array( 'class' => 'annotate-toggle' ),
        );
        $wp_admin_bar->add_node( $toggle_args );

    }

    public function maybe_show_admin_notices() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( current_user_can( 'manage_options' ) ) {
            $should_show_donate_notice = get_option( 'annotate_show_donate_notice', false );
            if ( $should_show_donate_notice ) {
                delete_option( 'annotate_show_donate_notice' );
                $settings_url = admin_url( 'options-general.php?page=annotate-settings' );
                printf(
                    '<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                    esc_html__( 'Help Dan maintain this and other plugins. He wants to become a better programmer by building free plugins.', 'dans-annotator' ),
                    esc_url( $settings_url ),
                    esc_html__( 'Configure Dan\'s Annotator', 'dans-annotator' )
                );
            }
        }

        $user_id = get_current_user_id();
        $pending = get_user_meta( $user_id, 'annotate_tag_notifications', true );
        if ( ! empty( $pending ) && is_array( $pending ) ) {
            foreach ( $pending as $note ) {
                printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $note ) );
            }
            // clear after showing
            delete_user_meta( $user_id, 'annotate_tag_notifications' );
        }
    }

    public function maybe_handle_activation_redirect() {
        if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $redirect = get_option( 'annotate_do_activation_redirect', false );
        if ( ! $redirect ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $action = 'activate-plugin_' . plugin_basename( __FILE__ );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
            delete_option( 'annotate_do_activation_redirect' );
            return;
        }

        delete_option( 'annotate_do_activation_redirect' );

        if ( isset( $_GET['activate-multi'] ) || is_network_admin() ) {
            return;
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=annotate-settings' ) );
        exit;
    }

    public function render_shell() {
        $can_use = function_exists( 'annotate_has_active_actor' ) ? annotate_has_active_actor() : is_user_logged_in();
        if ( ! $can_use ) {
            return;
        }
        ?>
        <div id="annotate-panel" class="annotate-panel" aria-hidden="true" data-panel-mode="">
            <div id="annotate-thread-view" class="annotate-panel-view annotate-panel-thread" hidden>
                <div class="annotate-header">
                    <div class="annotate-header-info annotate-header-info-thread">
                        <strong><?php esc_html_e( 'Annotations', 'dans-annotator' ); ?></strong>
                        <span class="annotate-subtitle" data-slot="subtitle"><?php esc_html_e( 'Discuss this element', 'dans-annotator' ); ?></span>
                    </div>
                    <div class="annotate-controls">
                        <button id="annotate-copy-link-header" class="annotate-btn-icon" title="<?php esc_attr_e( 'Copy link', 'dans-annotator' ); ?>" data-icon-copy="⧉" data-icon-check="✓" type="button">⧉</button>
                        <button id="annotate-back-to-list" class="annotate-btn-icon" title="<?php esc_attr_e( 'View all', 'dans-annotator' ); ?>">←</button>
                        <button id="annotate-panel-close" class="annotate-btn-icon" title="<?php esc_attr_e( 'Close', 'dans-annotator' ); ?>">✕</button>
                    </div>
                </div>
                <div class="annotate-selector" id="annotate-selector-container">
                    <?php esc_html_e( 'Selector:', 'dans-annotator' ); ?> <code class="annotate-selector-text"></code>
                </div>
                <div id="annotate-comments" class="annotate-comments"></div>
                <div class="annotate-form">
                    <textarea id="annotate-content" rows="3" placeholder="<?php esc_attr_e( 'Write a comment. Use @username to tag.', 'dans-annotator' ); ?>"></textarea>
                    <div class="annotate-actions annotate-actions-split">
                        <div class="annotate-actions-left">
                            <button id="annotate-post" class="annotate-btn annotate-btn-primary"><?php esc_html_e( 'Post', 'dans-annotator' ); ?></button>
                            <button id="annotate-cancel-new" class="annotate-btn annotate-btn-secondary"><?php esc_html_e( 'Cancel', 'dans-annotator' ); ?></button>
                        </div>
                        <div class="annotate-actions-right">
                            <button id="annotate-close-thread" class="annotate-btn annotate-btn-secondary"><?php esc_html_e( 'Close Thread', 'dans-annotator' ); ?></button>
                            <button id="annotate-delete-thread" class="annotate-btn annotate-btn-danger"><?php esc_html_e( 'Delete Thread', 'dans-annotator' ); ?></button>
                        </div>
                    </div>
                </div>
                <div class="annotate-confirm-panel annotate-confirm-hidden" id="annotate-close-confirm">
                    <p><?php esc_html_e( 'Really close this thread? No new comments can be added.', 'dans-annotator' ); ?></p>
                    <div class="annotate-confirm-actions">
                        <button id="annotate-confirm-close" class="annotate-btn annotate-btn-primary"><?php esc_html_e( 'Yes, Close', 'dans-annotator' ); ?></button>
                        <button id="annotate-cancel-close" class="annotate-btn annotate-btn-secondary"><?php esc_html_e( 'Cancel', 'dans-annotator' ); ?></button>
                    </div>
                </div>
                <div class="annotate-confirm-panel annotate-confirm-hidden" id="annotate-delete-confirm">
                    <p><?php esc_html_e( 'Really delete this thread? This cannot be undone.', 'dans-annotator' ); ?></p>
                    <div class="annotate-confirm-actions">
                        <button id="annotate-confirm-delete" class="annotate-btn annotate-btn-danger"><?php esc_html_e( 'Yes, Delete', 'dans-annotator' ); ?></button>
                        <button id="annotate-cancel-delete" class="annotate-btn annotate-btn-secondary"><?php esc_html_e( 'Cancel', 'dans-annotator' ); ?></button>
                    </div>
                </div>
            </div>
            <div id="annotate-viewall-view" class="annotate-panel-view annotate-panel-view-all" hidden>
                <div class="annotate-header">
                    <div class="annotate-header-info">
                        <strong><?php esc_html_e( 'All Annotations', 'dans-annotator' ); ?> (<span id="annotate-viewall-count">0</span>)</strong>
                        <span class="annotate-subtitle" id="annotate-viewall-subtitle"><?php esc_html_e( 'Everything on this page', 'dans-annotator' ); ?></span>
                        <span class="annotate-missing-pill" id="annotate-viewall-pill" hidden></span>
                    </div>
                    <div class="annotate-controls">
                        <button id="annotate-close-panel" class="annotate-btn-icon" title="<?php esc_attr_e( 'Close', 'dans-annotator' ); ?>">✕</button>
                    </div>
                </div>
                <div class="annotate-all-list" id="annotate-viewall-list">
                    <div id="annotate-viewall-empty" class="annotate-empty-state">
                        <p><?php esc_html_e( 'No annotations yet.', 'dans-annotator' ); ?></p>
                        <p class="annotate-empty-hint"><?php esc_html_e( 'Click an element on the page to start annotating.', 'dans-annotator' ); ?></p>
                    </div>
                    <div class="annotate-viewall-sections">
                        <div class="annotate-section" data-section="missing" hidden>
                            <h3 class="annotate-section-title">
                                <span class="annotate-section-dot annotate-section-dot-missing">●</span>
                                <?php esc_html_e( 'Missing', 'dans-annotator' ); ?> (<span class="annotate-section-count">0</span>)
                            </h3>
                            <div class="annotate-section-body"></div>
                        </div>
                        <div class="annotate-section" data-section="open" hidden>
                            <h3 class="annotate-section-title">
                                <span class="annotate-section-dot annotate-section-dot-open">●</span>
                                <?php esc_html_e( 'Open', 'dans-annotator' ); ?> (<span class="annotate-section-count">0</span>)
                            </h3>
                            <div class="annotate-section-body"></div>
                        </div>
                        <div class="annotate-section" data-section="closed" hidden>
                            <h3 class="annotate-section-title">
                                <span class="annotate-section-dot annotate-section-dot-closed">●</span>
                                <?php esc_html_e( 'Closed', 'dans-annotator' ); ?> (<span class="annotate-section-count">0</span>)
                            </h3>
                            <div class="annotate-section-body"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="annotate-floating-controls" aria-hidden="true">
            <button type="button" class="annotate-floating-btn annotate-floating-view"><?php esc_html_e( 'View All', 'dans-annotator' ); ?></button>
            <button type="button" class="annotate-floating-btn annotate-floating-create"><?php esc_html_e( 'Create New', 'dans-annotator' ); ?></button>
            <button type="button" class="annotate-floating-btn annotate-floating-cancel" hidden><?php esc_html_e( 'Cancel', 'dans-annotator' ); ?></button>
            <?php if ( function_exists( 'annotate_is_collaborator_session' ) && annotate_is_collaborator_session() ) : ?>
                <button type="button" class="annotate-floating-btn annotate-floating-disconnect" id="annotate-collab-disconnect"><?php esc_html_e( 'Disconnect', 'dans-annotator' ); ?></button>
            <?php endif; ?>
        </div>
        <div id="annotate-autocomplete" class="annotate-autocomplete" aria-hidden="true"></div>
        <?php
    }

    protected function maybe_handle_collaborator_session() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- collaborator invite links intentionally skip nonce.
        if ( empty( $_GET['annotate-collab'] ) ) {
            return;
        }
        if ( function_exists( 'annotate_outside_collaborators_enabled' ) && ! annotate_outside_collaborators_enabled() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- collaborator invite links intentionally skip nonce.
        $raw_param = sanitize_text_field( wp_unslash( $_GET['annotate-collab'] ) );
        $token     = function_exists( 'annotate_decode_collaborator_token_from_link' ) ? annotate_decode_collaborator_token_from_link( $raw_param ) : '';
        if ( $token ) {
            $collaborator = annotate_get_collaborator_by_encrypted_value( $token );
            if ( $collaborator && ! empty( $collaborator->id ) ) {
                annotate_set_collaborator_session_cookie( intval( $collaborator->id ) );
            }
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        if ( empty( $host ) ) {
            $current_url = home_url( $request_uri );
        } else {
            $scheme = is_ssl() ? 'https://' : 'http://';
            $current_url = $scheme . $host . $request_uri;
        }
        $redirect    = remove_query_arg( 'annotate-collab', $current_url );
        if ( empty( $redirect ) ) {
            $redirect = home_url();
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    public function run_auto_cleanup() {
        $settings = annotate_get_settings();
        if ( empty( $settings['auto_delete_enabled'] ) ) {
            return;
        }

        $months = max( 1, intval( $settings['auto_delete_months'] ) );
        $cutoff_ts = time() - ( $months * 30 * DAY_IN_SECONDS );
        $cutoff    = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

        global $wpdb;
        $threads_table = $wpdb->prefix . 'annotate_threads';
        $comments_table = $wpdb->prefix . 'annotate_comments';
        $tags_table = $wpdb->prefix . 'annotate_tags';
        $collaborators_table = $wpdb->prefix . 'annotate_collaborators';
        $collaborators_table_escaped = esc_sql( $collaborators_table );

        // Clean old threads and cascade delete comments and tags.
        // phpcs:ignore
        $thread_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $threads_table WHERE last_activity < %s", $cutoff ) );
        if ( ! empty( $thread_ids ) ) {
            $ids_sql = implode( ',', array_map( 'absint', $thread_ids ) );
            if ( $ids_sql ) {
                // phpcs:ignore
                $wpdb->query( "DELETE t FROM $tags_table t INNER JOIN $comments_table c ON t.comment_id = c.id WHERE c.thread_id IN ($ids_sql)" );
                // phpcs:ignore
                $wpdb->query( "DELETE FROM $comments_table WHERE thread_id IN ($ids_sql)" );
                // phpcs:ignore
                $wpdb->query( "DELETE FROM $threads_table WHERE id IN ($ids_sql)" );
            }
        }

        // Remove collaborators who have not been active.
        $collaborators_table_sql = sprintf( '`%s`', $collaborators_table_escaped );
        // phpcs:ignore -- table name is escaped separately above.
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$collaborators_table_sql} LIKE %s", 'last_activity_date' ) ) ) {
            // phpcs:ignore -- table name is escaped separately above.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$collaborators_table_sql} WHERE COALESCE(last_activity_date, created_at) < %s", $cutoff ) );
        }
    }
}

new Annotate_Plugin();
