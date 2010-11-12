<?php
/*
Plugin Name: Multi Twitter Stream
Plugin URI: http://thinkclay.com/
Description: A widget for multiple twitter accounts
Author: Clayton McIlrath
Version: 1.3.3
Author URI: http://thinkclay.com
*/
 
/*
TODO:
- Link hyperlinks in formatTwitter()
- Options for order arrangement (chrono, alpha, etc)
*/
function TimeAgo($datefrom,$dateto=-1){
	// Defaults and assume if 0 is passed in that its an error rather than the epoch
	if($datefrom<=0) { return "A long time ago"; }
	if($dateto==-1) { $dateto = time(); }
	
	// Calculate the difference in seconds betweeen the two timestamps
	$difference = $dateto - $datefrom;
	
	// If difference is less than 60 seconds use 'seconds'
	if($difference < 60){ $interval = "s"; }
	
	// If difference is between 60 seconds and 60 minutes use 'minutes'
	elseif($difference >= 60 && $difference<60*60){ $interval = "n"; }
	
	// If difference is between 1 hour and 24 hours use 'hours'
	elseif($difference >= 60*60 && $difference<60*60*24){ $interval = "h"; }
	
	// If difference is between 1 day and 7 days use 'days'
	elseif($difference >= 60*60*24 && $difference<60*60*24*7){ $interval = "d"; }
	
	// If difference is between 1 week and 30 days use 'weeks'
	elseif($difference >= 60*60*24*7 && $difference < 60*60*24*30){ $interval = "ww"; }
	
	// If difference is between 30 days and 365 days use 'months'
	elseif($difference >= 60*60*24*30 && $difference < 60*60*24*365){ $interval = "m"; }
	
	// If difference is greater than or equal to 365 days use 'years'
	elseif($difference >= 60*60*24*365){ $interval = "y"; }
	
	// Based on the interval, determine the number of units between the two dates
	// If the $datediff returned is 1, be sure to return the singular
	// of the unit, e.g. 'day' rather 'days'
	switch($interval){
		case "m":
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
			if($datediff==12){ $datediff--; }
	
			$res = ($datediff==1) ? "$datediff month ago" : "$datediff months ago";
		break;
	
		case "y":
			$datediff = floor($difference / 60 / 60 / 24 / 365);
			$res = ($datediff==1) ? "$datediff year ago" : "$datediff years ago";
		break;
	
		case "d":
			$datediff = floor($difference / 60 / 60 / 24);
			$res = ($datediff==1) ? "$datediff day ago" : "$datediff days ago";
		break;
	
		case "ww":
			$datediff = floor($difference / 60 / 60 / 24 / 7);
			$res = ($datediff==1) ? "$datediff week ago" : "$datediff weeks ago";
		break;
	
		case "h":
			$datediff = floor($difference / 60 / 60);
			$res = ($datediff==1) ? "$datediff hour ago" : "$datediff hours ago";
		break;
	
		case "n":
			$datediff = floor($difference / 60);
			$res = ($datediff==1) ? "$datediff minute ago" : "$datediff minutes ago";
		break;
	
		case "s":
			$datediff = $difference;
			$res = ($datediff==1) ? "$datediff second ago" : "$datediff seconds ago";
		break;
	}

	return $res;
} // end TimeAgo()



function formatTweet($tweet, $options) {
	if($options['reply']){
	    $tweet = preg_replace('/(^|\s)@(\w+)/', '\1@<a href="http://www.twitter.com/\2">\2</a>', $tweet);
	}
	if($options['hash']){
	    $tweet = preg_replace('/(^|\s)#(\w+)/', '\1#<a href="http://search.twitter.com/search?q=%23\2">\2</a>', $tweet);
	}
	
	return $tweet;
}

	
function feedSort($a, $b){
	if($a->status->created_at){
		$a_t = strtotime($a->status->created_at);
		$b_t = strtotime($b->status->created_at);
	}
	elseif($a->updated){
		$a_t = strtotime($a->updated);
		$b_t = strtotime($b->updated);
	}
	
	if( $a_t == $b_t ) return 0 ;
    return ($a_t > $b_t ) ? -1 : 1; 
}

