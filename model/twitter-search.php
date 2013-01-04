<?php

class TwitterSearch
{
	protected $encoded_query;
	protected $search_url;
	protected $max_tweets;
	protected $hide_replies;
	protected $mandatory_hash;
	protected $tweets;
	protected $feed;

	public function __construct( $query, $max_tweets, $hide_replies, $mandatory_hash = false )
	{
		$this->load_simplepie();
		$this->construct_search_url( $query );
		$this->max_tweets = $max_tweets;
		$this->hide_replies = $hide_replies;
		if ( $mandatory_hash && substr( $mandatory_hash, 0, 1 ) != '#' )
			$mandatory_hash = '#' . $mandatory_hash;
		$this->mandatory_hash = $mandatory_hash;
	}

	public function tweets()
	{
		$this->do_search();
		return (array) $this->tweets;
	}
	
	protected function construct_search_url( $query )
	{
		// Effing slashes
		$query = stripslashes( $query );
		$args = array();
		$args[ 'q' ] = rawurlencode( $query );
		$args[ 'rpp' ] = 50;
		$base_url = 'http://search.twitter.com/search.atom';
		$this->search_url = add_query_arg( $args, $base_url );
	}
	
	protected function do_search()
	{
		// N.B. Cache is implemented by SimplePie. Phew.
		$this->fetch_feed();

		// Retrieve number of tweets from the options
		$max_tweets = (int) @ $this->max_tweets;
		if ( ! $max_tweets ) $max_tweets = 3;


		foreach ( $this->feed->get_items() as $item ) {
			
			// Check for @ replies
			if ( $this->hide_replies && $this->is_reply( $item->get_content() ) )
				continue;

			// Check for mandatory #hashtags
			if ( $this->mandatory_hash && ! $this->has_mandatory_hash( $item->get_content() ) )
				continue;

			// Put the tweets together
			$author = $item->get_author();
			// The title contains non-HTMLified text
			$content = $item->get_title();
			// Sanitise the content
			$content = wp_kses_data( $content );
			// Wrap all URLs in a link
			$content = make_clickable( $content );
			// Remove nofollow attributes added by make_clickable
			$content = str_replace( array( ' rel="nofollow"', " rel='nofollow'" ), '', $content );
			// En-link-ify all the Twitter usernames in the content
			$content = preg_replace( '/(^|[^a-z0-9_])@([a-z0-9_]+)/i', '$1<a href="http://twitter.com/$2">@$2</a>', $content );
			// En-link-ify all the hashtags in the content
			$content = preg_replace( '/(^|\s)#(\w*[a-z_]+\w*)/i', '\1<a href="http://search.twitter.com/search?q=%23\2">#\2</a>', $content );
			$this->tweets[] = new Tweet(
					$content,
					$item->get_permalink(),
					$item->get_date( 'U' ),
					$author->get_name(),
					$author->get_link(),
					$item->get_link( 0, 'image' )
				);
			// Don't stop til we've got enough
			if ( count( $this->tweets ) == $max_tweets ) break;
		}
		
	}
	
	protected function fetch_feed()
	{
		require_once (ABSPATH . WPINC . '/class-feed.php');

		$this->feed = new SimplePie();
		$this->feed->set_feed_url(  $this->search_url );
		$this->feed->set_cache_class( 'WP_Feed_Cache' );
		$this->feed->set_file_class( 'WP_SimplePie_File' );
		$cache_duration = apply_filters( 'tt_cache_duration', 60 * 15 ); // Default 15 mins
		$cache_duration = apply_filters( 'tt_search_cache_duration', $cache_duration );
		$this->feed->set_cache_duration( $cache_duration );
		$this->feed->init();
		$this->feed->handle_content_type();

		if ( $this->feed->error() ) {
			error_log( "Twitter Tracker Feed Error: " . $this->feed->error() );
			return new WP_Error('simplepie-error', $this->feed->error());
		}

		return $this->feed;
	}
	
	protected function load_simplepie()
	{
		if ( !class_exists('SimplePie') ) 
			require_once (ABSPATH . WPINC . '/class-simplepie.php');
		if ( class_exists( 'SimplePie' ) ) 
			return true;
		return false;
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
