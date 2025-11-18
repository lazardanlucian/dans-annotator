<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'annotate/v1', '/threads/query', array(
        'methods'  => 'POST',
        'callback' => 'annotate_get_threads',
        'permission_callback' => 'annotate_rest_can_collaborate'
    ) );

    register_rest_route( 'annotate/v1', '/threads', array(
        'methods' => 'POST',
        'callback' => 'annotate_create_thread',
        'permission_callback' => 'annotate_rest_can_collaborate'
    ) );

    register_rest_route( 'annotate/v1', '/threads/(?P<id>\d+)/close', array(
        'methods' => 'POST',
        'callback' => 'annotate_close_thread',
        'permission_callback' => 'annotate_rest_require_user'
    ) );

    register_rest_route( 'annotate/v1', '/threads/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'annotate_delete_thread',
        'permission_callback' => 'annotate_rest_require_user'
    ) );

    register_rest_route( 'annotate/v1', '/comments/query', array(
        'methods' => 'POST',
        'callback' => 'annotate_get_comments',
        'permission_callback' => 'annotate_rest_can_collaborate'
    ) );

    register_rest_route( 'annotate/v1', '/comments', array(
        'methods' => 'POST',
        'callback' => 'annotate_create_comment',
        'permission_callback' => 'annotate_rest_can_collaborate'
    ) );

    register_rest_route( 'annotate/v1', '/users/search', array(
        'methods' => 'POST',
        'callback' => 'annotate_users_autocomplete',
        'permission_callback' => 'annotate_rest_can_collaborate'
    ) );

    register_rest_route( 'annotate/v1', '/session/disconnect', array(
        'methods' => 'POST',
        'callback' => 'annotate_disconnect_collaborator_session',
        'permission_callback' => 'annotate_rest_can_collaborate'
    ) );
} );

add_filter( 'rest_authentication_errors', 'annotate_maybe_allow_collaborator_rest', 10, 1 );
$annotate_collaborator_thread_cache = array();

function annotate_get_threads( WP_REST_Request $request ) {
    global $wpdb;
    $url = $request->get_param( 'url' );
    $actor = annotate_get_active_actor_context();
    $collaborator_id = ( $actor && ! empty( $actor['is_collaborator'] ) ) ? intval( $actor['id'] ) : 0;
    $table = $wpdb->prefix . 'annotate_threads';
    $comments_table = $wpdb->prefix . 'annotate_comments';
    $threads_table_sql  = sprintf( '`%s`', esc_sql( $table ) );
    $comments_table_sql = sprintf( '`%s`', esc_sql( $comments_table ) );
    if ( empty( $url ) ) {
        return new WP_REST_Response( array(), 200 );
    }
    // phpcs:disable -- table names are escaped above and cannot be parameterized.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT t.*, 
                ( SELECT c.content FROM {$comments_table_sql} c WHERE c.thread_id = t.id AND c.is_deleted = 0 ORDER BY c.created_at DESC LIMIT 1 ) AS last_comment_content,
                ( SELECT c.created_by_id FROM {$comments_table_sql} c WHERE c.thread_id = t.id AND c.is_deleted = 0 ORDER BY c.created_at DESC LIMIT 1 ) AS last_comment_actor_id,
                ( SELECT c.created_by_is_collaborator FROM {$comments_table_sql} c WHERE c.thread_id = t.id AND c.is_deleted = 0 ORDER BY c.created_at DESC LIMIT 1 ) AS last_comment_actor_is_collaborator
            FROM {$threads_table_sql} t WHERE t.post_url = %s",
            $url
        ),
        ARRAY_A
    );
    // phpcs:enable

    foreach ( $rows as $index => &$row ) {
        if ( $collaborator_id && ! annotate_collaborator_can_access_thread( intval( $row['id'] ), $collaborator_id ) ) {
            unset( $rows[ $index ] );
            continue;
        }
        $row['is_closed'] = intval( $row['is_closed'] );
        $excerpt = '';
        if ( ! empty( $row['last_comment_content'] ) ) {
            $comment_text = annotate_expand_content_tokens( $row['last_comment_content'] );
            $raw_excerpt = wp_strip_all_tags( $comment_text );
            if ( function_exists( 'mb_strimwidth' ) ) {
                $excerpt = mb_strimwidth( $raw_excerpt, 0, 180, '…', get_bloginfo( 'charset' ) );
            } else {
                $excerpt = substr( $raw_excerpt, 0, 180 );
                if ( strlen( $raw_excerpt ) > 180 ) {
                    $excerpt .= '…';
                }
            }
        }
        $row['last_comment_excerpt'] = $excerpt;
        $row['last_comment_author'] = '';
        if ( isset( $row['last_comment_actor_id'] ) && '' !== $row['last_comment_actor_id'] ) {
            $actor = annotate_format_actor_for_response( intval( $row['last_comment_actor_id'] ), intval( $row['last_comment_actor_is_collaborator'] ) );
            if ( $actor && isset( $actor['display_name'] ) ) {
                $row['last_comment_author'] = $actor['display_name'];
            }
        }
        unset( $row['last_comment_actor_id'], $row['last_comment_actor_is_collaborator'] );
        unset( $row['last_comment_content'] );
    }

    return rest_ensure_response( array_values( $rows ) );
}

