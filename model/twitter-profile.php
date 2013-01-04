<?php

class TwitterProfile
{
	protected $username;
	protected $profile_url;
	protected $max_tweets;
	protected $hide_replies;
	protected $tweets;
	protected $feed;

	public function __construct( $username, $max_tweets, $hide_replies, $include_retweets, $mandatory_hash )
	{
		$this->username = $username;
		$this->max_tweets = $max_tweets;
		$this->hide_replies = $hide_replies;
		$this->include_retweets = $include_retweets;
		$this->construct_profile_url();
		
		if ( $mandatory_hash && substr( $mandatory_hash, 0, 1 ) != '#' )
			$mandatory_hash = '#' . $mandatory_hash;
		$this->mandatory_hash = $mandatory_hash;
	}

	public function tweets()
	{
		$this->fetch_tweets();
		return (array) $this->tweets;
	}
	
	protected function construct_profile_url()
	{
		$include_retweets = $this->include_retweets ? 1 : 0;
		$url = 'http://api.twitter.com/1/statuses/user_timeline.json?screen_name=%s&count=%d&include_rts=%s';
		$this->profile_url = sprintf( $url, urlencode( $this->username ), (int) $this->max_tweets * 2, $include_retweets );
		
	}
	
	protected function fetch_tweets()
	{
		$this->max_tweets = (int) @ $this->max_tweets;
		if ( ! $this->max_tweets ) 
			$this->max_tweets = 3;

		// delete_option( 'twitter-tracker-profile' );
		$option_cache = get_option( 'twitter-tracker-profile', array() );
		if ( isset( $option_cache[ $this->username ] ) && $this->cache_fresh( $option_cache[ $this->username ] ) ) {
			$json = $option_cache[ $this->username ][ 'json' ];
		} else {
			$response = wp_remote_get( $this->profile_url );
			if ( is_wp_error( $response ) ) {
				error_log( "Twitter Tracker, Twitter API error: " . print_r( $response->get_error_messages(), true ) );
				return $response;
			}
			$json = trim( $response[ 'body' ], '();' );
			$option_cache[ $this->username ] = array( 
				'json' => $json,
				'timestamp' => time(),
			);
			update_option( 'twitter-tracker-profile', $option_cache );
		}
		$tweets_data = json_decode( $json );
		

		foreach ( $tweets_data as $tweet_data ) {
			
			// Check for @ replies
			if ( $this->hide_replies && $this->is_reply( $tweet_data->text ) )
				continue;

			// Check for mandatory #hashtags
			if ( $this->mandatory_hash && ! $this->has_mandatory_hash( $tweet_data->text ) )
				continue;

			// Put the tweets together
			$author = $tweet_data->user->screen_name;
			$original_twit_uid = false;
			// N.B. The retweeted property only records whether this tweet has been retweeted, not
			// whether this is a retweet
			if ( $retweeted = isset( $tweet_data->retweeted_status ) ) {
				$original_twit_uid = $tweet_data->retweeted_status->user->screen_name;
				$tweet_text = "RT @{$original_twit_uid}: {$tweet_data->retweeted_status->text}";
			} else {
				$tweet_text = $tweet_data->text;
			}
			
			$this->tweets[] = new ApiTweet(
					$tweet_text,
					$tweet_data->id_str,
					$tweet_data->created_at,
					$tweet_data->user->screen_name,
					$tweet_data->user->name,
					$retweeted,
					$original_twit_uid
				);
			
			// Don't stop til we've got enough
			if ( count( $this->tweets ) == $this->max_tweets )
				break;
		}

		return $this->tweets;
	}

	protected function is_reply( $content )
	{
		// Strip tags (we're being passed HTML)
		$content = strip_tags( $content );
		// Ensure no leading whitespace
		$content = trim( $content );
		// Is the first character an '@' sign?
		return ( substr( $content, 0, 1 ) == '@' );
	}
	
	/**
	 * Examines a cache and determines whether it's fresh enough,
	 * if too old then returns false..
	 *
	 * @param array $cache The cache in question, array( 'json' => cache contents, 'timestamp' => UNIX timestamp ) 
	 * @return boolean True if the cache is fresh enough
	 * @author Simon Wheatley
	 **/
	protected function cache_fresh( $cache ) {
		if ( ! isset( $cache[ 'timestamp' ] ) )
			return false;
		$ts = $cache[ 'timestamp' ];
		$max_age = apply_filters( 'tt_cache_duration', 60 * 5 ); // Default 5 mins
		$max_age = apply_filters( 'tt_profile_cache_duration', $max_age );
		return ( $ts > ( time() - $max_age ) );
	}

	protected function has_mandatory_hash( $content )
	{
		// Strip tags (we're being passed HTML)
		$content = strip_tags( $content );
		// Check for the hashtag
		$pos = stripos( $content, $this->mandatory_hash );
		return ( $pos !== false );
	}

}

?>