function multiTwitter($widget) {
	$upload = wp_upload_dir();
	$upload_dir = $upload['basedir']."/cache/twitter";
	if(!file_exists($upload_dir)){ echo $upload_dir; mkdir($upload_dir); }
	$accounts = explode(" ", $widget['users']);
	$terms = explode(", ", $widget['terms']);
	
	if(!$widget['user_limit']){ $widget['user_limit'] = 5; } // if limit hasn't been set, default to 10
	if(!$widget['term_limit']){ $widget['term_limit'] = 5; } // if limit hasn't been set, default to 10
	
	echo '<ul>';
		
	// Parse the accounts and CRUD cache
	foreach($accounts as $account):
		$cache = FALSE; // Assume the cache is empty
		$cFile = "$upload_dir/users_$account.xml";
	
		if(file_exists($cFile)) {
			$modtime = filemtime($cFile);		
			$timeago = time() - 1800; // 30 minutes ago
			if($modtime < $timeago) {
				$cache = FALSE; // Set to false just in case as the cache needs to be renewed
			} else {
				$cache = TRUE; // The cache is not too old so the cache can be used.
			}
		}
		
		if($cache === FALSE) {				
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
			
			if($content === FALSE) {
				// Content couldn't be retrieved... Do something..
				echo '<li>Content could not be retrieved. Twitter API failed...</li>';
			}
		
			// Let's save our data into uploads/cache/twitter/
			$fp = fopen($cFile, 'w');
			if(!$fp){ echo "Permission to write cache dir to <em>$cFile</em> not granted<br />"; }
			else { fwrite($fp, $content); }
			fclose($fp);
		} else {
			//cache is TRUE let's load the data from the cached file
			echo '<!--li>We have cache! Loading from local file...</li-->';
			$xml = simplexml_load_file($cFile);
			$feeds[] = $xml;
		}
	endforeach;
	
	// Parse the terms and CRUD cache
	foreach($terms as $term):
		$cache = FALSE; // Assume the cache is empty
		$cFile = "$upload_dir/term_$term.xml";
	
		if(file_exists($cFile)) {
			$modtime = filemtime($cFile);		
			$timeago = time() - 1800; // 30 minutes ago
			if($modtime < $timeago) {
				$cache = FALSE; // Set to false just in case as the cache needs to be renewed
			} else {
				$cache = TRUE; // The cache is not too old so the cache can be used.
			}
		}
		
		if($cache === FALSE) {				
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
			
			if($content === FALSE) {
				// Content couldn't be retrieved... Do something..
				echo '<li>Content could not be retrieved. Twitter API failed...</li>';
			}
			else {
				// Let's save our data into uploads/cache/twitter/
				$fp = fopen($cFile, 'w');
				if(!$fp){ echo "Permission to write cache dir to <em>$cFile</em> not granted<br />"; } 
				else { fwrite($fp, $content); }
				fclose($fp);
			}
		} else {
			//cache is TRUE let's load the data from the cached file
			echo '<!--li>We have cache! Loading from local file...</li-->';
			$xml = simplexml_load_file($cFile);
			$feeds[] = $xml;
		}
	endforeach;
	
	// Sort our $feeds array
	usort($feeds, "feedSort");
	
	// Split array and output results
	$i = 1;
	foreach($feeds as $feed):
	if($feed->screen_name != '' && $i <= $widget['user_limit']):
		echo '
			<li class="clearfix">
				<a href="http://twitter.com/'.$feed->screen_name.'">
					<img class="twitter-avatar" src="'.$feed->profile_image_url.'" width="40" height="40" alt="'.$feed->screen_name.'" />
					'.$feed->screen_name.': 
				</a>
				'.formatTweet($feed->status->text, $widget).'<br />
				<em>'.TimeAgo(strtotime($feed->status->created_at)).'</em>';
				if($widget['date']){ echo '<em>'.TimeAgo(strtotime($feed->entry[$i]->updated)).'</em>'; }
			echo '</li>';
	elseif(preg_match('/search.twitter.com/i', $feed->id) && $i <= $widget['term_limit']):		
		$count = count($feed->entry);
		
		for($i=0; $i<$count; $i++){
		if($i <= $widget['term_limit']):
			echo '
				<li class="clearfix">
					<a href="'.$feed->entry[$i]->author->uri.'">
						<img class="twitter-avatar" src="'.$feed->entry[$i]->link[1]->attributes()->href.'" width="40" height="40" alt="'.$feed->entry[$i]->author->name.'" />
						<strong>'.$feed->entry[$i]->author->name.':</strong>
					</a>
					'.formatTweet($feed->entry[$i]->content, $widget).'<br />
				';
			if($widget['date']){ echo '<em>'.TimeAgo(strtotime($feed->entry[$i]->updated)).'</em>'; }
			echo '</li>';
		endif;	
		}
	endif;	
	$i++;
	endforeach;
	echo '</ul>';
}