function annotate_create_thread( WP_REST_Request $request ) {
    global $wpdb;
    $data = $request->get_json_params();
    $url = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
    $selector = isset( $data['selector'] ) ? sanitize_text_field( $data['selector'] ) : '';
    if ( empty( $url ) || empty( $selector ) ) {
        return new WP_REST_Response( array( 'error' => 'missing' ), 400 );
    }
    $actor = annotate_get_active_actor_context();
    if ( ! $actor ) {
        return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
    }
    $table = $wpdb->prefix . 'annotate_threads';
    // phpcs:ignore
    $wpdb->insert( $table, array(
        'post_url' => $url,
        'selector' => $selector,
        'created_by_id' => intval( $actor['id'] ),
        'created_by_is_collaborator' => ! empty( $actor['is_collaborator'] ) ? 1 : 0,
    ), array( '%s', '%s', '%d', '%d' ) );
    $id = $wpdb->insert_id;
    if ( ! empty( $actor['is_collaborator'] ) ) {
        annotate_register_collaborator_thread_access( intval( $actor['id'] ), $id );
    }
    return rest_ensure_response( array( 'id' => $id ) );
}

function annotate_close_thread( WP_REST_Request $request ) {
    global $wpdb;
    $id = intval( $request['id'] );
    $table = $wpdb->prefix . 'annotate_threads';
    // phpcs:ignore
    $wpdb->update( $table, array( 'is_closed' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
    return rest_ensure_response( array( 'closed' => true ) );
}

function annotate_delete_thread( WP_REST_Request $request ) {
    global $wpdb;
    $id = intval( $request['id'] );
    $table = $wpdb->prefix . 'annotate_threads';
    // phpcs:ignore
    $thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    if ( ! $thread ) {
        return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        if ( intval( $thread->created_by_is_collaborator ) || intval( $thread->created_by_id ) !== get_current_user_id() ) {
            return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
        }
    }
    if ( ! intval( $thread->is_closed ) && ! current_user_can( 'manage_options' ) ) {
        return new WP_REST_Response( array( 'error' => 'must_close' ), 400 );
    }
    // delete comments and tags belonging to this thread
    $comments_table = $wpdb->prefix . 'annotate_comments';
    $tags_table = $wpdb->prefix . 'annotate_tags';
    // delete tags joined to comments of this thread
    // phpcs:ignore
    $wpdb->query( $wpdb->prepare( "DELETE t FROM $tags_table t INNER JOIN $comments_table c ON t.comment_id = c.id WHERE c.thread_id = %d", $id ) );
    // phpcs:ignore
    $wpdb->query( $wpdb->prepare( "DELETE FROM $comments_table WHERE thread_id = %d", $id ) );
    // phpcs:ignore
    $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id = %d", $id ) );
    return rest_ensure_response( array( 'deleted' => true ) );
}

function annotate_get_comments( WP_REST_Request $request ) {
    global $wpdb;
    $thread_id = intval( $request->get_param( 'thread_id' ) );
    if ( ! $thread_id ) {
        return rest_ensure_response( array() );
    }
    $actor = annotate_get_active_actor_context();
    if ( $actor && ! empty( $actor['is_collaborator'] ) ) {
        if ( ! annotate_collaborator_can_access_thread( $thread_id, intval( $actor['id'] ) ) ) {
            return rest_ensure_response( array() );
        }
    }
    $comments_table = $wpdb->prefix . 'annotate_comments';
    // phpcs:ignore
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $comments_table WHERE thread_id = %d AND is_deleted = 0 ORDER BY created_at ASC", $thread_id ), ARRAY_A );
    // attach user info and tags
    foreach ( $rows as &$r ) {
        $r['user'] = annotate_format_actor_for_response( intval( $r['created_by_id'] ), intval( $r['created_by_is_collaborator'] ) );
        $tags_table = $wpdb->prefix . 'annotate_tags';
        // phpcs:ignore
        $tags = $wpdb->get_results( $wpdb->prepare( "SELECT target_id, target_is_collaborator FROM $tags_table WHERE comment_id = %d", $r['id'] ), ARRAY_A );
        $tag_details = array();
        if ( ! empty( $tags ) ) {
            foreach ( $tags as $tag_row ) {
                $tag = annotate_format_actor_for_response( intval( $tag_row['target_id'] ), intval( $tag_row['target_is_collaborator'] ) );
                if ( $tag ) {
                    $tag_details[] = $tag;
                }
            }
        }
        $r['tags'] = $tag_details;
    }
    return rest_ensure_response( $rows );
}

function annotate_create_comment( WP_REST_Request $request ) {
    global $wpdb;
    $data = $request->get_json_params();
    $thread_id = isset( $data['thread_id'] ) ? intval( $data['thread_id'] ) : 0;
    $raw_content = isset( $data['content'] ) ? $data['content'] : '';
    $content = annotate_normalize_comment_content( $raw_content );
    $parent_id = isset( $data['parent_id'] ) ? intval( $data['parent_id'] ) : null;
    if ( ! $thread_id || '' === $content ) {
        return new WP_REST_Response( array( 'error' => 'missing' ), 400 );
    }
    $actor = annotate_get_active_actor_context();
    if ( ! $actor ) {
        return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
    }
    if ( ! empty( $actor['is_collaborator'] ) && ! annotate_collaborator_can_access_thread( $thread_id, intval( $actor['id'] ) ) ) {
        return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
    }
    $tagged_actors = array();
    $content_with_tokens = annotate_replace_mentions_with_tokens( $content, $tagged_actors, $actor );
    $comments_table = $wpdb->prefix . 'annotate_comments';
    // phpcs:ignore
    $wpdb->insert( $comments_table, array(
        'thread_id' => $thread_id,
        'parent_id' => $parent_id,
        'created_by_id' => intval( $actor['id'] ),
        'created_by_is_collaborator' => ! empty( $actor['is_collaborator'] ) ? 1 : 0,
        'content' => $content_with_tokens,
    ), array( '%d', '%d', '%d', '%d', '%s' ) );
    $comment_id = $wpdb->insert_id;

    $actor_entries = annotate_unique_actors( $tagged_actors );
    if ( ! empty( $actor_entries ) ) {
        $tags_table = $wpdb->prefix . 'annotate_tags';
        $base_url    = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
        $thread_link = $base_url ? add_query_arg( array( 'annotate-id' => absint( $thread_id ) ), $base_url ) : '';
        $thread_link = $thread_link ? esc_url( $thread_link ) : esc_url( $base_url ?: home_url() );
        $trigger_actor = annotate_get_active_actor_context();
        foreach ( $actor_entries as $actor ) {
            if ( empty( $actor['id'] ) ) {
                continue;
            }
            // phpcs:ignore
            $wpdb->insert( $tags_table, array(
                'comment_id' => $comment_id,
                'target_id' => intval( $actor['id'] ),
                'target_is_collaborator' => ( 'collaborator' === $actor['type'] ) ? 1 : 0,
            ), array( '%d', '%d', '%d' ) );
            annotate_handle_tag_notification( $actor, $thread_link, $trigger_actor );
            if ( 'collaborator' === $actor['type'] ) {
                annotate_register_collaborator_thread_access( intval( $actor['id'] ), $thread_id );
            }
        }
    }

    // update thread last_activity
    $threads_table = $wpdb->prefix . 'annotate_threads';
    // phpcs:ignore
    $wpdb->update( $threads_table, array( 'last_activity' => current_time( 'mysql' ) ), array( 'id' => $thread_id ), array( '%s' ), array( '%d' ) );

    return rest_ensure_response( array( 'id' => $comment_id ) );
}

function annotate_users_autocomplete( WP_REST_Request $request ) {
    global $wpdb;
    $term = $request->get_param( 'term' );
    if ( empty( $term ) ) {
        return rest_ensure_response( array() );
    }
    $users = get_users( array(
        'search' => '*' . $term . '*',
        'search_columns' => array( 'user_email', 'user_login', 'user_nicename', 'display_name' ),
        'number' => 10,
        'fields' => array( 'ID', 'user_email', 'display_name', 'user_login' ),
    ) );
    $collaborators_table = $wpdb->prefix . 'annotate_collaborators';
    // phpcs:ignore
    $collaborators = $wpdb->get_results( "SELECT id, email_encrypted, display_name FROM $collaborators_table ORDER BY created_at DESC LIMIT 200" );
    $term_lower = strtolower( $term );
    $collaborator_matches = array();

    $out = array();
    foreach ( $users as $u ) {
        $out[] = array(
            'ID'             => $u->ID,
            'login'          => $u->user_login,
            'display'        => $u->display_name,
            'email'          => sanitize_email( $u->user_email ),
            'is_collaborator'=> false,
        );
    }
    if ( ! empty( $collaborators ) ) {
        foreach ( $collaborators as $c ) {
            $email = annotate_decrypt_email( $c->email_encrypted );
            if ( ! $email ) {
                continue;
            }
            $display = sanitize_text_field( $c->display_name );
            if ( empty( $display ) ) {
                $parts = explode( '@', $email );
                $display = isset( $parts[0] ) ? $parts[0] : $email;
            }
            if ( '' !== $term_lower ) {
                $match_haystack = strtolower( $display . ' ' . $email );
                if ( false === strpos( $match_haystack, $term_lower ) ) {
                    continue;
                }
            }
            $collaborator_matches[] = array(
                'ID'              => 'c_' . intval( $c->id ),
                'login'           => '',
                'display'         => $display,
                'email'           => sanitize_email( $email ),
                'is_collaborator' => true,
            );
            if ( count( $collaborator_matches ) >= 20 ) {
                break;
            }
        }
    }
    $out = array_merge( $out, $collaborator_matches );
    // De-duplicate based on email/login combos and limit to 10
    $unique = array();
    $results = array();
    foreach ( $out as $entry ) {
        $key = '';
        if ( ! empty( $entry['email'] ) ) {
            $key = strtolower( $entry['email'] );
        } elseif ( ! empty( $entry['login'] ) ) {
            $key = strtolower( $entry['login'] );
        }
        if ( $key && isset( $unique[ $key ] ) ) {
            continue;
        }
        if ( $key ) {
            $unique[ $key ] = true;
        }
        $results[] = $entry;
        if ( count( $results ) >= 10 ) {
            break;
        }
    }

    return rest_ensure_response( $results );
}

function annotate_collaborators_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'annotate_collaborators';
}

function annotate_disconnect_collaborator_session( WP_REST_Request $request ) {
    if ( is_user_logged_in() ) {
        // logged in users simply receive success without clearing any WP auth.
        return rest_ensure_response( array( 'disconnected' => false ) );
    }
    if ( ! annotate_is_collaborator_session() ) {
        return rest_ensure_response( array( 'disconnected' => false ) );
    }
    annotate_clear_collaborator_session_cookie();
    return rest_ensure_response( array( 'disconnected' => true ) );
}

function annotate_is_annotate_rest_request() {
    $route = '';
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
        $route = '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
    } else {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path        = $request_uri ? wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
        $prefix      = '/' . rest_get_url_prefix() . '/';
        if ( $path ) {
            $pos = strpos( $path, $prefix );
            if ( false !== $pos ) {
                $route = substr( $path, $pos + strlen( $prefix ) );
                $route = '/' . ltrim( $route, '/' );
            }
        }
    }
    if ( empty( $route ) ) {
        return false;
    }
    return ( 0 === strpos( $route, '/annotate/v1' ) ) || ( 0 === strpos( $route, 'annotate/v1' ) );
}

