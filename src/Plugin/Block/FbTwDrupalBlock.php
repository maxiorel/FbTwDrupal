<?php

namespace Drupal\FbTwDrupal\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 *   id = "fbtwdrupal_block",
 *   admin_label = @Translation("FbTwDrupal Block")
 * )
 */

class FbTwDrupalBlock extends BlockBase {

  // Credit goes to http://stackoverflow.com/a/26098951
  private function trim_text($input, $length, $ellipses = true, $strip_tag = true,$strip_style = true) {
    //strip tags, if desired
    if ($strip_tag) {
        $input = strip_tags($input);
    }
    //strip tags, if desired
    if ($strip_style) {
        $input = preg_replace('/(<[^>]+) style=".*?"/i', '$1',$input);
    }
    if($length=='full')
    {
        $trimmed_text=$input;
    }
    else
    {
        //no need to trim, already shorter than trim length
        if (strlen($input) <= $length) {
        return $input;
        }
        //find last space within length
        $last_space = strrpos(mb_substr($input, 0, $length), ' ');
        $trimmed_text = substr($input, 0, $last_space);
        //add ellipses (...)
        if ($ellipses) {
        $trimmed_text .= '...';
        }
    }
    return $trimmed_text;
  }


  // Credit goes to https://codeforgeek.com/2014/10/time-ago-implementation-php/
  private function get_timeago( $ptime )
  {
    $estimate_time = time() - $ptime;
    if( $estimate_time < 1 )
    {
        return 'less than 1 second ago';
    }
    $condition = array(
      12 * 30 * 24 * 60 * 60  =>  'rokem',
      30 * 24 * 60 * 60       =>  ' měsíci',
      24 * 60 * 60            =>  'd',
      60 * 60                 =>  'h',
      60                      =>  'm',
      1                       =>  's'
    );
    foreach( $condition as $secs => $str )
    {
        $d = $estimate_time / $secs;
        if( $d >= 1 )
        {
            $r = round( $d );
            return 'před ' . $r . '' . $str;
        }
    }
  }

  private function cmp($a, $b) {
    return $b["time"] - $a["time"];
  }

  private function buildBaseString($baseURI, $method, $params) {
    $r = array();
    ksort($params);
    foreach($params as $key=>$value){
        $r[] = "$key=" . rawurlencode($value);
    }
    return $method."&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
  }

  private function buildAuthorizationHeader($oauth) {
    $r = 'Authorization: OAuth ';
    $values = array();
    foreach($oauth as $key=>$value)
        $values[] = "$key=\"" . rawurlencode($value) . "\"";
    $r .= implode(', ', $values);
    return $r;
  }

  public function build() {
	  // Facebook
	  $page_id = '0000000';  // your page id
    $app_id = '000000';  // your app id
    $app_secret = 'xxxxxxxx';  // your app secret
    $url = 'https://graph.facebook.com/'.$page_id.'/posts?access_token='.$app_id.'|'.$app_secret;
		$string = file_get_contents($url);
		$posts = json_decode($string);
		$rows = array();
		foreach ($posts->data as $post){
		  if (!isset($post->story)){
		    $rows[] = array(
		      'time' => strtotime($post->created_time),
		      'content' => $this->trim_text($post->message,100),
		      'type' => 'facebook',
		      'link' => 'http://www.facebook.com/'.$page_id.'/posts/'.str_replace($page_id.'_','',$post->id)
		    );
		  }
		}
		// Twitter
	  $twitter_handle = "xxxxxxx";  // your Twitter account name without @
		$url = "https://api.twitter.com/1.1/statuses/user_timeline.json";
		$oauth_access_token = "111111-22222222";  // your oauth token
		$oauth_access_token_secret = "xxxxxxx";  // your oauth token secret
		$consumer_key = "xxxxxxx";  // your consumer key
		$consumer_secret = "xxxxxx";  // your consumer key

		$oauth = array(
			'oauth_consumer_key' => $consumer_key,
	    'oauth_nonce' => time(),
	    'oauth_signature_method' => 'HMAC-SHA1',
	    'oauth_token' => $oauth_access_token,
	    'oauth_timestamp' => time(),
	    'oauth_version' => '1.0',
	    'screen_name' => $twitter_handle
		);
		$base_info = $this->buildBaseString($url, 'GET', $oauth);
		$composite_key = rawurlencode($consumer_secret) . '&' . rawurlencode($oauth_access_token_secret);
		$oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
		$oauth['oauth_signature'] = $oauth_signature;
		$header = array($this->buildAuthorizationHeader($oauth), 'Content-Type: application/json', 'Expect:');

		$options = array(
			CURLOPT_HTTPHEADER => $header,
	    CURLOPT_HEADER => false,
	    CURLOPT_URL => $url . '?screen_name=' . $twitter_handle,
	    CURLOPT_RETURNTRANSFER => true,
	    CURLOPT_SSL_VERIFYPEER => false
		);
		$feed = curl_init();
		curl_setopt_array($feed, $options);
		$json = curl_exec($feed);
		curl_close($feed);
		$posts = json_decode($json, true);

		foreach ($posts as $post){
		  $rows[] = array(
		    'time' => strtotime($post['created_at']),
		    'content' => $post['text'],
		    'type' => 'twitter',
		    'link' => ''
	      );
		}

		// Let's build the output
		$build = '';
		// Sort by datetime
	  usort($rows, array($this, "cmp"));
	  // Display only three rows
		$rows = array_slice($rows,0,3);

		$reg_exUrl = "/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";
		$reg_exHash = "/#([a-z_0-9]+)/i";
		$reg_exUser = "/@([a-z_0-9]+)/i";
		// make URLs as mentioned on http://www.johnbhartley.com/2013/twitter-feed-with-php-and-json-v1-1/
		foreach ($rows as $key => $row){
  	  if(preg_match($reg_exUrl, $row['content'], $url)) {
       // make the urls hyper links
       $row['content'] = preg_replace($reg_exUrl, "<a href='{$url[0]}'>{$url[0]}</a> ", $row['content']);
      }
      if(preg_match($reg_exHash, $row['content'], $hash)) {
        // make the hash tags hyper links
        $row['content'] = preg_replace($reg_exHash, "<a href='https://twitter.com/search?q={$hash[0]}'>{$hash[0]}</a> ", $row['content']);
        // swap out the # in the URL to make %23
        $row['content'] = str_replace("/search?q=#", "/search?q=%23", $row['content'] );
      }
      if(preg_match($reg_exUser, $row['content'], $user)) {
        $row['content'] = preg_replace("/@([a-z_0-9]+)/i", "<a href='http://twitter.com/$1'>$0</a>", $row['content']);
      }
      $rows[$key]['content'] = $row['content'];
		}

		foreach ($rows as $row){
		  $build .= '<div class="row type-'.$row['type'].'">';
		  $build .= '<div class="icon type-'.$row['type'].'"><span>'.$row['type'].'</span></div>';
		  $build .= '<span class="ago">'.$this->get_timeago($row['time']).'</span>';
		  $build .= '<div class="message">'.$row['content'];
		  if (!empty($row['link'])){
	  	    $build .= ' <a href="'.$row['link'].'" target="_blank">&raquo; &raquo; &raquo;</a>';
		  }
		  $build .= '</div>';
		  $build .= '</div>';
		}

    $build = array(
      '#markup' => $build
    );
    return $build;
  }
}
