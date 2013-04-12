<?php
/*
Plugin Name: Multi Twitter Stream
Plugin URI: http://thinkclay.com/
Description: A widget for multiple twitter accounts with oauth support for Twitter API v1.1
Author: Clayton McIlrath, Roger Hamilton
Version: 1.5.0
Author URI: http://thinkclay.com
*/

function human_time($datefrom, $dateto = -1)
{
    // Defaults and assume if 0 is passed in that its an error rather than the epoch
    if ( $datefrom <= 0 )
        return "A long time ago";

    if ( $dateto == -1 )
    {
        $dateto = time();
    }

    // Calculate the difference in seconds betweeen the two timestamps
    $difference = $dateto - $datefrom;

    // If difference is less than 60 seconds use 'seconds'
    if ($difference < 60)
    {
        $interval = "s";
    }
    // If difference is between 60 seconds and 60 minutes use 'minutes'
    else if ($difference >= 60 and $difference < (60 * 60))
        {
            $interval = "n";
        }
    // If difference is between 1 hour and 24 hours use 'hours'
    else if ($difference >= (60 * 60) and $difference < (60 * 60 * 24))
        {
            $interval = "h";
        }
    // If difference is between 1 day and 7 days use 'days'
    else if ($difference >= (60 * 60 * 24) and $difference < (60 * 60 * 24 * 7))
        {
            $interval = "d";
        }
    // If difference is between 1 week and 30 days use 'weeks'
    else if ($difference >= (60 * 60 * 24 * 7) and $difference < (60 * 60 * 24 * 30))
        {
            $interval = "ww";
        }
    // If difference is between 30 days and 365 days use 'months'
    else if ($difference >= (60 * 60 * 24 * 30) and $difference < (60 * 60 * 24 * 365))
        {
            $interval = "m";
        }
    // If difference is greater than or equal to 365 days use 'years'
    else if ($difference >= (60 * 60 * 24 * 365))
        {
            $interval = "y";
        }

    // Based on the interval, determine the number of units between the two dates
    // If the $datediff returned is 1, be sure to return the singular
    // of the unit, e.g. 'day' rather 'days'
    switch ( $interval )
    {
        case "m":
            $months_difference = floor($difference / 60 / 60 / 24 / 29);
            $date_from = mktime(
                date("H", $datefrom),
                date("i", $datefrom),
                date("s", $datefrom),
                date("n", $datefrom) + ($months_difference),
                date("j", $dateto), date("Y", $datefrom)
            );
    
            while ( $date_from < $dateto)
            {
                $months_difference++;
            }
            $datediff = $months_difference;
    
            // We need this in here because it is possible to have an 'm' interval and a months
            // difference of 12 because we are using 29 days in a month
            if ($datediff == 12)
            {
                $datediff--;
            }
            $res = ($datediff == 1) ? "$datediff month ago" : "$datediff months ago";
            break;
    
        case "y":
            $datediff = floor($difference / 60 / 60 / 24 / 365);
            $res      = ($datediff == 1) ? "$datediff year ago" : "$datediff years ago";
            break;
    
        case "d":
            $datediff = floor($difference / 60 / 60 / 24);
            $res      = ($datediff == 1) ? "$datediff day ago" : "$datediff days ago";
            break;
    
        case "ww":
            $datediff = floor($difference / 60 / 60 / 24 / 7);
            $res      = ($datediff == 1) ? "$datediff week ago" : "$datediff weeks ago";
            break;
    
        case "h":
            $datediff = floor($difference / 60 / 60);
            $res      = ($datediff == 1) ? "$datediff hour ago" : "$datediff hours ago";
            break;
    
        case "n":
            $datediff = floor($difference / 60);
            $res      = ($datediff == 1) ? "$datediff minute ago" : "$datediff minutes ago";
            break;
    
        case "s":
            $datediff = $difference;
            $res      = ($datediff == 1) ? "$datediff second ago" : "$datediff seconds ago";
            break;
    }

    return $res;
}


function format_tweet($tweet, $options)
{
    if ( $options['reply'] )
    {
        $tweet = preg_replace('/(^|\s)@(\w+)/', '\1@<a href="http://www.twitter.com/\2">\2</a>', $tweet);
    }

    if ( $options['hash'] )
    {
        $tweet = preg_replace('/(^|\s)#(\w+)/', '\1#<a href="http://search.twitter.com/search?q=%23\2">\2</a>', $tweet);
    }

    if
    ( $options['links'] )
    {
        $tweet = preg_replace('#(^|[\n ])(([\w]+?://[\w\#$%&~.\-;:=,?@\[\]+]*)(/[\w\#$%&~/.\-;:=,?@\[\]+]*)?)#is', '\\1\\2', $tweet);
    }

    return $tweet;
}


