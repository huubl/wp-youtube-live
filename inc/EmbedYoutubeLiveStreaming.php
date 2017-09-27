<?php

class EmbedYoutubeLiveStreaming {
    public $channelId;
    public $API_Key;

    public $jsonResponse; // pure server response
    public $objectResponse; // response decoded as object
    public $arrayRespone; // response decoded as array

    public $errorMessage; // error message
    public $errorArray; // all error codes

    public $isLive; // true if there is a live streaming at the channel

    public $queryData; // query values as an array
    public $getAddress; // address to request GET
    public $getQuery; // data to request, encoded

    public $queryString; // Address + Data to request

    public $part;
    public $eventType;
    public $type;

    public $subdomain;

    public $default_embed_width;
    public $default_embed_height;
    public $default_ratio;

    public $embed_code; // contain the embed code
    public $embed_autoplay;
    public $embed_width;
    public $embed_height;
    public $show_related;

    public $live_video_id;
    public $live_video_title;
    public $live_video_description;

    public $live_video_publishedAt;

    public $live_video_thumb_default;
    public $live_video_thumb_medium;
    public $live_video_thumb_high;

    public $resource_type;

    public $uploads_id;

    public $channel_title;

    /**
     * Set up the query
     * @param string  $ChannelID  YouTube channel ID
     * @param string  $API_Key    Google Developers API key
     * @param boolean [$autoQuery = true]  whether to automatically run the query
     */
    public function __construct($ChannelID, $API_Key, $autoQuery = true) {
        $this->channelId = $ChannelID;
        $this->API_Key = $API_Key;

        $this->part = "id,snippet";
        $this->eventType = "live";
        $this->type = "video";

        $this->getAddress = "https://www.googleapis.com/youtube/v3/";
        $this->resource = "search";

        $this->default_embed_width = "560";
        $this->default_embed_height = "315";
        $this->default_ratio = $this->default_embed_width / $this->default_embed_height;

        $this->embed_width = $this->default_embed_width;
        $this->embed_height = $this->default_embed_height;

        $this->embed_autoplay = true;

        if ( $autoQuery == true ) {
            $this->getVideoInfo();
        }
    }

