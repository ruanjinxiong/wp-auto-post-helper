<?php
/**
 * Plugin Name:       WP Auto Post Helper
 * Description:       Enhances the media REST API by tracking usage counts for attachments.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP Auto Post Helper Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-auto-post-helper
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPAPH_USAGE_META_KEY' ) ) {
    define( 'WPAPH_USAGE_META_KEY', 'wpaph_usage_count' );
}

/**
 * Register the attachment usage count meta field.
 */
function wpaph_register_attachment_usage_meta() {
    register_post_meta(
        'attachment',
        WPAPH_USAGE_META_KEY,
        [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => function () {
                return current_user_can( 'upload_files' );
            },
        ]
    );
}
add_action( 'init', 'wpaph_register_attachment_usage_meta' );

/**
 * Ensure that every REST response for attachments contains the usage count field.
 */
function wpaph_register_usage_count_rest_field() {
    register_rest_field(
        'attachment',
        'usage_count',
        [
            'get_callback' => function ( $object ) {
                $usage_count = get_post_meta( $object['id'], WPAPH_USAGE_META_KEY, true );

                if ( '' === $usage_count ) {
                    $usage_count = 0;
                }

                return (int) $usage_count;
            },
            'update_callback' => function ( $value, $post ) {
                if ( ! current_user_can( 'upload_files' ) ) {
                    return new WP_Error( 'rest_forbidden', __( 'You are not allowed to update usage counts.', 'wp-auto-post-helper' ), [ 'status' => 403 ] );
                }

                if ( null === $value || '' === $value ) {
                    delete_post_meta( $post->ID, WPAPH_USAGE_META_KEY );
                    return true;
                }

                update_post_meta( $post->ID, WPAPH_USAGE_META_KEY, absint( $value ) );

                return true;
            },
            'schema' => [
                'description' => __( 'Number of times the media item has been used.', 'wp-auto-post-helper' ),
                'type'        => 'integer',
                'context'     => [ 'view', 'edit' ],
                'default'     => 0,
            ],
        ]
    );
}
add_action( 'rest_api_init', 'wpaph_register_usage_count_rest_field' );

/**
 * Allow ordering media queries by usage count via the REST API.
 *
 * @param array           $args    Query arguments.
 * @param WP_REST_Request $request Current REST request object.
 *
 * @return array Modified query arguments.
 */
function wpaph_rest_attachment_query_args( $args, $request ) {
    $orderby = $request->get_param( 'orderby' );

    if ( 'usage_count' === $orderby ) {
        $args['meta_key'] = WPAPH_USAGE_META_KEY;
        $args['orderby']  = 'meta_value_num';

        if ( ! isset( $args['order'] ) ) {
            $args['order'] = 'DESC';
        }
    }

    return $args;
}
add_filter( 'rest_attachment_query', 'wpaph_rest_attachment_query_args', 10, 2 );

/**
 * Include the usage count in attachment objects prepared for JavaScript.
 *
 * @param array   $response   Prepared attachment data for JavaScript.
 * @param WP_Post $attachment The attachment post object.
 *
 * @return array
 */
function wpaph_prepare_attachment_for_js( $response, $attachment ) {
    if ( 'attachment' !== $attachment->post_type ) {
        return $response;
    }

    $usage_count = get_post_meta( $attachment->ID, WPAPH_USAGE_META_KEY, true );

    if ( '' === $usage_count ) {
        $usage_count = 0;
    }

    $response['usageCount'] = (int) $usage_count;

    return $response;
}
add_filter( 'wp_prepare_attachment_for_js', 'wpaph_prepare_attachment_for_js', 10, 2 );

/**
 * Prime the usage count meta when attachments are created.
 *
 * @param int $post_id The attachment ID.
 */
function wpaph_prime_usage_count_meta( $post_id ) {
    if ( 'attachment' !== get_post_type( $post_id ) ) {
        return;
    }

    if ( '' === get_post_meta( $post_id, WPAPH_USAGE_META_KEY, true ) ) {
        update_post_meta( $post_id, WPAPH_USAGE_META_KEY, 0 );
    }
}
add_action( 'add_attachment', 'wpaph_prime_usage_count_meta' );

