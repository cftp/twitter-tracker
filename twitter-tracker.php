<?php
/*
Plugin Name: Twitter Tracker
Plugin URI: http://wordpress.org/extend/plugins/twitter-tracker/
Description: Tracks the search results on Twitter search or Twitter profile in a sidebar widget.
Author: Simon Wheatley (Code for the People)
Version: 3.2.1
Author URI: http://codeforthepeople.com/
*/

// http://search.twitter.com/search.atom?q=wordcampuk

/*  Copyright 2008 Simon Wheatley

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

require_once( dirname (__FILE__) . '/plugin.php' );
require_once( dirname (__FILE__) . '/class-TwitterTracker_Widget.php' );
require_once( dirname (__FILE__) . '/class-TwitterTracker_Profile_Widget.php' );

/**
 *
 * @package default
 * @author Simon Wheatley
 **/
class TwitterTracker extends TwitterTracker_Plugin
{

	public $widget;

	public function __construct()
	{
		if ( is_admin() ) {
			$this->register_activation (__FILE__);
			$this->add_action( 'save_post', 'process_metabox', null, 2 );
			$this->add_action( 'add_meta_boxes' );
		}
		// Init
		$this->register_plugin ( 'twitter-tracker', __FILE__ );
		$this->add_action( 'init' );
		$this->add_filter( 'tt_allowed_post_types', 'warn_tt_allowed_post_types' );

		// register widget
		add_action('widgets_init', create_function('', 'return register_widget( "TwitterTracker_Widget" );'));
		add_action('widgets_init', create_function('', 'return register_widget( "TwitterTracker_Profile_Widget" );'));
	}
	
	// DOING IT WRONG
	// ==============
	
	/**
	 * Hooks the warn_tt_allowed_post_types filter to throw a
	 * doing it wrong warning if anyone has used the filter.
	 *
	 * @param mixed $pass_through A value to pass right through
	 * @return A value to pass right through
	 **/
	function warn_tt_allowed_post_types( $pass_through ) {
		remove_filter( 'tt_allowed_post_types', array( & $this, 'warn_tt_allowed_post_types' ) );
		if ( has_filter( 'tt_allowed_post_types' ) )
			_doing_it_wrong( 'tt_allowed_post_types', __( 'Twitter Tracker filter error: The tt_allowed_post_types filter has been deprecated and will be removed in a future version, please use tt_post_types_with_override instead.', 'twitter-tracker' ), '2.5' );
		return $pass_through;
	}
	
	// HOOKS
	// =====
	
	public function activate() {
		// Empty
	}
	