    /**
     * Get video info
     * @param string [$resource_type           = 'live'] type of video resource (live, video, channel, etc.)
     * @param string [$event_type              = 'live'] type of event (live, upcoming, completed)
     */
    public function getVideoInfo( $resource_type = 'live', $event_type = 'live' ) {
        // check transient before performing query
        $wp_youtube_live_api_transient = maybe_unserialize( get_transient( 'wp-youtube-live-api-response' ) );

        if ( ! $this->resource_type || $resource_type !== $this->resource_type ) {
            $this->resource_type = $resource_type;
        }

        if ( ! $this->eventType || $event_type !== $this->eventType ) {
            $this->eventType = $event_type;
        }

        if ( $wp_youtube_live_api_transient && array_key_exists( $this->eventType, $wp_youtube_live_api_transient ) ) {
            // 30-second transient is set
            reset( $wp_youtube_live_api_transient );
            $key_name = key( $wp_youtube_live_api_transient );
            $this->jsonResponse = $wp_youtube_live_api_transient[$key_name];
            $this->objectResponse = json_decode( $this->jsonResponse );
        } elseif ( $this->eventType === 'upcoming' ) {
            // get info for this video
            $this->resource = 'videos';

            $this->queryData = array(
                "key"   => $this->API_Key,
                "part"  => 'id,snippet',
                "id"    => $this->getUpcomingVideoInfo(),
            );

            // run the query
            $this->queryAPI();

            // save to 30-second transient to reduce API calls
            $API_results = array( $this->eventType => $this->jsonResponse );
            if ( is_array( $wp_youtube_live_api_transient ) ) {
                $API_results = array_merge( $API_results, $wp_youtube_live_api_transient );
            }
            set_transient( 'wp-youtube-live-api-response', maybe_serialize( $API_results ), apply_filters( 'wp_youtube_live_transient_timeout', '30' ) );
        } else {
            // no 30-second transient is set

            // set up query data
            $this->queryData = array(
                "part"      => $this->part,
                "channelId" => $this->channelId,
                "eventType" => $this->eventType,
                "type"      => $this->type,
                "key"       => $this->API_Key,
            );

            // set up additional query data for last live video
            if ( $this->eventType === 'completed' ) {
                $additional_data = array(
                    'part'          => 'id,snippet',
                    'eventType'     => 'completed',
                    'order'         => 'date',
                    'maxResults'    => '1',
                );

                $this->queryData = array_merge( $this->queryData, $additional_data );
            }

            // run the query
            $this->queryAPI();

            // save to 30-second transient to reduce API calls
            $API_results = array( $this->eventType => $this->jsonResponse );
            if ( is_array( $wp_youtube_live_api_transient ) ) {
                $API_results = array_merge( $API_results, $wp_youtube_live_api_transient );
            }
            set_transient( 'wp-youtube-live-api-response', maybe_serialize( $API_results ), apply_filters( 'wp_youtube_live_transient_timeout', '30' ) );
        }

        if ( count( $this->objectResponse->items ) > 0 && ( ( $this->resource_type == 'live' && $this->isLive() ) || ( $this->resource_type == 'live' && in_array( $this->eventType, array( 'upcoming', 'completed' ) ) ) ) ) {
            if ( 'upcoming' === $this->eventType ) {
                $this->live_video_id = $this->objectResponse->items[0]->id;
            } else {
                $this->live_video_id = $this->objectResponse->items[0]->id->videoId;
            }
            $this->live_video_title = $this->objectResponse->items[0]->snippet->title;
            $this->live_video_description = $this->objectResponse->items[0]->snippet->description;

            $this->live_video_published_at = $this->objectResponse->items[0]->snippet->publishedAt;
            $this->live_video_thumb_default = $this->objectResponse->items[0]->snippet->thumbnails->default->url;
            $this->live_video_thumb_medium = $this->objectResponse->items[0]->snippet->thumbnails->medium->url;
            $this->live_video_thumb_high = $this->objectResponse->items[0]->snippet->thumbnails->high->url;

            $this->channel_title = $this->objectResponse->items[0]->snippet->channelTitle;
            $this->embedCode();
        } elseif ( $this->resource_type == 'channel' ) {
            $this->resource = 'channels';
            $this->queryData = array(
                "id"    => $this->channelId,
                "key"   => $this->API_Key,
                "part"  => 'contentDetails'
            );
            $this->queryAPI();

            if ( $this->objectResponse ) {
                $this->uploads_id = $this->objectResponse->items[0]->contentDetails->relatedPlaylists->uploads;
                $this->resource_type = 'channel';
            }

            $this->embedCode();
        }
    }

    /**
     * Manually clear upcoming video cache
     * @return boolean whether the transient was successfully set
     */
    function clearUpcomingVideoInfo() {
        if ( get_transient( 'youtube-live-upcoming-videos' ) ) {
            delete_transient( 'youtube-live-upcoming-videos' );
        }

        return $this->cacheUpcomingVideoInfo();
    }

    /**
     * Cache info for all scheduled upcoming videos
     * @return boolean whether 24-hour transient was set
     */
    function cacheUpcomingVideoInfo() {
        // set up query data
        $this->queryData = array(
            "channelId"     => $this->channelId,
            "key"           => $this->API_Key,
            "part"          => 'id',
            "eventType"     => 'upcoming',
            "type"          => 'video',
            "maxResults"    => 50,
        );

        // run the query
        $all_upcoming_videos = json_decode( $this->queryAPI() );
        $all_videos_array = array();

        foreach ( $all_upcoming_videos->items as $video ) {
            $this->resource = 'videos';
            $this->queryData = array(
                "channelId"     => $this->channelId,
                "key"           => $this->API_Key,
                "id"            => $video->id->videoId,
                "part"          => 'liveStreamingDetails',
            );

            $this_video = json_decode( $this->queryAPI() );
            $start_time = date( 'U', strtotime( $this_video->items[0]->liveStreamingDetails->scheduledStartTime ) );
            $all_videos_array[$video->id->videoId] = $start_time;
        }

        // sort by date
        asort( $all_videos_array );

        return set_transient( 'youtube-live-upcoming-videos', maybe_serialize( $all_videos_array ), 86400 );
    }

    /**
     * Get next scheduled upcoming video
     * @return string video ID
     */
    function getUpcomingVideoInfo() {
        $now = time();

        $upcoming_videos = get_transient( 'youtube-live-upcoming-videos' );
        $next_video = '';

        if ( ! $upcoming_videos ) {
            $this->cacheUpcomingVideoInfo();
        } else {
            foreach ( maybe_unserialize( $upcoming_videos ) as $id => $start_time ) {

                if ( $start_time > time() ) {
                    $next_video = $id;
                    break;
                }
            }
        }

        return $next_video;
    }