function widget_multiTwitter($args) {
	extract($args);

	$options = get_option("widget_multiTwitter");
	
	if (!is_array($options)) { 
		$options = array(
			'title' => 'Multi Twitter',
			'users' => 'thinkclay bychosen',
			'terms' => 'wordpress',
			'user_limit' => 5,
			'term_limit' => 5,
			'links' => TRUE,
			'reply' => TRUE,
			'hash'	=> TRUE,
			'date'	=> TRUE
		);  
	}  

	echo $before_widget;
	echo $before_title;
    echo $options['title'];
	echo $after_title;

	multiTwitter($options);
	
	echo $after_widget;
}

function multiTwitter_control() {
	$options = get_option("widget_multiTwitter");
	
	if (!is_array($options)) { 
		$options = array(
			'title' => 'Multi Twitter',
			'users' => 'thinkclay bychosen',
			'terms' => 'wordpress',
			'user_limit' => 5,
			'term_limit' => 5,
			'links' => TRUE,
			'reply' => TRUE,
			'hash'	=> TRUE,
			'date'	=> TRUE
		); 
	}  

	if ($_POST['multiTwitter-Submit']) {
		$options['title'] = htmlspecialchars($_POST['multiTwitter-Title']);
		$options['users'] = htmlspecialchars($_POST['multiTwitter-Users']);
		$options['terms'] = htmlspecialchars($_POST['multiTwitter-Terms']);
		$options['user_limit'] = $_POST['multiTwitter-UserLimit'];
		$options['term_limit'] = $_POST['multiTwitter-TermLimit'];
		$options['links'] = $_POST['multiTwitter-Links'];
		$options['reply'] = $_POST['multiTwitter-Reply'];
		$options['hash']  = $_POST['multiTwitter-Hash'];
		$options['date']  = $_POST['multiTwitter-Date'];
		update_option("widget_multiTwitter", $options);
	}
?>
	<p>
		<label for="multiTwitter-Title">Widget Title: </label><br />
		<input type="text" class="widefat" id="multiTwitter-Title" name="multiTwitter-Title" value="<?php echo $options['title']; ?>" />
	</p>
	<p>	
		<label for="multiTwitter-Users">Users: </label><br />
		<input type="text" class="widefat" id="multiTwitter-Users" name="multiTwitter-Users" value="<?php echo $options['users']; ?>" /><br />
		<small><em>enter accounts separated with a space</em></small>
	</p>
	<p>
		<label for="multiTwitter-Terms">Search Terms: </label><br />
		<input type="text" class="widefat" id="multiTwitter-Terms" name="multiTwitter-Terms" value="<?php echo $options['terms']; ?>" /><br />
		<small><em>enter search terms separated with a comma</em></small>
	</p>
	<p>
		<label for="multiTwitter-UserLimit">Limit user feed to: </label>
		<select id="multiTwitter-UserLimit" name="multiTwitter-UserLimit">
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
		<label for="multiTwitter-TermLimit">Limit search feed to: </label>
		<select id="multiTwitter-TermLimit" name="multiTwitter-TermLimit">
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
		<label for="multiTwitter-Links">Automatically convert links?</label>
		<input type="checkbox" name="multiTwitter-Links" id="multiTwitter-Links" <?php if($options['links']){ echo 'checked="checked"'; } ?> />
	</p>
	<p>
		<label for="multiTwitter-Reply">Automatically convert @replies?</label>
		<input type="checkbox" name="multiTwitter-Reply" id="multiTwitter-Reply" <?php if($options['reply']){ echo 'checked="checked"'; } ?> />
	</p>
	<p>
		<label for="multiTwitter-Hash">Automatically convert #hashtags?</label>
		<input type="checkbox" name="multiTwitter-Hash" id="multiTwitter-Hash" <?php if($options['hash']){ echo 'checked="checked"'; } ?> />
	</p>
	<p>
		<label for="multiTwitter-Date">Show Date?</label>
		<input type="checkbox" name="multiTwitter-Date" id="multiTwitter-Date" <?php if($options['date']){ echo 'checked="checked"'; } ?> />
	</p>
	<p><input type="hidden" id="multiTwitter-Submit" name="multiTwitter-Submit" value="1" /></p>
<?php
}

function multiTwitter_init() {
	register_sidebar_widget('Multi Twitter', 'widget_multiTwitter');
	register_widget_control('Multi Twitter', 'multiTwitter_control', 250, 250);	
}

add_action("plugins_loaded", "multiTwitter_init");
?>