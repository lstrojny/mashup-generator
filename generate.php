<?php
ini_set('short_open_tag', 1);
$mashupGeneratorLocalTimezone = new DateTimeZone('Europe/Berlin');

function MashupGenerator_main($template, $output)
{
    $shortOpenTag = ini_get('short_open_tag');
    if (!ini_get('short_open_tag')) {
        MashupGenerator_error('INI setting short_open_tag no enabled. Please pass -dshort_open_tag=On');
    }

    if (!is_readable($template)) {
        MashupGenerator_error('Template file "%s" is not readable', $template);
    }

    if ((file_exists($output) && !is_writable($output)) || !touch($output)) {
        MashupGenerator_error('Output file "%s" it no writable', $output);
    }

    $outputTempfile = $output . rand() . microtime(true);
    if ((file_exists($outputTempfile) && !is_writable($outputTempfile)) || !touch($outputTempfile)) {
        MashupGenerator_error('Output tempfile "%s" it no writable', $outputTempfile);
    }

    if (!file_exists('config.php')) {
        MashupGenerator_error('config.php not found');
    }
    include 'config.php';

    $encode = function($str){return htmlentities($str, ENT_QUOTES, 'UTF-8');};
    $decode = function($str){return html_entity_decode($str, ENT_QUOTES, 'UTF-8');};
    $elipsize = function($str, $length){return mb_strlen($str, 'UTF-8') > $length
                                               ? mb_substr($str, 0, mb_strrpos(mb_substr($str, 0, $length), ' ')) . '&nbsp;…'
                                               : $str;};
    $dateFormat = '<\s\p\a\n \t\i\t\l\e="e \t\i\m\e\z\o\n\e">F&\n\b\s\p;j<\s\u\p>S</\s\u\p>&\n\b\s\p;Y, h:i:s&\n\b\s\p;a</\s\p\a\n>';

    if (MASHUPGENERATOR_TWEETS) {
        $tweets = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_TWEETS_TTL,
            'MashupGenerator_getTweets',
            array(
             MASHUPGENERATOR_TWEETS_USERNAME,
             MASHUPGENERATOR_TWEETS_LIMIT
            )
        );
    }

    if (MASHUPGENERATOR_GITHUBACTIVITIES) {
        $gitHubActivities = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_GITHUBACTIVITIES_TTL,
            'MashupGenerator_getGitHubActivities',
            array(
             MASHUPGENERATOR_GITHUBACTIVITIES_USERNAME,
             MASHUPGENERATOR_GITHUBACTIVITIES_LIMIT,
            )
        );
    }

    if (MASHUPGENERATOR_FLICKRPHOTOS) {
        $flickrPhotos = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_FLICKRPHOTOS_TTL,
            'MashupGenerator_getFlickrPhotos',
            array(
             MASHUPGENERATOR_FLICKRPHOTOS_USERID,
             MASHUPGENERATOR_FLICKRPHOTOS_LIMIT,
            )
        );
    }

    if (MASHUPGENERATOR_PINBOARDBOOKMARKS) {
        $pinboardBookmarks = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_PINBOARDBOOKMARKS_TTL,
            'MashupGenerator_getPinboardBookmarks',
            array(
             MASHUPGENERATOR_PINBOARDBOOKMARKS_USERNAME,
             MASHUPGENERATOR_PINBOARDBOOKMARKS_LIMIT
            )
        );
    }

    if (MASHUPGENERATOR_LASTFMTRACKS) {
        $lastFmTracks = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_LASTFMTRACKS_TTL,
            'MashupGenerator_getLastFmTracks',
            array(
             MASHUPGENERATOR_LASTFMTRACKS_USERNAME,
             MASHUPGENERATOR_LASTFMTRACKS_APIKEY,
             MASHUPGENERATOR_LASTFMTRACKS_LIMIT,
            )
        );
    }

    if (MASHUPGENERATOR_TOPALBUM) {
        $topAlbum = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_TOPALBUM_TTL,
            'MashupGenerator_getTopAlbumFromLastFmAndCoverFromAmazon',
            array(
             MASHUPGENERATOR_TOPALBUM_LASTFM_USERNAME,
             MASHUPGENERATOR_TOPALBUM_LASTFM_APIKEY,
             MASHUPGENERATOR_TOPALBUM_LASTFM_AWS_KEY,
             MASHUPGENERATOR_TOPALBUM_LASTFM_AWS_SECRET
            )
        );
    }

    if (MASHUPGENERATOR_BLOGENTRIES) {
        $blogEntries = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_BLOGENTRIES_TTL,
            'MashupGenerator_getBlogEntries',
            array(
             MASHUPGENERATOR_BLOGENTRIES_FEED,
             MASHUPGENERATOR_BLOGENTRIES_LIMIT,
            )
        );
    }

    if (MASHUPGENERATOR_BLOGCOMMENTS) {
        $blogComments = MashupGenerator_cacheFunction(
            MASHUPGENERATOR_BLOGCOMMENTS_TTL,
            'MashupGenerator_getBlogComments',
            array(
             MASHUPGENERATOR_BLOGCOMMENTS_FEED,
             MASHUPGENERATOR_BLOGCOMMENTS_LIMIT,
            )
        );
    }

    ob_start();
    include $template;
    file_put_contents($outputTempfile, ob_get_clean());
    rename($outputTempfile, $output);
    MashupGenerator_log('Successfully wrote "%s"', $output);
}