/**
 * Register the Tools page entry for recounting media usage.
 */
function wpaph_register_tools_page() {
    add_management_page(
        __( 'Recount Media Usage', 'wp-auto-post-helper' ),
        __( 'Recount Media Usage', 'wp-auto-post-helper' ),
        'manage_options',
        'wpaph-recount-media-usage',
        'wpaph_render_tools_page'
    );
}
add_action( 'admin_menu', 'wpaph_register_tools_page' );

/**
 * Render the Tools page UI.
 */
function wpaph_render_tools_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Recount Media Usage', 'wp-auto-post-helper' ); ?></h1>
        <p><?php esc_html_e( 'Scan your site content and update usage counts for every media item.', 'wp-auto-post-helper' ); ?></p>
        <p>
            <button type="button" class="button button-primary" id="wpaph-recount-button">
                <?php esc_html_e( 'Recount Usage', 'wp-auto-post-helper' ); ?>
            </button>
        </p>
        <div id="wpaph-recount-status" class="wpaph-recount-status" aria-live="polite"></div>
    </div>
    <?php
}

/**
 * Enqueue assets for the Tools page.
 *
 * @param string $hook Current admin page hook suffix.
 */
function wpaph_enqueue_tools_assets( $hook ) {
    if ( 'tools_page_wpaph-recount-media-usage' !== $hook ) {
        return;
    }

    wp_enqueue_script(
        'wpaph-tools',
        plugin_dir_url( __FILE__ ) . 'assets/js/admin-tools.js',
        [],
        '0.1.0',
        true
    );

    wp_localize_script(
        'wpaph-tools',
        'wpaphTools',
        [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpaph_recount_media_usage' ),
            'messages' => [
                'working'          => __( 'Recounting media usage…', 'wp-auto-post-helper' ),
                'genericError'     => __( 'Something went wrong. Please try again.', 'wp-auto-post-helper' ),
                'deadSummary'      => __( 'Total dead links: %d', 'wp-auto-post-helper' ),
                'logEntry'         => __( 'Post #%1$s • links found: %2$d • saved: %3$d • dead: %4$d', 'wp-auto-post-helper' ),
                'logEntryProgress' => __( 'Post #%1$s • links found: %2$d • saved: %3$d • dead: %4$d • progress: %5$s', 'wp-auto-post-helper' ),
                'progressTotals'   => __( '%1$d/%2$d processed', 'wp-auto-post-helper' ),
            ],
        ]
    );
}
add_action( 'admin_enqueue_scripts', 'wpaph_enqueue_tools_assets' );

/**
 * Enqueue media library enhancements.
 *
 * @param string $hook Current admin page hook suffix.
 */
function wpaph_enqueue_media_library_assets( $hook ) {
    if ( 'upload.php' !== $hook ) {
        return;
    }

    wp_enqueue_script(
        'wpaph-media-library',
        plugin_dir_url( __FILE__ ) . 'assets/js/media-library.js',
        [ 'media-views', 'jquery' ],
        '0.1.0',
        true
    );

    wp_localize_script(
        'wpaph-media-library',
        'wpaphMediaLibrary',
        [
            'labels' => [
                'usage' => __( 'Usage count', 'wp-auto-post-helper' ),
            ],
        ]
    );
}
add_action( 'admin_enqueue_scripts', 'wpaph_enqueue_media_library_assets' );

/**
 * Add the usage count column to the media list view.
 *
 * @param string[] $columns Existing columns.
 *
 * @return string[]
 */
function wpaph_add_usage_count_column( $columns ) {
    $columns['usage_count'] = __( 'Usage Count', 'wp-auto-post-helper' );

    return $columns;
}
add_filter( 'manage_upload_columns', 'wpaph_add_usage_count_column' );

/**
 * Render the usage count column for media items.
 *
 * @param string $column  Column name.
 * @param int    $post_id Attachment ID.
 */
