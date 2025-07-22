<?php
/**
 * Plugin Name:       Clean Unused Images
 * Description:       Browse the uploads directory, see which files are unused, and delete them safely (with a log).
 * Version:           1.0.0
 * Author:            The Mighty Mo
 * Author URI:        https://themightymo.com/
 * License:           GPL-2.0+
 * Text Domain:       clean-unused-images
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*----------------------------------------------------------------------------*
 * Admin menu
 *----------------------------------------------------------------------------*/
add_action( 'admin_menu', function () {
	add_options_page(
		'Clean Unused Images',
		'Clean Unused Images',
		'manage_options',
		'clean-unused-images',
		'cui_render_page'
	);
} );

/*----------------------------------------------------------------------------*
 * Page renderer (slightly renamed to avoid collision)
 *----------------------------------------------------------------------------*/
function cui_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		echo '<div class="wrap"><p>You do not have permission to access this page.</p></div>';
		return;
	}

	global $wpdb;

	// Get upload directory information
	$upload_dir = wp_get_upload_dir();
	$base_dir = trailingslashit( $upload_dir['basedir'] );
	$base_url = trailingslashit( $upload_dir['baseurl'] );

	// Get and sanitize filter parameters
	$folder = isset( $_GET['upload_subfolder'] ) ? sanitize_text_field( wp_unslash( $_GET['upload_subfolder'] ) ) : '';
	$search_filename = isset( $_GET['search_file'] ) ? sanitize_text_field( wp_unslash( $_GET['search_file'] ) ) : '';
	$only_unused = isset( $_GET['only_unused'] ) && $_GET['only_unused'] === '1';
	$current_page = max( 1, isset( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1 );
	$per_page = 50;

	$files = [];

	// --- 1. Collect files from disk ---
	$target_dir_for_scan = null;
	if ( $search_filename && ! $folder ) {
		$target_dir_for_scan = $base_dir;
	} elseif ( $folder && is_dir( trailingslashit( $base_dir . $folder ) ) ) {
		$target_dir_for_scan = trailingslashit( $base_dir . $folder );
	}

	if ( $target_dir_for_scan ) {
		$iterator = ( $search_filename && ! $folder )
			? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target_dir_for_scan, RecursiveDirectoryIterator::SKIP_DOTS ) )
			: new FilesystemIterator( $target_dir_for_scan, FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				continue;
			}
			if ( $search_filename && stripos( $file_info->getFilename(), $search_filename ) === false ) {
				continue;
			}

			$relative_path = str_replace( $base_dir, '', $file_info->getPathname() );
			$files[] = [ 
				'name' => $file_info->getFilename(),
				'path' => $file_info->getPathname(),
				'relative' => ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative_path ), '/' ),
				'size' => $file_info->getSize(),
			];
		}
	}

	$filtered_files = [];
	$total_files = 0;

	if ( ! empty( $files ) ) {
		// --- 2. OPTIMIZED: Batch-fetch all necessary data from the database ---
		$all_relative_paths = wp_list_pluck( $files, 'relative' );

		// Batch fetch attachment data (post_id and relative_path)
		$attachments_map = [];
		if ( ! empty( $all_relative_paths ) ) {
			$attachments_results = $wpdb->get_results(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ('" . implode( "','", array_map( 'esc_sql', $all_relative_paths ) ) . "')"
			);
			$attachments_map = wp_list_pluck( $attachments_results, 'post_id', 'meta_value' );
		}

		// Batch fetch all featured image IDs for quick lookups
		$thumbnail_ids = array_flip( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ) );

		// Batch search for file usage in post content
		$used_in_content_map = [];
		$search_terms = [];
		foreach ( $all_relative_paths as $path ) {
			$search_terms[] = "post_content LIKE '%" . $wpdb->esc_like( basename( $path ) ) . "%'";
		}
		$content_usage_query = "SELECT DISTINCT T.search_term FROM (VALUES " . implode( ',', array_fill( 0, count( $all_relative_paths ), '(%s)' ) ) . ') AS T(search_term) JOIN ' . $wpdb->posts . " ON post_content LIKE CONCAT('%%', T.search_term, '%%') WHERE post_status = 'publish'";
		$used_filenames = $wpdb->get_col( $wpdb->prepare( $content_usage_query, wp_list_pluck( $files, 'name' ) ) );

		foreach ( $used_filenames as $filename ) {
			$used_in_content_map[ $filename ] = true;
		}

		// --- 3. Process files in PHP using the pre-fetched data (very fast) ---
		usort( $files, fn( $a, $b ) => $b['size'] <=> $a['size'] );

		foreach ( $files as $file ) {
			$relative_path = $file['relative'];
			$attachment_id = $attachments_map[ $relative_path ] ?? null;
			$is_used = false;

			if ( $attachment_id && isset( $thumbnail_ids[ $attachment_id ] ) ) {
				$is_used = true;
			}

			if ( ! $is_used && isset( $used_in_content_map[ $file['name'] ] ) ) {
				$is_used = true;
			}

			if ( $only_unused && $is_used ) {
				continue;
			}

			$file['attachment_id'] = $attachment_id;
			$file['is_used'] = $is_used;
			$filtered_files[] = $file;
		}
		$total_files = count( $filtered_files );
	}

	$total_pages = ceil( $total_files / $per_page );
	$offset = ( $current_page - 1 ) * $per_page;
	$paged_files = array_slice( $filtered_files, $offset, $per_page );

	// --- 4. Render the page UI ---
	echo '<div class="wrap"><h1><span class="dashicons dashicons-open-folder" style="font-size: 30px; height: 30px; width: 30px;"></span> Clean Unused Images</h1>';

	// Filter form
	echo '<form method="get" style="margin-bottom: 20px; padding: 15px; background: #f6f7f7; border: 1px solid #ccd0d4;">';
	echo '<input type="hidden" name="page" value="clean-unused-images">';
	echo '<label for="upload_subfolder"><strong>Folder:</strong></label> ';
	echo '<input type="text" name="upload_subfolder" id="upload_subfolder" placeholder="e.g. 2025/07" value="' . esc_attr( $folder ) . '" style="margin-right:15px; width: 120px;">';
	echo '<label for="search_file"><strong>Search File Name:</strong></label> ';
	echo '<input type="text" name="search_file" id="search_file" placeholder="e.g. banner.jpg" value="' . esc_attr( $search_filename ) . '" style="margin-right:15px; width: 200px;">';
	echo '<label><input type="checkbox" name="only_unused" value="1"' . checked( $only_unused, true, false ) . '> Only show unused files</label> ';
	echo '<input type="submit" class="button button-primary" value="Search">';
	echo '</form>';

	// AJAX Deletion Feature UI
	echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #c82333; background: #fff;">';
	echo '<h2><span class="dashicons dashicons-trash" style="color: #c82333;"></span> Bulk Delete Unused & Unattached Files</h2>';
	echo '<p>This tool finds and deletes files that are <strong>NOT in the Media Library</strong> and are <strong>NOT referenced in posts or pages</strong>. The filters above will be used to determine which files to scan. This action is permanent and cannot be undone.</p>';
	echo '<button id="start-deletion-btn" class="button button-danger">Start Scan & Delete</button>';
	echo '<div id="deletion-progress" style="display:none; border: 1px solid #ccd0d4; background: #f6f7f7; padding: 15px; margin-top: 20px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 13px; line-height: 1.6;">';
	echo '<h4>Deletion Log</h4>';
	echo '<div id="progress-log"></div>';
	echo '<p id="progress-summary" style="font-weight: bold; margin-top: 10px;"></p>';
	echo '</div>';

	// Display deletion log file if it exists
	$log_file = trailingslashit( $upload_dir['basedir'] ) . 'uploads-browser-deletion-log.txt';
	if ( file_exists( $log_file ) ) {
		echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #0073aa; background: #fff;">';
		echo '<h2><span class="dashicons dashicons-list-view" style="color: #0073aa;"></span> Deletion History</h2>';
		echo '<p>Log file: <code>' . esc_html( $log_file ) . '</code></p>';

		$log_content = file_get_contents( $log_file );
		if ( $log_content ) {
			$log_lines = array_filter( explode( "\n", $log_content ) );
			$recent_entries = array_slice( $log_lines, -20 ); // Show last 20 entries

			echo '<div style="max-height: 300px; overflow-y: auto; background: #f6f7f7; padding: 10px; border: 1px solid #ccd0d4; font-family: monospace; font-size: 12px; line-height: 1.4;">';
			echo '<strong>Recent deletions (last 20):</strong><br><br>';
			foreach ( array_reverse( $recent_entries ) as $line ) {
				if ( trim( $line ) ) {
					echo esc_html( $line ) . '<br>';
				}
			}
			echo '</div>';

			echo '<p><a href="' . esc_url( trailingslashit( $upload_dir['baseurl'] ) . 'uploads-browser-deletion-log.txt' ) . '" target="_blank" class="button">Download Full Log</a> ';
			echo '<button id="clear-log-btn" class="button button-secondary">Clear Log</button></p>';
		} else {
			echo '<p>Log file is empty.</p>';
		}
		echo '</div>';
	}
	wp_nonce_field( 'uploads_browser_delete_action', 'uploads_browser_nonce' );
	echo '</div>';

	// Results table
	if ( ! empty( $paged_files ) ) {
		echo '<p>Showing ' . count( $paged_files ) . ' of ' . $total_files . ' file(s).</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>File Name</th><th>URL</th><th>Image ID</th><th>Size</th><th>Is Used?</th>';
		echo '</tr></thead><tbody>';

		foreach ( $paged_files as $file ) {
			$file_url = $base_url . $file['relative'];
			echo '<tr>';
			echo '<td>' . esc_html( $file['name'] ) . '</td>';
			echo '<td><a href="' . esc_url( $file_url ) . '" target="_blank">View</a></td>';
			echo '<td>' . ( $file['attachment_id'] ? esc_html( $file['attachment_id'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( size_format( $file['size'], 2 ) ) . '</td>';
			echo '<td>' . ( $file['is_used'] ? 'Yes' : 'No' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Pagination
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links(
				[ 
					'base' => add_query_arg( 'paged', '%#%' ),
					'format' => '',
					'total' => $total_pages,
					'current' => $current_page,
				]
			);
			echo '</div></div>';
		}
	} elseif ( $folder || $search_filename ) {
		echo '<p>No matching files found.</p>';
	} else {
		echo '<p>Please enter a folder or a search term to begin.</p>';
	}

	echo '</div>'; // close .wrap

	ob_start();
	?>
<style>
#progress-log .log-item {
  margin-bottom: 5px;
  padding: 3px 6px;
  border-radius: 3px;
}

#progress-log .status-checking {
  color: #50575e;
}

#progress-log .status-deleted {
  color: #c82333;
  background-color: #fddede;
}

#progress-log .status-skipped {
  color: #1e73be;
  background-color: #e8f3fa;
}

