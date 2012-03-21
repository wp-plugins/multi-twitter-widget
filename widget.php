<?php
/*
Plugin Name: Multi Twitter Stream
Plugin URI: http://incbrite.com/
Description: A widget for multiple twitter accounts
Author: Clayton McIlrath
Version: 1.4.2
Author URI: http://thinkclay.com
*/
 
/*
TODO:
- Link hyperlinks in formatTwitter()
- Options for order arrangement (chrono, alpha, etc)
*/
function human_time($datefrom, $dateto = -1)
{
	// Defaults and assume if 0 is passed in that its an error rather than the epoch
	if ( $datefrom <= 0 )
		return "A long time ago";
		
	if ( $dateto == -1) { 
		$dateto = time(); 
	}
	
	// Calculate the difference in seconds betweeen the two timestamps
	$difference = $dateto - $datefrom;
	
	// If difference is less than 60 seconds use 'seconds'
	if ( $difference < 60 )
	{ 
		$interval = "s"; 
	}
	// If difference is between 60 seconds and 60 minutes use 'minutes'
	else if ( $difference >= 60 AND $difference < (60*60) )
	{
		$interval = "n"; 
	}
	// If difference is between 1 hour and 24 hours use 'hours'
	else if ( $difference >= (60*60) AND $difference < (60*60*24) )
	{
		$interval = "h"; 
	}
	// If difference is between 1 day and 7 days use 'days'
	else if ( $difference >= (60*60*24) AND $difference < (60*60*24*7) )
	{
		$interval = "d"; 
	}
	// If difference is between 1 week and 30 days use 'weeks'
	else if ( $difference >= (60*60*24*7) AND $difference < (60*60*24*30) )
	{
		$interval = "ww";
	}
	// If difference is between 30 days and 365 days use 'months'
	else if ( $difference >= (60*60*24*30) AND $difference < (60*60*24*365) )
	{
		$interval = "m"; 
	}
	// If difference is greater than or equal to 365 days use 'years'
	else if ( $difference >= (60*60*24*365) )
	{
		$interval = "y"; 
	}
	
	// Based on the interval, determine the number of units between the two dates
	// If the $datediff returned is 1, be sure to return the singular
	// of the unit, e.g. 'day' rather 'days'
	switch ($interval)
	{
		case "m" :
			$months_difference = floor($difference / 60 / 60 / 24 / 29);
			
			while(
				mktime(date("H", $datefrom), date("i", $datefrom),
				date("s", $datefrom), date("n", $datefrom)+($months_difference),
				date("j", $dateto), date("Y", $datefrom)) < $dateto)
			{
				$months_difference++;
			}
			$datediff = $months_difference;
	
			// We need this in here because it is possible to have an 'm' interval and a months
			// difference of 12 because we are using 29 days in a month
			if ( $datediff == 12 )
			{ 
				$datediff--; 
			}
	
			$res = ($datediff==1) ? "$datediff month ago" : "$datediff months ago";
			
			break;
	
		case "y" :
			$datediff = floor($difference / 60 / 60 / 24 / 365);
			$res = ($datediff==1) ? "$datediff year ago" : "$datediff years ago";

			break;
	
		case "d" :
			$datediff = floor($difference / 60 / 60 / 24);
			$res = ($datediff==1) ? "$datediff day ago" : "$datediff days ago";
			
			break;
	
		case "ww" :
			$datediff = floor($difference / 60 / 60 / 24 / 7);
			$res = ($datediff==1) ? "$datediff week ago" : "$datediff weeks ago";

			break;
	
		case "h" :
			$datediff = floor($difference / 60 / 60);
			$res = ($datediff==1) ? "$datediff hour ago" : "$datediff hours ago";

			break;
	
		case "n" :
			$datediff = floor($difference / 60);
			$res = ($datediff==1) ? "$datediff minute ago" : "$datediff minutes ago";
	
			break;
	
		case "s":
			$datediff = $difference;
			$res = ($datediff==1) ? "$datediff second ago" : "$datediff seconds ago";
			
			break;
	}

	return $res;
}