function wpaph_render_usage_count_column( $column, $post_id ) {
    if ( 'usage_count' !== $column ) {
        return;
    }

    $usage_count = get_post_meta( $post_id, WPAPH_USAGE_META_KEY, true );

    if ( '' === $usage_count ) {
        $usage_count = 0;
    }

    echo esc_html( (int) $usage_count );
}
add_action( 'manage_media_custom_column', 'wpaph_render_usage_count_column', 10, 2 );

/**
 * Register the usage count column as sortable.
 *
 * @param array $columns Sortable columns.
 *
 * @return array
 */
function wpaph_make_usage_count_column_sortable( $columns ) {
    $columns['usage_count'] = 'usage_count';

    return $columns;
}
add_filter( 'manage_upload_sortable_columns', 'wpaph_make_usage_count_column_sortable' );

/**
 * Adjust the attachment query to handle sorting by usage count.
 *
 * @param WP_Query $query The current query instance.
 */
function wpaph_adjust_attachment_sorting( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    if ( ! $screen || 'upload' !== $screen->id ) {
        return;
    }

    if ( 'usage_count' !== $query->get( 'orderby' ) ) {
        return;
    }

    $query->set( 'meta_key', WPAPH_USAGE_META_KEY );
    $query->set( 'orderby', 'meta_value_num' );
}
add_action( 'pre_get_posts', 'wpaph_adjust_attachment_sorting' );

/**
 * Handle the AJAX request to recount media usage.
 */
function wpaph_ajax_recount_media_usage() {
    check_ajax_referer( 'wpaph_recount_media_usage', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            [
                'message' => __( 'You are not allowed to recount media usage.', 'wp-auto-post-helper' ),
            ],
            403
        );
    }

    $stage = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : 'init';

    switch ( $stage ) {
        case 'init':
            $response = wpaph_recount_ajax_stage_init();
            break;
        case 'process':
            $session  = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
            $response = wpaph_recount_ajax_stage_process( $session );
            break;
        case 'complete':
            $session  = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
            $response = wpaph_recount_ajax_stage_complete( $session );
            break;
        default:
            $response = new WP_Error( 'wpaph_invalid_stage', __( 'Invalid recount stage.', 'wp-auto-post-helper' ) );
            break;
    }

    if ( is_wp_error( $response ) ) {
        wp_send_json_error(
            [
                'message' => $response->get_error_message(),
            ]
        );
    }

    wp_send_json_success( $response );
}
add_action( 'wp_ajax_wpaph_recount_media_usage', 'wpaph_ajax_recount_media_usage' );

/**
 * Prepare the recount session and queue of posts.
 *
 * @return array|WP_Error
 */
function wpaph_recount_ajax_stage_init() {
    $posts = get_posts(
        [
            'post_type'              => 'post',
            'post_status'            => 'any',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'suppress_filters'       => true,
        ]
    );

    $posts_queue = array_map( 'intval', $posts );
    $state       = [
        'usage_links'     => [],
        'posts_queue'     => $posts_queue,
        'posts_total'     => count( $posts_queue ),
        'posts_processed' => 0,
        'usage_total'     => 0,
        'dead_total'      => 0,
    ];

    $session = wpaph_recount_generate_session_key();
    $saved   = wpaph_recount_save_state( $session, $state );

    if ( is_wp_error( $saved ) ) {
        return $saved;
    }

    return [
        'stage'       => 'init',
        'session'     => $session,
        'hasMore'     => ! empty( $posts_queue ),
        'totalPosts'  => $state['posts_total'],
        'processed'   => 0,
    ];
}

/**
 * Process the next post in the queue and record its usage data.
 *
 * @param string $session Session key.
 *
 * @return array|WP_Error
 */
