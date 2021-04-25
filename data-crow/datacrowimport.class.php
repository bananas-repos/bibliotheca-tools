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
 * read the data crow xml export and provide the information for further process
 */
class Datacrowimport {

	private $_importFolder;
	private $_xmlFile;
	private $_imagesFolder;
	private $_mappingFile = 'import-mapping.json';
	private $_mappingData;
	private $_entries = array();

    /**
     * setup some vars and some first checks
     *
     * @param $importFolder string The name of the import folder
     * @param $xmlFile string The name of the xml export file within the import folder
     * @param $imagesFolder mixed The name of the export images folder within the import folder. Can be false if none available
     * @throws Exception
     * @return void
     */
	public function __construct($importFolder, $xmlFile, $imagesFolder) {
		if(file_exists($importFolder) && is_readable($importFolder)) {
			$this->_importFolder = $importFolder;
			if(DEBUG) echo "Import folder: Ok.\n";
		}
		else {
			throw new Exception('Can not find or read folder: '.var_export($importFolder,true));
		}

		if(file_exists($this->_importFolder.'/'.$xmlFile) && is_readable($this->_importFolder.'/'.$xmlFile)) {
			$this->_xmlFile = $this->_importFolder.'/'.$xmlFile;
			if(DEBUG) echo "Import XML file: Ok.\n";
		}
		else {
			throw new Exception('Can not find or read import xml file: '.var_export($importFolder.'/'.$xmlFile,true));
		}

		if(file_exists($this->_importFolder.'/'.$this->_mappingFile) && is_readable($this->_importFolder.'/'.$this->_mappingFile)) {
	        $this->_mappingData = $this->_readFieldMapping($this->_importFolder.'/'.$this->_mappingFile);
			if(DEBUG) echo "Import mapping file: Ok.\n";
		}
		else {
			throw new Exception('Can not find or read mapping json file: '.var_export($importFolder.'/'.$this->_mappingFile,true));
		}

		$this->_imagesFolder = false;
		if(!empty($imagesFolder) && file_exists($this->_importFolder.'/'.$imagesFolder) && is_readable($this->_importFolder.'/'.$imagesFolder)) {
			$this->_imagesFolder = $this->_importFolder.'/'.$imagesFolder;
			if(DEBUG) echo "Images folder: Ok.\n";
		}
	}

    /**
     * Read the data in $this->_entries
     */
	public function readData() {

		$xmlString = file_get_contents($this->_xmlFile);
		if(!empty($xmlString)) {
			$xml = simplexml_load_string($xmlString);
			$elements = $xml->xpath('//'.$this->_mappingData['mapping']['element']);
			if(!empty($elements)) {
				foreach($elements as $el) {
					$_data = array();
					foreach($this->_mappingData['mapping']['fields'] as $key=>$field) {
						if(!isset($field['into'])) continue;
						$_v = '';

						if(isset($field['child'])) {
							$entries = $el->xpath($key.'/'.$field['child'].'/'.$field['valuefield']);
							$_v = array();
							if(!empty($entries)) {
								foreach ($entries as $entry) {
									array_push($_v, $entry[0]->__tostring());
								}
							}
						}
						elseif(isset($field['asset'])) {
							if(!empty($el->xpath($key))) {
								$_v = trim($el->xpath($key)[0]->__tostring());
								if (!empty($_v)) {
									// windows stuff does not work
									$_v = str_replace('file:///', '', $_v);
									$_v = str_replace("\\", '/', $_v);
									$_v = basename($_v);
									if (!empty($this->_imagesFolder)) {
										$_v = $this->_imagesFolder . '/' . $_v;
									}
								}
							}
						}
						else {
							if(isset($field['from'])) {
								$key = $field['from'];
							}
							if(!empty($el->xpath($key))) {
								$_v = trim($el->xpath($key)[0]->__tostring());
								if(isset($field['method']) && method_exists($this, '_modify_'.$field['method'])) {
									$_m = '_modify_'.$field['method'];
									$_v = $this->$_m($_v);
								}
							}
						}
						if(isset($_data[$field['into']]) && !empty($_data[$field['into']])) {
						    if(is_array($_data[$field['into']])) {
                                $_data[$field['into']][] = $this->_makeMDSave($_v);
                            }
						    else {
                                $_data[$field['into']] = array($_data[$field['into']]);
                                $_data[$field['into']][] = $this->_makeMDSave($_v);
                            }
                        }
						else {
                            $_data[$field['into']] = $this->_makeMDSave($_v);
                        }
					}

					if(!empty($_data)) {
						array_push($this->_entries, $_data);
					}
				}
			}
			else {
				throw Exception('Can not find mapping element node.');
			}

		}
		else {
			throw Exception("Can not read xml file into string");
		}

	}

	/**
	 * get the read entries to work with
	 * @return array
	 */
	public function getEntries() {
		return $this->_entries;
	}

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

	/**
	 * Read the given mapping file
	 * @param $file
	 * @return mixed
	 * @throws Exception
	 */
	private function _readFieldMapping($file) {
		$contents = file_get_contents($file);
		$contents = utf8_encode($contents);
		$result = json_decode($contents,true);
		if(!empty($result)) {
			return $result;
		}
		else {
			throw new Exception("Can not decode json file.");
		}
	}

	/**
	 * small and simple replace to make sure we have a valid MD string
	 * @param $string
	 * @return mixed
	 */
	private function _makeMDSave($string) {
		$ret = str_replace('"','',$string);

		return $ret;
	}

	/**
	 * _ + method attribute from json mapping will call this function
	 * with the extracted string from the source
	 * @param $string
	 * @return string
	 */
	private function _modify_firstSentence($string) {
		$string = trim(preg_replace('/\s+/', ' ', $string));
		$string = preg_replace('/(.*?[?!.](?=\s|$)).*/', '\\1', $string);
		$ret = substr($string, 0, 255);

		return $ret;
	}

	/**
	 * the rating is saved with a / as a devider
     * 5 / 10 -> 5 of 10
	 * @param $string
	 * @return mixed
	 */
	private function _modify_modifyRating($string) {
		$ret = str_replace("/","of",$string);

		return $ret;
	}

    /**
     * the rating is saved with a / as a devider
     * 5 / 10 -> 5/10
     * @param $string
     * @return mixed
     */
    private function _modify_modifyRatingTrim($string) {
        $ret = str_replace(" ","",$string);

        return $ret;
    }
}
