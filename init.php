<?php
class Youtube_Short_Filter extends Plugin {
	private $host;

	function about() {
		return array(1.0,
			"Filters out shorts from Youtube feeds",
			"bdutro");
	}

	function init($host) {
		$this->host = $host;

        $host->add_hook($host::HOOK_FEED_FETCHED, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
	}

    private function get_stored_array($name) {
            $tmp = $this->host->get($this, $name);

            if (!is_array($tmp)) $tmp = [];

            return $tmp;
    }

    function hook_prefs_edit_feed($feed_id) {
            $enabled_feeds = $this->get_stored_array("enabled_feeds");
            ?>

            <header><?= __("Youtube Shorts Filter") ?></header>
            <section>
                    <fieldset>
                            <label class='checkbox'>
                                    <?= \Controls\checkbox_tag("youtube_filter_shorts_enabled", in_array($feed_id, $enabled_feeds)) ?>
                                    <?= __('Remove shorts from Youtube feeds') ?>
                            </label>
                    </fieldset>
            </section>
            <?php
    }

    function hook_prefs_save_feed($feed_id) {
            $enabled_feeds = $this->get_stored_array("enabled_feeds");

            $enable = checkbox_to_sql_bool($_POST["youtube_filter_shorts_enabled"] ?? "");

            $enable_key = array_search($feed_id, $enabled_feeds);

            if ($enable) {
                    if ($enable_key === false) {
                            array_push($enabled_feeds, $feed_id);
                    }
            } else {
                    if ($enable_key !== false) {
                            unset($enabled_feeds[$enable_key]);
                    }
            }

            $this->host->set($this, "enabled_feeds", $enabled_feeds);
    }


    function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed) {
        $enabled_feeds = $this->get_stored_array("enabled_feeds");

        if(!in_array($feed, $enabled_feeds)) {
            return $feed_data;
        }

        $feed_cache = $this->get_stored_array('feed_cache');
        $feed_hash = md5($feed_data);
        if(!array_key_exists($feed, $feed_cache)) {
            $feed_cache[$feed] = [
                'hash' => '',
                'data' => '',
                'known_shorts' => []
            ];
        }
        if ($feed_cache[$feed]['hash'] != $feed_hash) {
            Debug::log('Filtering Youtube shorts from feed ' . $feed);
            $xml = new DOMDocument();
            $xml->preserveWhiteSpace = false;
            $xml->loadXML($feed_data);
            $entries_to_delete = array();
            foreach ($xml->getElementsByTagName('entry') as $entry) {
                foreach ($entry->getElementsByTagNameNS('http://www.youtube.com/xml/schemas/2015', 'videoId') as $video_id_node) {
                    $video_id = $video_id_node->nodeValue;
                    if(!in_array($video_id, $feed_cache[$feed]['known_shorts'])) {
                        $url = 'https://www.youtube.com/shorts/' . $video_id;
                        $handle = curl_init($url);
                        curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);
                        curl_setopt($handle,  CURLOPT_NOBODY, TRUE);
                        curl_setopt($handle,  CURLOPT_HEADER, TRUE);
                        $response = curl_exec($handle);
                        $httpCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                        curl_close($handle);
                        if($httpCode == 200) {
                            Debug::log($url . "is a short");
                            $entries_to_delete[] = $entry;
                            $feed_cache[$feed]['known_shorts'][] = $video_id;
                        }
                    }
                    else {
                        $entries_to_delete[] = $entry;
                    }
                }
            }
            foreach($entries_to_delete as $entry) {
                $entry->parentNode->removeChild($entry);
            }
            $feed_cache[$feed]['hash'] = $feed_hash;
            $feed_cache[$feed]['data'] = $xml->saveXML();
            $this->host->set($this, 'feed_cache', $feed_cache);
        }
        return $feed_cache[$feed]['data'];
    }

	function api_version() {
		return 2;
	}

}
?>