function wpaph_recount_ajax_stage_process( $session ) {
    if ( '' === $session ) {
        return new WP_Error( 'wpaph_missing_session', __( 'Missing recount session.', 'wp-auto-post-helper' ) );
    }

    $state = wpaph_recount_get_state( $session );

    if ( empty( $state ) || ! is_array( $state ) ) {
        return new WP_Error( 'wpaph_invalid_session', __( 'The recount session has expired or is invalid.', 'wp-auto-post-helper' ) );
    }

    $posts_queue = isset( $state['posts_queue'] ) ? (array) $state['posts_queue'] : [];

    if ( empty( $posts_queue ) ) {
        $state['posts_queue'] = [];
        $save_state = wpaph_recount_save_state( $session, $state );

        if ( is_wp_error( $save_state ) ) {
            return $save_state;
        }

        return [
            'stage'         => 'process',
            'hasMore'       => false,
            'postId'        => null,
            'linksFound'    => 0,
            'linksSaved'    => 0,
            'deadLinks'     => 0,
            'processed'     => isset( $state['posts_processed'] ) ? (int) $state['posts_processed'] : 0,
            'totalPosts'    => isset( $state['posts_total'] ) ? (int) $state['posts_total'] : 0,
        ];
    }

    $post_id = array_shift( $posts_queue );

    $state['posts_queue'] = array_values( $posts_queue );

    $processed = wpaph_recount_process_single_post( $state, $post_id );

    if ( is_wp_error( $processed ) ) {
        wpaph_recount_delete_state( $session );
        return $processed;
    }

    $state = $processed['state'];

    $save_result = wpaph_recount_save_state( $session, $state );

    if ( is_wp_error( $save_result ) ) {
        return $save_result;
    }

    return [
        'stage'         => 'process',
        'hasMore'       => ! empty( $state['posts_queue'] ),
        'postId'        => $processed['result']['post_id'],
        'linksFound'    => $processed['result']['links_found'],
        'linksSaved'    => $processed['result']['links_saved'],
        'deadLinks'     => $processed['result']['dead_links'],
        'processed'     => isset( $state['posts_processed'] ) ? (int) $state['posts_processed'] : 0,
        'totalPosts'    => isset( $state['posts_total'] ) ? (int) $state['posts_total'] : 0,
    ];
}

/**
 * Finalise the recount and update attachment usage counts.
 *
 * @param string $session Session key.
 *
 * @return array|WP_Error
 */
function wpaph_recount_ajax_stage_complete( $session ) {
    if ( '' === $session ) {
        return new WP_Error( 'wpaph_missing_session', __( 'Missing recount session.', 'wp-auto-post-helper' ) );
    }

    $state = wpaph_recount_get_state( $session );

    if ( empty( $state ) || ! is_array( $state ) ) {
        return new WP_Error( 'wpaph_invalid_session', __( 'The recount session has expired or is invalid.', 'wp-auto-post-helper' ) );
    }

    if ( ! empty( $state['posts_queue'] ) ) {
        return new WP_Error( 'wpaph_recount_incomplete', __( 'The recount queue is still being processed.', 'wp-auto-post-helper' ) );
    }

    $result = wpaph_recount_finalize_counts( $state );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    wpaph_recount_delete_state( $session );

    $result['message'] = sprintf(
        /* translators: 1: Number of posts scanned. 2: Number of attachments reviewed. 3: Number of attachments updated. 4: Total usage references found. */
        __( 'Recount completed. Scanned %1$d posts, reviewed %2$d attachments, updated %3$d (total references: %4$d).', 'wp-auto-post-helper' ),
        $result['posts_processed'],
        $result['attachments_touched'],
        $result['attachments_updated'],
        $result['usage_total']
    );

    return array_merge(
        [
            'stage' => 'complete',
        ],
        $result
    );
}

/**
 * Generate a unique session key for recount operations.
 *
 * @return string
 */
function wpaph_recount_generate_session_key() {
    $session = wp_generate_uuid4();

    return 'wpaph_' . $session;
}

/**
 * Retrieve the transient key used to store session state.
 *
 * @param string $session Session key.
 *
 * @return string
 */
function wpaph_recount_get_state_transient_key( $session ) {
    return 'wpaph_recount_' . md5( $session );
}