#progress-log .status-error {
  color: #d63638;
  font-weight: bold;
}

#progress-log .status-complete {
  color: #227122;
  font-weight: bold;
  margin-top: 10px;
  padding: 5px;
  background: #e9f6e9;
}
</style>
<script type="text/javascript">
jQuery(document).ready(function($) {
  $('#start-deletion-btn').on('click', function() {
    if (!confirm(
        'Are you sure you want to scan for and delete all unused and unattached files matching the current filters? This action cannot be undone.'
      )) {
      return;
    }

    const $btn = $(this);
    const $progressContainer = $('#deletion-progress');
    const $log = $('#progress-log');
    const $summary = $('#progress-summary');

    $btn.prop('disabled', true).text('Processing...');
    $progressContainer.show();
    $log.html('');
    $summary.text('');

    let filesToCheck = [];
    let totalFiles = 0,
      processedCount = 0,
      deletedCount = 0,
      skippedCount = 0;
    let deletedSize = 0;
    const CONCURRENCY = 5;
    let inFlight = 0;
    let deletionDone = false;

    function logMessage(message, statusClass) {
      $log.prepend('<div class="log-item ' + statusClass + '">' + message + '</div>');
    }

    function humanFileSize(bytes) {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function updateSummary() {
      $summary.text('Processed: ' + processedCount + '/' + totalFiles + ' | Deleted: ' + deletedCount +
        ' | Skipped: ' + skippedCount + ' | Deleted Size: ' + humanFileSize(deletedSize));
    }

    function processQueue() {
      if (deletionDone) return;
      while (inFlight < CONCURRENCY && filesToCheck.length > 0) {
        const fileObj = filesToCheck.shift();
        const file = typeof fileObj === 'string' ? fileObj : fileObj.relative;
        const fileSize = typeof fileObj === 'object' && fileObj.size ? fileObj.size : 0;
        inFlight++;
        updateSummary();
        $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
            action: 'uploads_browser_check_delete',
            nonce: $('#uploads_browser_nonce').val(),
            file_relative_path: file
          },
          dataType: 'json',
          success: function(response) {
            processedCount++;
            if (response.success) {
              const data = response.data;
              if (data.status === 'deleted') {
                deletedCount++;
                deletedSize += fileSize;
                logMessage('✅ DELETED: ' + data.file, 'status-deleted');
              } else {
                skippedCount++;
                logMessage('⏭️ SKIPPED: ' + data.file + ' (' + data.reason + ')', 'status-skipped');
              }
            } else {
              skippedCount++;
              logMessage('⚠️ ERROR processing ' + file + ': ' + (response.data || 'Check failed'),
                'status-error');
            }
          },
          error: function() {
            processedCount++;
            skippedCount++;
            logMessage('❌ SERVER ERROR while processing: ' + file, 'status-error');
          },
          complete: function() {
            inFlight--;
            if (filesToCheck.length === 0 && inFlight === 0 && !deletionDone) {
              deletionDone = true;
              logMessage('Scan complete!', 'status-complete');
              updateSummary();
              $btn.prop('disabled', false).text('Start Scan & Delete');
            } else {
              setTimeout(processQueue, 10);
            }
          }
        });
      }
    }

    // Step 1: Get list of all files to check
    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'uploads_browser_get_files',
        nonce: $('#uploads_browser_nonce').val(),
        upload_subfolder: $('input[name="upload_subfolder"]').val(),
        search_file: $('input[name="search_file"]').val()
      },
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          filesToCheck = response.data;
          totalFiles = filesToCheck.length;
          if (totalFiles === 0) {
            logMessage('No files found matching the criteria.', 'status-skipped');
            $btn.prop('disabled', false).text('Start Scan & Delete');
            return;
          }
          logMessage('Found ' + totalFiles + ' files. Starting scan...', 'status-checking');
          deletionDone = false;
          inFlight = 0;
          processQueue();
        } else {
          logMessage('ERROR: ' + (response.data || 'Unknown error.'), 'status-error');
          $btn.prop('disabled', false).text('Start Scan & Delete');
        }
      },
      error: function() {
        logMessage('SERVER ERROR: Could not retrieve file list.', 'status-error');
        $btn.prop('disabled', false).text('Start Scan & Delete');
      }
    });
  });

  // Clear log button functionality
  $('#clear-log-btn').on('click', function() {
    if (!confirm('Are you sure you want to clear the deletion log? This action cannot be undone.')) {
      return;
    }

    const $btn = $(this);
    $btn.prop('disabled', true).text('Clearing...');

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'uploads_browser_clear_log',
        nonce: $('#uploads_browser_nonce').val()
      },
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          location.reload();
        } else {
          alert('Error clearing log: ' + (response.data || 'Unknown error'));
          $btn.prop('disabled', false).text('Clear Log');
        }
      },
      error: function() {
        alert('Server error while clearing log');
        $btn.prop('disabled', false).text('Clear Log');
      }
    });
  });
});
</script>

