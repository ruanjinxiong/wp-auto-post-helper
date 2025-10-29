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
    $transient_key        = 'wpaph_usage_link_counts';
    $usage_links          = [];
    $usage_total          = 0;
    $posts_processed      = 0;
    $attachments_updated  = 0;
    $attachments_touched  = 0;
    $page                 = 1;
    $upload_dir           = wp_get_upload_dir();

    delete_transient( $transient_key );

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
            $links        = wpaph_collect_image_links_from_content( $post_content );

            foreach ( $links as $link ) {
                $relative = wpaph_convert_image_url_to_relative_path( $link, $upload_dir );

                if ( ! $relative ) {
                    continue;
                }

                $normalized = wpaph_normalize_relative_path_for_attachment( $relative );

                if ( ! $normalized ) {
                    continue;
                }

                if ( ! isset( $usage_links[ $normalized ] ) ) {
                    $usage_links[ $normalized ] = 0;
                }

                $usage_links[ $normalized ]++;
                $usage_total++;
            }
        }

        wp_reset_postdata();
        $page++;
    } while ( $page <= $query->max_num_pages );

    set_transient( $transient_key, $usage_links, HOUR_IN_SECONDS );

    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s",
            'attachment',
            '_wp_attached_file'
        ),
        ARRAY_A
    );

    foreach ( (array) $rows as $row ) {
        if ( ! isset( $row['ID'] ) ) {
            continue;
        }

        $attachment_id = (int) $row['ID'];
        $file          = isset( $row['meta_value'] ) && is_string( $row['meta_value'] ) ? $row['meta_value'] : '';
        $normalized    = $file ? wpaph_normalize_relative_path_for_attachment( $file ) : '';
        $count         = ( $normalized && isset( $usage_links[ $normalized ] ) ) ? (int) $usage_links[ $normalized ] : 0;

        $attachments_touched++;

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
        'transient_key'        => $transient_key,
    ];
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