/**
 * Persist the recount state for a session.
 *
 * @param string $session Session key.
 * @param array  $state   State to persist.
 *
 * @return true|WP_Error
 */
function wpaph_recount_save_state( $session, $state ) {
    $transient_key = wpaph_recount_get_state_transient_key( $session );
    $saved         = set_transient( $transient_key, $state, HOUR_IN_SECONDS );

    if ( ! $saved ) {
        return new WP_Error( 'wpaph_state_persist_failed', __( 'Unable to save recount progress.', 'wp-auto-post-helper' ) );
    }

    return true;
}

/**
 * Load the recount state for a session.
 *
 * @param string $session Session key.
 *
 * @return array|null
 */
function wpaph_recount_get_state( $session ) {
    $transient_key = wpaph_recount_get_state_transient_key( $session );

    $state = get_transient( $transient_key );

    if ( false === $state ) {
        return null;
    }

    return $state;
}

/**
 * Delete a recount session state.
 *
 * @param string $session Session key.
 */
function wpaph_recount_delete_state( $session ) {
    $transient_key = wpaph_recount_get_state_transient_key( $session );
    delete_transient( $transient_key );
}

/**
 * Process a single post and update the recount state.
 *
 * @param array $state   Current recount state.
 * @param int   $post_id Post ID to process.
 *
 * @return array|WP_Error
 */
function wpaph_recount_process_single_post( $state, $post_id ) {
    $post = get_post( $post_id );

    if ( ! $post || 'post' !== $post->post_type ) {
        return new WP_Error( 'wpaph_invalid_post', __( 'Invalid post supplied for recount.', 'wp-auto-post-helper' ) );
    }

    if ( ! isset( $state['usage_links'] ) || ! is_array( $state['usage_links'] ) ) {
        $state['usage_links'] = [];
    }

    $content      = $post->post_content;
    $links        = wpaph_collect_image_links_from_content( $content );
    $links_found  = count( $links );
    $links_saved  = 0;
    $dead_links   = 0;
    $upload_dir   = wp_get_upload_dir();

    foreach ( $links as $link ) {
        if ( wpaph_recount_is_image_link_dead( $link, $upload_dir ) ) {
            $dead_links++;
            continue;
        }

        $relative = wpaph_convert_image_url_to_relative_path( $link, $upload_dir );

        if ( ! $relative ) {
            continue;
        }

        $normalized = wpaph_normalize_relative_path_for_attachment( $relative );

        if ( ! $normalized ) {
            continue;
        }

        if ( ! isset( $state['usage_links'][ $normalized ] ) ) {
            $state['usage_links'][ $normalized ] = 0;
        }

        $state['usage_links'][ $normalized ]++;
        $state['usage_total'] = isset( $state['usage_total'] ) ? (int) $state['usage_total'] + 1 : 1;
        $links_saved++;
    }

    $state['posts_processed'] = isset( $state['posts_processed'] ) ? (int) $state['posts_processed'] + 1 : 1;
    $state['dead_total']      = isset( $state['dead_total'] ) ? (int) $state['dead_total'] + $dead_links : $dead_links;

    return [
        'state'  => $state,
        'result' => [
            'post_id'     => $post_id,
            'links_found' => $links_found,
            'links_saved' => $links_saved,
            'dead_links'  => $dead_links,
        ],
    ];
}

/**
 * Finalise attachment usage counts based on the gathered usage links.
 *
 * @param array $state Recount state.
 *
 * @return array|WP_Error
 */