function annotate_maybe_allow_collaborator_rest( $result ) {
    if ( true === $result ) {
        return $result;
    }
    if ( ! annotate_is_collaborator_session() ) {
        return $result;
    }
    if ( ! annotate_is_annotate_rest_request() ) {
        return $result;
    }
    if ( is_wp_error( $result ) ) {
        return true;
    }
    return $result;
}

function annotate_rest_can_collaborate() {
    return annotate_has_active_actor();
}

function annotate_rest_require_user() {
    return is_user_logged_in();
}

function annotate_get_email_crypto_key() {
    static $key = null;
    if ( null !== $key ) {
        return $key;
    }
    $salt = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
    $key  = hash( 'sha256', $salt, true );
    return $key;
}

function annotate_secure_random_bytes( $length = 16 ) {
    if ( function_exists( 'random_bytes' ) ) {
        try {
            return random_bytes( $length );
        } catch ( Exception $e ) {}
    }
    if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
        $bytes = openssl_random_pseudo_bytes( $length );
        if ( false !== $bytes ) {
            return $bytes;
        }
    }
    $bytes = '';
    for ( $i = 0; $i < $length; $i++ ) {
        $bytes .= chr( wp_rand( 0, 255 ) );
    }
    return $bytes;
}

function annotate_encrypt_email( $email ) {
    $email = sanitize_email( $email );
    if ( ! $email ) {
        return '';
    }
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        return '';
    }
    $key = annotate_get_email_crypto_key();
    $iv  = annotate_secure_random_bytes( 16 );
    $encrypted = openssl_encrypt( $email, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
    if ( false === $encrypted ) {
        return '';
    }
    return base64_encode( $iv . $encrypted );
}

