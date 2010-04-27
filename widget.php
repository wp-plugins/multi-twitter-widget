<?php
/*
Plugin Name: Multi Twitter Stream
Plugin URI: http://thinkclay.com/
Description: A widget for multiple twitter accounts
Author: Clayton McIlrath
Version: 1.0.1
Author URI: http://thinkclay.com
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

function checkCache($account){
	$cache = FALSE; // Assume the cache is empty
	$cFile = "./cache/twitter/$account.xml";

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
		
		if($content === FALSE) {
			// Content couldn't be retrieved... Do something..
			echo '<li>Content could not be retrieved. Twitter API failed...</li>';
		}
	
		// Let's save our data into webroot/cache/twitter/
		$fp = fopen($cFile, 'w');
		if(!$fp){ echo 'Permission to write cache dir not granted'; } 
		else { fwrite($fp, $content); }
		fclose($fp);
	} else {
		//cache is TRUE let's load the data from the cached file
		echo '<!--li>We have cache! Loading from local file...</li-->';
		$xml = simplexml_load_file($cFile);
	}

	echo '
		<li class="clearfix">
			<a href="http://twitter.com/'.$xml->screen_name.'">
				<img class="twitter-avatar" src="'.$xml->profile_image_url.'" width="40" height="40" alt="'.$xml->screen_name.'" />
				'.$xml->screen_name.': 
			</a>
			'.$xml->status->text.'<br />
			<em>'.TimeAgo(strtotime($xml->status->created_at)).'</em>
		</li>
	';
} // end: checkCache()

function multiTwitter($accounts) {
	$accounts = explode(" ", $accounts);

	echo '<ul>';
	foreach($accounts as $account){
		checkCache($account);
	}
	echo '</ul>';
}

function widget_multiTwitter($args) {
	extract($args);

	$options = get_option("widget_multiTwitter");
	
	if (!is_array($options)) { 
		$options = array(
			'title' => 'Multi Twitter',
			'users' => 'thinkclay bychosen'
		);  
	}   

	echo $before_widget;
	echo $before_title;
    echo $options['title'];
	echo $after_title;

	multiTwitter($options['users']);
	
	echo $after_widget;
}

function multiTwitter_control() {
	$options = get_option("widget_multiTwitter");
	
	if (!is_array($options)) { 
		$options = array(
			'title' => 'Multi Twitter',
			'users' => 'thinkclay bychosen'
		); 
	}  

	if ($_POST['multiTwitter-Submit']) {
		$options['title'] = htmlspecialchars($_POST['multiTwitter-Title']);
		$options['users'] = htmlspecialchars($_POST['multiTwitter-Users']);
		update_option("widget_multiTwitter", $options);
	}
?>
	<p>
		<label for="multiTwitter-WidgetTitle">Widget Title: </label><br />
		<input type="text" class="widefat" id="multiTwitter-Title" name="multiTwitter-Title" value="<?php echo $options['title'];?>" />
	</p>
	<p>	
		<label for="multiTwitter-WidgetUsers">Users: </label><br />
		<input type="text" class="widefat" id="multiTwitter-Users" name="multiTwitter-Users" value="<?php echo $options['users'];?>" /><br />
		<small><em>enter accounts separated with a space</em></small>
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