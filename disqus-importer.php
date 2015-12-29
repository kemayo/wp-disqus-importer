<?php
/*
Plugin Name: Disqus Comments Importer
Plugin URI: http://wordpress.org/extend/plugins/disqus-comments-importer/
Description: Import comments from a Disqus export file.
Author: Automattic
Author URI: http://automattic.com
Version: 0.1
Stable tag: 0.1
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Notes:
Disqus doesn't yet provide any way to relate comments to each other in comment threads.

*/

if ( !defined( 'WP_LOAD_IMPORTERS' ) )
	return;

/** 
 * Load the WordPress Import API
 */
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * Disqus Importer
 *
 */
if ( class_exists( 'WP_Importer' ) ) {
	class Disqus_Import extends WP_Importer {
	
		var $post_ids_processed = array ();
		var $inserted_comments = array ();
		var $found_comment_count;
		var $orphan_comments = array();
	
		var $num_comments = 0;
		var $num_duplicates = 0;
		var $num_uncertain = 0;
	
		var $file;
		var $id;
	
		// Prints the header for the admin gui
		function header() {
			echo '<div class="wrap">';
			screen_icon();
			echo '<h2>' . __( 'Import Disqus Comments', 'disqus-importer' ) . '</h2>';
		}
		
		// Prints the footer for the admin gui	
		function footer() {
			echo '</div>';
		}
	
		// Welcome page
		function greet() {
			echo '<div class="narrow">';
			echo '<p>' . __( 'Howdy! Upload your Disqus export file and we&#8217;ll import the comments.', 'disqus-importer' ) . '</p>';
			wp_import_upload_form( "admin.php?import=disqus&amp;step=1" );
			echo '</div>';
		}
	
		// Parses a line from XML file for a specific tag and returns value
		function get_tag( $string, $tag ) {
			global $wpdb;
			preg_match( "|<$tag.*?>(.*?)</$tag>|is", $string, $return );
			if ( isset( $return[1] ) ) {
				$return = preg_replace( '|^<!\[CDATA\[(.*)\]\]>$|s', '$1', $return[1] );
				$return = $wpdb->escape( trim( $return ) );
			} else {
				$return = '';
			}
			return $return;
		}
	
		function get_author_name( $url ) {
	
			if ( $name = wp_cache_get( $url, 'disqus_url2name' ) )
				return $name;
	
			$options = array();
			$options['redirection'] = 5;
			$options['method'] = 'GET';
	
			$response = wp_remote_request( $url, $options );
	
			if ( is_wp_error( $response ) )
				return false;
	
			// this feels sloppy, but short of more fully parsing the html, it seems to be the simplest solution
			preg_match( '|<([a-zA-Z0-9]*) [^>]*class=[^>]*fn[^>]*>(.*?)</\1|s' , $response['body'] , $matches );
	
			$name = wp_filter_nohtml_kses( $matches[2] );
			wp_cache_set( $url , $name , 'disqus_url2name' );
			return $name;
		}
	
		// Is gzip installed
		function has_gzip() {
			return is_callable( 'gzopen' );
		}
	
		// Wrapper to determine method to read file
		function fopen( $filename, $mode='r' ) {
			if ( $this->has_gzip() )
				return gzopen( $filename, $mode );
			return fopen( $filename, $mode );
		}
	
		// Wrapper to determine method to check for end of file file
		function feof( $fp ) {
			if ( $this->has_gzip() )
				return gzeof( $fp );
			return feof( $fp );
		}
	
		// Wrapper to determine method to read line in file
		function fgets( $fp, $len=8192 ) {
			if ( $this->has_gzip() )
				return gzgets( $fp, $len );
			return fgets( $fp, $len );
		}
	
		// Wrapper to determine method to close file
		function fclose( $fp ) {
			if ( $this->has_gzip() )
				return gzclose( $fp );
			return fclose( $fp );
		}
	
		// Parses file for all entries. Depending on arg, we count or process comments
		function get_entries( $process_comment_func = NULL ) {
			set_magic_quotes_runtime( 0 );
	
			$doing_entry = false;
			$is_disqus_file = false;
	
			// Read uploaded file
			$fp = $this->fopen( $this->file, 'r' );
			
			if ( $fp ) {
			
				// Loop through file, one line at a time
				while ( !$this->feof( $fp ) ) {
					
					// Remove whitespace.
					$importline = rtrim( $this->fgets( $fp ) );
					
					// this doesn't check that the file is perfectly valid but will at least confirm that it's not the wrong format altogether
					if ( !$is_disqus_file && strpos( $importline , '<comments>' ) )
						$is_disqus_file = true;
	
					// Identify opening of new post block
					if ( false !== strpos( $importline, '<article>' ) ) {
						if ( count( $this->orphan_comments ) )
							$this->process_orphan_comments();
						$this->post_url = '';
						continue;
					}
					
					// Identify post url
					if ( empty( $this->post_url ) && preg_match( '|<url>([^<]*)</url>|is' , $importline , $matches ) ) {
						$this->post_url = $matches[1];
					}
					
					// Identify start of new comment block
					if ( false !== strpos( $importline, '<comment>' ) ) {
						$this->post = '';
						$doing_entry = true;
						continue;
					}
					
					// Identify end of open comment block
					if ( false !== strpos( $importline, '</comment>' ) ) {
						$doing_entry = false;
						if ( $process_comment_func )
							call_user_func( $process_comment_func, $this->post );
						continue;
					}
					
					// Append to comment data if inside comment block
					if ( $doing_entry ) {
						$this->post .= $importline . "\n";
					}
				}
	
				$this->fclose( $fp );
			}
	
			return $is_disqus_file;
	
		}
	
		// If file uploaded appears to be discuss import, provide options page. Otherwise, provide error.
		function check_upload() {
			$is_disqus_file = $this->get_entries( array( &$this, 'count_entries' ));
	
			if ( $is_disqus_file ) {
				$this->options();
			} else {
				echo '<h2>' . __( 'Invalid file', 'disqus-importer' ) . '</h2>';
				echo '<p>' . __( 'Please upload a valid Disqus export file.', 'disqus-importer' ) . '</p>';
			}
		}
	
		// Display Options page prior to actual import, but after parsing file once.
		function options() {
			?>
			<h2><?php _e( 'Import Options', 'disqus-importer' ); ?></h2>
			<p><?php printf( _n( 'It looks like there&#8217;s %s comment in the file.', 'It looks like there are %s comments in the file.', $this->found_comment_count, 'disqus-importer' ), $this->found_comment_count ); ?></p>
			<p><?php _e( 'Click Next to import all of them.', 'disqus-importer' ); ?></p>
	
			<form action="?import=disqus&amp;step=2&amp;id=<?php echo $this->id; ?>" method="post">
			<?php wp_nonce_field( 'import-disqus' ); ?>
				<p class="submit">
					<input type="submit" class="button" value="<?php echo esc_attr__( 'Next', 'disqus-importer' ); ?>" /><br />
				</p>
			</form>
			<?php
		}
	
		// Increment count
		function count_entries( $comment ) {
			$entry = $this->get_tag( $comment, 'date' );
	
			if ( $entry )
				$this->found_comment_count++;
		}
	
		// Process comments (import if valid) and report back to user
		function process_comments() {
			
			echo '<ol>';
	
			// Parse the file: and act on comments as directed
			$this->get_entries( array( &$this, 'process_comment' ) );
			$this->process_orphan_comments(); // call it once to capture replies on the last post
			$this->process_orphan_comments( TRUE ); // call it again to force import any remaining unmatched orphans
	
			echo '</ol>';
	
			wp_import_cleanup( $this->id );
			do_action( 'import_done', 'disqus-importer' );
	
			if ( $this->num_comments )
				echo '<h3>' . sprintf( _n( 'Imported %s comment.', 'Imported %s comments.', $this->num_comments, 'disqus-importer' ) , $this->num_comments ) .'</h3>';
	
			if ( $this->num_duplicates )
				echo '<h3>' . sprintf( _n( 'Skipped %s duplicate.', 'Skipped %s duplicates.', $this->num_duplicates, 'disqus-importer' ) , $this->num_duplicates ) .'</h3>';
	
			if ( $this->num_uncertain )
				echo '<h3>' . sprintf( _n( 'Could not determine the correct item to attach %s comment to.', 'Could not determine the correct item to attach %s comments to.', $this->num_uncertain, 'disqus-importer' ) , $this->num_uncertain ) .'</h3>';
	
			echo '<h3>' . sprintf( __( 'All done.', 'disqus-importer' ) . ' <a href="%s">' . __( 'Have fun!', 'disqus-importer' ).'</a>', get_option( 'home' ) ).'</h3>';
	
		}
	
		// Imports a comment or echos error
		function process_comment( $comment ) {
	
			set_time_limit( 60 );
	
			$new_comment['comment_post_ID']			= ( trailingslashit( $this->post_url ) != trailingslashit( get_option( 'siteurl' ) ) ) ? (int) url_to_postid( $this->post_url ) : (int) get_option( 'page_on_front' );
			if( ! $new_comment['comment_post_ID'] ) { // Couldn't determine the post id from path
				echo '<li>'. sprintf( __( 'Couldn&#8217;t determine the correct item to attach this comment to. Given URL: <code>%s</code>.', 'disqus-importer' ) , esc_url( $post_url )) ."</li>\n";
				$this->num_uncertain++;
				return 0;
			}
	
			$new_comment['comment_author']			= $this->get_tag( $comment, 'name');
			$new_comment['comment_author_email']	= $this->get_tag( $comment, 'email');
			$new_comment['comment_author_IP']		= $this->get_tag( $comment, 'ip_address');
			$new_comment['comment_author_url']		= $this->get_tag( $comment, 'url');
			$gmt_unixtime							= strtotime( $this->get_tag( $comment, 'date' ));
			$new_comment['comment_date_gmt']		= date( 'Y-m-d H:i:s' , $gmt_unixtime );
			$new_comment['comment_date']			= date( 'Y-m-d H:i:s' , $gmt_unixtime + get_option( 'gmt_offset' ) * 3600 ); // strangely, get_date_from_gmt returns unexpected results here
			$new_comment['comment_content']			= $this->get_tag( $comment, 'message');
			$new_comment['comment_approved']		= 1; // the export appears to exclude non-public comments
			$new_comment['comment_type']			= ''; // Disqus doesn't appear to support trackbacks or pingbacks
			//$new_comment['comment_parent']			= $this->get_tag( $comment, 'wp:comment_parent');
	
			// comment author was auth'd by facebook
			if ( preg_match( '/facebook-.*/' , $new_comment['comment_author'] ) ) {
				$new_comment['comment_author'] = $this->get_author_name( $new_comment['comment_author_url'] );
			}
			// comment author was auth'd by twitter
			else if( preg_match( '/twitter-.*/' , $new_comment['comment_author'] ) ) {
				$new_comment['comment_author'] = $this->get_author_name( $new_comment['comment_author_url'] );
			}
			// comment author was auth'd by yahoo
			else if( preg_match( '/yahoo-.*/' , $new_comment['comment_author'] ) ) {
				$new_comment['comment_author'] = $this->get_author_name( $new_comment['comment_author_url'] );
			}
	
			$new_comment = array_map( 'html_entity_decode' , $new_comment );
	
			if( empty( $new_comment['disqus_parent_guid'] ) || $this->comment_exists( array( 'disqus_guid' => $new_comment['disqus_parent_guid'] ) ) ) {
				// $new_comment['comment_parent'] 	= $this->inserted_comments[ $new_comment['disqus_parent_guid'] ];
				$this->insert_comment( $new_comment );
			} else {
				//echo '<li>'. __( 'Postponed importing an orphan comment.', 'disqus-importer' ) ."</li>\n";
				$this->orphan_comments[ $new_comment['disqus_guid'] ] = $new_comment;
			}
	
			// do_action( 'import_post_added', $post_id );
		}
	
		// Loops through orphaned comments to determine if parent comment is now available
		function process_orphan_comments( $force = FALSE ) {
			if( $force )
				if( count( $this->orphan_comments ) )
					echo '<li>'.sprintf( _n( 'Processing %s orphan.' , 'Processing %s orphans.', count( $this->orphan_comments ), 'disqus-importer' ) , count( $this->orphan_comments ) ) .'.</li>';
				else
					return;
	
			ksort( 	$this->orphan_comments );
			while( $this->orphan_comments ) {
				foreach( $this->orphan_comments as $comment ){
					if( $this->comment_exists( array( 'disqus_guid' => $comment['disqus_parent_guid'] )) || $force ) {
						$comment['comment_parent'] = $this->inserted_comments[ $comment['disqus_parent_guid'] ];
						$this->insert_comment( $comment );
						unset( $this->orphan_comments[ $comment['disqus_guid'] ] );
					}
				}
	
				// detect a loop condition when a comment's parent can't be found
				if( count( $this->orphan_comments ) == $last_count )
					return;
	
				$last_count = count( $this->orphan_comments );
			}
		}
	
		// Performs the WP insert
		function insert_comment( $comment ) {
			if ( ! $this->comment_exists( $comment )) {
				unset( $comment['comment_id'] );
	
				$comment = wp_filter_comment( $comment );
				$this->inserted_comments[ $comment['disqus_guid'] ] = wp_insert_comment( $comment );
	
				update_comment_meta( $this->inserted_comments[ $comment['disqus_guid'] ], 'disqus_guid' , $comment['disqus_guid'], TRUE );
	
				$this->post_ids_processed[ $comment['comment_post_ID'] ]++;
				$this->num_comments++;
	
				echo '<li>'. sprintf(__( 'Imported comment by %s on %s.', 'disqus-importer') , esc_html( stripslashes( $comment['comment_author'] )) , get_the_title( $comment['comment_post_ID'] ) ) ."</li>\n";
			}
			else{
				$this->num_duplicates++;
				echo '<li>'. __( 'Skipped duplicate comment.', 'disqus-importer' ) ."</li>\n";
			}
		}
	
		/**
		 * get the comment_id by disqus_guid or by matching author & date
		 *
		 * @param array $comment
		 * @return int
		 */
		function comment_exists( $comment ) {
			global $wpdb;
			
			/*
			 NO PAIRING OF PARENT / CHILD IN DISQUS EXPORT YET
			
			// must have disqus_guid
			if( ! isset( $comment['disqus_guid'] ))
				return FALSE;
	
			// edge case: we've been given a comment_id because the comment was received through the Echo WP plugin
			// still, the comment_id may not be correct, so confirm the time is close (because the servers' clocks may not be sync'd)
			if( isset( $comment['comment_id'] )) {
				if( $local_comment_date_gmt = $wpdb->get_var( $wpdb->prepare( "SELECT comment_date_gmt FROM $wpdb->comments 
					WHERE comment_id = %s", (int) $comment['comment_id'] ) ) 
					&& 2880 < absint( strtotime( $comment['comment_date_gmt'] ) - strtotime( $local_comment_date_gmt )) ) {
						update_comment_meta( $comment_id, 'disqus_guid' , $comment['disqus_guid'], TRUE );
						$this->inserted_comments[ $comment['disqus_guid'] ] = $comment['comment_id'];
						return $comment['comment_id'];
				}
			}
	
			// look for disqus_guids in the processed comment list
			if( isset( $this->inserted_comments[ $comment['disqus_guid'] ] ))
				return $this->inserted_comments[ $comment['disqus_guid'] ];
	
			// look for disqus_guids in the commentmeta
			if( ( $comment_id = $wpdb->get_var( $wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'disqus_guid' AND meta_value = %s", $comment['disqus_guid'] ))) && $comment_id > 0 ) {
				$this->inserted_comments[ $comment['disqus_guid'] ] = $comment_id;
				return $comment_id;
			}
			*/
			
			// finally, try to match the comment using WP's comment_exists() rules
			// unfortunately, we need the comment_id, not comment_post_ID, so we can't use the built-in
			// add a disqus_guid to the comment if it matches
			if( isset( $comment['comment_author'] , $comment['comment_date'] )) {
				$comment_author = stripslashes( $comment['comment_author'] );
				$comment_date = stripslashes( $comment['comment_date'] );
				$comment_id = $wpdb->get_var( $wpdb->prepare("SELECT comment_id FROM $wpdb->comments WHERE comment_author = %s AND comment_date = %s", $comment_author, $comment_date ));

				// TODO: Write patch to accept below query.
				// $comment_array = get_comments( array( 'comment_author' => $comment_author, 'comment_date' => $comment_date, 'number' => 1 ) );
				// $comment_id = isset( $comment_array[0]->comment_ID ) ? $comment_array[0]->comment_ID : 0;
					
				update_comment_meta( $comment_id, 'disqus_guid' , $comment['disqus_guid'], TRUE );
				$this->inserted_comments[ $comment['disqus_guid'] ] = $comment_id;
				return $comment_id;
			}
	
			return FALSE;
		}
	
		// Runs prior to import
		function import_start() {
			wp_defer_comment_counting(true);
			do_action('import_start');
		}
		
		// Runs after import
		function import_end() {
			do_action('import_end');
	
			// clear the caches after backfilling
			foreach ($this->post_ids_processed as $post_id)
				clean_post_cache($post_id);
	
			wp_defer_comment_counting(false);
		}
	
		// Fires off import
		function import( $id ) {
			$this->id = (int) $id;
			$file = get_attached_file( $this->id );
			$this->import_file( $file );
		}
	
		function import_file( $file ) {
			$this->file = $file;
	
			$this->import_start();
			wp_suspend_cache_invalidation(true);
			$this->get_entries();
			$result = $this->process_comments();
			wp_suspend_cache_invalidation(false);
			$this->import_end();
	
			if ( is_wp_error( $result ) )
				return $result;
		}
	
		// Uploades file
		function handle_upload() {
			$file = wp_import_handle_upload();
			if ( isset($file['error']) ) {
				echo '<p>'.__('Sorry, there has been an error.', 'disqus-importer').'</p>';
				echo '<p><strong>' . $file['error'] . '</strong></p>';
				return false;
			}
			$this->file = $file['file'];
			$this->id = (int) $file['id'];
			return true;
		}
	
		// Which step are we on. Calls needed function
		function dispatch() {
			if (empty ($_GET['step']))
				$step = 0;
			else
				$step = (int) $_GET['step'];
	
			$this->header();
			switch ($step) {
				case 0 :
					$this->greet();
					break;
				case 1 :
					check_admin_referer('import-upload');
					if ( $this->handle_upload() )
						$this->check_upload();
					break;
				case 2:
					check_admin_referer('import-disqus');
					$result = $this->import( $_GET['id'] );
					if ( is_wp_error( $result ) )
						echo $result->get_error_message();
					break;
			}
			$this->footer();
		}
	
		// Constructor does nothing
		function Disqus_Import() {

		}
	} // End Class

	/**
	 * Register Disqus Importer
	 *
	 */
	$disqus_import = new Disqus_Import();
	
	register_importer('disqus', 'Disqus Comments', __('Import comments from an Disqus export file.', 'disqus-importer'), array ( $disqus_import, 'dispatch' ));

} // class_exists( 'WP_Importer' )

// Loads plugin textdomain on init
function disqus_importer_init() {
    load_plugin_textdomain( 'disqus-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'disqus_importer_init' );