function format_tweet($tweet, $options) 
{
	if ( $options['reply'] )
	    $tweet = preg_replace('/(^|\s)@(\w+)/', '\1@<a href="http://www.twitter.com/\2">\2</a>', $tweet);
	
	if ( $options['hash'] )
	    $tweet = preg_replace('/(^|\s)#(\w+)/', '\1#<a href="http://search.twitter.com/search?q=%23\2">\2</a>', $tweet);
	
	return $tweet;
}

	
function feed_sort($a, $b)
{
	if ( $a->status->created_at )
	{
		$a_t = strtotime($a->status->created_at);
		$b_t = strtotime($b->status->created_at);
	}
	else if ( $a->updated ) 
	{
		$a_t = strtotime($a->updated);
		$b_t = strtotime($b->updated);
	}
	
	if ( $a_t == $b_t ) 
		return 0 ;

    return ($a_t > $b_t ) ? -1 : 1; 
}

function multi_twitter($widget) 
{
	// Create our HTML output var to return
	$output = ''; 
	
	// Get our root upload directory and create cache if necessary
	$upload = wp_upload_dir();
	$upload_dir = $upload['basedir']."/cache";
	
	if ( ! file_exists($upload_dir) )
	{
		if ( ! mkdir($upload_dir) )
		{
			$output .= '<span style="color: red;">could not create dir'.$upload_dir.' please create this directory</span>';

			return $output;
		}
	}
	
	$accounts = explode(" ", $widget['users']);
	$terms = explode(", ", $widget['terms']);
	
	if ( ! $widget['user_limit'] )
	{
		$widget['user_limit'] = 5; 
	} 
	
	if ( ! $widget['term_limit'] )
	{ 
		$widget['term_limit'] = 5; 
	} 
	
	$output .= '<ul>';
		
	// Parse the accounts and CRUD cache
	foreach ( $accounts as $account )
	{
		$cache = false; // Assume the cache is empty
		$cFile = "$upload_dir/users_$account.xml";
	
		if ( file_exists($cFile) ) 
		{
			$modtime = filemtime($cFile);		
			$timeago = time() - 1800; // 30 minutes ago
			if ( $modtime < $timeago )
			{
				// Set to false just in case as the cache needs to be renewed
				$cache = false; 
			} 
			else 
			{
				// The cache is not too old so the cache can be used.
				$cache = true; 
			}
		}
		
		if ( $cache === false ) 
		{
			// curl the account via XML to get the last tweet and user data
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, "http://twitter.com/users/$account.xml");
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$content = curl_exec($ch);
			curl_close($ch);
			
			// Createa an XML object from curl'ed content
			$xml = new SimpleXMLElement($content);
			$feeds[] = $xml;
			
			if ( $content === false ) 
			{
				// Content couldn't be retrieved... Do something..
				$output .= '<li style="color: red;">Content could not be retrieved. Twitter API failed...</li>';
			}
		
			// Let's save our data into uploads/cache/
			$fp = fopen($cFile, 'w');
			if ( ! $fp )
			{
				$output .= '<li style="color: red;">Permission to write cache dir to <em>'.$cFile.'</em> not granted</li>';
			}
			else 
			{
				fwrite($fp, $content);
			}
			fclose($fp);
		} 
		else
		{
			//cache is true let's load the data from the cached file
			$xml = simplexml_load_file($cFile);
			$feeds[] = $xml;
		}
	}
	
	// Parse the terms and CRUD cache
	foreach ( $terms as $term )
	{
		$cache = false; // Assume the cache is empty
		$cFile = "$upload_dir/term_$term.xml";
	
		if ( file_exists($cFile) )
		{
			$modtime = filemtime($cFile);
			$timeago = time() - 1800; // 30 minutes ago
			if ( $modtime < $timeago )
			{
				// Set to false just in case as the cache needs to be renewed
				$cache = false; 
			}
			else
			{
				// The cache is not too old so the cache can be used.
				$cache = true; 
			}
		}
		
		if ( $cache === false ) 
		{				
			// curl the account via XML to get the last tweet and user data
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, "http://search.twitter.com/search.atom?q=$term");
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$content = curl_exec($ch);
			curl_close($ch);
			
			// Createa an XML object from curl'ed content
			$xml = new SimpleXMLElement($content);
			$feeds[] = $xml;
			
			if ( $content === false )
			{
				// Content couldn't be retrieved... Do something..
				$output .= '<li>Content could not be retrieved. Twitter API failed...</li>';
			}
			else 
			{
				// Let's save our data into uploads/cache/twitter/
				$fp = fopen($cFile, 'w');
				if ( ! $fp )
				{
					 $output .= '<li style="color: red;">Permission to write cache dir to <em>'.$cFile.'</em> not granted</li>';
				} 
				else 
				{ 
					fwrite($fp, $content); 
				}
				fclose($fp);
			}
		} 
		else 
		{
			//cache is true let's load the data from the cached file
			$xml = simplexml_load_file($cFile);
			$feeds[] = $xml;
		}
	}
	
	// Sort our $feeds array
	usort($feeds, "feed_sort");
	
	// Split array and output results
	$i = 1;
	
	foreach ( $feeds as $feed )
	{
		if ( $feed->screen_name != '' AND $i <= $widget['user_limit'] )
		{
			$output .= 
				'<li class="clearfix">'.
					'<a href="http://twitter.com/'.$feed->screen_name.'">'.
						'<img class="twitter-avatar" src="'.$feed->profile_image_url.'" width="40" height="40" alt="'.$feed->screen_name.'" />'.
						$feed->screen_name.': </a>'.format_tweet($feed->status->text, $widget).'<br />';
						
			if ( $widget['date'] )
			{ 
				$output .= '<em>'.human_time(strtotime($feed->status->created_at)).'</em>'; 
			}
			
			$output .= '</li>';
		}
		else if ( preg_match('/search.twitter.com/i', $feed->id) AND $i <= $widget['term_limit'] )
		{
			$count = count($feed->entry);
			
			for ( $i=0; $i<$count; $i++ )
			{
				if ( $i <= $widget['term_limit'] )
				{
					$output .= 
						'<li class="clearfix">'.
							'<a href="'.$feed->entry[$i]->author->uri.'">'.
								'<img class="twitter-avatar" '.
									'src="'.$feed->entry[$i]->link[1]->attributes()->href.'" '.
									'width="40" height="40" '.
									'alt="'.$feed->entry[$i]->author->name.'" />'.
								'<strong>'.$feed->entry[$i]->author->name.':</strong>'.
							'</a>'.
							format_tweet($feed->entry[$i]->content, $widget).
							'<br />';
							
					if ( $widget['date'] )
					{ 
						$output .= '<em>'.human_time(strtotime($feed->status->created_at)).'</em>'; 
					}
					$output .= '</li>';
				}	
			}
		}	
		$i++;
	}
	
	$output .= '</ul>';
	
	if ( $widget['credits'] === true )
	{
		$output .= 
			'<hr />'.
			'<strong>powered by</strong> '.
			'<a href="http://incbrite.com/services/wordpress-plugins" target="_blank">Incbrite Wordpress Plugins</a>';
	}
	
	if ( $widget['styles'] === true )
	{
		$output .= 
			'<style type="text/css">'.
			'.twitter-avatar { clear: both; float: left; padding: 6px 12px 2px 0; }'.
			'</style>';
	}	
	
	echo $output;
}

