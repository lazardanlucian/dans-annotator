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
    wp_enqueue_style( 'annotate-admin', ANNOTATE_PLUGIN_URL . 'assets/annotate-admin.css', array(), '1.0.1' );
}

function annotate_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $donate_iframe_src = 'https://onlyframes.ro/donate.html?plugin=dans-annotator';

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

            <div class="annotate-card annotate-card-donate" id="annotate-donate-inline" data-donate-iframe="<?php echo esc_attr( $donate_iframe_src ); ?>"></div>

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

        var donateCard=document.getElementById('annotate-donate-inline');
        if(donateCard){
            var iframeSrc=donateCard.getAttribute('data-donate-iframe');
            if(iframeSrc){
                annotateAdminShowDonateOverlay(iframeSrc,donateCard);
            }
        }
    });

    function annotateAdminEscapeAttr(str){
        if(!str){return '';}
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;');
    }

    function annotateAdminShowDonateOverlay(iframeSrc,inlineTarget){
        var existing=document.getElementById('annotate-admin-donate-overlay');
        if(existing){existing.parentNode.removeChild(existing);}
        var modal=document.createElement('div');
        modal.id='annotate-admin-donate-overlay';
        modal.className='annotate-donate-modal annotate-donate-modal-overlay';
        modal.setAttribute('role','dialog');
        modal.setAttribute('aria-modal','true');
        modal.innerHTML='' +
            '<div class="annotate-donate-backdrop"></div>' +
            '<div class="annotate-donate-card" aria-label="<?php echo esc_attr( 'Donate to Dan\'s Annotator', 'dans-annotator' ); ?>">' +
                '<button type="button" class="annotate-modal-close" aria-label="<?php esc_attr_e( 'Close donation panel', 'dans-annotator' ); ?>">✕</button>' +
                '<iframe class="annotate-donate-iframe" src="'+annotateAdminEscapeAttr(iframeSrc)+'" title="<?php esc_attr_e( 'Donate to Dan\'s Annotator', 'dans-annotator' ); ?>" loading="lazy" referrerpolicy="no-referrer"></iframe>' +
            '</div>';
        document.body.appendChild(modal);
        var iframe=modal.querySelector('.annotate-donate-iframe');
        var hideModal=function(){
            modal.classList.add('is-leaving');
            setTimeout(function(){
                if(modal.parentNode){
                    modal.parentNode.removeChild(modal);
                }
            },200);
            if(inlineTarget && iframe){
                inlineTarget.appendChild(iframe);
                inlineTarget.classList.add('annotate-donate-inline-ready');
            }
            document.removeEventListener('keydown',escHandler);
        };
        var closeBtn=modal.querySelector('.annotate-modal-close');
        var backdrop=modal.querySelector('.annotate-donate-backdrop');
        if(closeBtn){
            closeBtn.addEventListener('click',function(e){
                e.preventDefault();
                hideModal();
            });
        }
        if(backdrop){
            backdrop.addEventListener('click',hideModal);
        }
        function escHandler(ev){
            if(ev.key==='Escape'){
                hideModal();
            }
        }
        document.addEventListener('keydown',escHandler);
    }
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
