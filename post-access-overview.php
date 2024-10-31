<?php
/*
Plugin Name: Post Access Overview
Version: 1
Description: Show who has access to which post in a single table. Works in conjunction with User Access Manager.
Author: Zaantar
Author URI: http://zaantar.eu
License: GPL2
Donate link: http://zaantar.eu/financni-prispevek
Plugin URI: http://wordpress.org/extend/plugins/

    Copyright 2013 Zaantar (email: zaantar@zaantar.eu)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


namespace PostAccessOverview {
	
	
	const SLUG = "post-access-overview";
	const PAGE = SLUG;
	const TXD = SLUG; /* Textdomain */
	const PLUGIN_NAME = "Post Access Overview";
	const POSTS_PER_PAGE = 20;
	
	
	/* Assure that 'class-wp-list-table.php' is available. */
	if(!class_exists('WP_List_Table')) {
		require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
	}
	
	
	/* Custom styling */
	add_action( "admin_enqueue_scripts", "\PostAccessOverview\admin_enqueue_styles" );
	
	function admin_enqueue_styles() {
		global $pagenow;
		if( $pagenow == "users.php" && isset( $_GET["page"] ) && $_GET["page"] == PAGE ) {
			wp_enqueue_style( SLUG . "-style", plugins_url( "includes/style.css", __FILE__ ) );
		}
	}
	
	
	/* Add Settings submenu page entry. */
	add_action( 'admin_menu', '\PostAccessOverview\admin_menu' );
	function admin_menu() {
	
		add_submenu_page(
				"users.php",
				__( PLUGIN_NAME, TXD ),
				__( PLUGIN_NAME, TXD ),
				"manage_options",
				PAGE,
				"\PostAccessOverview\handle_page" );
	
	}
	
	
	
	function handle_page() {
		?>
			<div class="wrap">
		<?php
		$action = isset( $_REQUEST["action"] ) ? $_REQUEST["action"] : "default";
						
		switch( $action ) {
			default:
				show_default_page();
				break;
		}
		?>
			</div>
		<?php
	}
	
	
	function show_default_page() {
		?>
		<h2><?php _e( PLUGIN_NAME, TXD ); ?></h2>
		<form method="get">
			<?php
				$patable = new PostAccessTable();
				$patable->prepare_items();
				$patable->display();
			?>
		</form>
		<?php
	}
	
	
	/* Class for showing the table in a WP way. */
	class PostAccessTable extends \WP_List_Table {


		var $users = array();


		function __construct() {
			parent::__construct( array(
					'singular'  => 'note',
					'plural'    => 'notes',
					'ajax'      => false ) );
		}
		
		
		/* Load all users and store them in cache if not yet loaded. */
		function load_users() {
			if( empty( $this->users ) ) {
				$this->users = get_users( array() ); // TODO ordering
			}
		}
		
		
		function get_columns() {

			$columns = array(
					"date" => __( "Date", TXD ),
					"title" => __( "Post title", TXD ) );
			
			/* Add a column for each user. Colum name is user's ID. */
			$this->load_users();
			foreach( $this->users as $user ) {
				$columns[$user->ID] = $user->user_login;
			}
			
			return $columns;
		}
		
		
		function get_sortable_columns() {
			$sortable_columns = array(
					"date" => array( "date", true ),
					"title" => array( "title", false ) );
			return $sortable_columns;
		}
		
		
		/* For styling. */
		function get_table_classes() {
			$classes = parent::get_table_classes();
			$classes[] = "post-access-overview-table";
			return $classes;
		}
		
		
		function prepare_items() {
		
			$columns = $this->get_columns();
			$hidden = array();
			$sortable = $this->get_sortable_columns();
			$this->_column_headers = array( $columns, $hidden, $sortable );
		
			$per_page = POSTS_PER_PAGE;
			$current_page = $this->get_pagenum();
		
			$order = isset( $_REQUEST["order"] ) ? $_REQUEST["order"] : "DESC";
			$orderby = isset( $_REQUEST["orderby"] ) ? $_REQUEST["orderby"] : "date";
		
			/* Get required portion of the posts. */
			$this->items = get_posts(
					array(
							"posts_per_page" => POSTS_PER_PAGE,
							"paged" => $current_page,
							"order" => $order,
							"orderby" => $orderby,
							"post_status" => array( "publish", "pending", "draft", "private" ) ) );
		
			/* Count all relevant posts. */
			$post_counts = wp_count_posts();
			$total_items = $post_counts->publish + $post_counts->pending + $post_counts->draft + $post_counts->private;
			
			/*get_posts(
					array(
							"post_status" => array( "publish", "pending", "draft", "private" ) ) );*/
		
			$this->set_pagination_args( array(
					'total_items' => $total_items,
					'per_page'    => $per_page,
					'total_pages' => ceil($total_items/$per_page)
			) );
		}
		
		
		function column_date( $item ) {
			return date( "j.n.Y", strtotime( $item->post_date ) );
		}
		
		
		function column_title( $item ) {
			$out = sprintf(
					"<a href=\"%s\">%s</a><br />",
					admin_url( "post.php?post={$item->ID}&action=edit" ),
					$item->post_title );
			
			$categories = get_the_category( $item->ID );
			$category_names = array();
			foreach( $categories as $category ) {
				$category_names[] = $category->cat_name;
			}
			$out .= "<small>" . implode( ", ", $category_names ) . "</small>";
			
			return $out;
		}
		
		
		/* Function for the columns with user ID's, rows (items) are posts. */
		function column_default( $item, $column_name ) {
		
			$user_id = $column_name;
			$post_id = $item->ID;
			
			$can_read = true;
			$reason = ""; /* If user cannot read the post, say why. */
			
			/* Check if WP core allows user to read the post.
			 * I had to guess the function 3-parameter usage and "read_post" value
			 * but it seems to work fine.
		     */
			if( user_can( $user_id, "read_post", $post_id ) ) {
				
				/* If WP core allows user to read the post, check if UAM does it as well.
				 * Note that there is no API to check access rights for other than current user,
				 * so we have to use wp_set_current_user().
				 * */
				$current_user = get_current_user_id();
				wp_set_current_user( $user_id );
				
				/* Initialize new instance of UAM for user we want. The existing
				 * one which is in global variable $userAccessManager cannot be used.
				 */
				$uam_access_manager = new \UserAccessManager();
				
				/* Get reference to access handler */
				$uam_access_handler = &$uam_access_manager->getAccessHandler();
				
				/* Ask access handler if "current" user can access the post. */
				$is_uam_access = $uam_access_handler->checkObjectAccess( "post", $post_id );
				
				/* Return to current user. */
				wp_set_current_user( $current_user_id );
				
				if( !$is_uam_access ) {
					/* UAM doesn't allow user to read this post. */
					$can_read = false;
					$reason = "uam";
				}
				
			} else {

				/* Wordpress core doesn't allow user to read this post. */
				$can_read = false;
				$reason = "wp";
			}
			
			
			/* Output. */
			if( $can_read ) {
				$out = "<span style=\"font-weight: bold; color: green;\">YES</span>";
			} else {
				$out = "<span style=\"font-weight: bold; color: red;\">NO</span>";
			}

			if( !empty( $reason ) ) {
				$out .= " <small>" . $reason . "</small>";
			}
			
			return $out;
		}

	}
	
}

?>