function feed_sort($a, $b)
{
    if ( $a[created_at] )
    {
        $a_t = strtotime($a[created_at]);
        $b_t = strtotime($b[created_at]);
    }
    else if ( $a[updated] )
    {
        $a_t = strtotime($a[updated]);
        $b_t = strtotime($b[updated]);
    }

    if ($a_t == $b_t)
        return 0;

    return ($a_t > $b_t) ? -1 : 1;
}


function multi_twitter($widget)
{
    if ( ! class_exists('Codebird') )
        require 'lib/codebird.php';

    // Initialize Codebird with our keys.  We'll wait and
    // pass the token when we make an actual request
    Codebird::setConsumerKey($widget['consumer_key'], $widget['consumer_secret']);

    $cb = Codebird::getInstance();
    $output = '';

    // Get our root upload directory and create cache if necessary
    $upload     = wp_upload_dir();
    $upload_dir = $upload['basedir'] . "/cache";

    if ( ! file_exists($upload_dir) )
    {
        if ( ! mkdir($upload_dir))
        {
            $output .= '<span style="color: red;">could not create dir' . $upload_dir . ' please create this directory</span>';

            return $output;
        }
    }

    // split the accounts and search terms specified in the widget
    $accounts = explode(" ", $widget['users']);
    $terms    = explode(", ", $widget['terms']);

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
        $cache = false;
        // Assume the cache is empty
        $cFile = "$upload_dir/users_$account.txt";

        if ( file_exists($cFile) )
        {
            $modtime = filemtime($cFile);
            $timeago = time() - 1800;
            // 30 minutes ago
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
        
        // begin
        if ( $cache === false )
        {
            $cb->setToken($widget['access_token'], $widget['access_token_secret']);
            $params  = array('screen_name' => $account, 'count' => $widget['user_limit']);
            // let Codebird make an authenticated request  Result is json
            $reply   = $cb->statuses_userTimeline($params);
            // turn the json into an array
            $json    = json_decode($reply, true);
            $length = count($json);
            
            for ($i = 0; $i < $length; $i++)
            {
                // add it to the feeds array
                $feeds[] = $json[$i];
                // prepare it for caching
                $content[] = $json[$i];
            }
            
            // Let's save our data into uploads/cache/
            $fp = fopen($cFile, 'w');
            
            if (!$fp)
            {
                $output .= '<li style="color: red;">Permission to write cache dir to <em>' . $cFile . '</em> not granted</li>';
            }
            else
            {
                $str = serialize($content);
                fwrite($fp, $str);
            }
            fclose($fp);
        }
        else
        {
            //cache is true let's load the data from the cached file
            $str     = file_get_contents($cFile);
            $content = unserialize($str);
            $length = count($content);
            echo $length;
            $feeds = null;
            
            for ($i = 0; $i < $length; $i++)
            {
                // add it to the feeds array
                $feeds[] = $content[$i];
            }
        }
    }

    // Parse the terms and CRUD cache
    foreach ( $terms as $term )
    {
        $cache = false;
        // Assume the cache is empty
        $cFile = "$upload_dir/term_$term.txt";
        
        if (file_exists($cFile))
        {
            $modtime = filemtime($cFile);
            $timeago = time() - 1800;
            
            // 30 minutes ago
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
            $cb->setToken($widget['access_token'], $widget['access_token_secret']);
            $search_params = array(
                'q' => $term
            );
            $reply         = $cb->search_tweets($search_params);
            $json          = json_decode($reply, true);
            $length = count($json);
            
            for ( $i = 0; $i < $length; $i++ )
            {
                // add it to the feeds array
                $feeds[] = $json[$i];
                // prepare it for caching
                $content = array($json[statuses][$i]);
            }

            if ($content === false)
            {
                // Content couldn't be retrieved... Do something..
                $output .= '<li>Content could not be retrieved. Twitter API failed...</li>';
            } 
            else
            {
                // Let's save our data into uploads/cache/twitter/
                $fp = fopen($cFile, 'w');
                
                if (!$fp)
                {
                    $output .= '<li style="color: red;">Permission to write cache dir to <em>' . $cFile . '</em> not granted</li>';
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
            $str     = file_get_contents($cFile);
            $content = unserialize($str);
            $feeds[] = $content;
        }
    }

    // Sort our $feeds array
    usort($feeds, "feed_sort");

    // Split array and output results
    $i = 1;

    // format the tweet for display
    foreach ( $feeds as $feed )
    {
        if ( $feed[user][screen_name] != '' and $i <= $widget['user_limit'] )
        {
            $output .= '<li class="tweet clearfix">' . '<a href="http://twitter.com/' . $feed[user][screen_name] . '">' . '<img class="twitter-avatar" src="' . $feed[user][profile_image_url] . '" width="40" height="40" alt="' . $feed[user][screen_name] . '" />' . '</a>';
            $output .= '<div class="tweet-userName">' . $feed[user][name] . '</div>';
            if ($widget['date'])
            {
                $output .= '<div class="tweet-time"><em>&nbsp;-&nbsp;' . human_time(strtotime($feed[created_at])) . '</em></div>';
            }
            $output .= '<div style="clear:both"></div>';
            $output .= '<div class="tweet-message">' . format_tweet($feed[text], $widget) . '</div>';

            $output .= '</li>';
        }
        $i++;
    }
    $output .= '</ul>';

    if ($widget['credits'] === true)
    {
        $output .= '<hr />' . '<strong>development by</strong> ' . '<a href="http://twitter.com/thinkclay" target="_blank">Clay McIlrath</a> and <a href="http://twitter.com/roger_hamilton" target="_blank">Roger Hamilton</a>';
    }
    
    if ($widget['styles'] === true)
    {
        $output .= '<style type="text/css">' . '.twitter-avatar { clear: both; float: left; padding: 6px 12px 2px 0; }' . '.twitter{background:none;}' . '.tweet{min-height:48px;margin:0!important;}' . '.tweet a{text-decoration:underline;}' . '.tweet-userName{padding-top:7px;font-size:12px;line-height:0;color:#454545;font-family:Arial,sans-serif;font-weight:700;margin-bottom:10px;margin-left:8px;float:left;min-width:50px;}' . '.twitter-avatar{width:48px;height:48px;-webkit-border-radius:5px;-moz-border-radius:5px;border-radius:5px;padding:0!important;}' . '.tweet-time{color:#8A8A8A;float:left;margin-top:-3px;font-size:11px!important;}' . '.tweet-message{font-size:11px;line-height:14px;color:#333;font-family:Arial,sans-serif;word-wrap:break-word;margin-top:-30px!important;width:200px;margin-left:58px;}' . '</style>';
    }
    echo $output;
}


function widget_multi_twitter($args)
{
    extract($args);
    $options = get_option("widget_multi_twitter");

    if ( ! is_array($options) )
    {
        $options = array('consumer_key' => '0',
            'consumer_secret' => '0',
            'access_token' => '0',
            'access_token_secret' => '0',
            'title' => 'Multi Twitter',
            'users' => 'thinkclay roger_hamilton',
            'terms' => 'wordpress',
            'user_limit' => 5,
            'term_limit' => 5,
            'links' => true,
            'reply' => true,
            'hash' => true,
            'date' => true,
            'credits' => true,
            'styles' => true
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

    if ( ! is_array($options) )
    {
        $options = array('consumer_key' => 'lNbFfDyYdUdZPhqqlsAGVA',
            'consumer_secret' => 'izPPTeFQYlC2UFsxxu6ODZtoOC6FFyPZyXX959p4Z4',
            'access_token' => '16468552-NUSSANz4tgU7gUCZPHMWxSdgatO10YtG1SrBuYUAA',
            'access_token_secret' => 'SZJZIJ0jdRKa9EQ9T6JpamxOz28GDWuvDl7tydwzHIQ',
            'title' => 'Multi Twitter',
            'users' => 'thinkclay roger_hamilton',
            'terms' => 'wordpress',
            'user_limit' => 5,
            'term_limit' => 5,
            'links' => true,
            'reply' => true,
            'hash' => true,
            'date' => true,
            'credits' => true,
            'styles' => true
        );
    }

    if ( $_POST['multi_twitter-Submit'] )
    {
        // oauth
        $options['consumer_key']        = htmlspecialchars($_POST['multi_twitter_consumer_key']);
        $options['consumer_secret']     = htmlspecialchars($_POST['multi_twitter_consumer_secret']);
        $options['access_token']        = htmlspecialchars($_POST['multi_twitter_access_token']);
        $options['access_token_secret'] = htmlspecialchars($_POST['multi_twitter_access_token_secret']);
        // twitter
        $options['title']      = htmlspecialchars($_POST['multi_twitter-Title']);
        $options['users']      = htmlspecialchars($_POST['multi_twitter-Users']);
        $options['terms']      = htmlspecialchars($_POST['multi_twitter-Terms']);
        $options['user_limit'] = $_POST['multi_twitter-UserLimit'];
        $options['term_limit'] = $_POST['multi_twitter-TermLimit'];
        // options
        $options['hash']    = ($_POST['multi_twitter-Hash']) ? true : false;
        $options['reply']   = ($_POST['multi_twitter-Reply']) ? true : false;
        $options['links']   = ($_POST['multi_twitter-Links']) ? true : false;
        $options['date']    = ($_POST['multi_twitter-Date']) ? true : false;
        $options['credits'] = ($_POST['multi_twitter-Credits']) ? true : false;
        $options['styles']  = ($_POST['multi_twitter-Styles']) ? true : false;

        update_option("widget_multi_twitter", $options);
    }
?>
    <fieldset style="border:1px solid gray;padding:10px;">
    <legend>Oauth Settings</legend>
    <p>
    <label for="multi_twitter_consumer_key">Comsumer Key: </label><br />
    <input type="text" class="widefat" id="multi_twitter_consumer_key" name="multi_twitter_consumer_key" value="<?php
    echo $options['consumer_key'];
    ?>" />
    <p>
    <label for="multi_twitter_consumer_secret">Consumer Secret: </label><br />
    <input type="text" class="widefat" id="multi_twitter_consumer_secret" name="multi_twitter_consumer_secret" value="<?php
    echo $options['consumer_secret'];
    ?>" />
    <p>
    <label for="multi_twitter_access_token">Access Token: </label><br />
    <input type="text" class="widefat" id="multi_twitter_access_token" name="multi_twitter_access_token" value="<?php
    echo $options['access_token'];
    ?>" />
    <p>
    <label for="multi_twitter_access_token_secret">Acess Token Secret: </label><br />
    <input type="text" class="widefat" id="multi_twitter_access_token_secret" name="multi_twitter_access_token_secret" value="<?php
    echo $options['access_token_secret'];
    ?>" />
    </fieldset>
    <p>
    <label for="multi_twitter-Title">Widget Title: </label><br />
    <input type="text" class="widefat" id="multi_twitter-Title" name="multi_twitter-Title" value="<?php
    echo $options['title'];
    ?>" />
    </p>
    <p>
    <label for="multi_twitter-Users">Users: </label><br />
    <input type="text" class="widefat" id="multi_twitter-Users" name="multi_twitter-Users" value="<?php
    echo $options['users'];
    ?>" /><br />
    <small><em>enter accounts separated with a space</em></small>
    </p>
    <p>
    <label for="multi_twitter-Terms">Search Terms: </label><br />
    <input type="text" class="widefat" id="multi_twitter-Terms" name="multi_twitter-Terms" value="<?php
    echo $options['terms'];
    ?>" /><br />
    <small><em>enter search terms separated with a comma</em></small>
    </p>
    <p>
    <label for="multi_twitter-UserLimit">Limit user feed to: </label>
    <select id="multi_twitter-UserLimit" name="multi_twitter-UserLimit">
    <option value="<?php
    echo $options['user_limit'];
    ?>"><?php
    echo $options['user_limit'];
    ?></option>
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
    <option value="<?php
    echo $options['term_limit'];
    ?>"><?php
    echo $options['term_limit'];
    ?></option>
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
    <input type="checkbox" name="multi_twitter-Links" id="multi_twitter-Links" <?php
    if ($options['links'])
    {
        echo 'checked="checked"';
    }
    ?> />
    </p>
    <p>
    <label for="multi_twitter-Reply">Automatically convert @replies?</label>
    <input type="checkbox" name="multi_twitter-Reply" id="multi_twitter-Reply" <?php
    if ($options['reply'])
    {
        echo 'checked="checked"';
    }
    ?> />
    </p>
    <p>
    <label for="multi_twitter-Hash">Automatically convert #hashtags?</label>
    <input type="checkbox" name="multi_twitter-Hash" id="multi_twitter-Hash" <?php
    if ($options['hash'])
    {
        echo 'checked="checked"';
    }
    ?> />
    </p>
    <p>
    <label for="multi_twitter-Date">Show Date?</label>
    <input type="checkbox" name="multi_twitter-Date" id="multi_twitter-Date" <?php
    if ($options['date'])
    {
        echo 'checked="checked"';
    }
    ?> />
    </p>
    <p>
    <label for="multi_twitter-Credits">Show Credits?</label>
    <input type="checkbox" name="multi_twitter-Credits" id="multi_twitter-Credits" <?php
    if ($options['credits'])
    {
        echo 'checked="checked"';
    }
    ?> />
    </p>
    <p>
    <label for="multi_twitter-Styles">Use Default Styles?</label>
    <input type="checkbox" name="multi_twitter-Styles" id="multi_twitter-Styles" <?php
    if ($options['styles'])
    {
        echo 'checked="checked"';
    }
    ?> />
    <div>
    <p>If you prefer to use your own styles you can override the following in your stylesheet</p>
    <ul>
    <li>.twitter // the ul wrapper</li>
    <li>.tweet // the li</li>
    <li>.tweet a // anchors in the tweet</li>
    <li>.tweet-userName // the display name</li>
    <li>.twitter-avatar // the thumbnail</li>
    <li>.tweet-time // the post date</li>
    <li>.tweet-message // the message</li>
    </ul>
    </div>
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