    /**
     * Query the YouTube API
     * @return string JSON API response
     */
    function queryAPI() {
        $this->getQuery = http_build_query( $this->queryData ); // transform array of data in url query
        $this->queryString = $this->getAddress . $this->resource . '?' . $this->getQuery;

        // request from API via curl
        $curl = curl_init();
        curl_setopt( $curl, CURLOPT_URL, $this->queryString );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $curl, CURLOPT_CAINFO, plugin_dir_path( __FILE__ ) . 'cacert.pem' );
        curl_setopt( $curl, CURLOPT_CAPATH, plugin_dir_path( __FILE__ ) );
        $this->jsonResponse = curl_exec( $curl );
        curl_close( $curl );

        #TODO: add If-None-Match etag header to improve performance

        $this->objectResponse = json_decode( $this->jsonResponse ); // decode as object
        $this->arrayResponse = json_decode( $this->jsonResponse, TRUE ); // decode as array

        if ( property_exists( $this->objectResponse, 'error' ) ) {

            $this->errorMessage = $this->objectResponse->error->message;
            $this->errorArray = $this->arrayResponse['error']['errors'];
        }

        return $this->jsonResponse;
    }

    /**
     * Determine whether there is a live video or not
     * @param  boolean [$getOrNot = false] whether to run the query or not
     * @return boolean whether or not a video is live
     */
    public function isLive( $getOrNot = false ) {
        if ( $getOrNot == true ) {
            $this->getVideoInfo();
        }

        if ( $this->objectResponse ) {
            $live_items = count( $this->objectResponse->items );

            if ( $live_items > 0 ) {
                $this->isLive = true;
                return true;
            } else {
                $this->isLive = false;
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Calculate embed size by width
     * @param integer $width        width in pixels
     * @param boolean [$refill_code = true] whether to generate embed code or not
     */
    public function setEmbedSizeByWidth( $width, $refill_code = true ) {
        $ratio = $this->default_embed_width / $this->default_embed_height;
        $this->embed_width = $width;
        $this->embed_height = $width / $ratio;

        if ( $refill_code == true ) {
            $this->embedCode();
        }
    }

    /**
     * Calculate embed size by height
     * @param integer $height       height in pixels
     * @param boolean [$refill_code = true] whether to generate embed code or not
     */
    public function setEmbedSizeByHeight( $height, $refill_code = true ) {
        $ratio = $this->default_embed_width / $this->default_embed_height;
        $this->embed_height = $height;
        $this->embed_width = $height * $ratio;

        if ( $refill_code == true ) {
            $this->embedCode();
        }
    }

    /**
     * Generate embed code
     * @return string HTML embed code
     */
    public function embedCode() {
        $autoplay = $this->embed_autoplay ? 1 : 0;
        $related = $this->show_related ? 1 : 0;
        if ( $this->resource_type == 'channel' ) {
            $this->embed_code = '<iframe
                id="wpYouTubeLive"
                width="' . $this->embed_width . '"
                height="' . $this->embed_height . '"
                src="https://' . $this->subdomain. '.youtube.com/?listType=playlist&list=' . $this->uploads_id . $embedResource . '?autoplay='. $autoplay . '&rel=' . $related . '"
                frameborder="0"
                allowfullscreen>
            </iframe>';
        } else {
            wp_enqueue_script( 'youtube-iframe-api' );
            ob_start(); ?>
                <div id="wpYouTubeLive" width="<?php echo $this->embed_width; ?>" height="<?php echo $this->embed_height; ?>"></div>
            <?php
            $this->embed_code = ob_get_clean();

            $api_inline_script = "
                var player;
                function onYouTubeIframeAPIReady() {
                    player = new YT.Player('wpYouTubeLive', {
                        videoId: '$this->live_video_id',
                        playerVars: {
                            'autoplay': $autoplay,
                            'rel': $related
                        },
                        events: {
                            'onReady': wpYTonPlayerReady,
                            'onStateChange': wpYTonPlayerStateChange
                        }
                    });
                }
            ";
            wp_add_inline_script( 'youtube-iframe-api', $api_inline_script );
        }

        return $this->embed_code;
    }

    /**
     * Get error message string
     * @return string error message
     */
    public function getErrorMessage() {
        return $this->errorMessage;
    }

    /**
     * Get detailed array of error messages
     * @return array array of all messages
     */
    public function getAllErrors() {
        return $this->errorArray;
    }
}