<?php
	$output = ob_get_clean();
	echo $output;
}

/*----------------------------------------------------------------------------*
 * AJAX handlers (copied verbatim from your snippet, only namespaced)
 *----------------------------------------------------------------------------*/
add_action( 'wp_ajax_uploads_browser_get_files', 'cui_ajax_get_files' );
add_action( 'wp_ajax_uploads_browser_check_delete', 'cui_ajax_check_delete' );
add_action( 'wp_ajax_uploads_browser_clear_log', 'cui_ajax_clear_log' );

function cui_ajax_get_files() {
	check_ajax_referer( 'uploads_browser_delete_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$upload_dir = wp_get_upload_dir();
	$base_dir = trailingslashit( $upload_dir['basedir'] );
	$folder = isset( $_POST['upload_subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_subfolder'] ) ) : '';
	$search_filename = isset( $_POST['search_file'] ) ? sanitize_text_field( wp_unslash( $_POST['search_file'] ) ) : '';
	$relative_files = [];

	if ( $folder && is_dir( trailingslashit( $base_dir . $folder ) ) ) {
		$target_dir_for_scan = trailingslashit( $base_dir . $folder );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $target_dir_for_scan, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				continue;
			}
			if ( $search_filename && stripos( $file_info->getFilename(), $search_filename ) === false ) {
				continue;
			}
			$relative_path = str_replace( $base_dir, '', $file_info->getPathname() );
			$relative_files[] = [ 
				'relative' => ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative_path ), '/' ),
				'size' => $file_info->getSize(),
			];
		}
	} elseif ( ! $folder ) {
		$subdirs = [];
		foreach ( new FilesystemIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) as $sub ) {
			if ( $sub->isDir() ) {
				$subdirs[] = $sub->getPathname();
			}
		}
		sort( $subdirs, SORT_STRING );
		foreach ( $subdirs as $subdir ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $subdir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file_info ) {
				if ( $file_info->isDir() ) {
					continue;
				}
				if ( $search_filename && stripos( $file_info->getFilename(), $search_filename ) === false ) {
					continue;
				}
				$relative_path = str_replace( $base_dir, '', $file_info->getPathname() );
				$relative_files[] = [ 
					'relative' => ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative_path ), '/' ),
					'size' => $file_info->getSize(),
				];
			}
		}
	} else {
		if ( strpos( $folder, '/' ) !== false ) {
			$target_dir = trailingslashit( $base_dir . $folder );
			if ( is_dir( $target_dir ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file_info ) {
					if ( $file_info->isDir() ) {
						continue;
					}
					if ( $search_filename && stripos( $file_info->getFilename(), $search_filename ) === false ) {
						continue;
					}
					$relative_path = str_replace( $base_dir, '', $file_info->getPathname() );
					$relative_files[] = [ 
						'relative' => ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative_path ), '/' ),
						'size' => $file_info->getSize(),
					];
				}
			}
		} else {
			foreach ( new FilesystemIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) as $sub ) {
				if ( $sub->isDir() && stripos( $sub->getFilename(), $folder ) !== false ) {
					$subdir = $sub->getPathname();
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $subdir, RecursiveDirectoryIterator::SKIP_DOTS )
					);
					foreach ( $iterator as $file_info ) {
						if ( $file_info->isDir() ) {
							continue;
						}
						if ( $search_filename && stripos( $file_info->getFilename(), $search_filename ) === false ) {
							continue;
						}
						$relative_path = str_replace( $base_dir, '', $file_info->getPathname() );
						$relative_files[] = [ 
							'relative' => ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative_path ), '/' ),
							'size' => $file_info->getSize(),
						];
					}
				}
			}
		}
	}

	wp_send_json_success( $relative_files );
}

