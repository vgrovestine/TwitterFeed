<?php

require('TwitterFeed.php');

$tf = new TwitterFeed('USER_JOE_Q_PUBLIC', 'Your_Twitter_developer_bearer_token');

$ten_raw_tweets_including_pinned_tweet = $tf->getRecentTweets(10, 'raw', true);

$twenty_raw_tweets_excluding_pinned_tweet = $tf->getRecentTweets(20, 'raw', false);

$ten_simplified_tweets_including_pinned_tweet = $tf->getRecentTweets(10, 'simplified', true);

$twenty_simplified_tweets_excluding_pinned_tweet = $tf->getRecentTweets(20, 'simplified', false);

$pinned_tweet_only_if_available = $tf->getRecentTweets(1, 'pinned');

?>