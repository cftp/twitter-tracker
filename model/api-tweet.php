<?php

require_once( 'tweet.php' );

// @TODO: Make it so we have a generic Tweet class to extend, rather than this current mess.
class ApiTweet extends Tweet
{

//	public function __construct( $content, $link = null, $timestamp, $twit, $twit_link, $twit_pic ) {
	public function __construct( $content, $tweet_id, $created_at, $screen_name, $name, $retweeted = false, $original_twit_uid = false ) {
		$this->content = $content;
		$this->linkify_content();
		$this->twit_uid = $screen_name;
		$this->tweet_id = $tweet_id;
		$this->construct_twit_link();
		$this->construct_tweet_link();
		$this->timestamp = strtotime( $created_at );
		$this->set_tweet_date();
		$this->twit_name = $name;
		$this->retweeted = $retweeted;
		$this->original_twit_uid = $original_twit_uid;
		$this->construct_twit_avatar_links();
	}

	/**
	 * Links up @usernames and #hashtags.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function linkify_content() {
		// Make URLs in message clickable
		$this->content = $this->make_clickable( $this->content );
		
		// En-link-ify all the #hashtags in the message text
		$hashtag_regex = '/(^|\s)#(\w*[a-zA-Z_]+\w*)/';
		preg_match_all( $hashtag_regex, $this->content, $preg_output );
		$this->content = preg_replace( $hashtag_regex, '\1<a href="http://search.twitter.com/search?q=%23\2">#\2</a>', $this->content );
		
		// En-link-ify all the @usernames in the message text
		$username_regex = '/(^\.?|\s|)\@(\w*[a-zA-Z_]+\w*)/';
		preg_match_all( $username_regex, $this->content, $preg_output );
		$this->content = preg_replace( $username_regex, '\1<a href="http://twitter.com/\2">@\2</a>', $this->content );

	}
	
	/**
	 * Make up the tweet link.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function construct_tweet_link() {
		$this->link = sprintf( "https://twitter.com/%s/status/%s", $this->twit_uid, $this->tweet_id );
	}
	
	/**
	 * Make up the twit link.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function construct_twit_link() {
		$this->twit_link = sprintf( "https://twitter.com/%s", $this->twit_uid );
	}

	/**
	 * Convert plaintext URI to HTML links.
	 *
	 * Converts URI, www and ftp, and email addresses. Finishes by fixing links
	 * within links.
	 *
	 * @param string $ret Content to convert URIs.
	 * @return string Content with converted URIs.
	 */
	protected function make_clickable( $ret ) {
		$ret = ' ' . $ret;
		// in testing, using arrays here was found to be faster
		$save = @ini_set('pcre.recursion_limit', 10000);
		$retval = preg_replace_callback('#(?<!=[\'"])(?<=[*\')+.,;:!&$\s>])(\()?([\w]+?://(?:[\w\\x80-\\xff\#%~/?@\[\]-]{1,2000}|[\'*(+.,;:!=&$](?![\b\)]|(\))?([\s]|$))|(?(1)\)(?![\s<.,;:]|$)|\)))+)#is', '_tt_make_url_clickable_cb', $ret);
		if (null !== $retval )
			$ret = $retval;
		@ini_set('pcre.recursion_limit', $save);
		$ret = preg_replace_callback('#([\s>])((www|ftp)\.[\w\\x80-\\xff\#$%&~/.\-;:=,?@\[\]+]+)#is', '_tt_make_web_ftp_clickable_cb', $ret);
		$ret = preg_replace_callback('#([\s>])([.0-9a-z_+-]+)@(([0-9a-z-]+\.)+[0-9a-z]{2,})#i', '_make_email_clickable_cb', $ret);
		// this one is not in an array because we need it to run last, for cleanup of accidental links within links
		$ret = preg_replace("#(<a( [^>]+?>|>))<a [^>]+?>([^>]+?)</a></a>#i", "$1$3</a>", $ret);
		$ret = trim($ret);
		return $ret;
	}

}

/**
 * Callback to convert URL match to HTML A element.
 *
 * This function was backported from 2.5.0 to 2.3.2. Regex callback for {@link
 * make_clickable()}.
 *
 * @access private
 *
 * @param array $matches Single Regex Match.
 * @return string HTML A element with URL address.
 */
function _tt_make_web_ftp_clickable_cb( $matches ) {
	$ret = '';
	$dest = $matches[2];
	$dest = 'http://' . $dest;
	$dest = esc_url($dest);
	if ( empty($dest) )
		return $matches[0];

	// removed trailing [.,;:)] from URL
	if ( in_array( substr($dest, -1), array('.', ',', ';', ':', ')') ) === true ) {
		$ret = substr($dest, -1);
		$dest = substr($dest, 0, strlen($dest)-1);
	}
	return $matches[1] . "<a href=\"$dest\">$dest</a>$ret";
}

/**
 * Callback to convert URI match to HTML A element.
 *
 * This function was backported from 2.5.0 to 2.3.2. Regex callback for {@link
 * make_clickable()}.
 *
 * @since 2.3.2
 * @access private
 *
 * @param array $matches Single Regex Match.
 * @return string HTML A element with URI address.
 */
function _tt_make_url_clickable_cb($matches) {
	$url = $matches[2];
	$suffix = '';

	/** Include parentheses in the URL only if paired **/
	while ( substr_count( $url, '(' ) < substr_count( $url, ')' ) ) {
		$suffix = strrchr( $url, ')' ) . $suffix;
		$url = substr( $url, 0, strrpos( $url, ')' ) );
	}

	$url = esc_url($url);
	if ( empty($url) )
		return $matches[0];

	return $matches[1] . "<a href=\"$url\">$url</a>" . $suffix;
}


?>