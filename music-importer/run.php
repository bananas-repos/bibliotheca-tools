<?php
/**
 * Copyright 2021 Johannes Keßler
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

define('DEBUG',true);

mb_http_output('UTF-8');
mb_internal_encoding('UTF-8');
ini_set('error_reporting',-1); // E_ALL & E_STRICT
# time settings
date_default_timezone_set('Europe/Berlin');
## set the error reporting
ini_set('log_errors',true);
ini_set('error_log','import-error.log');
if(DEBUG === true) {
	ini_set('display_errors',true);
}
else {
	ini_set('display_errors',false);
}

# where the music files at
# designed to work with the following structure
# MUSIC_LIB_FOLDER/artist/year/album/tracks|coverfile
define('MUSIC_LIB_FOLDER','/media/Storage/mp3/');
define('MUSIC_FOLDER_IGNORE',array(
	'Mix', 'OST', 'Tron'
));

# the bibliotheca api endpoint
define('API_ENDPOINT','http://localhost/code/bibliotheca-php-test/webclient/api.php?p=add');
# the tolen to connect to the api.
# created in bibliotheca use management
define('API_TOKEN','8551bb65a92c81aadb917aacf0e9d560');

# the bibliotheca collection to store into
define('BIB_COLLECTION_ID','2');

if(!is_dir(MUSIC_LIB_FOLDER) || !is_readable(MUSIC_LIB_FOLDER)) {
	exit('Can not access or read: '.MUSIC_LIB_FOLDER);
}

require_once('getid3/getid3.php');
$getID3 = new getID3();

$music = array();

# read based on the designed dir structure
foreach (new DirectoryIterator(MUSIC_LIB_FOLDER) as $artistInfo) {
	if($artistInfo->isDot()) continue;
	if($artistInfo->isFile()) continue;
	$artist = $artistInfo->getFilename();
	$artist = str_replace('-',' ',$artist);
	if(in_array($artist,MUSIC_FOLDER_IGNORE)) continue;

	echo "$artist\n";
	foreach (new DirectoryIterator($artistInfo->getPathname()) as $yearInfo) {
		if($yearInfo->isDot()) continue;
		if($yearInfo->isFile()) continue;
		$year = $yearInfo->getFilename();

		echo "- $year\n";
		foreach (new DirectoryIterator($yearInfo->getPathname()) as $albumInfo) {
			if($albumInfo->isDot()) continue;
			if($albumInfo->isFile()) continue;
			$album = $albumInfo->getFilename();
			$album = str_replace('-',' ',$album);

			echo "-- $album\n";
			$_tracks = array();
			$_tracksID = array();
			$_cover = '';
			foreach (new DirectoryIterator($albumInfo->getPathname()) as $trackInfo) {
				if($trackInfo->isDot()) continue;
				if($trackInfo->isDir()) continue;
				$track = $trackInfo->getFilename();

				if(strstr(strtolower($track),'cover.') || strstr(strtolower($track),'cover ')) {
					$_cover = $trackInfo->getPathname();
					continue;
				}

				echo "--- $track\n";
				$_tracks[] = $track;

				$_idInfo = $getID3->analyze($trackInfo->getPathname());
				if(!empty($_idInfo)) {
					$getID3->CopyTagsToComments($_idInfo);

					$_tracksID[] = array(
						'playtime_sec' => $_idInfo['playtime_seconds'],
						'playtime_str' => $_idInfo['playtime_string'],
						'title' => $_idInfo['comments_html']['title'][0],
						'artist' => $_idInfo['comments_html']['artist'][0],
						'number' => $_idInfo['comments_html']['track_number'][0],
						'album' => $_idInfo['comments_html']['album'][0],
						'year' => $_idInfo['comments_html']['year'][0]
					);
				}
			}

			if(!empty($_tracks)) {
				$music[] = array(
					'artist' => $artist,
					'year' => $year,
					'album' => $album,
					'tracks' => $_tracks,
					'tracksID' => $_tracksID,
					'cover' => $_cover
				);
			}
		}
	}
}

# now add it to bibliotheca
$url = API_ENDPOINT.'&authKey='.API_TOKEN.'&collection='.BIB_COLLECTION_ID;
if(!empty($music)) {
	foreach($music as $entry) {
		$_data = array();

		$_data['title'] = $entry['album'];
		$_data['artist'] = $entry['artist'];
		$_data['year'] = is_numeric($entry['year']) ? $entry['year'] : 0;
		$_data['description'] = $entry['artist'];
		$_data['content'] = '';

		if(isset($entry['tracksID']) && !empty($entry['tracksID'])) {
			$_data['runtime'] = 0;
			foreach($entry['tracksID'] as $_t) {
				$_data['runtime'] += $_t['playtime_sec'];
				$l = date("i:s",$_t['playtime_sec']);
				$_data['content'] .= $_t['number'].' - '.$_t['title'].' - '.$l."\n";
			}
			$_data['runtime'] = round($_data['runtime'] / 60);

		}
		else {
			$_data['content'] = implode("\n",$entry['tracks']);
		}

		if(!empty($entry['cover'])) {
			$_data['coverimage'] = curl_file_create($entry['cover']);
		}


		$do = curlPostCall($url,$_data);
		if(!empty($do)) {
			$retJson = json_decode($do,true);
			if(!empty($retJson) && isset($retJson['status']) && $retJson['status'] === 200) {
				echo "Created: ".$_data['title']."\n";
				echo "With data: ".var_export($_data,true)."\n";
				echo "Returndata: ".var_export($do, true)."\n";
			}
			else {
				echo "can not create: ".$_data['title']."\n";
				echo "With data: ".var_export($_data,true)."\n";
				echo "Returndata: ".var_export($do, true)."\n";
				exit(1);
			}
		}
		else {
			echo "can not create: ".$_data['title']."\n";
			echo "invalid call return data\n";
			echo "With data: ".var_export($_data,true)."\n";
			echo "Returndata: ".var_export($do, true)."\n";
			exit(1);
		}
	}
}


## methods
/**
 * execute a curl call to the given $url
 * @param string $url The request url
 * @param array $data The POST data as an array
 * @param bool $port
 * @return bool|mixed
 */
function curlPostCall($url,$data,$port=false) {
	$ret = false;

	$headers = array("Content-Type" => "multipart/form-data");

	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 2);

	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST,1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

	/** remove for debug
	$fp = fopen(dirname(__FILE__).'/import-error.log', 'w+');
	curl_setopt($ch, CURLOPT_VERBOSE, true);
	curl_setopt($ch, CURLOPT_STDERR, $fp);
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	 */

	if(!empty($port)) {
		curl_setopt($ch, CURLOPT_PORT, $port);
	}

	$do = curl_exec($ch);

	if(is_string($do) === true) {
		$ret = $do;
		// debug line
		//error_log(var_export(curl_getinfo($ch),true));
	}
	else {
		error_log(var_export(curl_error($ch),true));
		error_log(var_export(curl_getinfo($ch),true));
	}

	curl_close($ch);

	return $ret;
}
