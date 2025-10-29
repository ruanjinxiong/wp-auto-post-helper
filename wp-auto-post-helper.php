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
        <p id="wpaph-recount-status" aria-live="polite"></p>
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
                'working'      => __( 'Recounting media usage…', 'wp-auto-post-helper' ),
                'genericError' => __( 'Something went wrong. Please try again.', 'wp-auto-post-helper' ),
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

    $result = wpaph_recount_media_usage_counts();

    if ( is_wp_error( $result ) ) {
        wp_send_json_error(
            [
                'message' => $result->get_error_message(),
            ]
        );
    }

    $result['message'] = sprintf(
        /* translators: 1: Number of posts scanned. 2: Number of attachments reviewed. 3: Number of attachments updated. 4: Total usage references found. */
        __( 'Recount completed. Scanned %1$d posts, reviewed %2$d attachments, updated %3$d (total references: %4$d).', 'wp-auto-post-helper' ),
        $result['posts_processed'],
        $result['attachments_touched'],
        $result['attachments_updated'],
        $result['usage_total']
    );

    wp_send_json_success( $result );
}
add_action( 'wp_ajax_wpaph_recount_media_usage', 'wpaph_ajax_recount_media_usage' );

/**
 * Recount how often media items are used across site content.
 *
 * @return array|WP_Error Summary data about the recount operation.
 */
function wpaph_recount_media_usage_counts() {
    $attachment_ids = get_posts(
        [
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]
    );

    $counts              = [];
    $usage_total         = 0;
    $posts_processed     = 0;
    $attachments_updated = 0;
    $attachments_touched = 0;
    $page                = 1;

    $query_args = [
        'post_type'              => 'post',
        'post_status'            => 'any',
        'posts_per_page'         => 100,
        'paged'                  => $page,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'ignore_sticky_posts'    => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ];

    do {
        $query_args['paged'] = $page;
        $query               = new WP_Query( $query_args );

        if ( ! $query->have_posts() ) {
            wp_reset_postdata();
            break;
        }

        while ( $query->have_posts() ) {
            $query->the_post();

            $posts_processed++;
            $post_content = get_post_field( 'post_content', get_the_ID() );
            $ids          = wpaph_collect_attachment_ids_from_content( $post_content );

            foreach ( $ids as $id ) {
                if ( ! $id ) {
                    continue;
                }

                if ( ! isset( $counts[ $id ] ) ) {
                    $counts[ $id ] = 0;
                }

                $counts[ $id ]++;
                $usage_total++;
            }
        }

        wp_reset_postdata();
        $page++;
    } while ( $page <= $query->max_num_pages );

    $all_attachment_ids = array_unique( array_merge( $attachment_ids, array_keys( $counts ) ) );

    foreach ( $all_attachment_ids as $attachment_id ) {
        if ( 'attachment' !== get_post_type( $attachment_id ) ) {
            continue;
        }

        $attachments_touched++;
        $count = isset( $counts[ $attachment_id ] ) ? (int) $counts[ $attachment_id ] : 0;

        $existing = get_post_meta( $attachment_id, WPAPH_USAGE_META_KEY, true );
        $existing = '' === $existing ? null : (int) $existing;

        if ( null === $existing || $existing !== $count ) {
            update_post_meta( $attachment_id, WPAPH_USAGE_META_KEY, $count );
            $attachments_updated++;
        }
    }

    return [
        'posts_processed'      => $posts_processed,
        'attachments_touched'  => $attachments_touched,
        'attachments_updated'  => $attachments_updated,
        'usage_total'          => $usage_total,
    ];
}

/**
 * Extract attachment IDs from post content.
 *
 * @param string $content Post content.
 *
 * @return int[] List of attachment IDs (duplicates preserved to reflect multiple uses).
 */
function wpaph_collect_attachment_ids_from_content( $content ) {
    $ids = [];

    if ( function_exists( 'parse_blocks' ) ) {
        $ids = array_merge( $ids, wpaph_collect_attachment_ids_from_blocks( parse_blocks( $content ) ) );
    }

    $ids = array_merge( $ids, wpaph_collect_attachment_ids_from_html( $content ) );

    return array_values(
        array_filter(
            $ids,
            function ( $id ) {
                return absint( $id ) > 0;
            }
        )
    );
}

/**
 * Extract attachment IDs from parsed block structures.
 *
 * @param array $blocks Parsed blocks from post content.
 *
 * @return int[]
 */
