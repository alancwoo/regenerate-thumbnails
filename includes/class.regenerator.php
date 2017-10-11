<?php

/**
 * Regenerates the thumbnails for a given attachment.
 *
 * @since 3.0.0
 */
class RegenerateThumbnails_Regenerator {

	/**
	 * The WP_Post object for the attachment that is being operated on.
	 *
	 * @since 3.0.0
	 *
	 * @var WP_Post
	 */
	public $attachment;

	/**
	 * The full path to the original image so that it can be passed between methods.
	 *
	 * @since 3.0.0
	 *
	 * @var string
	 */
	public $fullsizepath;

	/**
	 * An array of thumbnail size(s) that were skipped during regeneration due to already existing.
	 * A class variable is used so that the data can later be used to merge the size(s) back in.
	 *
	 * @since 3.0.0
	 *
	 * @var array
	 */
	public $skipped_thumbnails = array();

	/**
	 * Generates an instance of this class after doing some setup.
	 *
	 * MIME type is purposefully not validated in order to be more future proof and
	 * to avoid duplicating a ton of logic that already exists in WordPress core.
	 *
	 * @since 3.0.0
	 *
	 * @param int $attachment_id Attachment ID to process.
	 *
	 * @return RegenerateThumbnails_Regenerator|WP_Error A new instance of RegenerateThumbnails_Regenerator on success, or WP_Error on error.
	 */
	public static function get_instance( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_attachment_doesnt_exist',
				__( 'No attachment exists with that ID.', 'regenerate-thumbnails' ),
				array(
					'status' => 404,
				)
			);
		}

		// We can only regenerate thumbnails for attachments
		if ( 'attachment' !== get_post_type( $attachment ) ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_not_attachment',
				__( 'This item is not an attachment.', 'regenerate-thumbnails' ),
				array(
					'status' => 400,
				)
			);
		}

		// Don't touch any attachments that are being used as a site icon. Their thumbnails are usually custom cropped.
		if ( self::is_site_icon( $attachment ) ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_is_site_icon',
				__( "This attachment is a site icon and therefore the thumbnails shouldn't be touched.", 'regenerate-thumbnails' ),
				array(
					'status'     => 415,
					'attachment' => $attachment,
				)
			);
		}

		return new RegenerateThumbnails_Regenerator( $attachment );
	}

	/**
	 * The constructor for this class. Don't call this directly, see get_instance() instead.
	 * This is done so that WP_Error objects can be returned during class initiation.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_Post $attachment The WP_Post object for the attachment that is being operated on.
	 */
	private function __construct( WP_Post $attachment ) {
		$this->attachment = $attachment;
	}

	/**
	 * Returns whether the attachment is or was a site icon.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_Post $attachment The WP_Post object for the attachment that is being operated on.
	 *
	 * @return bool Whether the attachment is or was a site icon.
	 */
	public static function is_site_icon( WP_Post $attachment ) {
		return ( 'site-icon' === get_post_meta( $attachment->ID, '_wp_attachment_context', true ) );
	}

	/**
	 * Helper function to set the fullsizepath class variable.
	 *
	 * Can't be called in the get_instance() because it's static or __construct()
	 * because the result is needed.
	 *
	 * @since 3.0.0
	 *
	 * @return true|WP_Error True if set, or WP_Error if the fullsize image is missing.
	 */
	public function set_fullsizepath() {
		if ( $this->fullsizepath ) {
			return true;
		}

		$this->fullsizepath = get_attached_file( $this->attachment->ID );

		if ( false === $this->fullsizepath || ! file_exists( $this->fullsizepath ) ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_file_not_found',
				sprintf(
					__( "The fullsize image file cannot be found in your uploads directory at <code>%s</code>. Without it, new thumbnail images can't be generated.", 'regenerate-thumbnails' ),
					_wp_relative_upload_path( $this->fullsizepath )
				),
				array(
					'status'       => 404,
					'fullsizepath' => _wp_relative_upload_path( $this->fullsizepath ),
					'attachment'   => $this->attachment,
				)
			);
		}

		return true;
	}

	/**
	 * Regenerate the thumbnails for this instance's attachment.
	 *
	 * @todo  Additional parameters such as deleting old thumbnails or only regenerating certain sizes.
	 *
	 * @since 3.0.0
	 *
	 * @param array|string $args {
	 *     Optional. Array or string of arguments for thumbnail regeneration.
	 *
	 *     @type bool $only_regenerate_missing_thumbnails  Skip regenerating existing thumbnail files. Default true.
	 *     @type bool $delete_unregistered_thumbnail_files Delete any thumbnail sizes that are no longer registered. Default false.
	 * }
	 *
	 * @return mixed|WP_Error Metadata for attachment (see wp_generate_attachment_metadata()), or WP_Error on error.
	 */
	public function regenerate( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'only_regenerate_missing_thumbnails'  => true,
			'delete_unregistered_thumbnail_files' => false,
		) );

		$set_fullsizepath = $this->set_fullsizepath();
		if ( is_wp_error( $set_fullsizepath ) ) {
			return $set_fullsizepath;
		}

		$old_metadata = wp_get_attachment_metadata( $this->attachment->ID );

		if ( $args['only_regenerate_missing_thumbnails'] ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_image_sizes_to_only_missing_thumbnails' ), 10, 2 );
		}

		require_once( ABSPATH . 'wp-admin/includes/admin.php' );
		$new_metadata = wp_generate_attachment_metadata( $this->attachment->ID, $this->fullsizepath );

		if ( $args['only_regenerate_missing_thumbnails'] ) {
			// Thumbnail sizes that existed were removed and need to be added back to the metadata.
			foreach ( $this->skipped_thumbnails as $skipped_thumbnail ) {
				if ( ! empty( $old_metadata['sizes'][ $skipped_thumbnail ] ) ) {
					$new_metadata['sizes'][ $skipped_thumbnail ] = $old_metadata['sizes'][ $skipped_thumbnail ];
				}
			}
			$this->skipped_thumbnails = array();

			remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_image_sizes_to_only_missing_thumbnails' ), 10 );
		}

		$wp_upload_dir = dirname( $this->fullsizepath ) . DIRECTORY_SEPARATOR;

		// If the user wants to keep their storage usage down, we can iterate over the list of
		// thumbnails in the old metadata and delete any that are no longer registered as valid sizes.
		if ( $args['delete_unregistered_thumbnail_files'] ) {
			$intermediate_image_sizes = get_intermediate_image_sizes();
			foreach ( $old_metadata['sizes'] as $old_size => $old_size_data ) {
				if ( in_array( $old_size, $intermediate_image_sizes ) ) {
					continue;
				}

				wp_delete_file( $wp_upload_dir . $old_size_data['file'] );

				unset( $new_metadata['sizes'][ $old_size ] );
			}
		} else {
			// If not deleting, rename any size conflicts to avoid them being lost if the file still exists.
			foreach ( $old_metadata['sizes'] as $old_size => $old_size_data ) {
				if ( empty( $new_metadata['sizes'][ $old_size ] ) ) {
					$new_metadata['sizes'][ $old_size ] = $old_metadata['sizes'][ $old_size ];
					continue;
				}

				$new_size_data = $new_metadata['sizes'][ $old_size ];

				if (
					$new_size_data['width'] !== $old_size_data['width']
					&& $new_size_data['height'] !== $old_size_data['height']
					&& file_exists( $wp_upload_dir . $old_size_data['file'] )
				) {
					$new_metadata['sizes'][ $old_size . '_old_' . $old_size_data['width'] . 'x' . $old_size_data['height'] ] = $old_size_data;
				}
			}
		}

		wp_update_attachment_metadata( $this->attachment->ID, $new_metadata );

		return $new_metadata;
	}

	/**
	 * Filters the list of thumbnail sizes to only include those which have missing files.
	 *
	 * @since 3.0.0
	 *
	 * @param array $sizes             An associative array of registered thumbnail image sizes.
	 * @param array $fullsize_metadata An associative array of fullsize image metadata: width, height, file.
	 *
	 * @return array An associative array of image sizes.
	 */
	public function filter_image_sizes_to_only_missing_thumbnails( $sizes, $fullsize_metadata ) {
		if ( ! $sizes ) {
			return $sizes;
		}

		$set_fullsizepath = $this->set_fullsizepath();
		if ( is_wp_error( $set_fullsizepath ) ) {
			return $sizes;
		}

		$editor = wp_get_image_editor( $this->fullsizepath );
		if ( is_wp_error( $editor ) ) {
			return $sizes;
		}

		$metadata = wp_get_attachment_metadata( $this->attachment->ID );

		// This is based on WP_Image_Editor_GD::multi_resize() and others
		foreach ( $sizes as $size => $size_data ) {
			if ( empty( $metadata['sizes'][ $size ] ) ) {
				continue;
			}

			if ( ! isset( $size_data['width'] ) && ! isset( $size_data['height'] ) ) {
				continue;
			}

			if ( ! isset( $size_data['width'] ) ) {
				$size_data['width'] = null;
			}
			if ( ! isset( $size_data['height'] ) ) {
				$size_data['height'] = null;
			}

			if ( ! isset( $size_data['crop'] ) ) {
				$size_data['crop'] = false;
			}

			$thumbnail = $this->get_thumbnail(
				$editor,
				$fullsize_metadata['width'],
				$fullsize_metadata['height'],
				$size_data['width'],
				$size_data['height'],
				$size_data['crop']
			);

			// The false check filters out thumbnails that would be larger than the fullsize image.
			// The size comparison makes sure that the size is also correct.
			if (
				false === $thumbnail
				|| (
					$thumbnail['width'] === $metadata['sizes'][ $size ]['width']
					&& $thumbnail['height'] === $metadata['sizes'][ $size ]['height']
					&& file_exists( $thumbnail['filename'] )
				)
			) {
				$this->skipped_thumbnails[] = $size;
				unset( $sizes[ $size ] );
			}
		}

		return $sizes;
	}

	/**
	 * Generate the thumbnail filename and dimensions for a given set of constraint dimensions.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_Image_Editor|WP_Error $editor           An instance of WP_Image_Editor, as returned by wp_get_image_editor().
	 * @param int                      $fullsize_width   The width of the fullsize image.
	 * @param int                      $fullsize_height  The height of the fullsize image.
	 * @param int                      $thumbnail_width  The width of the thumbnail.
	 * @param int                      $thumbnail_height The height of the thumbnail.
	 * @param bool                     $crop             Whether to crop or not.
	 *
	 * @return array|false An array of the filename, thumbnail width, and thumbnail height,
	 *                     or false on failure to resize such as the thumbnail being larger than the fullsize image.
	 */
	public function get_thumbnail( $editor, $fullsize_width, $fullsize_height, $thumbnail_width, $thumbnail_height, $crop ) {
		$dims = image_resize_dimensions( $fullsize_width, $fullsize_height, $thumbnail_width, $thumbnail_height, $crop );

		if ( ! $dims ) {
			return false;
		}

		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		$suffix   = "{$dst_w}x{$dst_h}";
		$file_ext = strtolower( pathinfo( $this->fullsizepath, PATHINFO_EXTENSION ) );

		return array(
			'filename' => $editor->generate_filename( $suffix, null, $file_ext ),
			'width'    => $dst_w,
			'height'   => $dst_h,
		);
	}

	/**
	 * Update the post content of any public post types (posts and pages by default)
	 * that make use of this attachment.
	 *
	 * @since 3.0.0
	 *
	 * @param array|string $args {
	 *     Optional. Array or string of arguments for controlling the updating.
	 *
	 *     @type array $post_type      The post types to update. Defaults to public post types (posts and pages by default).
	 *     @type array $post_ids       Specific post IDs to update as opposed to any that uses the attachment.
	 *     @type int   $posts_per_loop How many posts to query at a time to keep memory usage down. You shouldn't need to modify this.
	 * }
	 *
	 * @return array List of post IDs that were modified. The key is the post ID and the value is either the post ID again or a WP_Error object if wp_update_post() failed.
	 */
	public function update_usages_in_posts( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'post_type'      => array(),
			'post_ids'       => array(),
			'posts_per_loop' => 10,
		) );

		if ( empty( $args['post_type'] ) ) {
			$args['post_type'] = array_values( get_post_types( array( 'public' => true ) ) );
			unset( $args['post_type']['attachment'] );
		}

		$offset        = 0;
		$posts_updated = array();

		while ( true ) {
			$posts = get_posts( array(
				'numberposts'            => $args['posts_per_loop'],
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'include'                => $args['post_ids'],
				'post_type'              => $args['post_type'],
				's'                      => 'wp-image-' . $this->attachment->ID,

				// For faster queries
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( ! $posts ) {
				break;
			}

			$offset += $args['posts_per_loop'];

			foreach ( $posts as $post ) {
				$content = $post->post_content;
				$search  = $replace = array();

				// Find all <img> tags for this attachment and update them
				preg_match_all(
					'#<img [^>]+wp-image-' . $this->attachment->ID . '[^>]+/>#i',
					$content,
					$matches,
					PREG_SET_ORDER
				);
				if ( $matches ) {
					foreach ( $matches as $img_tag ) {
						preg_match( '# class="([^"]+)?size-([^" ]+)#i', $img_tag[0], $thumbnail_size );

						if ( $thumbnail_size ) {
							$thumbnail = image_downsize( $this->attachment->ID, $thumbnail_size[2] );

							if ( ! $thumbnail ) {
								continue;
							}

							$search[] = $img_tag[0];

							$img_tag[0] = preg_replace( '# src="[^"]+"#i', ' src="' . esc_url( $thumbnail[0] ) . '"', $img_tag[0] );
							$img_tag[0] = preg_replace(
								'# width="[^"]+" height="[^"]+"#i',
								' width="' . esc_attr( $thumbnail[1] ) . '" height="' . esc_attr( $thumbnail[2] ) . '"',
								$img_tag[0]
							);

							$replace[] = $img_tag[0];
						}
					}
				}
				$content = str_replace( $search, $replace, $content );
				$search  = $replace = array();

				// Update the width in any [caption] shortcodes
				preg_match_all(
					'#\[caption id="attachment_' . $this->attachment->ID . '"([^\]]+)? width="[^"]+"\]([^\[]+)size-([^" ]+)([^\[]+)\[\/caption\]#i',
					$content,
					$matches,
					PREG_SET_ORDER
				);
				if ( $matches ) {
					foreach ( $matches as $match ) {
						$thumbnail = image_downsize( $this->attachment->ID, $match[3] );

						if ( ! $thumbnail ) {
							continue;
						}

						$search[]  = $match[0];
						$replace[] = '[caption id="attachment_' . $this->attachment->ID . '"' . $match[1] . ' width="' . esc_attr( $thumbnail[1] ) . '"]' . $match[2] . 'size-' . $match[3] . $match[4] . '[/caption]';
					}
				}
				$content = str_replace( $search, $replace, $content );

				$updated_post_object = (object) array(
					'ID'           => $post->ID,
					'post_content' => $content,
				);

				$posts_updated[ $post->ID ] = wp_update_post( $updated_post_object, true );
			}
		}

		return $posts_updated;
	}

	/**
	 * Returns information about the current attachment for use in the REST API.
	 *
	 * @since 3.0.0
	 *
	 * @return array The attachment name, fullsize URL, registered thumbnail size status, and any unregistered sizes.
	 *               On error, the key "error" will be set with the error message as the value.
	 */
	public function get_attachment_info() {
		$set_fullsizepath = $this->set_fullsizepath();
		if ( is_wp_error( $set_fullsizepath ) ) {
			return array(
				'error' => $set_fullsizepath->get_error_message(),
			);
		}

		$editor = wp_get_image_editor( $this->fullsizepath );
		if ( is_wp_error( $editor ) ) {
			return array(
				'error' => $editor->get_error_message(),
			);
		}

		$metadata = wp_get_attachment_metadata( $this->attachment->ID );

		$response = array(
			'name'               => $this->attachment->post_title,
			'fullsizeurl'        => wp_get_attachment_url( $this->attachment->ID ), // We can only guarantee that the fullsize image file exists
			'relative_path'      => _wp_get_attachment_relative_path( $this->fullsizepath ) . DIRECTORY_SEPARATOR . basename( $this->fullsizepath ),
			'width'              => $metadata['width'],
			'height'             => $metadata['height'],
			'registered_sizes'   => array(),
			'unregistered_sizes' => array(),
		);

		// Check the status of all currently registered sizes
		$registered_sizes = RegenerateThumbnails()->get_thumbnail_sizes();
		foreach ( $registered_sizes as $size ) {
			$thumbnail = $this->get_thumbnail( $editor, $metadata['width'], $metadata['height'], $size['width'], $size['height'], $size['crop'] );

			if ( $thumbnail ) {
				$size['filename']   = basename( $thumbnail['filename'] );
				$size['fileexists'] = file_exists( $thumbnail['filename'] );
			} else {
				$size['filename']   = false;
				$size['fileexists'] = false;
			}

			$response['registered_sizes'][] = $size;
		}

		$wp_upload_dir = dirname( $this->fullsizepath ) . DIRECTORY_SEPARATOR;

		// Look at the attachment metadata and see if we have any extra files from sizes that are no longer registered
		foreach ( $metadata['sizes'] as $label => $size ) {
			if ( ! file_exists( $wp_upload_dir . $size['file'] ) ) {
				continue;
			}

			// An unregistered size could match a registered size's dimensions. Ignore these.
			// @todo: check to see if we'd be wrongly deleting these in the regeneration method
			foreach ( $response['registered_sizes'] as $registered_size ) {
				if ( $size['file'] === $registered_size['filename'] ) {
					continue 2;
				}
			}

			if ( ! empty( $registered_sizes[ $label ] ) ) {
				/* translators: Used for listing old sizes of currently registered thumbnails */
				$label = sprintf( __( '%s (old)', 'regenerate-thumbnails' ), $label );
			}

			$response['unregistered_sizes'][] = array(
				'label'      => $label,
				'width'      => $size['width'],
				'height'     => $size['height'],
				'filename'   => $size['file'],
				'fileexists' => true,
			);
		}

		return $response;
	}
}
