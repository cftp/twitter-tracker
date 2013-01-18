<?php


// N.B. This class is extended by ApiTweet class (need to create an abstract base class for both tweet types,
// they mostly differ in the way they are constructed).
class Tweet
{
	public $content;
	public $link;
	public $timestamp;
	public $date;
	public $twit;
	public $twit_name;
	public $twit_link;
	public $twit_pic;
	public $twit_uid;
	public $retweeted;
	public $original_twit;
	
	public function __construct( $content, $link, $timestamp, $twit, $twit_link, $twit_pic, $retweeted = false, $original_twit_uid = false ) {
		// General tweet properties
		$this->content = $content;
		$this->link = $link;
		$this->timestamp = $timestamp;
		$this->set_tweet_date();
		$this->twit = $twit;
		$this->twit_link = $twit_link;
		$this->set_twit_uid();
		$this->set_twit_name();
		$this->retweeted = false;
		$this->original_twit_uid = false;
		$this->construct_twit_avatar_links();
	}
	
	/**
	 * Ripped off from the bb_since bbPress function
	 */
	public function time_since( $do_more = 0 ) {
		$today = time();

		// array of time period chunks
		$chunks = array(
			( 60 * 60 * 24 * 365 ), // years
			( 60 * 60 * 24 * 30 ),  // months
			( 60 * 60 * 24 * 7 ),   // weeks
			( 60 * 60 * 24 ),       // days
			( 60 * 60 ),            // hours
			( 60 ),                 // minutes
			( 1 )                   // seconds
		);

		$since = $today - $this->timestamp;

		for ($i = 0, $j = count($chunks); $i < $j; $i++) {
			$seconds = $chunks[$i];

			if ( 0 != $count = floor($since / $seconds) )
				break;
		}

		$trans = array(
			$this->pluralise( __('%d year', 'twitter-tracker'), __('%d years', 'twitter-tracker'), $count ),
			$this->pluralise( __('%d month', 'twitter-tracker'), __('%d months', 'twitter-tracker'), $count ),
			$this->pluralise( __('%d week', 'twitter-tracker'), __('%d weeks', 'twitter-tracker'), $count ),
			$this->pluralise( __('%d day', 'twitter-tracker'), __('%d days', 'twitter-tracker'), $count ),
			$this->pluralise( __('%d hour', 'twitter-tracker'), __('%d hours', 'twitter-tracker'), $count ),
			$this->pluralise( __('%d minute', 'twitter-tracker'), __('%d minutes', 'twitter-tracker'), $count ),
			$this->pluralise( __('%d second', 'twitter-tracker'), __('%d seconds', 'twitter-tracker'), $count )
		);

		$basic = sprintf( $trans[$i], $count );

		if ( $do_more && $i + 1 < $j) {
			$seconds2 = $chunks[$i + 1];
			if ( 0 != $count2 = floor( ($since - $seconds * $count) / $seconds2) ) {
				$trans = array(
					$this->pluralise( __('a year', 'twitter-tracker'), __('%d years', 'twitter-tracker'), $count2 ),
					$this->pluralise( __('a month', 'twitter-tracker'), __('%d months', 'twitter-tracker'), $count2 ),
					$this->pluralise( __('a week', 'twitter-tracker'), __('%d weeks', 'twitter-tracker'), $count2 ),
					$this->pluralise( __('a day', 'twitter-tracker'), __('%d days', 'twitter-tracker'), $count2 ),
					$this->pluralise( __('an hour', 'twitter-tracker'), __('%d hours', 'twitter-tracker'), $count2 ),
					$this->pluralise( __('a minute', 'twitter-tracker'), __('%d minutes', 'twitter-tracker'), $count2 ),
					$this->pluralise( __('a second', 'twitter-tracker'), __('%d seconds', 'twitter-tracker'), $count2 )
				);
				$additional = sprintf( $trans[$i + 1], $count2 );
			}
			
			$final = sprintf( __( 'about %s, %s ago', 'twitter-tracker' ), $basic, $additional );
			return $final;
		}
		$final = sprintf( __( 'about %s ago', 'twitter-tracker' ), $basic );
		return $final;
	}
	
	protected function set_twit_uid() {
		$twit_uid = str_replace( 'http://twitter.com/', '', $this->twit_link );
		$this->twit_uid = $twit_uid;
	}
	
	// Expects something of the form "username (Real Name)"
	protected function set_twit_name()
	{
		// We now go to a lot of trouble, basically because I don't know regexes.
		// Lop off the username component
		$bits = explode( '(', $this->twit );
		array_shift( $bits );
		// Join back together with a '(' as glue, in case there were any of 
		// those in the "real name".
		$string = implode( '(', $bits );
		// Lop off the last character, which is a ")"
		$this->twit_name = substr( $string, 0, -1 );
	}
	
	// e.g. Jul 30, 2009 @ 10:01
	protected function set_tweet_date() {
		$this->date = date( 'M n, Y  @ G:i', $this->timestamp );
	}
	
	/**
	 * Make up the twit avatar (profile pic) links.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function construct_twit_avatar_links() {
		$twit_uid = $this->retweeted ? $this->original_twit_uid : $this->twit_uid;
		$twit_pic = sprintf( "http://api.twitter.com/1/users/profile_image/%s", $twit_uid );
		$this->twit_pic = apply_filters( 'tt_avatar_url', $twit_pic, $twit_uid, 48 );
		$twit_pic_bigger = sprintf( "http://api.twitter.com/1/users/profile_image/%s?size=bigger", $twit_uid );
		$this->twit_pic_bigger = apply_filters( 'tt_avatar_bigger_url', $twit_pic_bigger, $twit_uid, 73 );
	}

	protected function pluralise( $singular, $plural, $count ) {
		if ( $count == 0 || $count > 1 ) return $plural;
		return $singular;
	}

}


?>