function annotate_decrypt_email( $ciphertext ) {
    if ( empty( $ciphertext ) ) {
        return '';
    }
    if ( ! function_exists( 'openssl_decrypt' ) ) {
        return '';
    }
    $decoded = base64_decode( $ciphertext, true );
    if ( false === $decoded || strlen( $decoded ) <= 16 ) {
        return '';
    }
    $iv   = substr( $decoded, 0, 16 );
    $data = substr( $decoded, 16 );
    $key  = annotate_get_email_crypto_key();
    $plain = openssl_decrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
    if ( false === $plain ) {
        return '';
    }
    return sanitize_email( $plain );
}

function annotate_prepare_collaborator_row( $row ) {
    if ( ! $row ) {
        return null;
    }
    if ( isset( $row->email ) && $row->email ) {
        $row->email = sanitize_email( $row->email );
        return $row;
    }
    $row->email = annotate_decrypt_email( isset( $row->email_encrypted ) ? $row->email_encrypted : '' );
    return $row;
}

function annotate_get_collaborator( $id ) {
    $id = intval( $id );
    if ( ! $id ) {
        return null;
    }
    global $wpdb;
    $table = annotate_collaborators_table_name();
    // phpcs:ignore
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    return annotate_prepare_collaborator_row( $row );
}

function annotate_get_collaborator_by_email( $email ) {
    $email = sanitize_email( $email );
    if ( ! $email ) {
        return null;
    }
    $target = strtolower( $email );
    global $wpdb;
    $table = annotate_collaborators_table_name();
    // phpcs:ignore
    $rows = $wpdb->get_results( "SELECT id, email_encrypted, display_name FROM $table" );
    if ( empty( $rows ) ) {
        return null;
    }
    foreach ( $rows as $row ) {
        $decrypted = annotate_decrypt_email( $row->email_encrypted );
        if ( ! $decrypted ) {
            continue;
        }
        if ( strtolower( $decrypted ) === $target ) {
            $row->email = sanitize_email( $decrypted );
            return annotate_prepare_collaborator_row( $row );
        }
    }
    return null;
}