function wpaph_collect_attachment_ids_from_blocks( $blocks ) {
    $ids = [];

    foreach ( (array) $blocks as $block ) {
        if ( empty( $block ) || ! is_array( $block ) ) {
            continue;
        }

        if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
            $ids = array_merge( $ids, wpaph_collect_attachment_ids_from_block_attrs( $block['attrs'] ) );
        }

        if ( ! empty( $block['innerHTML'] ) ) {
            $ids = array_merge( $ids, wpaph_collect_attachment_ids_from_html( $block['innerHTML'] ) );
        }

        if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
            foreach ( $block['innerContent'] as $inner_content ) {
                $ids = array_merge( $ids, wpaph_collect_attachment_ids_from_html( $inner_content ) );
            }
        }

        if ( ! empty( $block['innerBlocks'] ) ) {
            $ids = array_merge( $ids, wpaph_collect_attachment_ids_from_blocks( $block['innerBlocks'] ) );
        }
    }

    return $ids;
}

/**
 * Collect attachment IDs from block attributes.
 *
 * @param array $attrs Block attributes.
 *
 * @return int[]
 */
function wpaph_collect_attachment_ids_from_block_attrs( $attrs ) {
    $ids = [];

    $single_keys = [ 'id', 'mediaId', 'mediaID', 'imageID', 'backgroundId', 'backgroundMediaId' ];

    foreach ( $single_keys as $key ) {
        if ( isset( $attrs[ $key ] ) ) {
            $ids[] = absint( $attrs[ $key ] );
        }
    }

    if ( isset( $attrs['ids'] ) ) {
        $ids = array_merge( $ids, wpaph_normalize_id_list( $attrs['ids'] ) );
    }

    if ( isset( $attrs['mediaGallery'] ) && is_array( $attrs['mediaGallery'] ) ) {
        foreach ( $attrs['mediaGallery'] as $item ) {
            if ( is_array( $item ) && isset( $item['id'] ) ) {
                $ids[] = absint( $item['id'] );
            }
        }
    }

    if ( isset( $attrs['attachments'] ) && is_array( $attrs['attachments'] ) ) {
        foreach ( $attrs['attachments'] as $attachment ) {
            if ( is_array( $attachment ) && isset( $attachment['id'] ) ) {
                $ids[] = absint( $attachment['id'] );
            }
        }
    }

    return $ids;
}

/**
 * Parse attachment IDs from HTML content.
 *
 * @param string $html HTML markup to inspect.
 *
 * @return int[]
 */
function wpaph_collect_attachment_ids_from_html( $html ) {
    $ids = [];

    if ( ! is_string( $html ) || '' === $html ) {
        return $ids;
    }

    if ( preg_match_all( '/wp-image-([0-9]+)/', $html, $matches ) ) {
        foreach ( $matches[1] as $match ) {
            $ids[] = absint( $match );
        }
    }

    if ( preg_match_all( '/data-id="([0-9]+)"/', $html, $matches ) ) {
        foreach ( $matches[1] as $match ) {
            $ids[] = absint( $match );
        }
    }

    if ( preg_match_all( '/id="attachment_([0-9]+)"/', $html, $matches ) ) {
        foreach ( $matches[1] as $match ) {
            $ids[] = absint( $match );
        }
    }

    if ( preg_match_all( '/\[gallery[^\]]*ids="([^\"]+)"/', $html, $matches ) ) {
        foreach ( $matches[1] as $ids_list ) {
            $ids = array_merge( $ids, wpaph_normalize_id_list( $ids_list ) );
        }
    }

    if ( preg_match_all( '/\[caption[^\]]*id="attachment_([0-9]+)"/', $html, $matches ) ) {
        foreach ( $matches[1] as $match ) {
            $ids[] = absint( $match );
        }
    }

    return $ids;
}

/**
 * Normalize an ID list from either a comma-separated string or an array.
 *
 * @param mixed $value Potential ID list.
 *
 * @return int[]
 */
function wpaph_normalize_id_list( $value ) {
    $ids = [];

    if ( is_array( $value ) ) {
        foreach ( $value as $item ) {
            $item = absint( $item );

            if ( $item ) {
                $ids[] = $item;
            }
        }

        return $ids;
    }

    $parts = preg_split( '/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

    foreach ( $parts as $part ) {
        $part = absint( $part );

        if ( $part ) {
            $ids[] = $part;
        }
    }

    return $ids;
}
