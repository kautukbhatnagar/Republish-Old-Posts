<?php
/*
Plugin Name: Republish Old Posts
Version: 1.17
Plugin URI: http://www.kautukbhatnagar.com/republish-old-post/
Description: This plugin republish the old posts automatically by setting the date to the current date. Puts your evergreen posts in front of your users via the front page and feeds. 
Author: Kautuk bhatnagar
Author URI: http://www.kautukbhatnagar.com/republish-old-post/
License: GPLv2 or later
Text Domain: republish-old-posts
*/

/*
Copyright 2017 Kautuk bhatnagar

Forked from the Old Post Promoter Plugin by Marios Alexandrou that was once housed in the WordPress Plugin Repository.

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

//Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ROP_1_MINUTE', 60 );
define( 'ROP_1_HOUR', 60 * ROP_1_MINUTE ); 
define( 'ROP_4_HOURS', 4 * ROP_1_HOUR ); 
define( 'ROP_6_HOURS', 6 * ROP_1_HOUR ); 
define( 'ROP_12_HOURS', 12 * ROP_1_HOUR ); 
define( 'ROP_24_HOURS', 24 * ROP_1_HOUR ); 
define( 'ROP_INTERVAL', ROP_12_HOURS ); 
define( 'ROP_INTERVAL_SLOP', ROP_4_HOURS ); 
define( 'ROP_AGE_LIMIT', 120); // 120 days
define( 'ROP_OMIT_CATS', "" ); 

register_activation_hook( __FILE__, 'rop_activate' );
register_deactivation_hook( __FILE__, 'rop_deactivate' );

add_action( 'init', 'rop' );
add_action( 'admin_menu', 'rop_options_setup' );
add_filter( 'the_content', 'rop_the_content' );
add_filter( 'plugin_row_meta', 'rop_plugin_meta', 10, 2 );

function rop_plugin_meta( $links, $file ) { // add some links to plugin meta row
	if ( strpos( $file, 'republish-old-posts.php' ) !== false ) {
		$links = array_merge( $links, array( '<a href="' . esc_url( get_admin_url(null, 'options-general.php?page=republish-old-posts') ) . '">Settings</a>' ) );
		
	}

	return $links;
}

function rop_deactivate() {

}

function rop_activate() {
	add_option( 'rop_interval', ROP_INTERVAL );
	add_option( 'rop_interval_slop', ROP_INTERVAL_SLOP );
	add_option( 'rop_age_limit', ROP_AGE_LIMIT );
	add_option( 'rop_omit_cats', ROP_OMIT_CATS );
	add_option( 'rop_show_original_pubdate', 1 );
	add_option( 'rop_pos', 0 );
	add_option( 'rop_at_top', 0 );
}

function rop() {
	if ( rop_update_time() ) {
		update_option( 'rop_last_update', time() );
		rop_republish_old_post();
	}
}

function rop_republish_old_post () {
	global $wpdb;
	$rop_omit_cats = get_option( 'rop_omit_cats' );
	$rop_age_limit = get_option( 'rop_age_limit' );

	if ( !isset( $rop_omit_cats ) ) {
		$rop_omit_cats = ROP_OMIT_CATS;
	}
	if ( !isset( $rop_age_limit ) ) {
		$rop_age_limit = ROP_AGE_LIMIT;
	}
	
	$sql = "(SELECT ID, post_date
            FROM $wpdb->posts
            WHERE post_type = 'post'
                  AND post_status = 'publish'
                  AND post_date < '" . current_time( 'mysql' ) . "' - INTERVAL " . $rop_age_limit * 24 . " HOUR 
                  ";
    if ( $rop_omit_cats!='' ) {
    	$sql = $sql."AND NOT(ID IN (SELECT tr.object_id 
                                    FROM $wpdb->terms t 
                                          inner join $wpdb->term_taxonomy tax on t.term_id=tax.term_id and tax.taxonomy='category' 
                                          inner join $wpdb->term_relationships tr on tr.term_taxonomy_id=tax.term_taxonomy_id 
                                    WHERE t.term_id IN (".$rop_omit_cats.")))";
    }            
	$sql = $sql. ")";
	$sql = $sql.
            "ORDER BY post_date ASC 
            LIMIT 1 ";						

	//error_log ( $sql );
	
	$oldest_post = $wpdb->get_var( $sql, 0, 0 );
	if ( isset( $oldest_post ) ) {
		rop_update_old_post( $oldest_post );
	}
}

function rop_update_old_post( $oldest_post ) {
	global $wpdb;

	$post = get_post( $oldest_post );
	$rop_original_pub_date = get_post_meta( $oldest_post, 'rop_original_pub_date', true ); 

	if ( !( isset( $rop_original_pub_date ) && $rop_original_pub_date!='' ) ) {
	    $sql = "SELECT post_date from ".$wpdb->posts." WHERE ID = '$oldest_post'";
		$rop_original_pub_date = $wpdb->get_var( $sql );
		add_post_meta( $oldest_post, 'rop_original_pub_date', $rop_original_pub_date );
		$rop_original_pub_date = get_post_meta($oldest_post, 'rop_original_pub_date', true ); 
	}

	$rop_pos = get_option('rop_pos');
	if ( !isset( $rop_pos ) ) {
		$rop_pos = 0;
	}

	if ( $rop_pos == 1 ) {
		$new_time = date('Y-m-d H:i:s');
		$gmt_time = get_gmt_from_date($new_time);
	} else {
		$lastposts = get_posts( 'numberposts=1&offset=1' );
		foreach ($lastposts as $lastpost) {
			$post_date = strtotime( $lastpost->post_date );
			$new_time = date('Y-m-d H:i:s',mktime(date("H",$post_date),date("i",$post_date),date("s",$post_date)+1,date("m",$post_date),date("d",$post_date),date("Y",$post_date)));
			$gmt_time = get_gmt_from_date( $new_time );
		}
	}

	$sql = "UPDATE $wpdb->posts SET post_date = '$new_time',post_date_gmt = '$gmt_time',post_modified = '$new_time',post_modified_gmt = '$gmt_time' WHERE ID = '$oldest_post'";		
	$wpdb->query($sql);
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	
	//Cache clearing code for the WP Fastest Cache plugin
	if(isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'singleDeleteCache')){
		$GLOBALS['wp_fastest_cache']->singleDeleteCache(false, $oldest_post);
	}
			
	//do_action( 'old_post_promoted', $post );
}

function rop_the_content( $content ) {
	global $post;
	$rop_show_original_pubdate = get_option( 'rop_show_original_pubdate' );
	if ( !isset( $rop_show_original_pubdate ) ) {
		$rop_show_original_pubdate = 1;
	}
	$rop_original_pub_date = get_post_meta( $post->ID, 'rop_original_pub_date', true );
	$dateline = '';
	if ( isset( $rop_original_pub_date ) && $rop_original_pub_date != '' ) {
		if ( $rop_show_original_pubdate ) {
			$dateline .= '<p id="rop"><small>';
			if ( $rop_show_original_pubdate ) {
				$dateline .= 'Originally posted ' . $rop_original_pub_date . '. ';
			}
			$dateline.='</small></p>';
		}
	}
	$rop_at_top = get_option( 'rop_at_top' );
	if ( isset( $rop_at_top ) && $rop_at_top ) {
		$content = $dateline.$content;
	} else {
		$content = $content.$dateline;
	}
	return $content;
}

function rop_update_time () {
	$last = get_option( 'rop_last_update' );
	$interval = get_option( 'rop_interval' );
	$time = time();

	if ( !( isset( $interval ) && is_numeric( $interval ) ) ) {
		$interval = ROP_INTERVAL;
	}

	$slop = get_option( 'rop_interval_slop' );
	if ( !( isset( $slop ) && is_numeric( $slop ) ) ) {
		$slop = ROP_INTERVAL_SLOP;
	}

	//error_log( 'last: ' . $last );
	//error_log( 'time: ' . $time );
	//error_log( 'time minus last: ' . ( $time - $last ) );
	//error_log( 'interval: ' . $interval );
	//error_log( 'slop: ' . $slop );
	
	if ( false === $last ) {
		$ret = 1;
		//error_log( 'ret (forced): ' . $ret );
	} else if ( is_numeric( $last ) ) { 
		if ( $slop == 0 ) {
			if ( ( $time - $last ) >= $interval ) {
				$ret = 1;
			} else {
				$ret = 0;
			}
		} else {
			if ( ( $time - $last ) >= ( $interval + rand( 0, $slop ) ) ) {
				$ret = 1;
			} else {
				$ret = 0;
			}
		}
		//error_log( 'ret (calculated): ' . $ret );
	}

	return $ret;
}

function rop_options_page() {	 	
	$message = null;
	$message_updated = __("Options Updated.", 'rop');

	if ( !empty( $_POST['rop_action'] ) ) {
		$message = $message_updated;

		if ( isset( $_POST['rop_interval'] ) ) {
			$rop_interval = $_POST['rop_interval'];
			$rop_interval = intval( $rop_interval );
			update_option( 'rop_interval', $rop_interval );
		}

		if ( isset( $_POST['rop_interval_slop'] ) ) {
			$rop_interval_slop = $_POST['rop_interval_slop'];
			$rop_interval_slop = intval( $rop_interval_slop );
			update_option( 'rop_interval_slop', $rop_interval_slop );
		}

		if ( isset( $_POST['rop_age_limit'] ) ) {
			if ( is_numeric( $_POST['rop_age_limit'] ) ) {
			$rop_age_limit = $_POST['rop_age_limit'];
			} else {
				$rop_age_limit = ROP_AGE_LIMIT;
			}
			update_option( 'rop_age_limit', $rop_age_limit );
		}

		if ( isset( $_POST['rop_show_original_pubdate'] ) ) {
			$rop_show_original_pubdate = $_POST['rop_show_original_pubdate'];
			$rop_show_original_pubdate = intval( $rop_show_original_pubdate );
			update_option( 'rop_show_original_pubdate', $rop_show_original_pubdate );
		}

		if ( isset( $_POST['rop_pos'] ) ) {
			$rop_pos = $_POST['rop_pos'];
			$rop_pos = intval( $rop_pos );
			update_option( 'rop_pos', $rop_pos );
		}

		if ( isset( $_POST['rop_at_top'] ) ) {
			$rop_at_top = $_POST['rop_at_top'];
			$rop_at_top = intval( $rop_at_top );
			update_option( 'rop_at_top', $rop_at_top );
		}

		if ( isset( $_POST['post_category'] ) ) {
			$rop_omit_custom_field_value = implode( ',', $_POST['post_category'] );
			$rop_omit_custom_field_value = sanitize_text_field( $rop_omit_custom_field_value );
			update_option( 'rop_omit_cats', $rop_omit_custom_field_value );
		} else {
			update_option('rop_omit_cats', '');		
		}
		
		print('
			<div id="message" class="updated fade">
				<p>' . __( 'Options Updated.', 'republish-old-posts' ).'</p>
			</div>');
	}

	$rop_omit_cats = sanitize_text_field( get_option( 'rop_omit_cats' ) );
	if ( !isset( $rop_omit_cats ) ) {
		$rop_omit_cats = ROP_OMIT_CATS;
	}
	
	$rop_age_limit = intval( get_option( 'rop_age_limit' ) );
	if ( !isset( $rop_age_limit ) || $rop_age_limit == 0 ) {
		$rop_age_limit = ROP_AGE_LIMIT;
	}

	$rop_show_original_pubdate = intval( get_option( 'rop_show_original_pubdate' ) );
	if ( !isset( $rop_show_original_pubdate ) && !( $rop_show_original_pubdate == 0 || $rop_show_original_pubdate == 1 ) ) {
		$rop_show_original_pubdate = 1;
	}

	$rop_at_top = intval( get_option( 'rop_at_top' ) );
	if ( !( isset( $rop_at_top ) ) ) {
		$rop_at_top = 0;
	}

	$rop_pos = intval( get_option( 'rop_pos' ) );
	if ( !( isset( $rop_pos ) ) ) {
		$rop_pos = 1;
	}

	$interval = intval( get_option( 'rop_interval' ) );
	if ( !( isset( $interval ) ) ) {
		$interval = ROP_INTERVAL;
	}

	$slop = intval( get_option( 'rop_interval_slop' ) );
	if ( !( isset( $slop ) ) ) {
		$slop = ROP_INTERVAL_SLOP;
	}
		
	print('
	<div class="wrap" style="padding-bottom: 5em">
		<h2>Republish Old Posts</h2>
		<p>Posts on your site will be republished based on the conditions you specify below.</p>
		<p>A republished post will have its date reset to the current date and so it will appear in feeds, on your front page and at the top of archive pages.</p>
		<p><strong>WARNING:</strong> If your permalinks contain dates, disable this plugin immediately.</p>
		
		<div id="rop-items" class="postbox">
			<form id="rop" name="rop" action="' . $_SERVER['REQUEST_URI'] . '" method="post">
				<input type="hidden" name="rop_action" value="rop_update_settings" />
				<fieldset class="options">
					<div class="option">
						<label for="rop_interval">' . __( 'Minimum Interval Between Post Republishing: ', 'republish-old-posts' ) . '</label>
						<select name="rop_interval" id="rop_interval">
							<option value="' . ROP_4_HOURS . '" ' . rop_option_selected( ROP_4_HOURS, $interval ) . '>' . __( '4 Hours', 'republish-old-posts' ) . '</option>
							<option value="' . ROP_6_HOURS . '" ' . rop_option_selected( ROP_6_HOURS, $interval ) . '>' . __( '6 Hours', 'republish-old-posts' ) . '</option>
							<option value="' . ROP_12_HOURS . '" ' . rop_option_selected( ROP_12_HOURS, $interval ) . '>'. __( '12 Hours', 'republish-old-posts' ) . '</option>
							<option value="' . ROP_24_HOURS . '" ' . rop_option_selected( ROP_24_HOURS, $interval ) . '>' . __( '24 Hours (1 day)', 'republish-old-posts' ) . '</option>
						</select>
					</div>
					<div class="option">
						<label for="rop_interval_slop">' . __( 'Randomness Interval (added to minimum interval): ', 'republish-old-posts' ) . '</label>
						<select name="rop_interval_slop" id="rop_interval_slop">
							<option value="' . ROP_1_HOUR . '" ' . rop_option_selected( ROP_1_HOUR, $slop ) . '>' . __( 'Upto 1 Hour', 'republish-old-posts' ) . '</option>
							<option value="' . ROP_4_HOURS . '" ' . rop_option_selected( ROP_4_HOURS, $slop ) . '>' . __( 'Upto 4 Hours', 'republish-old-posts' ) . '</option>
							<option value="' . ROP_6_HOURS . '" ' . rop_option_selected( ROP_6_HOURS, $slop ) . '>' . __( 'Upto 6 Hours', 'republish-old-posts' ) . '</option>
						</select>
					</div>
					<div class="option">
						<label for="rop_age_limit">' . __( 'Post Age Before Eligible for Republishing: ', 'republish-old-posts' ).'</label>
						<select name="rop_age_limit" id="rop_age_limit">
							<option value="1" ' . rop_option_selected( 1, $rop_age_limit ) . '>' . __( '1 Day', 'republish-old-posts' ) . '</option>
							<option value="7" ' . rop_option_selected( 7, $rop_age_limit ) . '>' . __( '7 Days', 'republish-old-posts' ) . '</option>
							<option value="15" ' . rop_option_selected( 15, $rop_age_limit ) . '>' . __( '25 Days', 'republish-old-posts' ) . '</option>
							<option value="30" ' . rop_option_selected( 30, $rop_age_limit ) . '>' . __( '30 Days', 'republish-old-posts' ) . '</option>
							<option value="60" ' . rop_option_selected( 60, $rop_age_limit ) . '>' . __( '60 Days', 'republish-old-posts' ) . '</option>
							<option value="90" ' . rop_option_selected( 90, $rop_age_limit ) . '>' . __( '90 Days', 'republish-old-posts' ) . '</option>
						</select>
					</div>
					<div class="option">
						<label for="rop_pos">' . __( 'Republish post to position (choosing the 2nd position will leave the most recent post in place): ', 'republish-old-posts' ) . '</label>
						<select name="rop_pos" id="rop_pos">
							<option value="1" ' . rop_option_selected( 1, $rop_pos ) . '>' . __( '1st Position', 'republish-old-posts' ) . '</option>
							<option value="2" ' . rop_option_selected( 2, $rop_pos ) . '>' . __( '2nd Position', 'republish-old-posts' ) . '</option>
						</select>
					</div>
					<div class="option">
						<label for="rop_show_original_pubdate">' . __( 'Show Original Publication Date at Post End? ', 'republish-old-posts' ) . '</label>
						<select name="rop_show_original_pubdate" id="rop_show_original_pubdate">
							<option value="1" ' . rop_option_selected( 1, $rop_show_original_pubdate ) . '>' . __( 'Yes', 'republish-old-posts' ) . '</option>
							<option value="0" ' . rop_option_selected( 0, $rop_show_original_pubdate ) . '>' . __( 'No', 'republish-old-posts' ) . '</option>
						</select>
					</div>
					<div class="option">
						<label for="rop_at_top">' . __( 'Show Original Publication Date At Top of Post? ', 'republish-old-posts' ) . '</label>
						<select name="rop_at_top" id="rop_at_top">
							<option value="1" ' . rop_option_selected( 1, $rop_at_top ) . '>' . __( 'Yes', 'republish-old-posts' ) . '</option>
							<option value="0" ' . rop_option_selected( 0, $rop_at_top ) . '>' . __( 'No', 'republish-old-posts' ) . '</option>
						</select>
					</div>
					<div class="clearpad"></div>
					<div class="option">
						' . __( 'Select Categories to Omit from Republishing: ', 'republish-old-posts' ) . '
					</div>
					<ul>
					');
wp_category_checklist( 0, 0, explode( ',', $rop_omit_cats ) );
print('				</ul>
				</fieldset>
				<div id="divTxt"></div>
				<div class="clearpad"></div>
				<input type="submit" name="submit" value="' . __( 'Update Options', 'republish-old-posts' ) . '" />
				<div class="clearpad"></div>
			</form>
		</div>'
			);
?>
		<div id="rop-sb">
			<div class="postbox" id="rop-sbtwo">
				<h3 class="hndle"><span>Support</span></h3>
				<div class="inside">
					<p>You can contact me in case of support required on<a href="mailto:kautukbhatnagar@gmail.com">kautukbhatnagar@gmail.com</a>.</p>
					
				</div>
			</div>

		</div>
	</div>
<?php
}

function rop_option_selected( $option_value, $value ) {
	if($option_value == $value) {
		return 'selected="selected"';
	}
	return '';
}

function rop_options_setup() {	
	$page = add_submenu_page( 'options-general.php', 'Republish Old Posts', 'Republish Old Posts', 'activate_plugins', 'republish-old-posts', 'rop_options_page' );
	add_action( "admin_print_scripts-$page", "rop_admin_scripts" );
	
}

/*
* Scripts needed for the admin side
*/
function rop_admin_scripts() {
	wp_enqueue_style( 'rop_styles', plugins_url( 'css/rop.css', __FILE__ ) );
}