function cui_ajax_check_delete() {
	check_ajax_referer( 'uploads_browser_delete_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	if ( ! isset( $_POST['file_relative_path'] ) ) {
		wp_send_json_error( 'No file path provided.' );
	}

	$relative_path = sanitize_text_field( wp_unslash( $_POST['file_relative_path'] ) );

	global $wpdb;
	$upload_dir = wp_get_upload_dir();
	$full_path = trailingslashit( $upload_dir['basedir'] ) . $relative_path;
	$file_url = trailingslashit( $upload_dir['baseurl'] ) . $relative_path;

	if ( ! file_exists( $full_path ) ) {
		wp_send_json_success( [ 'status' => 'skipped', 'reason' => 'file not found', 'file' => esc_html( $relative_path ) ] );
		return;
	}

	// Check 1: Is it in the Media Library?
	if ( attachment_url_to_postid( $file_url ) ) {
		wp_send_json_success( [ 'status' => 'skipped', 'reason' => 'in Media Library', 'file' => esc_html( $relative_path ) ] );
		return;
	}

	// Check 2: Is it a thumbnail of a media library item?
	if ( preg_match( '/(-\d+x\d+)\.(jpg|jpeg|png|gif|webp)$/i', $relative_path, $matches ) ) {
		$original_relative_path = str_replace( $matches[1], '', $relative_path );
		if ( attachment_url_to_postid( trailingslashit( $upload_dir['baseurl'] ) . $original_relative_path ) ) {
			wp_send_json_success( [ 'status' => 'skipped', 'reason' => 'is a thumbnail', 'file' => esc_html( $relative_path ) ] );
			return;
		}
	}

	// Check 3: Is the filename used in post content or meta? (Optimized single query)
	$search_term = '%' . $wpdb->esc_like( basename( $file_url ) ) . '%';
	$usage_query = $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s LIMIT 1", $search_term );
	$used_in_posts = $wpdb->get_var( $usage_query );

	if ( $used_in_posts ) {
		wp_send_json_success( [ 'status' => 'skipped', 'reason' => 'URL found in content', 'file' => esc_html( $relative_path ) ] );
		return;
	}

	// All checks passed, OK to delete
	$file_size = filesize( $full_path ) ?: 0;
	if ( unlink( $full_path ) ) {
		// Log the deletion
		$log_entry = sprintf(
			"[%s] DELETED: %s | Size: %s | Path: %s\n",
			current_time( 'Y-m-d H:i:s' ),
			basename( $relative_path ),
			size_format( $file_size, 2 ),
			$relative_path
		);

		$log_file = trailingslashit( $upload_dir['basedir'] ) . 'uploads-browser-deletion-log.txt';
		file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );

		wp_send_json_success( [ 'status' => 'deleted', 'file' => esc_html( $relative_path ) ] );
	} else {
		wp_send_json_error( 'Could not delete file (check permissions).' );
	}
}

function cui_ajax_clear_log() {
	check_ajax_referer( 'uploads_browser_delete_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$upload_dir = wp_get_upload_dir();
	$log_file = trailingslashit( $upload_dir['basedir'] ) . 'uploads-browser-deletion-log.txt';

	if ( file_exists( $log_file ) ) {
		if ( unlink( $log_file ) ) {
			wp_send_json_success( 'Log file cleared successfully.' );
		} else {
			wp_send_json_error( 'Could not delete log file (check permissions).' );
		}
	} else {
		wp_send_json_success( 'Log file does not exist.' );
	}
}