	/**
	 * Hooks the WP add_meta_boxes action to add the metaboxes to 
	 * post_type editing screens.
	 *
	 * @return void
	 **/
	function add_meta_boxes() {
		// Allow other plugins to add to the allowed post types for this metabox
		// First use the legacy filter name
		$allowed_post_types = apply_filters( 'tt_allowed_post_types', array( 'page', 'post' ) );
		// Now use the new, more sensible filter name
		$allowed_post_types = apply_filters( 'tt_post_types_with_override', $allowed_post_types );
		// This work around because the WO CIDIW client needs to upgrade WP,
		// but we need to get this plugin working before they do… can be removed
		// once everyone is up to v3.1.0 as required by the plugin.
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( ! in_array( $screen->post_type, $allowed_post_types ) )
				return;
		}
		$this->add_meta_box( 'twitter_tracker', __( 'Twitter Tracker', 'twitter-tracker' ), 'metabox', 'post', 'normal', 'default' );
		$this->add_meta_box( 'twitter_tracker', __( 'Twitter Tracker', 'twitter-tracker' ), 'metabox', 'page', 'normal', 'default' );
	}
	
	/**
	 * Callback function providing the HTML for the metabox
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function metabox() {
		global $post;
		$vars = array();
		$vars[ 'query' ] = get_post_meta( $post->ID, '_tt_query', true );
		$vars[ 'username' ] = get_post_meta( $post->ID, '_tt_username', true );
		$this->render_admin( 'metabox', $vars );
	}
		
	public function process_metabox( $post_id, $post ) {
		// Are we being asked to do anything?
		$do_something = (bool) @ $_POST[ '_tt_query_nonce' ];
		if ( ! $do_something ) return;
		// Allow other plugins to add to the allowed post types
		// First use the legacy filter name
		$allowed_post_types = apply_filters( 'tt_allowed_post_types', array( 'page', 'post' ) );
		// Now use the new, more sensible filter name
		$allowed_post_types = apply_filters( 'tt_post_types_with_override', $allowed_post_types );
		// Don't bother doing this on revisions and wot not
		if ( ! in_array( $post->post_type, $allowed_post_types ) )
			return;
		// Are we authorised to do anything?
		check_admin_referer( 'tt_query', '_tt_query_nonce' );
		// OK. We are good to go.
		$query = @ $_POST[ 'tt_query' ];
		update_post_meta( $post_id, '_tt_query', $query );
		$username = @ $_POST[ 'tt_username' ];
		update_post_meta( $post_id, '_tt_username', $username );
	}
	
	public function init()
	{
		// Slightly cheeky, but change the cache age of Magpie from 60 minutes to 15 minutes
		// That's still plenty of caching IMHO :)
		if ( ! defined( 'MAGPIE_CACHE_AGE' ) )
			define( 'MAGPIE_CACHE_AGE', 60 * 15 ); // Fifteen of your Earth minutes
	}
	
	public function show( $args )
	{
		$defaults = array (
			'hide_replies' => false,
			'include_retweets' => false,
			'mandatory_hash' => '',
			'max_tweets' => 3,
			'html_after' => '',
			'preamble' => '',
		);
		$args = wp_parse_args( $args, $defaults );
		extract( $args );

		// Let the user know if there's no search query
		if ( empty( $twitter_search ) ) {
			$this->render( 'widget-error', array() );
			return;
		}
		require_once( dirname( __FILE__ ) . '/model/twitter-search.php' );
		require_once( dirname( __FILE__ ) . '/model/tweet.php' );
		global $post;
		// Allow the local custom field to overwrite the widget's query
		if ( is_singular() || is_single() ) {
			if ( $local_query = trim( get_post_meta( $post->ID, '_tt_query', true ) ) )
				$twitter_search = $local_query;
			if ( ! $local_query && ! $twitter_search )
				return;
		}
		if ( ! $twitter_search )
			return;
		$search = new TwitterSearch ( $twitter_search, $max_tweets, $hide_replies, $mandatory_hash );
		$vars = array( 
			'tweets' => $search->tweets(), 
			'preamble' => $preamble,
			'html_after' => $html_after,
		);
		$vars[ 'datef' ] = _x( 'M j, Y @ G:i', 'Publish box date format', 'twitter-tracker' );
		$this->render( 'widget-contents', $vars );
	}
	
	public function show_profile( $args )
	{
		$defaults = array (
			'hide_replies' => false,
			'include_retweets' => false,
			'mandatory_hash' => '',
			'max_tweets' => 3,
			'html_after' => '',
			'preamble' => '',
		);
		$args = wp_parse_args( $args, $defaults );
		
		extract( $args );

		require_once( dirname( __FILE__ ) . '/model/twitter-profile.php' );
		require_once( dirname( __FILE__ ) . '/model/api-tweet.php' );

		// Allow the local custom field to overwrite the widget's query, but
		// only on single post (of any type)
		if ( is_singular() && $post_id = get_queried_object_id() )
			if ( $local_username = trim( get_post_meta( $post_id, '_tt_username', true ) ) )
				$username = $local_username;

		if ( ! $username )
			return;
		
		$search = new TwitterProfile ( $username, $max_tweets, $hide_replies, $include_retweets, $mandatory_hash );
		$vars = array( 
			'tweets' => $search->tweets(), 
			'preamble' => $preamble,
			'html_after' => $html_after,
		);
		$vars[ 'datef' ] = _x( 'M j, Y @ G:i', 'Publish box date format', 'twitter-tracker' );
		$this->render( 'widget-contents', $vars );
	}

	public function & get()
	{
	    static $instance;

	    if ( ! isset ( $instance ) ) {
			$c = __CLASS__;
			$instance = new $c;
	    }

	    return $instance;
	}

}

function twitter_tracker( $instance )
{
	$tracker = TwitterTracker::get();
	$tracker->show( $instance );
}

function twitter_tracker_profile( $instance )
{
	$tracker = TwitterTracker::get();
	$tracker->show_profile( $instance );
}


/**
 * Instantiate the plugin
 *
 * @global
 **/

$GLOBALS[ 'TwitterTracker' ] = new TwitterTracker();

?>