function MashupGenerator_error($message)
{
    $args = func_get_args();
    array_shift($args);
    die(vsprintf($message . PHP_EOL, $args));
}

function MashupGenerator_log($message)
{
    global $mashupGeneratorLastFunction;

    $args = func_get_args();
    array_shift($args);


    foreach ($args as &$arg) {
        $arg = preg_replace('/(key|signature)([^=]*)=([^\&]+)/i', '\1\2=...', $arg);
    }

    $date = DateTime::createFromFormat('U.u', microtime(true));
    $fmt = 'D, d M y H:i:s.u';

    $bt = debug_backtrace();
    $function = $bt[1]['function'];
    if ($function != $mashupGeneratorLastFunction) {
        $mashupGeneratorLastFunction = $function;
        $prefix = $function . ' ' . $date->format($fmt) . ': ';
    } else {
        $prefix = str_repeat(' ', strlen($function) + 1) . $date->format($fmt) . ': ';
    }

    vprintf($prefix . $message . PHP_EOL, $args);
}

function MashupGenerator_cacheFunction($expire, $function, array $arguments = array())
{
    $now = time();
    $dir = __DIR__ . '/.cache';
    $filename = $dir . '/' . str_replace('\\', '.', $function) . md5(serialize($arguments));
    if (file_exists($filename)) {
        $file = fopen($filename, 'rb');
        $expiry = fgets($file);
        if ($now > $expiry) {
            fclose($file);
            unlink($filename);
            MashupGenerator_log('Cache for %s expired. Cleanup up', $function);
        } else {
            MashupGenerator_log('Reading %s from cache', $function);
            $serialized = '';
            while (!feof($file)) {
                $serialized .= fgets($file);
            }
            $data = unserialize($serialized);
            fclose($file);
            return $data;
        }
    }

    $data = call_user_func_array($function, $arguments);

    if (!file_exists($dir) || !is_dir($dir)) {
        MashupGenerator_log('Cache dir "%s" does not exists or is not an directory', $dir);
    } else {
        MashupGenerator_log('Caching %s result for %f minutes', $function, $expire / 60);
        $file = fopen($filename, 'w+');
        fputs($file, $now + $expire . "\n");
        fputs($file, serialize($data));
        fclose($file);
    }

    return $data;
}


function MashupGenerator_Escape($str)
{
    return htmlentities($str, ENT_QUOTES, 'UTF-8');
}


