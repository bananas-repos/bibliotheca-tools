<?php
/**
 * Copyright 2019-2020 Johannes Keßler
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

/**
 * this file creates markdown files which can be used in the static-hugo "client"
 * reads the data-crow export from the import folder and saves the processed data into output folder
 */

# where the data crow export data is located.
define('IMPORT_FOLDER','import/game');
# the data crow xml filename within the IMPORT_FOLDER
define('DATACROW_XML_FILENAME','games-export.xml');
# the data crow images folder within the IMPORT_FOLDER (optional)
define('DATACROW_IMAGES_FOLDERNAME','games-export_images');
# the markdown template file used to import to
define('MARKDOWN_TEMPLATE_FILE','markdown-template.md');
# the output folder where the generead MD content will be saved
define('OUTPUT_FOLDER','output');

define('DEBUG',false);

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

require 'datacrowimport.class.php';

$entries = false;
$templateData = false;
$created = date("Y-m-d");

try {
	// create and check some the importent settings
	$ImportObj = new Datacrowimport(IMPORT_FOLDER, DATACROW_XML_FILENAME,
		DATACROW_IMAGES_FOLDERNAME);
}
catch(Exception $e) {
	echo $e->getMessage()."\n";
	exit(1);
}

try {
	// read the data using the mapping file
	$ImportObj->readData();
	$entries = $ImportObj->getEntries();
}
catch(Exception $e) {
	echo $e->getMessage()."\n";
	exit(1);
}

// now use the mapped entries, apply the template file and create the folders and md files
if(!empty($entries)) {
	if(file_exists(IMPORT_FOLDER.'/'.MARKDOWN_TEMPLATE_FILE) && is_readable(IMPORT_FOLDER.'/'.MARKDOWN_TEMPLATE_FILE)) {
		$templateData = file_get_contents(IMPORT_FOLDER.'/'.MARKDOWN_TEMPLATE_FILE);
		if(empty($templateData)) {
			echo "Can not get the contents of the template file: ".var_export(IMPORT_FOLDER.'/'.MARKDOWN_TEMPLATE_FILE, true);
			exit(1);
		}
	}
	else {
		echo "Can not read the markdown template file: ".var_export(IMPORT_FOLDER.'/'.MARKDOWN_TEMPLATE_FILE, true);
		exit(1);
	}

	if(!file_exists(OUTPUT_FOLDER) || !is_writeable(OUTPUT_FOLDER)) {
		echo "Can not read or write the output folder: ".var_export(OUTPUT_FOLDER, true);
		exit(1);
	}

	foreach ($entries as $entry) {
		if(empty($entry['title'])) continue;

		$entry['created'] = $created;

		$saveFolder = OUTPUT_FOLDER.'/'.strtoupper($entry['title'][0]);
		if(!file_exists($saveFolder)) {
			mkdir($saveFolder);
		}
		file_put_contents($saveFolder.'/_index.md','');
		$saveFolderMovie = $saveFolder.'/'.saveDirname($entry['title']);
		if(!file_exists($saveFolderMovie)) {
			mkdir($saveFolderMovie);
		}
		$saveFolderImages = $saveFolderMovie.'/images';
		if(!file_exists($saveFolderImages)) {
			mkdir($saveFolderImages);
		}

		$saveData = $templateData;
		foreach($entry as $k=>$v) {
			$_search = '#'.strtoupper($k).'#';

			if(is_array($v)) {
				$saveData = str_replace($_search,'"'.implode('","',$v).'"',$saveData);
			}
			elseif(strpos($k,"file_") !== false) {
				if(!empty($v)) {
					$_filename = str_replace("file_","",$k);
					$_fileinfo = pathinfo($v);
					$_filename .= ".".$_fileinfo['extension'];

					copy($v, $saveFolderImages.'/'.$_filename);
				}
			}
			else {
				$saveData = str_replace($_search,$v,$saveData);
			}
		}

		file_put_contents($saveFolderMovie.'/index.md',$saveData);

		echo "Created: ".$entry['title']."\n";
	}
}
else {
	echo "Nothing to import from the xml file?\n";
	exit(1);
}

function saveDirname($string) {
	$ret = trim($string);
	$ret = strtolower($ret);

	$ret = preg_replace('/[^\p{L}\p{N}]/u',"-",$ret);

	return $ret;
}