function widget_multi_twitter($args) 
{
	extract($args);

	$options = get_option("widget_multi_twitter");
	
	if ( ! is_array($options)) 
	{	 
		$options = array(
			'title' 		=> 'Multi Twitter',
			'users' 		=> 'arraecreative incbrite thinkclay',
			'terms' 		=> 'wordpress',
			'user_limit' 	=> 5,
			'term_limit' 	=> 5,
			'links' 		=> true,
			'reply' 		=> true,
			'hash'			=> true,
			'date'			=> true,
			'credits'		=> true,
			'styles'		=> true
		);  
	}  

	echo $before_widget;
	echo $before_title;
    echo $options['title'];
	echo $after_title;

	multi_twitter($options);
	
	echo $after_widget;
}

function multi_twitter_control() 
{
	$options = get_option("widget_multi_twitter");
	
	if ( ! is_array($options)) 
	{ 
		$options = array(
			'title' 		=> 'Multi Twitter',
			'users' 		=> 'thinkclay bychosen',
			'terms' 		=> 'wordpress',
			'user_limit' 	=> 5,
			'term_limit' 	=> 5,
			'links' 		=> true,
			'reply'			=> true,
			'hash'			=> true,
			'date'			=> true,
			'credits'		=> true,
			'styles'		=> true
		); 
	}  

	if ( $_POST['multi_twitter-Submit'] ) 
	{
		$options['title'] = htmlspecialchars($_POST['multi_twitter-Title']);
		$options['users'] = htmlspecialchars($_POST['multi_twitter-Users']);
		$options['terms'] = htmlspecialchars($_POST['multi_twitter-Terms']);
		$options['user_limit'] = $_POST['multi_twitter-UserLimit'];
		$options['term_limit'] = $_POST['multi_twitter-TermLimit'];
		
		$options['hash']	= ($_POST['multi_twitter-Hash']) ? true : false;
		$options['reply']	= ($_POST['multi_twitter-Reply']) ? true : false;
		$options['links']	= ($_POST['multi_twitter-Links']) ? true : false;
		$options['date']	= ($_POST['multi_twitter-Date']) ? true : false;
		$options['credits']	= ($_POST['multi_twitter-Credits']) ? true : false;
		$options['styles']	= ($_POST['multi_twitter-Styles']) ? true : false;
		
		update_option("widget_multi_twitter", $options);
	}
?>
	<p>
		<label for="multi_twitter-Title">Widget Title: </label><br />
		<input type="text" class="widefat" id="multi_twitter-Title" name="multi_twitter-Title" value="<?php echo $options['title']; ?>" />
	</p>
	<p>	
		<label for="multi_twitter-Users">Users: </label><br />
		<input type="text" class="widefat" id="multi_twitter-Users" name="multi_twitter-Users" value="<?php echo $options['users']; ?>" /><br />
		<small><em>enter accounts separated with a space</em></small>
	</p>
	<p>
		<label for="multi_twitter-Terms">Search Terms: </label><br />
		<input type="text" class="widefat" id="multi_twitter-Terms" name="multi_twitter-Terms" value="<?php echo $options['terms']; ?>" /><br />
		<small><em>enter search terms separated with a comma</em></small>
	</p>
	<p>
		<label for="multi_twitter-UserLimit">Limit user feed to: </label>
		<select id="multi_twitter-UserLimit" name="multi_twitter-UserLimit">
			<option value="<?php echo $options['user_limit']; ?>"><?php echo $options['user_limit']; ?></option>
			<option value="1">1</option>
			<option value="2">2</option>
			<option value="3">3</option>
			<option value="4">4</option>
			<option value="5">5</option>
			<option value="6">6</option>
			<option value="7">7</option>
			<option value="8">8</option>
			<option value="9">9</option>
			<option value="10">10</option>
		</select>
	</p>
	<p>
		<label for="multi_twitter-TermLimit">Limit search feed to: </label>
		<select id="multi_twitter-TermLimit" name="multi_twitter-TermLimit">
			<option value="<?php echo $options['term_limit']; ?>"><?php echo $options['term_limit']; ?></option>
			<option value="1">1</option>
			<option value="2">2</option>
			<option value="3">3</option>
			<option value="4">4</option>
			<option value="5">5</option>
			<option value="6">6</option>
			<option value="7">7</option>
			<option value="8">8</option>
			<option value="9">9</option>
			<option value="10">10</option>
		</select>
	</p>
	<p>
		<label for="multi_twitter-Links">Automatically convert links?</label>
		<input type="checkbox" name="multi_twitter-Links" id="multi_twitter-Links" <?php if ($options['links']) echo 'checked="checked"'; ?> />
	</p>
	<p>
		<label for="multi_twitter-Reply">Automatically convert @replies?</label>
		<input type="checkbox" name="multi_twitter-Reply" id="multi_twitter-Reply" <?php if ($options['reply']) echo 'checked="checked"'; ?> />
	</p>
	<p>
		<label for="multi_twitter-Hash">Automatically convert #hashtags?</label>
		<input type="checkbox" name="multi_twitter-Hash" id="multi_twitter-Hash" <?php if ($options['hash']) echo 'checked="checked"'; ?> />
	</p>
	<p>
		<label for="multi_twitter-Date">Show Date?</label>
		<input type="checkbox" name="multi_twitter-Date" id="multi_twitter-Date" <?php if ($options['date']) echo 'checked="checked"'; ?> />
	</p>
	<p>
		<label for="multi_twitter-Credits">Show Credits?</label>
		<input type="checkbox" name="multi_twitter-Credits" id="multi_twitter-Credits" <?php if ($options['credits']) echo 'checked="checked"'; ?> />
	</p>
	<p>
		<label for="multi_twitter-Styles">Use Default Styles?</label>
		<input type="checkbox" name="multi_twitter-Styles" id="multi_twitter-Styles" <?php if ($options['styles']) echo 'checked="checked"'; ?> />
	</p>
	<p><input type="hidden" id="multi_twitter-Submit" name="multi_twitter-Submit" value="1" /></p>
<?php
}

function multi_twitter_init() 
{
	register_sidebar_widget('Multi Twitter', 'widget_multi_twitter');
	register_widget_control('Multi Twitter', 'multi_twitter_control', 250, 250);	
}

add_action("plugins_loaded", "multi_twitter_init");
?>