function annotate_create_collaborator( $email, $display_name = '' ) {
    $email = sanitize_email( $email );
    if ( ! $email ) {
        return 0;
    }
    $existing = annotate_get_collaborator_by_email( $email );
    if ( $existing ) {
        return intval( $existing->id );
    }
    $encrypted = annotate_encrypt_email( $email );
    if ( ! $encrypted ) {
        return 0;
    }
    if ( empty( $display_name ) ) {
        $parts = explode( '@', $email );
        $display_name = isset( $parts[0] ) ? $parts[0] : $email;
    }
    global $wpdb;
    $table = annotate_collaborators_table_name();
    // phpcs:ignore
    $wpdb->insert( $table, array(
        'email_encrypted' => $encrypted,
        'display_name' => sanitize_text_field( $display_name ),
        'last_activity_date' => current_time( 'mysql' ),
    ), array( '%s', '%s', '%s' ) );
    return intval( $wpdb->insert_id );
}

function annotate_build_user_actor_array( $user ) {
    if ( ! $user ) {
        return null;
    }
    return array(
        'type'           => 'user',
        'id'             => intval( $user->ID ),
        'display_name'   => $user->display_name,
        'user_email'     => sanitize_email( $user->user_email ),
        'user_login'     => $user->user_login,
        'is_collaborator'=> false,
    );
}

function annotate_build_collaborator_actor_array( $collaborator ) {
    if ( ! $collaborator ) {
        return null;
    }
    $email = sanitize_email( isset( $collaborator->email ) ? $collaborator->email : '' );
    $display = isset( $collaborator->display_name ) ? $collaborator->display_name : '';
    if ( empty( $display ) && $email ) {
        $parts = explode( '@', $email );
        $display = isset( $parts[0] ) ? $parts[0] : $email;
    }
    $display = sanitize_text_field( $display );
    return array(
        'type'            => 'collaborator',
        'id'              => intval( $collaborator->id ),
        'display_name'    => $display,
        'user_email'      => $email,
        'user_login'      => '',
        'is_collaborator' => true,
    );
}

function annotate_format_actor_for_response( $id, $is_collaborator ) {
    $id = intval( $id );
    if ( ! $id ) {
        return null;
    }
    if ( intval( $is_collaborator ) ) {
        $collaborator = annotate_get_collaborator( $id );
        $actor = annotate_build_collaborator_actor_array( $collaborator );
    } else {
        $user = get_userdata( $id );
        $actor = annotate_build_user_actor_array( $user );
    }
    if ( ! $actor ) {
        return null;
    }
    $display_name = $actor['display_name'];
    if ( empty( $display_name ) && ! empty( $actor['user_email'] ) ) {
        $display_name = $actor['user_email'];
    }
    return array(
        'ID'             => $actor['id'],
        'display_name'   => $display_name,
        'user_email'     => $actor['user_email'],
        'user_login'     => isset( $actor['user_login'] ) ? $actor['user_login'] : '',
        'is_collaborator'=> (bool) $actor['is_collaborator'],
        'type'           => $actor['type'],
    );
}

function annotate_resolve_actor_from_token( $token, $current_actor = null ) {
    $token = trim( $token );
    if ( empty( $token ) ) {
        return null;
    }
    if ( null === $current_actor ) {
        $current_actor = annotate_get_active_actor_context();
    }
    $is_current_collaborator = $current_actor && ! empty( $current_actor['is_collaborator'] );
    $email_token = sanitize_email( $token );
    $current_actor = annotate_get_active_actor_context();
    $is_current_collaborator = $current_actor && ! empty( $current_actor['is_collaborator'] );
    if ( $email_token && is_email( $email_token ) ) {
        $user = get_user_by( 'email', $email_token );
        if ( $user ) {
            return annotate_build_user_actor_array( $user );
        }
        if ( ! $is_current_collaborator ) {
            if ( function_exists( 'annotate_outside_collaborators_enabled' ) && ! annotate_outside_collaborators_enabled() ) {
                return null;
            }
            $collaborator = annotate_get_collaborator_by_email( $email_token );
            if ( ! $collaborator ) {
                $collaborator_id = annotate_create_collaborator( $email_token );
                $collaborator = $collaborator_id ? annotate_get_collaborator( $collaborator_id ) : null;
            }
            if ( $collaborator ) {
                return annotate_build_collaborator_actor_array( $collaborator );
            }
        }
    }
    $username = sanitize_user( $token, true );
    if ( $username ) {
        $user = get_user_by( 'login', $username );
        if ( $user ) {
            return annotate_build_user_actor_array( $user );
        }
    }
    $users = get_users( array(
        'search' => '*' . $token . '*',
        'search_columns' => array( 'display_name' ),
        'number' => 1,
    ) );
    if ( ! empty( $users ) ) {
        return annotate_build_user_actor_array( $users[0] );
    }
    return null;
}