function MashupGenerator_getTweets($username, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $resource = sprintf(
        'http://api.twitter.com/1/statuses/user_timeline.json?screen_name=%s&exclude_replies=true&count=%d',
        rawurlencode($username),
        $limit * 4
    );
    $json = file_get_contents($resource);
    if (!$json) {
        MashupGenerator_error('Could not fetch resource "%s" for "%s"', $resource, $username);
    }

    MashupGenerator_log('Successfully fetched "%s"', $resource);

    $tweets = json_decode($json, true);
    if (json_last_error()) {
        MashupGenerator_error('Could not decode JSON: %d', json_last_error());
    }

    MashupGenerator_log('Successfully decoded');

    $tweet = array_slice($tweets, 0, $limit);

    foreach ($tweets as &$tweet) {
        // Wed Jul 20 23:11:50 +0000 2011
        $tweet['date'] = DateTime::createFromFormat(
            'D M d H:i:s O Y',
            $tweet['created_at'],
            new DateTimeZone('UTC')
        );
        $tweet['date']->setTimeZone($mashupGeneratorLocalTimezone);
        $tweet['text'] = preg_replace_callback('@(https?://|#)([^\s:]+)@', function($matches) use (&$tweet) {
            if ($matches[1] == '#') {
                return sprintf('<span class="small">%s</span>', MashupGenerator_Escape($matches[0]));
            } else {
                $url = $matches[0];
                foreach (get_headers($url) as $header) {
                    if (stripos($header, 'location:') !== false) {
                        $url = preg_replace('/location:\s*/i', '', $header);
                    }
                }
                MashupGenerator_log('Resolved short URL "%s" to "%s"', $matches[0], $url);
                $name = preg_replace('@^https?://(www\.)?(.+)$@', '\2', $url);
                $name = rtrim($name, '/');
                if (strlen($name) > 30) {
                    $name = substr($name, 0, 30) . '…';
                }
                return sprintf('<a href="%s">%s</a>', $url, $name);
            }
        }, $tweet['text']);
    }

    MashupGenerator_log('Successfully transformed');

    return $tweets;
}

function MashupGenerator_getGitHubActivities($username, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $resource = sprintf('https://github.com/%s.atom', rawurlencode($username));
    $xml = file_get_contents($resource);
    if (!$xml) {
        MashupGenerator_error('Could not fetch resource "%s" for "%s"', $resource, $username);
    }

    MashupGenerator_log('Successfully fetched "%s"', $resource);

    try {
        $xml = new SimpleXmlElement($xml);
    } catch (Exception $e) {
        MashupGenerator_Fata('Could not parse resource "%s" as XML', $e->getMessage());
    }
    MashupGenerator_log('Successfully parsed');

    $contribs = array();

    $i = 0;
    foreach ($xml->entry as $entry) {
        $contrib = array();
        $contrib['title'] = html_entity_decode((string)$entry->title, ENT_QUOTES, 'UTF-8');
        $contrib['date'] = DateTime::createFromFormat(DATE_ATOM, $entry->published);
        $contrib['date']->setTimeZone($mashupGeneratorLocalTimezone);
        $contrib['link'] = (string)$entry->link['href'];

        $contribs[] = $contrib;
        if (++$i == $limit) {
            break;
        }
    }

    MashupGenerator_log('Successfully transformed');

    return $contribs;
}

function MashupGenerator_getFlickrPhotos($flickrId, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $resource = sprintf(
        "http://api.flickr.com/services/feeds/photos_public.gne?id=%s&format=php_serial",
        rawurlencode($flickrId)
    );
    $stream = file_get_contents($resource);
    if (!$stream) {
        MashupGenerator_error('Could not fetch resource "%s" for "%s"', $resource, $flickrId);
    }

    MashupGenerator_log('Successfully fetched "%s"', $resource);

    $photos = unserialize($stream);
    if (!$photos) {
        MashupGenerator_error('Could not unserialize', $resource, $flickrId);
    }
    MashupGenerator_log('Successfully unserialized');

    $photos = array_slice($photos['items'], 0, $limit);

    foreach ($photos as &$photo) {
        $photo['date_uploaded'] = DateTime::createFromFormat('U', $photo['date']);
        $photo['date_uploaded']->setTimeZone($mashupGeneratorLocalTimezone);
    }

    MashupGenerator_log('Successfully transformed');

    return $photos;
}

function MashupGenerator_getPinboardBookmarks($username, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $resource = sprintf('http://feeds.pinboard.in/rss/u:%s', rawurlencode($username));
    $stream = file_get_contents($resource);
    if (!$stream) {
        MashupGenerator_error('Could not fetch resource "%s" for "%s"', $resource, $username);
    }

    MashupGenerator_log('Successfully fetched "%s"', $resource);

    $taxoNs = 'http://purl.org/rss/1.0/modules/taxonomy/';
    $dcNs = 'http://purl.org/dc/elements/1.1/';
    $rdfNs = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    try {
        $xml = new SimpleXmlElement($stream);
    } catch (Exception $e) {
        MashupGenerator_Fata('Could not parse resource "%s" as XML', $e->getMessage());
    }
    MashupGenerator_log('Successfully parsed');

    $bookmarks = array();
    $i = 0;
    foreach ($xml->item as $entry) {
        $date = DateTime::createFromFormat(DATE_ATOM, $entry->children($dcNs)->date);
        $date->setTimeZone($mashupGeneratorLocalTimezone);
        $taxonomy = $entry->children($taxoNs);
        $tags = array();
        if ($taxonomy->topics) {
            foreach ($taxonomy->topics->children($rdfNs)->Bag->li as $topic) {
                $tags[] = array(
                    'tag'  => preg_replace('/.*:([^:]+)$/', '$1', $topic['resource']),
                    'link' => (string)$topic['resource'],
                );
            }
        }

        $bookmarks[] = array(
            'title' => (string)$entry->title,
            'link'  => (string)$entry->link,
            'date'  => $date,
            'tags'  => $tags,
        );
        if (++$i == $limit) {
            break;
        }
    }
    MashupGenerator_log('Successfully transformed');

    return $bookmarks;
}

