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
# the output folder where the generated MD content will be saved
define('OUTPUT_FOLDER','output');

# the bibliotheca api endpoint
define('API_ENDPOINT','http://localhost/bibliotheca/webclient/api.php?p=add');
# the tolen to connect to the api.
# created in bibliotheca use management
define('API_TOKEN','1e637832829255b453ce589b066b3c69');

# the bibliotheca collection to store into
define('BIB_COLLECTION_ID','2');


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

// now use the mapped entries build the data required for the api
if(!empty($entries)) {
    if(!file_exists(OUTPUT_FOLDER) || !is_writeable(OUTPUT_FOLDER)) {
        echo "Can not read or write the output folder: ".var_export(OUTPUT_FOLDER, true);
        exit(1);
    }

    $url = API_ENDPOINT.'&authKey='.API_TOKEN.'&collection='.BIB_COLLECTION_ID;

    foreach ($entries as $entry) {
        if (empty($entry['title'])) continue;

        $_data = $entry;

        // the resulting POST data array is key=>value (string) only
        // only the upload can be an array
		// lookupmultiple fields
		// change to match your import settings
        if(isset($_data['category'])) $_data['category'] = implode(',',$entry['category']);
		if(isset($_data['developer'])) $_data['developer'] = implode(',',$entry['developer']);
		if(isset($_data['tag'])) $_data['tag'] = implode(',',$entry['tag']);
		if(isset($_data['publisher'])) $_data['publisher'] = implode(',',$entry['publisher']);

        if(!empty($_data['coverimage'])) {
            $_data['coverimage'] = curl_file_create($_data['coverimage']);
            //unset($_data['coverimage']);
        }
        if(!empty($_data['attachment'])) {
            // https://labs.wearede.com/php-curl-multiple-file-upload-nightmare/
            if(is_array($_data['attachment'])) {
                foreach($_data['attachment'] as $k=>$attach) {
                    if(!empty($attach)) {
                        $_data["attachment[$k]"] = curl_file_create($attach);
                    }
                }
            }
            else {
                $_data['attachment[0]'] = curl_file_create($_data['attachment']);
            }
            unset($_data['attachment']);
        }

        $do = $ImportObj->curlPostCall($url,$_data);
        if(!empty($do)) {
			$retJson = json_decode($do,true);
			if(!empty($retJson) && isset($retJson['status']) && $retJson['status'] === 200) {
				echo "Created: ".$entry['title']."\n";
				echo "With data: ".var_export($entry,true)."\n";
				echo "Returndata: ".var_export($do, true)."\n";
			}
			else {
				echo "can not create: ".$entry['title']."\n";
				echo "With data: ".var_export($entry,true)."\n";
				echo "Returndata: ".var_export($do, true)."\n";
				exit(1);
			}
        }
        else {
            echo "can not create: ".$entry['title']."\n";
			echo "invalid call return data\n";
			echo "With data: ".var_export($entry,true)."\n";
            echo "Returndata: ".var_export($do, true)."\n";
            exit(1);
        }
    }
}
else {
    echo "Nothing to import from the xml file?\n";
    exit(1);
}