function annotate_get_collaborator_thread_ids( $collaborator_id, $force_refresh = false ) {
    global $annotate_collaborator_thread_cache;
    $collaborator_id = intval( $collaborator_id );
    if ( ! $collaborator_id ) {
        return array();
    }
    if ( ! is_array( $annotate_collaborator_thread_cache ) ) {
        $annotate_collaborator_thread_cache = array();
    }
    if ( $force_refresh ) {
        unset( $annotate_collaborator_thread_cache[ $collaborator_id ] );
    }
    if ( isset( $annotate_collaborator_thread_cache[ $collaborator_id ] ) ) {
        return $annotate_collaborator_thread_cache[ $collaborator_id ];
    }
    global $wpdb;
    $comments_table = $wpdb->prefix . 'annotate_comments';
    $tags_table     = $wpdb->prefix . 'annotate_tags';
    $threads_table  = $wpdb->prefix . 'annotate_threads';
    $comments_table_sql = sprintf( '`%s`', esc_sql( $comments_table ) );
    $tags_table_sql     = sprintf( '`%s`', esc_sql( $tags_table ) );
    $threads_table_sql  = sprintf( '`%s`', esc_sql( $threads_table ) );
    // phpcs:ignore
    $tagged_thread_ids = $wpdb->get_col(
        $wpdb->prepare(
            // phpcs:ignore -- table names escaped above cannot be parameterized.
            "SELECT DISTINCT c.thread_id FROM {$comments_table_sql} c INNER JOIN {$tags_table_sql} t ON t.comment_id = c.id WHERE t.target_id = %d AND t.target_is_collaborator = 1",
            $collaborator_id
        )
    );
    // phpcs:ignore
    $created_thread_ids = $wpdb->get_col(
        $wpdb->prepare(
            // phpcs:ignore -- table names escaped above cannot be parameterized.
            "SELECT id FROM {$threads_table_sql} WHERE created_by_id = %d AND created_by_is_collaborator = 1",
            $collaborator_id
        )
    );
    $ids = array_unique( array_filter( array_map( 'intval', array_merge( (array) $tagged_thread_ids, (array) $created_thread_ids ) ) ) );
    $annotate_collaborator_thread_cache[ $collaborator_id ] = $ids;
    return $ids;
}

function annotate_collaborator_can_access_thread( $thread_id, $collaborator_id ) {
    $thread_id = intval( $thread_id );
    $collaborator_id = intval( $collaborator_id );
    if ( ! $thread_id || ! $collaborator_id ) {
        return false;
    }
    $allowed_ids = annotate_get_collaborator_thread_ids( $collaborator_id );
    if ( empty( $allowed_ids ) ) {
        return false;
    }
    return in_array( $thread_id, $allowed_ids, true );
}

function annotate_register_collaborator_thread_access( $collaborator_id, $thread_id ) {
    global $annotate_collaborator_thread_cache;
    $collaborator_id = intval( $collaborator_id );
    $thread_id = intval( $thread_id );
    if ( ! $collaborator_id || ! $thread_id ) {
        return;
    }
    $allowed = annotate_get_collaborator_thread_ids( $collaborator_id );
    if ( ! in_array( $thread_id, $allowed, true ) ) {
        $allowed[] = $thread_id;
        if ( ! is_array( $annotate_collaborator_thread_cache ) ) {
            $annotate_collaborator_thread_cache = array();
        }
        $annotate_collaborator_thread_cache[ $collaborator_id ] = $allowed;
    }
}

function annotate_handle_tag_notification( $actor, $thread_link, $trigger_actor = null ) {
    if ( empty( $actor ) || empty( $thread_link ) ) {
        return;
    }
    if ( null === $trigger_actor ) {
        $trigger_actor = annotate_get_active_actor_context();
    }
    $current_name = '';
    if ( $trigger_actor && ! empty( $trigger_actor['display_name'] ) ) {
        $current_name = $trigger_actor['display_name'];
    }
    if ( empty( $current_name ) ) {
        $current_user = wp_get_current_user();
        $current_name = ( $current_user && $current_user->ID ) ? $current_user->display_name : esc_html__( 'Someone', 'dans-annotator' );
    }
    if ( 'user' === $actor['type'] ) {
        /* translators: 1: name of the person who tagged the user, 2: link to the thread. */
        $note = sprintf( esc_html__( 'You were tagged in a note by %1$s — %2$s', 'dans-annotator' ), $current_name, $thread_link );
        add_user_meta( $actor['id'], 'annotate_tag_notifications', wp_strip_all_tags( $note ) );
    }
    $email = isset( $actor['user_email'] ) ? sanitize_email( $actor['user_email'] ) : '';
    if ( ! $email ) {
        return;
    }
    $link_notice = '';
    $link_url    = $thread_link;
    if ( 'collaborator' === $actor['type'] ) {
        if ( function_exists( 'annotate_outside_collaborators_enabled' ) && ! annotate_outside_collaborators_enabled() ) {
            return;
        }
        $collaborator = annotate_get_collaborator( $actor['id'] );
        if ( $collaborator && ! empty( $collaborator->email_encrypted ) ) {
            $token = annotate_encode_collaborator_token_for_link( $collaborator->email_encrypted );
            if ( $token ) {
                $link_url = add_query_arg(
                    'annotate-collab',
                    $token,
                    $link_url
                );
                $link_notice = '<p><strong>' . esc_html__( 'Please do not share this link; it is tied to your email address.', 'dans-annotator' ) . '</strong></p>';
            }
        }
    }
    $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    /* translators: 1: name of the person who tagged you, 2: site name. */
    $subject = sprintf( esc_html__( '%1$s tagged you in a note on %2$s', 'dans-annotator' ), $current_name, $site_name );
    /* translators: %s: name of the person who tagged you. */
    $message  = '<p>' . sprintf( esc_html__( '%s tagged you in a note:', 'dans-annotator' ), esc_html( $current_name ) ) . '</p>';
    $message .= '<p><a href="' . esc_url( $link_url ) . '">' . esc_html__( 'Open this note', 'dans-annotator' ) . '</a></p>';
    if ( $link_notice ) {
        $message .= $link_notice;
    }
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    wp_mail( $email, $subject, $message, $headers );
}

