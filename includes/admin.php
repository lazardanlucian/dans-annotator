<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'annotate_register_settings_page' );
add_action( 'admin_init', 'annotate_register_settings' );
add_action( 'admin_enqueue_scripts', 'annotate_admin_enqueue_assets' );
add_action( 'admin_post_annotate_delete_all', 'annotate_handle_delete_all' );

function annotate_register_settings_page() {
    add_options_page(
        esc_html__( 'Dan\'s Annotator Settings', 'dans-annotator' ),
        esc_html__( 'Dan\'s Annotator', 'dans-annotator' ),
        'manage_options',
        'annotate-settings',
        'annotate_render_settings_page'
    );
}

function annotate_register_settings() {
    register_setting( 'dans_annotator_settings', 'dans_annotator_settings', 'annotate_sanitize_settings' );
}

function annotate_get_settings_defaults() {
    return array(
        'allow_outside_collaborators' => true,
        'auto_delete_enabled'        => true,
        'auto_delete_months'         => annotate_default_auto_delete_months(),
        'show_donate_button'         => true,
    );
}

function annotate_default_auto_delete_months() {
    return 12;
}

function annotate_outside_collaborators_enabled() {
    $settings = annotate_get_settings();
    return ! empty( $settings['allow_outside_collaborators'] );
}

function annotate_seed_settings_defaults() {
    $existing = get_option( 'dans_annotator_settings', false );
    if ( false === $existing ) {
        add_option( 'dans_annotator_settings', annotate_get_settings_defaults() );
        return;
    }
    if ( ! is_array( $existing ) ) {
        return;
    }
    $updated = false;
    if ( ! isset( $existing['auto_delete_months'] ) ) {
        $existing['auto_delete_months'] = annotate_default_auto_delete_months();
        $updated = true;
    }
    if ( ! isset( $existing['auto_delete_enabled'] ) ) {
        $existing['auto_delete_enabled'] = true;
        $updated = true;
    }
    if ( ! isset( $existing['allow_outside_collaborators'] ) ) {
        $existing['allow_outside_collaborators'] = true;
        $updated = true;
    }
    if ( ! isset( $existing['show_donate_button'] ) ) {
        $existing['show_donate_button'] = true;
        $updated = true;
    }
    if ( $updated ) {
        update_option( 'dans_annotator_settings', $existing );
    }
}

function annotate_get_settings() {
    $saved    = get_option( 'dans_annotator_settings', array() );
    $defaults = annotate_get_settings_defaults();
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    $settings = wp_parse_args( $saved, $defaults );
    $settings['allow_outside_collaborators'] = ! empty( $settings['allow_outside_collaborators'] );
    $settings['auto_delete_enabled']         = ! empty( $settings['auto_delete_enabled'] );
    $settings['show_donate_button']          = ! empty( $settings['show_donate_button'] );
    if ( empty( $settings['auto_delete_months'] ) ) {
        // Support legacy stored date by estimating months difference.
        if ( ! empty( $settings['auto_delete_date'] ) ) {
            $target = strtotime( $settings['auto_delete_date'] );
            if ( $target ) {
                $diff_months = max( 1, ceil( ( $target - time() ) / ( 30 * DAY_IN_SECONDS ) ) );
                $settings['auto_delete_months'] = $diff_months;
            }
        }
    }

    if ( empty( $settings['auto_delete_months'] ) ) {
        $settings['auto_delete_months'] = annotate_default_auto_delete_months();
    }

    return $settings;
}

function annotate_sanitize_settings( $input ) {
    $defaults = annotate_get_settings_defaults();
    $clean    = array();

    $clean['allow_outside_collaborators'] = ! empty( $input['allow_outside_collaborators'] ) ? 1 : 0;
    $clean['auto_delete_enabled']         = ! empty( $input['auto_delete_enabled'] ) ? 1 : 0;
    $clean['show_donate_button']          = ! empty( $input['show_donate_button'] ) ? 1 : 0;

    $months = isset( $input['auto_delete_months'] ) ? intval( $input['auto_delete_months'] ) : 0;
    if ( $months < 1 ) {
        $months = $defaults['auto_delete_months'];
    }
    if ( $months > 60 ) {
        $months = 60;
    }
    $clean['auto_delete_months'] = $months;

    return wp_parse_args( $clean, $defaults );
}

