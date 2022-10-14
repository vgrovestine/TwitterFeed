<?php
class TwitterFeed {
  public $ssl_verify_host = 1;
  public $ssl_verify_peer = 1;
  private $user_id = '';
  private $bearer_token = false;


  function __construct($user_id, $bearer_token = false) {
    $this->user_id = $user_id;
    $this->bearer_token = $bearer_token;
  }


  public function getRecentTweets($max_results, $format = 'simplified', $include_pinned_tweet = true, $include_referenced_tweets = false) {
    switch($format) {
      case 'pinned':
        return $this->getPinnedTweet_raw();
        break;
      case 'raw':
        return $this->getRecentTweets_raw($max_results, $include_pinned_tweet);
        break;
      case 'simplified':
      default:
        return $this->getRecentTweets_simplified($max_results, $include_pinned_tweet, $include_referenced_tweets);
        break;
    }
  }


  public function getPinnedTweet_raw() {
    $url = 'https://api.twitter.com/2/users/by/username/' . $this->user_id . '?user.fields=pinned_tweet_id';
    $user = json_decode($this->curlGet($url, $this->bearer_token)); 
    if(!isset($user->data->pinned_tweet_id)) {
      return false;
    }
    $qs = array(
      'tweet.fields=created_at,lang,conversation_id,public_metrics',
      'expansions=referenced_tweets.id,referenced_tweets.id.author_id,entities.mentions.username,in_reply_to_user_id,attachments.media_keys,attachments.poll_ids',
      'media.fields=duration_ms,height,media_key,preview_image_url,public_metrics,type,url,width,alt_text'
      );
    $url = 'https://api.twitter.com/2/tweets/' . $user->data->pinned_tweet_id . '?' . implode('&', $qs); 
    $tweet = json_decode($this->curlGet($url, $this->bearer_token));
    $tweet->data->pinned = 1;
    return $tweet;
  }


  private function getRecentTweets_raw($max_results, $include_pinned_tweet) {
    $qs = array(
      'query=from:' . $this->user_id,
      'max_results=' . $max_results,
      'tweet.fields=created_at,lang,conversation_id,public_metrics',
      'expansions=referenced_tweets.id,referenced_tweets.id.author_id,entities.mentions.username,in_reply_to_user_id,attachments.media_keys,attachments.poll_ids',
      'media.fields=duration_ms,height,media_key,preview_image_url,public_metrics,type,url,width,alt_text'
      );
    $url = 'https://api.twitter.com/2/tweets/search/recent' . '?' . implode('&', $qs); 
    $tweets = json_decode($this->curlGet($url, $this->bearer_token));
    if($include_pinned_tweet) {
      if(!empty($pinned_tweet = $this->getPinnedTweet_raw())) {
        for($k = 0; $k < count($tweets->data); $k++) {
          if($tweets->data[$k]->id == $pinned_tweet->data->id) {
            array_splice($tweets->data, $k, 1);
          }
        }
        array_unshift($tweets->data, $pinned_tweet->data);
        $tweets->includes->media = array_merge($tweets->includes->media, $pinned_tweet->includes->media);
        $tmp = array();
        for($k = count($tweets->includes->media) - 1; $k >= 0; $k--) {
          if(in_array($tweets->includes->media[$k]->media_key, $tmp)) {
            array_splice($tweets->includes->media, $k, 1);
          }
          else {
            $tmp[] = $tweets->includes->media[$k]->media_key;
          }
        }
        $tweets->includes->users = array_merge($tweets->includes->users, $pinned_tweet->includes->users);
        $tmp = array();
        for($k = count($tweets->includes->users) - 1; $k >= 0; $k--) {
          if(in_array($tweets->includes->users[$k]->id, $tmp)) {
            array_splice($tweets->includes->users, $k, 1);
          }
          else {
            $tmp[] = $tweets->includes->users[$k]->id;
          }
        }
        unset($tmp);
      }
    }
    return $tweets;
  }