function annotate_clean_encrypted_value( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    $value = str_replace( ' ', '+', $value );
    return preg_replace( '/[^A-Za-z0-9=+\/]/', '', $value );
}

function annotate_encode_collaborator_token_for_link( $encrypted ) {
    $clean = annotate_clean_encrypted_value( $encrypted );
    if ( '' === $clean ) {
        return '';
    }
    $token = rtrim( strtr( $clean, '+/', '-_' ), '=' );
    return $token;
}

function annotate_decode_collaborator_token_from_link( $token ) {
    $token = trim( (string) $token );
    if ( '' === $token ) {
        return '';
    }
    $token = strtr( $token, '-_', '+/' );
    $padding = strlen( $token ) % 4;
    if ( $padding ) {
        $token .= str_repeat( '=', 4 - $padding );
    }
    return annotate_clean_encrypted_value( $token );
}

function annotate_is_collaborator_session() {
    return annotate_get_collaborator_session_id() > 0;
}

function annotate_has_active_actor() {
    return null !== annotate_get_active_actor_context();
}

function annotate_get_collaborator_by_encrypted_value( $encrypted ) {
    $token = annotate_clean_encrypted_value( $encrypted );
    if ( '' === $token ) {
        return null;
    }
    global $wpdb;
    $table = annotate_collaborators_table_name();
    // phpcs:ignore
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email_encrypted = %s", $token ) );
    return annotate_prepare_collaborator_row( $row );
}

function annotate_get_collaborator_cookie_name() {
    return 'annotate_collaborator_session';
}

function annotate_generate_collaborator_session_value( $collaborator_id, $expires ) {
    $collaborator_id = intval( $collaborator_id );
    $expires = intval( $expires );
    if ( ! $collaborator_id || $expires <= time() ) {
        return '';
    }
    $payload = $collaborator_id . '|' . $expires;
    $key     = annotate_get_email_crypto_key();
    $signature = hash_hmac( 'sha256', $payload, $key );
    return base64_encode( $payload . '|' . $signature );
}

function annotate_parse_collaborator_session_value( $value ) {
    if ( empty( $value ) ) {
        return false;
    }
    $decoded = base64_decode( $value, true );
    if ( false === $decoded ) {
        return false;
    }
    $parts = explode( '|', $decoded );
    if ( 3 !== count( $parts ) ) {
        return false;
    }
    list( $id, $expires, $signature ) = $parts;
    $payload = $id . '|' . $expires;
    $expected = hash_hmac( 'sha256', $payload, annotate_get_email_crypto_key() );
    $valid = function_exists( 'hash_equals' ) ? hash_equals( $expected, $signature ) : ( $expected === $signature );
    if ( ! $valid ) {
        return false;
    }
    if ( time() > intval( $expires ) ) {
        return false;
    }
    return array(
        'id'      => intval( $id ),
        'expires' => intval( $expires ),
    );
}

function annotate_set_collaborator_session_cookie( $collaborator_id, $duration = WEEK_IN_SECONDS ) {
    $collaborator_id = intval( $collaborator_id );
    if ( ! $collaborator_id ) {
        return false;
    }
    $duration = max( DAY_IN_SECONDS, intval( $duration ) );
    $expires  = time() + $duration;
    $value    = annotate_generate_collaborator_session_value( $collaborator_id, $expires );
    if ( ! $value ) {
        return false;
    }
    $cookie_name = annotate_get_collaborator_cookie_name();
    $path        = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
    $domain      = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
    $secure      = is_ssl();
    setcookie( $cookie_name, $value, $expires, $path, $domain, $secure, true );
    if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH !== $path ) {
        setcookie( $cookie_name, $value, $expires, SITECOOKIEPATH, $domain, $secure, true );
    }
    $_COOKIE[ $cookie_name ] = $value;
    return true;
}

function annotate_get_collaborator_session_id() {
    $cookie_name = annotate_get_collaborator_cookie_name();
    if ( empty( $_COOKIE[ $cookie_name ] ) ) {
        return 0;
    }
    $cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
    $session      = annotate_parse_collaborator_session_value( $cookie_value );
    if ( ! $session || empty( $session['id'] ) ) {
        return 0;
    }
    return intval( $session['id'] );
}

function annotate_clear_collaborator_session_cookie() {
    $cookie_name = annotate_get_collaborator_cookie_name();
    $path        = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
    $domain      = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
    setcookie( $cookie_name, '', time() - YEAR_IN_SECONDS, $path, $domain, is_ssl(), true );
    if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH !== $path ) {
        setcookie( $cookie_name, '', time() - YEAR_IN_SECONDS, SITECOOKIEPATH, $domain, is_ssl(), true );
    }
    unset( $_COOKIE[ $cookie_name ] );
}