function wpaph_recount_finalize_counts( $state ) {
    $usage_links = isset( $state['usage_links'] ) && is_array( $state['usage_links'] ) ? $state['usage_links'] : [];

    global $wpdb;

    $attachments_touched = 0;
    $attachments_updated = 0;
    $processed_ids       = [];

    foreach ( $usage_links as $relative => $count ) {
        $count = (int) $count;

        $attachment_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_wp_attached_file',
                $relative
            )
        );

        if ( empty( $attachment_ids ) ) {
            continue;
        }

        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;

            if ( isset( $processed_ids[ $attachment_id ] ) ) {
                continue;
            }

            $processed_ids[ $attachment_id ] = true;
            $attachments_touched++;

            $existing = get_post_meta( $attachment_id, WPAPH_USAGE_META_KEY, true );
            $existing = '' === $existing ? null : (int) $existing;

            if ( null === $existing || $existing !== $count ) {
                update_post_meta( $attachment_id, WPAPH_USAGE_META_KEY, $count );
                $attachments_updated++;
            }
        }
    }

    $remaining_query = "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} AS p LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id AND pm.meta_key = %s WHERE p.post_type = %s";

    if ( ! empty( $processed_ids ) ) {
        $remaining_query .= ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', array_keys( $processed_ids ) ) ) . ')';
    }

    $remaining_sql  = $wpdb->prepare( $remaining_query, WPAPH_USAGE_META_KEY, 'attachment' );
    $remaining_rows = $wpdb->get_results( $remaining_sql, ARRAY_A );

    foreach ( (array) $remaining_rows as $row ) {
        if ( empty( $row['ID'] ) ) {
            continue;
        }

        $attachment_id = (int) $row['ID'];

        if ( isset( $processed_ids[ $attachment_id ] ) ) {
            continue;
        }

        $processed_ids[ $attachment_id ] = true;
        $attachments_touched++;

        $existing = isset( $row['meta_value'] ) ? $row['meta_value'] : '';
        $existing = '' === $existing ? null : (int) $existing;

        if ( null === $existing || 0 !== $existing ) {
            update_post_meta( $attachment_id, WPAPH_USAGE_META_KEY, 0 );
            $attachments_updated++;
        }
    }

    return [
        'posts_processed'     => isset( $state['posts_processed'] ) ? (int) $state['posts_processed'] : 0,
        'attachments_touched' => $attachments_touched,
        'attachments_updated' => $attachments_updated,
        'usage_total'         => isset( $state['usage_total'] ) ? (int) $state['usage_total'] : 0,
        'dead_total'          => isset( $state['dead_total'] ) ? (int) $state['dead_total'] : 0,
    ];
}

/**
 * Resolve a URL into an absolute form for validation.
 *
 * @param string $url        Original URL.
 * @param array  $upload_dir Upload directory information.
 *
 * @return string
 */
function wpaph_recount_get_absolute_url( $url, $upload_dir = null ) {
    if ( ! is_string( $url ) ) {
        return '';
    }

    $url = trim( $url );

    if ( '' === $url ) {
        return '';
    }

    if ( preg_match( '#^https?://#i', $url ) ) {
        return $url;
    }

    if ( 0 === strpos( $url, '//' ) ) {
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . ':' . $url;
    }

    if ( 0 === strpos( $url, '/' ) ) {
        return home_url( $url );
    }

    if ( null === $upload_dir ) {
        $upload_dir = wp_get_upload_dir();
    }

    if ( empty( $upload_dir['baseurl'] ) ) {
        return '';
    }

    return trailingslashit( $upload_dir['baseurl'] ) . ltrim( $url, '/' );
}

/**
 * Determine if an image URL is unreachable by inspecting the response headers.
 *
 * @param string $url        Image URL.
 * @param array  $upload_dir Upload directory information.
 *
 * @return bool
 */