function MashupGenerator_getLastFmTracks($lastFmUsername, $lastFmApiKey, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $resource = sprintf(
        'http://ws.audioscrobbler.com/2.0/?method=user.getRecentTracks&user=%s&limit=%d&api_key=%s',
        rawurlencode($lastFmUsername),
        $limit * 2,
        rawurlencode($lastFmApiKey)
    );

    $stream = file_get_contents($resource);
    if (!$stream) {
        MashupGenerator_error('Could not fetch resource "%s" for "%s"', $resource, $lastFmUsername);
    }
    MashupGenerator_log('Successfully fetched "%s"', $resource);

    try {
        $xml = new SimpleXmlElement($stream);
    } catch (Exception $e) {
        MashupGenerator_Fata('Could not parse resource "%s" as XML', $e->getMessage());
    }
    MashupGenerator_log('Successfully parsed');

    $tracks = array();
    $i = 0;
    foreach ($xml->recenttracks->track as $track) {
        if ((string)$track->artist['mbid'] == '') {
            continue;
        }

        $images = array();
        foreach ($track->image as $image) {
            $images[] = (string)$image;
        }
        $tracks[] = array(
            'title'  => (string)$track->name,
            'artist' => (string)$track->artist,
            'album'  => (string)$track->album,
            'link'   => (string)$track->url,
            'images' => $images,
        );
        if (++$i == $limit) {
            break;
        }
    }
    MashupGenerator_log('Successfully transformed');

    return $tracks;
}