function annotate_touch_collaborator_activity( $collaborator_id ) {
    $collaborator_id = intval( $collaborator_id );
    if ( ! $collaborator_id ) {
        return;
    }
    global $wpdb;
    $table = annotate_collaborators_table_name();
    // phpcs:ignore
    $wpdb->update( $table, array( 'last_activity_date' => current_time( 'mysql' ) ), array( 'id' => $collaborator_id ), array( '%s' ), array( '%d' ) );
}

function annotate_get_active_actor_context() {
    static $actor = null;
    if ( null !== $actor ) {
        return $actor;
    }
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        if ( $user && $user->ID ) {
            $actor = array(
                'type'            => 'user',
                'id'              => intval( $user->ID ),
                'display_name'    => $user->display_name ?: $user->user_login,
                'user_email'      => sanitize_email( $user->user_email ),
                'user_login'      => $user->user_login,
                'is_collaborator' => false,
            );
            return $actor;
        }
    }
    $collaborator_id = annotate_get_collaborator_session_id();
    if ( $collaborator_id ) {
        if ( function_exists( 'annotate_outside_collaborators_enabled' ) && ! annotate_outside_collaborators_enabled() ) {
            $actor = null;
            return null;
        }
        $collaborator = annotate_get_collaborator( $collaborator_id );
        $actor = annotate_build_collaborator_actor_array( $collaborator );
        if ( $actor ) {
            $actor['is_collaborator'] = true;
            annotate_touch_collaborator_activity( $collaborator_id );
            return $actor;
        }
    }
    $actor = null;
    return null;
}

function annotate_normalize_comment_content( $content ) {
    if ( null === $content ) {
        return '';
    }
    $content = wp_unslash( $content );
    $content = wp_specialchars_decode( $content, ENT_QUOTES );
    $content = sanitize_textarea_field( $content );
    return $content;
}

function annotate_generate_tag_token( $actor ) {
    if ( empty( $actor ) || empty( $actor['id'] ) ) {
        return '';
    }
    $is_collaborator = ! empty( $actor['is_collaborator'] ) || ( isset( $actor['type'] ) && 'collaborator' === $actor['type'] );
    $prefix = $is_collaborator ? 'c' : 'u';
    return 'tag://' . $prefix . intval( $actor['id'] );
}

function annotate_replace_mentions_with_tokens( $content, &$actors = array(), $current_actor = null ) {
    if ( '' === $content ) {
        return '';
    }
    $pattern = '/@([^\s<>"\'\)\(,;:]+)/';
    $actors = array();
    $result = preg_replace_callback(
        $pattern,
        function( $matches ) use ( &$actors, $current_actor ) {
            $token = isset( $matches[1] ) ? $matches[1] : '';
            if ( '' === $token ) {
                return $matches[0];
            }
            $actor = annotate_resolve_actor_from_token( $token, $current_actor );
            if ( ! $actor ) {
                return $matches[0];
            }
            $actors[] = $actor;
            $replacement = annotate_generate_tag_token( $actor );
            return $replacement ? $replacement : $matches[0];
        },
        $content
    );
    return $result;
}

function annotate_actor_storage_key( $actor ) {
    if ( empty( $actor ) || empty( $actor['id'] ) ) {
        return '';
    }
    $type = isset( $actor['type'] ) ? $actor['type'] : ( ! empty( $actor['is_collaborator'] ) ? 'collaborator' : 'user' );
    return $type . ':' . intval( $actor['id'] );
}

function annotate_unique_actors( $actors ) {
    if ( empty( $actors ) || ! is_array( $actors ) ) {
        return array();
    }
    $unique = array();
    foreach ( $actors as $actor ) {
        $key = annotate_actor_storage_key( $actor );
        if ( ! $key || isset( $unique[ $key ] ) ) {
            continue;
        }
        $unique[ $key ] = $actor;
    }
    return array_values( $unique );
}

function annotate_expand_content_tokens( $text ) {
    if ( empty( $text ) || false === strpos( $text, 'tag://' ) ) {
        return $text;
    }
    return preg_replace_callback(
        '/tag:\/\/([a-z]?)(\d+)/i',
        function( $matches ) {
            $type_flag = isset( $matches[1] ) ? strtolower( $matches[1] ) : '';
            $id        = isset( $matches[2] ) ? intval( $matches[2] ) : 0;
            if ( ! $id ) {
                return '@';
            }
            $is_collaborator = ( 'c' === $type_flag );
            $actor           = annotate_format_actor_for_response( $id, $is_collaborator );
            if ( ! $actor ) {
                return '@';
            }
            return annotate_actor_display_handle( $actor );
        },
        $text
    );
}

function annotate_actor_display_handle( $actor ) {
    if ( empty( $actor ) ) {
        return '@' . esc_html__( 'User', 'dans-annotator' );
    }
    $email = isset( $actor['user_email'] ) ? $actor['user_email'] : '';
    $label = '';
    if ( $email ) {
        $parts = explode( '@', $email );
        $label = isset( $parts[0] ) ? $parts[0] : $email;
    } else {
        $label = isset( $actor['display_name'] ) ? $actor['display_name'] : '';
    }
    $label = $label ? sanitize_text_field( $label ) : esc_html__( 'User', 'dans-annotator' );
    return '@' . $label;
}