  private function getRecentTweets_simplified($max_results, $include_pinned_tweet, $include_referenced_tweets) {
    $raw_tweets = $this->getRecentTweets_raw($max_results, $include_pinned_tweet);
    $simplified_tweets = array();
    for($k = 0; $k < count($raw_tweets->data); $k++) {
      if(isset($raw_tweets->data[$k]->referenced_tweets) && !$include_referenced_tweets) {
        continue;
      }
      $tweet_id = $raw_tweets->data[$k]->id;
      $tweet_text = explode(' ', $raw_tweets->data[$k]->text);
      if(strpos($tweet_text[count($tweet_text)-1], 'https://t.co/') !== false) {
        array_pop($tweet_text);
      }
      $tweet_text = implode(' ', $tweet_text);
      $simplified_tweets[$tweet_id] = array(
        'id' => $tweet_id,
        'conversation_id' => $raw_tweets->data[$k]->conversation_id,
        'author_id' => $raw_tweets->data[$k]->author_id,
        'pinned' => (isset($raw_tweets->data[$k]->pinned) ? $raw_tweets->data[$k]->pinned : 0),
        'ts' => strtotime($raw_tweets->data[$k]->created_at),
        'text' => $tweet_text,
        'tweet_url' => 'https://twitter.com/' . $this->user_id . '/status/' . $tweet_id,
        'media' => array(),
        'reactions' => (array) $raw_tweets->data[$k]->public_metrics
        );
      foreach($raw_tweets->data[$k]->attachments->media_keys as $media_key) {
        foreach($raw_tweets->includes->media as $media_include) {
          if($media_key == $media_include->media_key) {
            $simplified_tweets[$tweet_id]['media'][$media_key] = (array) $media_include;
            break;
          }
        }
      }
      $markup_text = preg_replace('/(http[s]{0,1}\:\/\/\S{4,})\s{0,}/ims', '<a href="$1" target="_blank">$1</a> ', htmlspecialchars($tweet_text));
      $markup_text = preg_replace('/(@([a-z0-9]+))/i', '<a class="mention" href="https://twitter.com/$2" target="_blank">$1</a>', $markup_text);
      $markup_text = preg_replace('/(#([a-z0-9]+))/i', '<a class="hashtag" href="https://twitter.com/hashtag/$2" target="_blank">$1</a>', $markup_text);
      $markup_text = '<p>' . preg_replace('/(\v+)/', '</p><p>', $markup_text) . '</p>';
      $simplified_tweets[$tweet_id]['markup'] = array(
        'user' => $this->user_id,
        'text' => $markup_text,
        'tweet_url' => $simplified_tweets[$tweet_id]['tweet_url'],
        'image' => '',
        'datetime' => date('M j/y g:ia', $simplified_tweets[$tweet_id]['ts']),
        'reactions' => '<div class="reaction"><span class="fas fa-comment-dots"></span> <span class="label">Replies: </span>' . $simplified_tweets[$tweet_id]['reactions']['reply_count'] . '</div>' .
          '<div class="reaction"><span class="fas fa-retweet"></span> <span class="label">Retweets: </span>' . $simplified_tweets[$tweet_id]['reactions']['retweet_count'] . '</div>' .
          '<div class="reaction"><span class="fas fa-quote-right"></span> <span class="label">Quotes: </span>' . $simplified_tweets[$tweet_id]['reactions']['quote_count'] .'</div>' .
          '<div class="reaction"><span class="far fa-heart"></span> <span class="label">Likes: </span>' . $simplified_tweets[$tweet_id]['reactions']['like_count'] . '</div>' 
      );
      if(isset($simplified_tweets[$tweet_id]['media'])) {
        foreach($simplified_tweets[$tweet_id]['media'] as $tweet_media) {
          if($tweet_media['type'] == 'photo') {
            $simplified_tweets[$tweet_id]['markup']['image'] = '<a class="tweet" href="' . $simplified_tweets[$tweet_id]['tweet_url'] . '" target="_blank"><img src="' . $tweet_media['url'] . '" alt="' . (empty($tweet_media['alt_text']) ? '(tweet)' : $tweet_media['alt_text']) . '"></a>';
            break;
          }
        }
      }
    }
    return $simplified_tweets;
  }


  private function curlGet($url, $bearer_token = false) {
    $handler = curl_init($url);
    curl_setopt($handler, CURLOPT_VERBOSE, false);
    curl_setopt($handler, CURLOPT_SSL_VERIFYHOST, $this->ssl_verify_host);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, $this->ssl_verify_peer);
    curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
    if(!empty($bearer_token)) {
      curl_setopt($handler, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $bearer_token));
      //curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
      //curl_setopt($handler, CURLOPT_XOAUTH2_BEARER, $bearer_token);	
    }
    $response = curl_exec($handler);
    if(curl_error($handler)) {
      $response = false;
    }
    if($response === false || !empty(curl_error($handler))) {
      die('Bailing out of ' . __METHOD__ . ':  ' . curl_error($handler));
      return false;
    }
    curl_close($handler);
    return $response;
  }


  private function curlPost($url, $post_fields, $bearer_token = false) {
    $handler = curl_init($url);
    curl_setopt($handler, CURLOPT_VERBOSE, false);
    curl_setopt($handler, CURLOPT_SSL_VERIFYHOST, $this->ssl_verify_host);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, $this->ssl_verify_peer);
    curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
    if(!empty($bearer_token)) {
      curl_setopt($handler, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $bearer_token));
      //curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
      //curl_setopt($handler, CURLOPT_XOAUTH2_BEARER, $bearer_token);	
    }
    curl_setopt($handler, CURLOPT_POST, true);
    curl_setopt($handler, CURLOPT_POSTFIELDS, $post_fields);
    $response = curl_exec($handler);
    if(curl_error($handler)) {
      $response = false;
    }
    if($response === false || !empty(curl_error($handler))) {
      die('Bailing out of ' . __METHOD__ . ':  ' . curl_error($handler));
      return false;
    }
    curl_close($handler);
    return $response;  
  }


  public function dump($x, $exit = false, $pre = false) {
    echo ($pre ? '<pre>' : '') . print_r($x, true) . ($pre ? '</pre>' : '') . "\n";
    if($exit) {
      exit();
    }
  }

}