function MashupGenerator_getTopAlbumFromLastFmAndCoverFromAmazon($lastFmUsername, $lastFmApiKey, $awsId, $awsKey)
{
    $resource = 'http://ws.audioscrobbler.com/2.0/?method=user.gettopalbums&user=%s&api_key=%s&format=json&limit=2&period=7day';
    $resource = sprintf($resource, rawurlencode($lastFmUsername), rawurlencode($lastFmApiKey));
    $topAlbums = file_get_contents($resource);
    if (!$topAlbums) {
        MashupGenerator_error('Could not fetch resource "%s" for "%s"', $resource, $username);
    }
    MashupGenerator_log('Successfully fetched "%s"', $resource);
    $topAlbums = json_decode($topAlbums, true);
    if (json_last_error()) {
        MashupGenerator_error('Could not decode JSON: %d', json_last_error());
    }
    MashupGenerator_log('Successfully decoded');

    $albumInfo = array();
    foreach ($topAlbums['topalbums']['album'] as $albumCnt => $album) {
        $artistName = mb_strtolower($album['artist']['name'], 'UTF-8');
        $albumName = mb_strtolower($album['name'], 'UTF-8');
        $params = array(
            'Service'        => 'AWSECommerceService',
            'AWSAccessKeyId' => $awsId,
            'Operation'      => 'ItemSearch',
            'SearchIndex'    => 'Music',
            'Artist'         => $artistName,
            'Title'          => $albumName,
            'ResponseGroup'  => 'Images,ItemAttributes',
            'Version'        => '2010-11-01',
            'Timestamp'      => gmdate("Y-m-d\TH:i:s\Z"),
        );

        $url = 'http://ecs.amazonaws.com/onca/xml';
        ksort($params);
        $paramList = array();
        foreach ($params as $key => $value) {
            $paramList[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $sigBase = join("\n", array(
            'GET',
            parse_url($url, PHP_URL_HOST),
            parse_url($url, PHP_URL_PATH),
            join('&', $paramList),
        ));
        $signature = base64_encode(hash_hmac('sha256', $sigBase, $awsKey, true));
        $amazonResource = $url . '?'
                        . join('&', $paramList)
                        . '&Signature=' . rawurlencode($signature);

        $xml = file_get_contents($amazonResource);
        if (!$xml) {
            MashupGenerator_error('Could not fetch Amazon resource "%s"', $amazonResource);
        }
        MashupGenerator_log('Successfully fetch Amazon resource "%s" for Album #%d (%s)', $amazonResource, $albumCnt, $albumName);
        $xml = new SimpleXmlElement($xml);
        foreach ($xml->Items->Item as $itemCnt => $item) {
            $mismatch = false;
            $foundArtist = mb_strtolower($item->ItemAttributes->Artist, 'UTF-8');
            if ($foundArtist != $artistName) {
                $mismatch = true;
                MashupGenerator_log('Artist #%d.%d did not match. Searched "%s", got "%s"', $albumCnt, $itemCnt, $artistName, $foundArtist);
            }
            $foundAlbum = mb_strtolower($item->ItemAttributes->Title, 'UTF-8');
            if ($foundAlbum != $albumName) {
                $mismatch = true;
                MashupGenerator_log('Album #%d.%d did not match. Searched "%s", got "%s"', $albumCnt, $itemCnt, $albumName, $foundAlbum);
            }

            if ($mismatch) {
                continue;
            }

            MashupGenerator_log('Album #%d.%d matched', $albumCnt, $itemCnt);

            $images = array();
            foreach ($item->ImageSets->ImageSet->children() as $image) {
                $images[strtolower(str_replace('Image', '', $image->getName()))] = array(
                    'url'    => (string)$image->URL,
                    'width'  => (string)$image->Width,
                    'height' => (string)$image->Height,
                );
            }
            if ($images) {
                $albumInfo = $album;
                unset($albumInfo['image']);
                $albumInfo['images'] = $images;

                break;
            } else {
                MashupGenerator_log('Album #%d did not reveal images. Skipped', $itemCnt);
            }

        }

        if ($albumInfo) {
            break;
        }
    }

    return $albumInfo;
}

function MashupGenerator_getBlogEntries($url, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $entries = file_get_contents($url);
    if (!$entries) {
        MashupGenerator_error('Could not fetch "%s"', $url);
    }
    $entries = new SimpleXmlElement($entries);

    MashupGenerator_log('Successfully parsed');

    $blog = array();
    $cnt = 0;
    foreach ($entries->channel->item as $entry) {
        $date = DateTime::createFromFormat(DATE_RFC2822, $entry->pubDate);
        $date->setTimeZone($mashupGeneratorLocalTimezone);
        $blog[] = array(
            'title'        => (string)$entry->title,
            'link'         => (string)$entry->link,
            'commentLink'  => (string)$entry->comments,
            'comments'     => (int)(string)$entry->children('http://purl.org/rss/1.0/modules/slash/')->comments,
            'content'      => html_entity_decode($entry->children('http://purl.org/rss/1.0/modules/content/')->encoded, ENT_QUOTES, 'UTF-8'),
            'date'         => $date,
        );
        if (++$cnt == $limit) {
            break;
        }
    }
    MashupGenerator_log('Successfully transformed');

    return $blog;
}

function MashupGenerator_getBlogComments($url, $limit = 10)
{
    MashupGenerator_log('Started');
    global $mashupGeneratorLocalTimezone;

    $xml = file_get_contents($url);
    if (!$xml) {
        MashupGenerator_error('Could not fetch "%s"', $url);
    }
    $xml = new SimpleXmlElement($xml);

    MashupGenerator_log('Successfully parsed');

    $comments = array();
    $cnt = 0;
    foreach ($xml->channel->item as $comment) {
        $date = DateTime::createFromFormat(DATE_RFC2822, $comment->pubDate);
        $date->setTimeZone($mashupGeneratorLocalTimezone);
        $blog[] = array(
            'title'   => (string)$comment->title,
            'link'    => (string)$comment->link,
            'content' => html_entity_decode($comment->children('http://purl.org/rss/1.0/modules/content/')->encoded, ENT_QUOTES, 'UTF-8'),
            'date'    => $date,
            'author'  => preg_replace('/.*\((.*)\)$/', '$1', $comment->author),
        );
        if (++$cnt == $limit) {
            break;
        }
    }
    MashupGenerator_log('Successfully transformed');

    return $blog;
}

if (file_exists('template.phtml')) {
    MashupGenerator_main("template.phtml", "out.html");
} else {
    MashupGenerator_main("template.phtml.example", "out.html");
}