function wpaph_recount_is_image_link_dead( $url, $upload_dir = null ) {
    $absolute_url = wpaph_recount_get_absolute_url( $url, $upload_dir );

    if ( '' === $absolute_url ) {
        return false;
    }

    static $response_cache = [];

    if ( isset( $response_cache[ $absolute_url ] ) ) {
        return $response_cache[ $absolute_url ];
    }

    $args     = [
        'timeout'     => 5,
        'redirection' => 3,
    ];
    $response = wp_remote_head( $absolute_url, $args );

    if ( is_wp_error( $response ) ) {
        $response_cache[ $absolute_url ] = true;
        return true;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    if ( 405 === $code ) {
        $get_response = wp_remote_get( $absolute_url, $args );

        if ( is_wp_error( $get_response ) ) {
            $response_cache[ $absolute_url ] = true;
            return true;
        }

        $code = (int) wp_remote_retrieve_response_code( $get_response );
    }

    $is_dead = ( $code < 200 || $code >= 400 );

    $response_cache[ $absolute_url ] = $is_dead;

    return $is_dead;
}

/**
 * Collect image links from post content.
 *
 * @param string $content Post content.
 *
 * @return string[] List of image URLs (duplicates preserved).
 */
function wpaph_collect_image_links_from_content( $content ) {
    $links = [];

    if ( function_exists( 'parse_blocks' ) ) {
        $links = array_merge( $links, wpaph_collect_image_links_from_blocks( parse_blocks( $content ) ) );
    }

    $links = array_merge( $links, wpaph_collect_image_links_from_html( $content ) );

    return array_values(
        array_filter(
            $links,
            function ( $link ) {
                return is_string( $link ) && '' !== trim( $link );
            }
        )
    );
}

/**
 * Extract image links from parsed block structures.
 *
 * @param array $blocks Parsed blocks from post content.
 *
 * @return string[]
 */
function wpaph_collect_image_links_from_blocks( $blocks ) {
    $links = [];

    foreach ( (array) $blocks as $block ) {
        if ( empty( $block ) || ! is_array( $block ) ) {
            continue;
        }

        if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
            $links = array_merge( $links, wpaph_collect_image_links_from_block_attrs( $block['attrs'] ) );
        }

        if ( ! empty( $block['innerHTML'] ) ) {
            $links = array_merge( $links, wpaph_collect_image_links_from_html( $block['innerHTML'] ) );
        }

        if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
            foreach ( $block['innerContent'] as $inner_content ) {
                $links = array_merge( $links, wpaph_collect_image_links_from_html( $inner_content ) );
            }
        }

        if ( ! empty( $block['innerBlocks'] ) ) {
            $links = array_merge( $links, wpaph_collect_image_links_from_blocks( $block['innerBlocks'] ) );
        }
    }

    return $links;
}

/**
 * Collect image links from block attributes.
 *
 * @param array $attrs Block attributes.
 *
 * @return string[]
 */
function wpaph_collect_image_links_from_block_attrs( $attrs ) {
    $links = [];

    foreach ( $attrs as $key => $value ) {
        if ( is_array( $value ) ) {
            $links = array_merge( $links, wpaph_collect_image_links_from_block_attrs( $value ) );
            continue;
        }

        if ( in_array( $key, [ 'url', 'src', 'href' ], true ) && is_string( $value ) ) {
            $links[] = $value;
        }
    }

    return $links;
}

/**
 * Extract image links from raw HTML strings.
 *
 * @param string $html HTML content.
 *
 * @return string[]
 */
function wpaph_collect_image_links_from_html( $html ) {
    $links = [];

    if ( ! is_string( $html ) || '' === trim( $html ) ) {
        return $links;
    }

    libxml_use_internal_errors( true );

    $dom    = new DOMDocument();
    $loaded = $dom->loadHTML( '<meta http-equiv="content-type" content="text/html; charset=utf-8">' . $html );

    if ( ! $loaded ) {
        libxml_clear_errors();
        libxml_use_internal_errors( false );
        return $links;
    }

    $image_tags = [ 'img', 'source' ];

    foreach ( $image_tags as $tag_name ) {
        $nodes = $dom->getElementsByTagName( $tag_name );

        foreach ( $nodes as $node ) {
            if ( $node->hasAttribute( 'srcset' ) ) {
                $links = array_merge( $links, wpaph_extract_links_from_srcset( $node->getAttribute( 'srcset' ) ) );
            }

            if ( $node->hasAttribute( 'data-src' ) ) {
                $links[] = $node->getAttribute( 'data-src' );
            }

            if ( $node->hasAttribute( 'src' ) ) {
                $links[] = $node->getAttribute( 'src' );
            }
        }
    }

    libxml_clear_errors();
    libxml_use_internal_errors( false );

    return $links;
}

