<?php
 
/*  Copyright 2012 Code for the People Ltd

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

/**
 * Twitter oAuth Authentication Line Dance Caller
 *
 * @package Twitter Tracker
 * @since 3.3
 */
class TT_Twitter_Authentication {
	
	/**
	 * An array of error messages for the user
	 * 
	 * @var type array
	 */
	public $errors;

	protected $creds; 

	/**
	 * Singleton stuff.
	 * 
	 * @access @static
	 * 
	 * @return NAO_Duplicates_Checker
	 */
	static public function init() {

		static $instance = false;

		if ( ! $instance )
			$instance = new TT_Twitter_Authentication;

		return $instance;

	}

	/**
	 * Let's go!
	 *
	 * @return void
	 **/
	public function __construct() {

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'load-settings_page_tt_auth', array( $this, 'load_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'tt_twitter_credentials', array( $this, 'tt_twitter_credentials' ) );

		$this->errors = array();

		$this->load_creds();
	}
	
	// HOOKS
	// =====

	public function tt_twitter_credentials( $creds ) {
		if ( ! $this->creds[ 'authenticated' ] )
			return $creds;
		return $this->creds;
	}

	public function admin_init() {
		$this->maybe_upgrade();
		// Handy couple of lines to reset all the tweet collection
//		$this->init_oauth();
//		$this->oauth->delete_property( 'last_mention_id' );
//		delete_option( 'twtwchr_queued_mentions' );
//		var_dump( "Done" );
//		exit;
//		$this->queue_new_mentions();
	}
	
	public function admin_menu() {
		add_options_page( 'Twitter Tracker Authentication', 'Twitter Tracker Auth', 'manage_options', 'tt_auth', array( $this, 'settings' ) );
	}

	public function load_settings() {

		// Denied?
		if ( isset( $_GET[ 'denied' ] ) ) {
			if ( $_GET[ 'denied' ] == $this->creds[ 'oauth_token' ] ) {
				$unset_creds = array(
					'oauth_token',
					'oauth_token_secret',
					'user_id',
					'screen_name',
					'authenticated',
				);
				$this->unset_creds( $unset_creds );
				nocache_headers();
				wp_redirect( admin_url( 'options-general.php?page=tt_auth&tt_denied=1' ) );
				exit;
			}
		}

		// Un-authentication request:
		if ( isset( $_POST[ 'tt_unauthenticate' ] ) ) {
			check_admin_referer ( "tt_unauthenticate_{$this->creds[ 'user_id' ]}", '_tt_unauth_nonce_field' );
			$unset_creds = array(
				'oauth_token',
				'oauth_token_secret',
				'user_id',
				'screen_name',
				'authenticated',
			);
			$this->unset_creds( $unset_creds );

			nocache_headers();
			wp_redirect( admin_url( 'options-general.php?page=tt_auth&tt_unauthenticated=1' ) );
			exit;			
		}
		
		// Authentication request:
		if ( ! $this->creds[ 'authenticated' ] && isset( $_POST[ 'tt_authenticate' ] ) ) {
		
			if ( isset( $_POST[ '_cftp_tt_nonce_field' ] ) )
				check_admin_referer ( 'tt_authenticate', '_tt_auth_nonce_field' );

			$connection = $this->oauth_connection();
			$request_token_response = $connection->getRequestToken( admin_url( 'options-general.php?page=tt_auth' ) );
			
			$new_creds = array(
				'oauth_token'        => $request_token_response[ 'oauth_token' ],
				'oauth_token_secret' => $request_token_response[ 'oauth_token_secret' ],
			);
			$this->set_creds( $new_creds );

			nocache_headers();
			$authorize_url = $connection->getAuthorizeURL( $this->creds[ 'oauth_token' ] );
			wp_redirect( $authorize_url );
			exit;
		}
		
		// Partway through the authentication:
		if ( ! $this->creds[ 'authenticated' ] && $this->is_authentication_response() ) {
			$connection = $this->oauth_connection();
			$params = array(
				'oauth_token' => $this->creds[ 'oauth_token' ],
			);
			$oauth_verifier = isset( $_GET[ 'oauth_verifier' ] ) ? $_GET[ 'oauth_verifier' ] : false;
			$access_token_response = $connection->getAccessToken( $oauth_verifier, $params );

			$creds_option = get_option( 'tt_twitter_creds', array() );
			$new_creds = array(
				'oauth_token'        => $access_token_response[ 'oauth_token' ],
				'oauth_token_secret' => $access_token_response[ 'oauth_token_secret' ],
				'user_id'            => $access_token_response[ 'user_id' ],
				'screen_name'        => $access_token_response[ 'screen_name' ],
				'authenticated'      => true,
			);
			$this->set_creds( $new_creds );

			nocache_headers();
			wp_redirect( admin_url( 'options-general.php?page=tt_auth&tt_authenticated=1' ) );
			exit;			
		}

		// No authentication process in progress

	}

	public function admin_notices() {
		if ( isset( $_GET[ 'tt_authenticated' ] ) )
			printf( '<div class="updated"><p>%s</p></div>', sprintf( __( 'Thank you for authenticating <strong>@%s</strong> with Twitter', 'twitter-tracker' ), $this->creds[ 'screen_name' ] ) );
		if ( isset( $_GET[ 'tt_unauthenticated' ] ) )
			printf( '<div class="updated"><p>%s</p></div>', sprintf( __( 'You have remove the authorisation with Twitter', 'twitter-tracker' ), $this->creds[ 'screen_name' ] ) );
		if ( isset( $_GET[ 'tt_denied' ] ) )
			printf( '<div class="error"><p>%s</p></div>', sprintf( __( 'Authorisation with Twitter was <strong>not</strong> completed.', 'twitter-tracker' ), $this->creds[ 'screen_name' ] ) );
	}	
	
	public function queue_new_mentions() {
		$this->init_oauth();

		if ( ! $users = $this->oauth->get_users() )
			return;
		
		foreach ( $users as $user_id => & $user ) {
			$args = array( 'include_entities' => 'true', 'count' => 100 );
			if ( isset( $user[ 'last_mention_id' ] ) && $user[ 'last_mention_id' ] ) {
				$args[ 'since_id' ] = $user[ 'last_mention_id' ];
			}
			if ( $mentions = $this->oauth->get_mentions( $user_id, $args ) ) {
				$queued_mentions = (array) get_option( 'twtwchr_queued_mentions', array() );
				foreach ( $mentions as & $mention ) {
					// error_log( "TW: Queue $mention->id_str" );
					array_unshift( $queued_mentions, $mention );
				}
				update_option( 'twtwchr_queued_mentions', $queued_mentions );
				$last_mention = array_shift( $mentions );
				$this->oauth->set_user_property( $user_id, 'last_mention_id', $last_mention->id_str );
			}
		}
		$this->process_mentions();
	}
	
	public function queue_new_tweets() {
		$this->init_oauth();

		if ( ! $users = $this->oauth->get_users() )
			return;
		
		foreach ( $users as $user_id => & $user ) {
			$args = array( 'include_entities' => 'true', 'include_rts' => 'true', 'contributor_details' => 'true', 'count' => 100 );
			if ( isset( $user[ 'last_tweet_id' ] ) && $user[ 'last_tweet_id' ] ) {
				$args[ 'since_id' ] = $user[ 'last_tweet_id' ];
			}
			if ( $tweets = $this->oauth->get_tweets( $user_id, $args ) ) {
				$queued_tweets = (array) get_option( 'twtwchr_queued_tweets', array() );
				foreach ( $tweets as & $tweet ) {
					array_unshift( $queued_tweets, $tweet );
				}
				update_option( 'twtwchr_queued_tweets', $queued_tweets );
				$last_tweet = array_shift( $tweets );
				$this->oauth->set_user_property( $user_id, 'last_tweet_id', $last_tweet->id_str );
			}
		}
		$this->process_tweets();
	}
	
	// CALLBACKS
	// =========
	
	public function settings() {


		var_dump( $this->creds );
		if ( $this->creds[ 'authenticated' ] ) {
			$vars = array();
			$vars[ 'authenticated' ] = $this->creds[ 'authenticated' ];
			$vars[ 'user_id' ]       = $this->creds[ 'user_id' ];
			$vars[ 'screen_name' ]   = $this->creds[ 'screen_name' ];
			$this->view_unauthenticate( $vars );
		} else {
			$this->view_authenticate( array() );
		}

	}

	// "VIEWS"
	// =======

	public function view_unauthenticate( $vars ) {
		var_dump( $vars );
		
		extract( $vars );
?>

<div class="wrap">
	
	<?php screen_icon(); ?>
	<h2 class="title">Twitter Tracker</h2>

	<p><?php _e( 'Note that the restrictions registered for this plugin with Twitter mean that it can only read your tweets and mentions, and see your followers, it cannot tweet on your behalf or see DMs.', 'twitter-tracker' ); ?></p>
		
	<?php if ( $authenticated ) : ?>
	
		<form action="" method="post">
			<?php wp_nonce_field( "tt_unauthenticate_$user_id", '_tt_unauth_nonce_field' ); ?>

			<p>
				<?php 
					printf( 
						__( 'You are currently accessing Twitter as the following user: %s', 'twitter-tracker' ), 
						'<a href="http://twitter.com/<?php echo esc_attr( $screen_name ); ?>">@' . esc_html( $screen_name ) . '</a> ' . get_submit_button( __( 'Remove Authentication', 'twitter-tracker' ), 'delete', 'tt_unauthenticate', false )
					);
				?> 
			</p>

		</form>
	
	<?php endif; ?>
	
</div>

<?php
	}

	public function view_authenticate( $vars ) {
		extract( $vars );
?>

<div class="wrap">
	
	<?php screen_icon(); ?>
	<h2 class="title"><?php _e( 'Twitter Tracker', 'twitter-tracker' ); ?></h2>
	
	<p><?php _e( 'Note that the restrictions registered for this plugin with Twitter mean that it can only read your tweets and mentions, and see your followers, it cannot tweet on your behalf or see DMs.', 'twitter-tracker' ); ?></p>

	<form action="" method="post">
		<?php wp_nonce_field( 'tt_authenticate', '_tt_auth_nonce_field' ); ?>
		<p>
			<?php 
				printf( 
					__( 'In order to read and display Twitter searches and user tweets, we need you to authorise Twitter Tracker with Twitter: %s', 'twitter-tracker' ),
					get_submit_button( __( 'Authorise with Twitter', 'twitter-tracker' ), null, 'tt_authenticate', false )
				); ?>
		</p>

	</form>
	
</div>


<?php
	}

	// UTILITIES
	// =========

	public function oauth_connection() {
		require_once( 'class.oauth.php' );
		require_once( 'class.wp-twitter-oauth.php' );
		return new WP_Twitter_OAuth( 
			$this->creds[ 'consumer_key' ], 
			$this->creds[ 'consumer_secret' ],
			$this->creds[ 'oauth_token' ],
			$this->creds[ 'oauth_token_secret' ]
		);
	}

	public function load_creds() {
		$creds_defaults = array(
			'consumer_key'       => 'XV7HZZKjYpPtGwhsTZY6A',
			'consumer_secret'    => 'etSpBLB6951otLgmAsKP67oV7ALKe8ipAaKe5OIyU',
			'oauth_token'        => null,
			'oauth_token_secret' => null,
			'authenticated'      => false,
			'user_id'            => null,
			'screen_name'        => null,
		);
		$creds_option = get_option( 'tt_twitter_creds', array() );
		$this->creds = wp_parse_args( $creds_option, $creds_defaults );
	}

	public function set_creds( $new_creds ) {
		$current_creds = get_option( 'tt_twitter_creds', array() );
		unset( $current_creds[ 'consumer_key' ] );
		unset( $current_creds[ 'consumer_secret' ] );
		update_option( 'tt_twitter_creds', wp_parse_args( $new_creds, $current_creds ) );
		$this->load_creds();
	}

	public function unset_creds( $names ) {
		$creds = get_option( 'tt_twitter_creds', array() );
		unset( $creds[ 'consumer_key' ] );
		unset( $creds[ 'consumer_secret' ] );
		foreach ( $names as $name )
			unset( $creds[ $name ] );
		update_option( 'tt_twitter_creds', $creds );
		$this->load_creds();
	}

	public function is_authentication_response() {
		return isset( $_GET[ 'oauth_token' ] );
	}
	
	public function process_tweets() {
		// Try to give outselves a 4 minute execution time to play with,
		// bearing in mind that the Cron job is every five minutes.
		set_time_limit( 4*60 );
		while( $queued_tweets = get_option( 'twtwchr_queued_tweets', array() ) ) {
			$tweet = array_pop( $queued_tweets );

			$tweet->html_text = $tweet->text;
			$is_protected = $tweet->user->protected;
			$is_reply = (bool) ( isset( $tweet->in_reply_to_status_id_str ) && $tweet->in_reply_to_status_id_str );
			$is_retweet = $tweet->retweeted;

			$hashtags = wp_list_pluck( $tweet->entities->hashtags, 'text' );
			$tweet = $this->make_hashtags_links( $tweet, $hashtags );

			$user_mentions = $this->extract_user_mentions( $tweet );
			$tweet = $this->make_user_mentions_links( $tweet, $user_mentions );

			$urls = wp_list_pluck( $tweet->entities->urls, 'expanded_url' );
			$tweet = $this->make_urls_links( $tweet );

			do_action( 'twtwchr_tweet', $tweet, $tweet->id_str, $is_reply, $is_retweet, $is_protected, $hashtags, $user_mentions, $urls );

			update_option( 'twtwchr_queued_tweets', $queued_tweets );
		}
//		exit;
	}
	
	public function process_mentions() {
		// Try to give outselves a 4 minute execution time to play with,
		// bearing in mind that the Cron job is every five minutes.
		set_time_limit( 4*60 );
		while( $queued_mentions = get_option( 'twtwchr_queued_mentions', array() ) ) {
			$tweet = array_pop( $queued_mentions );

			$tweet->html_text = $tweet->text;
			$is_protected = $tweet->user->protected;

			$hashtags = wp_list_pluck( $tweet->entities->hashtags, 'text' );
			$tweet = $this->make_hashtags_links( $tweet, $hashtags );

			$user_mentions = $this->extract_user_mentions( $tweet );
			$tweet = $this->make_user_mentions_links( $tweet, $user_mentions );

			$urls = wp_list_pluck( $tweet->entities->urls, 'expanded_url' );
			$tweet = $this->make_urls_links( $tweet );
			
			do_action( 'twtwchr_mention', $tweet, $tweet->id_str, $is_protected, $hashtags, $user_mentions, $urls );

			update_option( 'twtwchr_queued_mentions', $queued_mentions );
			unset( $queued_mentions );
		}
	}
	
	public function make_hashtags_links( $tweet, $hashtags ) {
		// Make links from the hashtags in the tweet text
		$search = array();
		$replace = array();
		foreach ( $hashtags as & $s ) {
			$hashtag = "#$s";
			$args = array( 'q' => rawurlencode( $hashtag ) );
			$hashtag_url = add_query_arg( $args, 'https://twitter.com/search/realtime/' );
			$search[] = $hashtag;
			$replace[] = '<a href="' . esc_url( $hashtag_url ) . '">' . esc_html( $hashtag ) . '</a>';
		}
		$tweet->html_text = str_replace( $search, $replace, $tweet->html_text );
		return $tweet;
	}

	public function extract_user_mentions( $tweet ) {
		$user_mentions = array();
		foreach ( $tweet->entities->user_mentions as & $user_mention )
			$user_mentions[ $user_mention->screen_name ] = $user_mention->name;
		return $user_mentions;
	}
	
	public function make_user_mentions_links( $tweet, $user_mentions ) {
		// Make links from the user mentions in the tweet text
		$search = array();
		$replace = array();
		foreach ( $user_mentions as $screen_name => $name ) {
			$user = "@$screen_name";
			$user_url = "https://twitter.com/$screen_name";
			$search[] = $user;
			$replace[] = '<a href="' . esc_url( $user_url ) . '" title="' . esc_attr( $name ) . '">' . esc_html( $user ) . '</a>';
		}
		$tweet->html_text = str_replace( $search, $replace, $tweet->html_text );
		return $tweet;
	}
	
	public function make_urls_links( $tweet ) {
		// Make links from the URLs in the tweet text
		$search = wp_list_pluck( $tweet->entities->urls, 'url' );
		$replace = array();
		foreach ( $tweet->entities->urls as & $url )
			$replace[] = '<a href="' . esc_url( $url->expanded_url ) . '">' . esc_html( $url->display_url ) . '</a>';
		$tweet->html_text = str_replace( $search, $replace, $tweet->html_text );
		return $tweet;
	}
	
	/**
	 * We cannot sanitise some ID strings by converting to integers, as 
	 * they will overflow 32 bit systems and corrupt data. This method
	 * sanitises without converting to ints.
	 * 
	 * @param string $id_str An integer ID represented as a string to be sanitised
	 * @return string A sanitised integer ID 
	 */
	public function sanitise_id_str( $id_str ) {
		$id_str = preg_replace( '/[^\d]/', '', (string) $id_str );
		return $id_str;
	}
	
	/**
	 * Checks the DB structure is up to date.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	function maybe_upgrade() {
		return;
		global $wpdb;
		$option_name = 'twtwchr_version';
		$version = get_option( $option_name, 0 );

		if ( $version == $this->version )
			return;

		delete_option( "{$option_name}_running", true, null, 'no' );
		if ( $start_time = get_option( "{$option_name}_running", false ) ) {
			$time_diff = time() - $start_time;
			// Check the lock is less than 30 mins old, and if it is, bail
			if ( $time_diff < ( 60 * 30 ) ) {
				error_log( "Tweet Watcher: Existing update routine has been running for less than 30 minutes" );
				return;
			}
			error_log( "Tweet Watcher: Update routine is running, but older than 30 minutes; going ahead regardless" );
		} else {
			update_option( "{$option_name}_running", time(), null, 'no' );
		}

		if ( $version < 2 ) {
			wp_clear_scheduled_hook( 'twtwchr_queue_mentions' );
			wp_schedule_event( time(), 'twtwchr_check_interval', 'twtwchr_queue_mentions' );
			error_log( "Tweet Watcher: Setup cron job for mentions." );
		}

		if ( $version < 3 ) {
			wp_clear_scheduled_hook( 'twtwchr_queue_new_tweets' );
			wp_schedule_event( time(), 'twtwchr_check_interval', 'twtwchr_queue_new_tweets' );
			error_log( "Tweet Watcher: Setup cron job for new tweets." );
		}

		update_option( $option_name, $this->version );
		delete_option( "{$option_name}_running", true, null, 'no' );
		error_log( "Tweet Watcher: Done upgrade" );
	}

	
}

TT_Twitter_Authentication::init();