function annotate_admin_enqueue_assets( $hook ) {
    if ( 'settings_page_annotate-settings' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'annotate-admin', ANNOTATE_PLUGIN_URL . 'assets/annotate-admin.css', array(), '1.0.0' );
}

function annotate_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core verifies the nonce on options save before adding this flag.
    if ( ! empty( $_GET['settings-updated'] ) ) {
        add_settings_error( 'annotate_messages', 'annotate_saved', esc_html__( 'Settings saved.', 'dans-annotator' ), 'updated' );
    }

    $status_nonce = isset( $_GET['annotate-status-nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['annotate-status-nonce'] ) ) : '';
    $status       = isset( $_GET['annotate-status'] ) ? sanitize_text_field( wp_unslash( $_GET['annotate-status'] ) ) : '';
    if ( $status && wp_verify_nonce( $status_nonce, 'annotate-status' ) && 'deleted' === $status ) {
        add_settings_error( 'annotate_messages', 'annotate_deleted', esc_html__( 'All Dan\'s Annotator data was deleted.', 'dans-annotator' ), 'updated' );
    }

    $settings = annotate_get_settings();
    ?>
    <div class="wrap annotate-admin">
        <h1><?php esc_html_e( 'Dan\'s Annotator Settings', 'dans-annotator' ); ?></h1>
        <?php settings_errors( 'annotate_messages' ); ?>

        <div class="annotate-admin-grid">
            <div class="annotate-card">
                <div class="annotate-card-header">
                    <div>
                        <div class="annotate-eyebrow"><?php esc_html_e( 'Collaboration', 'dans-annotator' ); ?></div>
                        <h2><?php esc_html_e( 'Outside Collaborators', 'dans-annotator' ); ?></h2>
                    </div>
                    <span class="annotate-chip"><?php esc_html_e( 'Admin only', 'dans-annotator' ); ?></span>
                </div>
                <p class="annotate-lead"><?php esc_html_e( 'Let invited collaborators comment without full accounts. We keep their sessions scoped to the threads you share with them.', 'dans-annotator' ); ?></p>

                <form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="annotate-settings-form">
                    <?php settings_fields( 'dans_annotator_settings' ); ?>
                    <div class="annotate-field annotate-toggle-field">
                        <label class="annotate-switch">
                            <input type="checkbox" name="dans_annotator_settings[allow_outside_collaborators]" value="1" <?php checked( $settings['allow_outside_collaborators'], true ); ?> />
                            <span class="annotate-slider" aria-hidden="true"></span>
                            <span class="annotate-switch-label"><?php esc_html_e( 'Allow outside collaborators', 'dans-annotator' ); ?></span>
                        </label>
                        <p class="annotate-help"><?php esc_html_e( 'When enabled, invite links can be shared with non-users so they can participate in specific threads.', 'dans-annotator' ); ?></p>
                    </div>

                    <div class="annotate-card-divider"></div>

                    <div class="annotate-field annotate-toggle-field">
                        <label class="annotate-switch">
                            <input type="checkbox" id="annotate-auto-delete-enabled" name="dans_annotator_settings[auto_delete_enabled]" value="1" <?php checked( $settings['auto_delete_enabled'], true ); ?> />
                            <span class="annotate-slider" aria-hidden="true"></span>
                            <span class="annotate-switch-label"><?php esc_html_e( 'Auto-delete old threads', 'dans-annotator' ); ?></span>
                        </label>
                        <p class="annotate-help"><?php esc_html_e( 'Automatically remove annotation threads and comments after they have been inactive past the window below.', 'dans-annotator' ); ?></p>
                    </div>

                    <div class="annotate-field annotate-range-field<?php echo $settings['auto_delete_enabled'] ? '' : ' is-disabled'; ?>" id="annotate-auto-delete-field">
                        <label for="annotate-auto-delete-months">
                            <?php esc_html_e( 'Auto-delete window (months since last activity)', 'dans-annotator' ); ?>
                            <span class="annotate-dot"></span>
                        </label>
                        <div class="annotate-range-control">
                            <input type="range" min="1" max="60" step="1" id="annotate-auto-delete-months" name="dans_annotator_settings[auto_delete_months]" value="<?php echo esc_attr( intval( $settings['auto_delete_months'] ) ); ?>" <?php disabled( ! $settings['auto_delete_enabled'] ); ?> oninput="annotateAdminUpdateMonths(this)" />
                            <div class="annotate-range-value">
                                <span id="annotate-auto-delete-months-value"><?php echo esc_html( intval( $settings['auto_delete_months'] ) ); ?></span>
                                <span class="annotate-range-units"><?php esc_html_e( 'months', 'dans-annotator' ); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="annotate-card-divider"></div>

                    <div class="annotate-field annotate-toggle-field">
                        <label class="annotate-switch">
                            <input type="checkbox" name="dans_annotator_settings[show_donate_button]" value="1" <?php checked( $settings['show_donate_button'], true ); ?> />
                            <span class="annotate-slider" aria-hidden="true"></span>
                            <span class="annotate-switch-label"><?php esc_html_e( 'Show donate button on frontend', 'dans-annotator' ); ?></span>
                        </label>
                        <p class="annotate-help"><?php esc_html_e( 'Disable this if you do not want the floating donate button and modal available to site users.', 'dans-annotator' ); ?></p>
                    </div>

                    <?php submit_button( esc_html__( 'Save Settings', 'dans-annotator' ), 'primary', 'submit', false ); ?>
                </form>
            </div>

                <div class="annotate-card annotate-card-donate">
                    <div class="annotate-card-header">
                        <div>
                            <div class="annotate-eyebrow"><?php esc_html_e( 'Fuel the roadmap', 'dans-annotator' ); ?></div>
                            <h2><?php esc_html_e( 'Donate to Dan\'s Annotator', 'dans-annotator' ); ?></h2>
                        </div>
                        <span class="annotate-chip"><?php esc_html_e( 'Thank you', 'dans-annotator' ); ?></span>
                        <button type="button" class="annotate-modal-close" aria-label="<?php esc_attr_e( 'Close donation panel', 'dans-annotator' ); ?>">✕</button>
                    </div>
                    <p class="annotate-lead"><?php esc_html_e( 'Help Dan maintain this and other plugins. He wants to become a better programmer by building free plugins.', 'dans-annotator' ); ?></p>
                    <div class="annotate-field annotate-donate-field">
                        <label for="annotate-donate-eth"><?php esc_html_e( 'Ethereum address', 'dans-annotator' ); ?></label>
                        <div class="annotate-donate-row">
                            <div class="annotate-donate-qr" title="Donate via QR">
                                <img src="<?php echo esc_url( ANNOTATE_PLUGIN_URL . 'assets/donate.jpg' ); ?>" alt="<?php esc_attr_e( 'Scan to donate', 'dans-annotator' ); ?>" />
                            </div>
                            <div class="annotate-donate-address" id="annotate-donate-eth" data-eth="0xaf2c6Bfd1fF0434443854E566E88913Ea1C4e8e1">
                                <a href="ethereum:0xaf2c6Bfd1fF0434443854E566E88913Ea1C4e8e1?value=0.2" class="annotate-donate-link">0xaf2c6Bfd1fF0434443854E566E88913Ea1C4e8e1</a>
                            </div>
                        </div>
                    <p class="annotate-help"><?php esc_html_e( 'Send any amount you like — every tip directly funds maintenance and features.', 'dans-annotator' ); ?></p>
                    </div>
                </div>

            <div class="annotate-card annotate-card-danger">
                <div class="annotate-card-header">
                    <div>
                        <div class="annotate-eyebrow"><?php esc_html_e( 'Maintenance', 'dans-annotator' ); ?></div>
                        <h2><?php esc_html_e( 'Delete all Dan\'s Annotator data', 'dans-annotator' ); ?></h2>
                    </div>
                    <span class="annotate-chip annotate-chip-danger"><?php esc_html_e( 'Irreversible', 'dans-annotator' ); ?></span>
                </div>
                <p class="annotate-lead"><?php esc_html_e( 'Remove every annotation thread, comment, tag, and collaborator. Use when you need a clean slate.', 'dans-annotator' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="annotate-danger-form" onsubmit="return confirm('<?php echo esc_js( esc_html__( 'Delete all Dan\'s Annotator data? This cannot be undone.', 'dans-annotator' ) ); ?>');">
                    <input type="hidden" name="action" value="annotate_delete_all" />
                    <?php wp_nonce_field( 'annotate_delete_all' ); ?>
                    <?php submit_button( esc_html__( 'Delete Everything', 'dans-annotator' ), 'delete', 'submit', false, array( 'class' => 'annotate-delete-button' ) ); ?>
                </form>
            </div>
        </div>
    </div>
    <script>
    function annotateAdminUpdateMonths(input){
        var target=document.getElementById('annotate-auto-delete-months-value');
        if(target){target.textContent=input.value;}
    }
    function annotateAdminToggleRange(){
        var toggle=document.getElementById('annotate-auto-delete-enabled');
        var slider=document.getElementById('annotate-auto-delete-months');
        var field=document.getElementById('annotate-auto-delete-field');
        if(!toggle||!slider){return;}
        var disabled=!toggle.checked;
        slider.disabled=disabled;
        if(field){field.classList.toggle('is-disabled',disabled);}
    }
    document.addEventListener('DOMContentLoaded',function(){
        var slider=document.getElementById('annotate-auto-delete-months');
        if(slider){annotateAdminUpdateMonths(slider);}
        var toggle=document.getElementById('annotate-auto-delete-enabled');
        if(toggle){
            toggle.addEventListener('change',annotateAdminToggleRange);
            annotateAdminToggleRange();
        }

        window.annotateCopyEth=function(e){
            var link=e && e.currentTarget ? e.currentTarget : e.target;
            var container=document.getElementById('annotate-donate-eth');
            if(!container||!link){return;}
            var address=container.getAttribute('data-eth');
            var href=link.getAttribute('href');
            if(!address||!href){return;}
            if(navigator.clipboard && navigator.clipboard.writeText){
                var openInNewTab=e && (e.metaKey || e.ctrlKey || e.button===1);
                e.preventDefault();
                navigator.clipboard.writeText(address).then(function(){
                    link.classList.add('is-copied');
                    setTimeout(function(){link.classList.remove('is-copied');},900);
                    setTimeout(function(){
                        if(openInNewTab){
                            window.open(href,'_blank');
                        }else{
                            window.location.href=href;
                        }
                    },120);
                }).catch(function(){
                    if(openInNewTab){
                        window.open(href,'_blank');
                    }else{
                        window.location.href=href;
                    }
                });
            }
        }

        var donateLink=document.querySelector('.annotate-donate-link');
        if(donateLink){
            donateLink.addEventListener('click',annotateCopyEth);
        }

        // Donation modal open on page load
        var wrapper=document.querySelector('.annotate-admin');
        var donateCard=document.querySelector('.annotate-card-donate');
        var closeBtn=document.querySelector('.annotate-modal-close');
        if(wrapper && donateCard){
            wrapper.classList.add('is-donate-open');
            donateCard.classList.add('is-modal');
        }
        function closeDonateModal(){
            if(!wrapper || !donateCard){return;}
            donateCard.classList.add('is-leaving');
            setTimeout(function(){
                wrapper.classList.remove('is-donate-open');
                donateCard.classList.remove('is-modal');
                donateCard.classList.remove('is-leaving');
                if(closeBtn){
                    closeBtn.remove();
                }
            },350);
        }
        if(closeBtn){
            closeBtn.addEventListener('click',closeDonateModal);
        }
    });
    </script>
    <?php
}

function annotate_handle_delete_all() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to delete Dan\'s Annotator data.', 'dans-annotator' ) );
    }

    check_admin_referer( 'annotate_delete_all' );

    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'annotate_tags',
        $wpdb->prefix . 'annotate_comments',
        $wpdb->prefix . 'annotate_threads',
        $wpdb->prefix . 'annotate_collaborators',
    );

    foreach ( $tables as $table ) {
        $table_escaped = esc_sql( $table );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are predefined above and escaped.
        $wpdb->query( "TRUNCATE TABLE `{$table_escaped}`" );
    }

    $redirect = add_query_arg(
        array(
            'annotate-status'       => 'deleted',
            'annotate-status-nonce' => wp_create_nonce( 'annotate-status' ),
        ),
        admin_url( 'options-general.php?page=annotate-settings' )
    );
    wp_safe_redirect( $redirect );
    exit;
}