/**
 * Extract individual image URLs from a srcset string.
 *
 * @param string $srcset Srcset attribute value.
 *
 * @return string[]
 */
function wpaph_extract_links_from_srcset( $srcset ) {
    if ( ! is_string( $srcset ) || '' === trim( $srcset ) ) {
        return [];
    }

    $links      = [];
    $candidates = array_map( 'trim', explode( ',', $srcset ) );

    foreach ( $candidates as $candidate ) {
        if ( '' === $candidate ) {
            continue;
        }

        $parts = preg_split( '/\s+/', $candidate );

        if ( ! empty( $parts[0] ) ) {
            $links[] = $parts[0];
        }
    }

    return $links;
}

/**
 * Convert an image URL to a relative upload path.
 *
 * @param string $url        Image URL.
 * @param array  $upload_dir Upload directory data.
 *
 * @return string Relative path or empty string if it cannot be determined.
 */
function wpaph_convert_image_url_to_relative_path( $url, $upload_dir = null ) {
    if ( ! is_string( $url ) || '' === trim( $url ) ) {
        return '';
    }

    $url = trim( $url );

    if ( null === $upload_dir ) {
        $upload_dir = wp_get_upload_dir();
    }

    if ( empty( $upload_dir['baseurl'] ) ) {
        return '';
    }

    $url_parts  = wp_parse_url( $url );
    $baseurl    = $upload_dir['baseurl'];
    $base_parts = wp_parse_url( $baseurl );
    $base_path  = isset( $base_parts['path'] ) ? untrailingslashit( $base_parts['path'] ) : '';

    if ( false === $url_parts ) {
        return '';
    }

    if ( isset( $url_parts['host'] ) ) {
        $host      = strtolower( $url_parts['host'] );
        $base_host = isset( $base_parts['host'] ) ? strtolower( $base_parts['host'] ) : '';

        if ( $base_host && $host !== $base_host ) {
            return '';
        }
    }

    if ( empty( $url_parts['path'] ) ) {
        return '';
    }

    $path = $url_parts['path'];

    if ( $base_path && 0 === strpos( $path, $base_path . '/' ) ) {
        $path = substr( $path, strlen( $base_path . '/' ) );
    } elseif ( $base_path && $path === $base_path ) {
        $path = '';
    } elseif ( $base_path ) {
        return '';
    } else {
        $path = ltrim( $path, '/' );
    }

    if ( '' === $path ) {
        return '';
    }

    return $path;
}

/**
 * Normalize a relative upload path so it maps to the original attachment file.
 *
 * @param string $path Relative path within the uploads directory.
 *
 * @return string
 */
function wpaph_normalize_relative_path_for_attachment( $path ) {
    if ( ! is_string( $path ) || '' === trim( $path ) ) {
        return '';
    }

    $path = ltrim( $path, '/' );

    $info      = pathinfo( $path );
    $dirname   = isset( $info['dirname'] ) && '.' !== $info['dirname'] ? $info['dirname'] : '';
    $extension = isset( $info['extension'] ) ? $info['extension'] : '';
    $filename  = isset( $info['filename'] ) ? $info['filename'] : '';

    if ( '' === $filename ) {
        return $path;
    }

    $normalized_filename = wpaph_strip_size_suffix_from_filename( $filename );

    $normalized_path = $dirname ? $dirname . '/' . $normalized_filename : $normalized_filename;

    if ( $extension ) {
        $normalized_path .= '.' . $extension;
    }

    return $normalized_path;
}

/**
 * Remove generated size suffixes from attachment filenames.
 *
 * @param string $filename Filename without extension.
 *
 * @return string
 */
function wpaph_strip_size_suffix_from_filename( $filename ) {
    if ( ! is_string( $filename ) || '' === $filename ) {
        return '';
    }

    $normalized = $filename;

    do {
        $previous  = $normalized;
        $normalized = preg_replace( '/-(?:\d+x\d+|scaled(?:-\d+)?)(?=$)/i', '', $normalized );
    } while ( $previous !== $normalized );

    return $normalized;
}
