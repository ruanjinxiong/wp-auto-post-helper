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
