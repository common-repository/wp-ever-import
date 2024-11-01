<?php
/*
Plugin Name: Ever Import
Plugin URI: http://www.wordpress.org/
Description: The plugin lets you import any xml or csv file into the posts and pages of WordPress. It lets the user select different data structures using an interactive interface and provides the ability to map incoming fields to the post and pages data
Author: Satnhue
Version: 1.0.0
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Imp_Ever_Import' ) ) {

	if ( ! class_exists( 'XML2Array' ) ) {	
		include_once(dirname(__FILE__).'/includes/XML2Array.php');
	}

	if ( ! class_exists( 'Array2XML' ) ) {	
		include_once(dirname(__FILE__).'/includes/Array2XML.php');
	}

	class Imp_Ever_Import {

		function imp_ever_import_serve() {
			add_action( 'admin_menu', array($this, 'imp_dashboard_menu'));
			add_action( 'admin_print_scripts-post-new.php', array($this, 'imp_admin_print_scripts_main'), 11 );
			add_action( 'admin_print_scripts-post.php', array($this, 'imp_admin_print_scripts_main'), 11 );
			add_action( 'edit_form_after_editor', array($this, 'imp_dashboard_view'));
			add_action( 'wp_ajax_imp_load_nav', array($this, 'imp_load_nav'));
			add_action( 'wp_ajax_imp_process_file_upload', array($this, 'imp_do_the_file_upload'));
			add_action( 'wp_ajax_imp_process_batch', array($this, 'imp_process_batch'));
			add_action( 'init', array($this, 'imp_create_post_type'));
			add_action( 'wp_ajax_imp_data_load', array($this, 'imp_data_load'));
			add_action( 'save_post', array($this, 'imp_save_mapping'));
			add_action( 'edit_form_top', array($this, 'imp_load_mapping'));

		}

		function imp_dashboard_menu() {
			remove_meta_box( 'postcustom','imp_import','normal' );
			remove_meta_box( 'slugdiv','imp_import','normal' ); // Slug Metabox
			$mainpage = add_menu_page( 	'Ever Import', 'Ever Import', 'manage_options', 'imp_dashboard_menu', array($this, 'imp_dashboard_view')); 
		}


		function imp_admin_print_scripts_main() {

			global $post_type;

			if($post_type != 'imp_import')
				return;

			add_meta_box('postcustom', __('Custom Fields'), 'post_custom_meta_box', null, 'normal', 'core');
			wp_enqueue_script( 'imp_globals', plugin_dir_url( __FILE__ ) . 'js/globals.js', array(), false, true );
			wp_enqueue_script( 'imp_layout_script', plugin_dir_url( __FILE__ ) . 'js/build.js', array(), false, true );	
			wp_enqueue_script( 'jquery-ui-draggable');	
			wp_enqueue_style( 'fixed-data-table', plugin_dir_url( __FILE__ ) . 'css/fixed-data-table.min.css');	
			wp_enqueue_style( 'imp-main-style', plugin_dir_url( __FILE__ ) . 'css/everimport.css');
			wp_enqueue_script('post');
			add_action('admin_head', array($this, 'imp_print_head'));
		}

		function imp_print_head() {
			?>
			<script type="text/javascript">
				var imp_post_types = <?php echo json_encode($this->imp_get_all_post_types()); ?>;
			</script>
			<?php
		}

		function imp_dashboard_view() {
			
			// leave blank for now, as the view is rendered by the JS
		}

		function imp_get_all_post_types() {

			$custom_types = get_post_types(array('_builtin' => true), 'objects') + get_post_types(array('_builtin' => false, 'show_ui' => true), 'objects'); 
			
			foreach ($custom_types as $key => $ct) {
				if (in_array($key, array('attachment', 'revision', 'nav_menu_item', 'shop_webhook', 'import_users'))) unset($custom_types[$key]);
			}
			

			$hidden_post_types = get_post_types(array('_builtin' => false, 'show_ui' => false), 'objects');
			foreach ($hidden_post_types as $key => $ct) {
				if (in_array($key, array('attachment', 'revision', 'nav_menu_item'))) unset($hidden_post_types[$key]);
			}
			
			$all_post_types = array();
			
			

			if ( ! empty($custom_types)) {
				
				foreach ($custom_types as $key => $ct) {
						$cpt = $key;
						$cpt_label = $ct->labels->name;	
						if($key == 'post' || $key == 'page')												
							$all_post_types[$cpt] = $cpt_label;
				}
				
			}
			/*
			if ( ! empty($hidden_post_types)) {
				
				foreach ($hidden_post_types as $key => $ct) {
						$cpt = $key;
						$cpt_label = $ct->labels->name;													
						$all_post_types[$cpt] = $cpt_label;
				}
				
			}*/

			return $all_post_types;
			
		}

		/*
		function imp_parse_xml_recursive($xml, &$nodecount, $bloodline = array()) {

			if(sizeof($bloodline) > 0)
				$count_prefix = implode('#', $bloodline);
			else
				$count_prefix = '';

			echo "<ul>";
			foreach($xml->children() as $child) {
				echo "<li>";
				echo implode('-', $bloodline)." -- ";
				$this_name = $child->getName();
				//echo $this_name."<br />";
				
				if($count_prefix == '')
					$count_key = $this_name;
				else
					$count_key = $count_prefix.'#'.$this_name;

				if(isset($nodecount[$count_key]))
					$nodecount[$count_key] = $nodecount[$count_key]+1;
				else
					$nodecount[$count_key] = 1;

				$children = $child->children();
				//recurse
				if($children->count() > 0) {

					$bloodline[$this_name] = $this_name;

					$this->imp_parse_xml_recursive($child, $nodecount, $bloodline);
				}
				echo "</li>";
			}
			echo "</ul>";
		}
		*/

		function imp_encode_cdata($matches) {
			return ':CDATA'.base64_encode($matches[1]);
		}


		function imp_load_nav() {

			$post_ID = isset($_REQUEST["post_ID"]) && is_numeric($_REQUEST["post_ID"]) ? intval($_REQUEST["post_ID"]) : false;

			if(!$post_ID) {
				wp_send_json_error(array( 'success' => false ));
				return;
			}

			$path = get_post_meta($post_ID, '_imp_data_folder', true);

			$nav = json_decode(file_get_contents($path.'/nav.json'), true);

			if(!$nav) {
				wp_send_json_error(array( 'success' => false ));
				return;
			}

			wp_send_json($nav);
		}

		function imp_do_the_file_upload() {
			
			if(!isset($_FILES['imp_upload_file']))
				return;

			$file_type = isset($_FILES['imp_upload_file']['type']) ? stripslashes($_FILES['imp_upload_file']['type']) : false;
			$tmp_name = isset($_FILES['imp_upload_file']['tmp_name']) ? stripslashes($_FILES["imp_upload_file"]["tmp_name"]) : false;

			if($file_type && $tmp_name) {

				$post_ID = isset($_REQUEST["post_ID"]) && is_numeric($_REQUEST["post_ID"]) ? intval($_REQUEST["post_ID"]) : false;

				if(!$post_ID) {
					wp_send_json_error(array( 'success' => false ));
					return;
				}

				$subdir = '';
				
				//if ( get_option( 'uploads_use_yearmonth_folders' ) ) {
					// Generate the yearly and monthly dirs
					$time = current_time( 'mysql' );
					$y = substr( $time, 0, 4 );
					$m = substr( $time, 5, 2 );
					$path = "/$y/$m";
				//}

				$path =  '/everimport' . $path.'/'.$post_ID;
				$upload_dir = wp_upload_dir();

				$path = $upload_dir['basedir'].$path;

				// if directory does not exist, create it
				if (!file_exists($path)) {
				    mkdir($path, 0777, true);
				}

				// update the file's path in the post meta
				update_post_meta($post_ID, '_imp_data_folder', $path);

				$content = '';

				if( $file_type == 'text/xml') {

					$content = file_get_contents( $tmp_name );
				}

				elseif( $file_type == 'text/csv' ) {
					// convert the csv to xml format
					// Open csv to read
					$inputFile  = fopen($tmp_name, 'rt');

					// Get the headers of the file
					$headers = fgetcsv($inputFile);

					// Create a new dom document with pretty formatting
					$doc  = new DomDocument();
					$doc->formatOutput   = true;

					// Add a root node to the document
					$root = $doc->createElement('rows');
					$root = $doc->appendChild($root);


					// Loop through each row creating a <row> node with the correct data
					while (($row = fgetcsv($inputFile)) !== FALSE)
					{
					    $container = $doc->createElement('row');
					    foreach($headers as $i => $header)
					    {
					    	if(empty($header))
					    		continue;
					        $child = $doc->createElement($header);
					        $child = $container->appendChild($child);
					        $value = $doc->createTextNode($row[$i]);
					        $value = $child->appendChild($value);
					    }

					    $root->appendChild($container);
					}

					$content = $doc->saveXML();
					
				}

				// remove CDATA attr
				$content = preg_replace_callback('/<!\[CDATA\[(.*)\]\]>/i', array($this, 'imp_encode_cdata'), $content);
				// save the file to uploads folder after removing name space convention
				// also remove CDATA attr


				// intelligent chunking, you cannot just split an xml. Identify the tables

				$data = XML2Array::createArray($content);

				$result = array();

				$this->imp_parse_nav_recursive($data, $result);

				// identify could be tables in the datales
				$tables = array();
				foreach($result as $key => $val) {
					if(substr($key, -2) == '*]')
						$tables[$key] = $val;
				}

				// unique them out
				$cleantables = $tables;
				foreach($tables as $key => $item) {
					
					foreach($cleantables as $key2 => $item2) {
						if(strpos(trim($key2), trim($key)) === 0 && $key2 != $key) {
							unset($cleantables[$key2]);
						}
					}
					
				}

				// now, identify the the tables that have records greater than 10 in number, and pluck out those tables from the main file and store in chunks
				$chunk_thres = 12;
				$xml = simplexml_load_string($content);
				

				foreach($cleantables as $key => $item) {
					if(intval($item) > $chunk_thres) {
						// for now, leave the first 10 records here and store the rest of the chunk in a separate file

						$xpath = $key;
						$xpath_data = $xml->xpath($xpath);

						// remove the first 10 records, and put the rest into xml and a file
						$xpath_data = array_slice($xpath_data, $chunk_thres, sizeof($xpath_data)-10);

						$fullXml = "<root>\n";
						foreach($xpath_data as $xmlElement){
						    $fullXml .= str_replace('<?xml version="1.0"?>', '',$xmlElement->asXML());
						}
						$fullXml .= "\n</root>";

						// if directory does not exist, create it
						$dirname = 'preview_chunks';
						if (!file_exists($path."/".$dirname)) {
						    mkdir($path."/".$dirname, 0777, true);
						}

						file_put_contents($path."/".$dirname."/".base64_encode($key).".xml", preg_replace('/<([^\<]*):([^\<]*)>/', '<$1_$2>', str_replace('></', '>:CDATA </', $fullXml)));

						
						// unset the records greater than 10 in those tables				
						
						$expression = '$data["'.$xml->getName().'"]["'.join('"]["', explode('/', str_replace('[*]', '', $key))).'"]';
						//echo $expression;
						eval($expression.' = '.'array_slice('.$expression.', 0, '.$chunk_thres.');');
						

					}
				}
				$xml = Array2XML::createXML($xml->getName(), $data[$xml->getName()]);
				
				
				file_put_contents($path."/full.xml", preg_replace('/<([^\<]*):([^\<]*)>/', '<$1_$2>', str_replace('></', '>:CDATA </', $content)));

				file_put_contents($path."/preview.xml", preg_replace('/<([^\<]*):([^\<]*)>/', '<$1_$2>', str_replace('></', '>:CDATA </', $xml->saveXML())));
			
				file_put_contents($path."/nav.json", json_encode(array('nav' => $result, 'hints' => $cleantables)));
				
				wp_send_json(array('nav' => $result, 'hints' => $cleantables));
			}

			die(0);
		}


		function imp_parse_nav_recursive($data, &$result, $bloodline = array(), $parent = "", $rootkey = false) {
			
			$blstring = "";

			foreach($bloodline as $blitem) {
				if(strlen($blstring) > 0)
					$blstring.="/";

				$blstring.=$blitem;
			}
			
			$dataisarray = false;

			if(!$this->imp_is_assoc($data) && is_array($data)) {
				$dataisarray = true;
			}
			
			if(!$dataisarray && strlen($blstring) > 0) {

				if(!$rootkey) {
					$rootkey = $blstring;
				}
				else {
					$blstring = str_replace($rootkey."/", '', $blstring);
					$blstring = str_replace($rootkey."[*]/", '', $blstring);
					if(!isset($result[$blstring]))
						$result[$blstring] = 0;
					
					$result[$blstring]++;
				}
			}

			if(!is_array($data))
				return;
			
			foreach($data as $key => $item) {

				$nbloodline = $bloodline;
			
				$nparent = $parent;
				if(strlen($parent) > 0) {
					$nparent.="[*]";
				}

				$nbloodline[$parent] = $nparent;
				
				if(!$dataisarray)
					$nbloodline[$key] = $key;

					
				$this->imp_parse_nav_recursive($item, $result, $nbloodline, !$dataisarray?$key:null, $rootkey);
			}

		}

		function imp_is_assoc($data) {
			return isset($data) && is_array($data) && count($data)!=0 && array_keys($data) !== range(0, count($data) - 1);
		}


		function imp_do_the_file_download($url, $parent_post_id) {
			
			$post = array();

			$upload = $this->imp_download_remote_file( $url);
			
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}

			if ( $info = wp_check_filetype( $upload['file'] ) )
				$post['post_mime_type'] = $info['type'];
			else
				return new WP_Error( 'attachment_processing_error', __('Invalid file type', 'wordpress-importer') );

			$post['guid'] = $upload['url'];

			// as per wp-admin/includes/upload.php
			$post_id = wp_insert_attachment( $post, $upload['file'], $parent_post_id );
			wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

			// remap resized image URLs, works by stripping the extension and remapping the URL stub.
			if ( preg_match( '!^image/!', $info['type'] ) ) {
				$parts = pathinfo( $url );
				$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

				$parts_new = pathinfo( $upload['url'] );
				$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

				
			}
			set_post_thumbnail( $parent_post_id, $post_id );
			

			return $post_id;

		}

		function imp_download_remote_file( $url ) {
			// extract the file name and extension from the url
			$file_name = basename( $url );

			// get placeholder file in the upload dir with a unique, sanitized filename
			$upload = wp_upload_bits( $file_name, 0, '');

			if ( $upload['error'] ) {
				
				return new WP_Error( 'upload_dir_error', $upload['error'] );
			}

			// fetch the remote url and write it to the placeholder file
			$headers = wp_get_http( $url, $upload['file'] );

			// request failed
			if ( ! $headers ) {
				@unlink( $upload['file'] );
				return new WP_Error( 'import_file_error', __('Remote server did not respond', 'wordpress-importer') );
			}

			// make sure the fetch was successful
			if ( $headers['response'] != '200' ) {
				@unlink( $upload['file'] );
				return new WP_Error( 'import_file_error', sprintf( __('Remote server returned error response %1$d %2$s', 'wordpress-importer'), esc_html($headers['response']), get_status_header_desc($headers['response']) ) );
			}

			$filesize = filesize( $upload['file'] );

			if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
				@unlink( $upload['file'] );
				return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'wordpress-importer') );
			}

			if ( 0 == $filesize ) {
				@unlink( $upload['file'] );
				return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'wordpress-importer') );
			}

			return $upload;
		}


		function imp_add_categories($newcats) {
			$taxonomy = get_taxonomy( 'category' );

			$parent = isset( $newcats['parent'] ) && (int) $newcats['parent'] > 0 ? (int) $newcats['parent'] : 0;
			$names = explode( ',', $newcats['name'] );
			$added = array();

			foreach ( $names as $cat_name ) {
				$cat_name = trim( $cat_name );
				$cat_nicename = sanitize_title( $cat_name );

				if ( empty( $cat_nicename ) ) {
					continue;
				}

				$existing_term = term_exists( $cat_name, $taxonomy->name, $parent );
				if ( $existing_term ) {
					$added[] = $existing_term['term_id'];
					continue;
				}

				$cat_id = wp_insert_term( $cat_name, $taxonomy->name, array( 'parent' => $parent ) );

				if ( is_wp_error( $cat_id ) ) {
					continue;
				} elseif ( is_array( $cat_id ) ) {
					$cat_id = $cat_id['term_id'];
				}

				$added[] = $cat_id;
			}

			return $added;
		}

		function imp_base64_decode($content) {
			if(strpos($content, ':CDATA') === 0) {
				return base64_decode(str_replace(':CDATA', '', $content));
			}
			return $content;
		}

		function imp_get_translated($val, $i, $xml, $datatype) {
			$translated = "";
			$trans_array = array();
			for($j = 1; $j < sizeof($val); $j++) {
				
				if(isset($val[$j]['tag']) && $val[$j]['tag'] == 'SPAN') {
					// do the replacement
					$xpath = $datatype.'['.$i.']/'.str_replace(':', '_', $val[$j]['mapping']);
					//remove any last [*] from the xpath
					if(substr($xpath, strlen($xpath)-3, 3) == '[*]')
						$xpath = substr($xpath, 0, strlen($xpath)-3);

					// if not array
					if(!isset($val[$j]['array'])) {
						$xpath_data = $xml->xpath($xpath);
						if(isset($xpath_data[0]) && isset($xpath_data[0][0]))
							$translated.= $this->imp_base64_decode($xpath_data[0][0]);
						if(sizeof($trans_array) > 0) {
							foreach($trans_array as $key => $item) {
								$trans_array[$key] = $trans_array[$key].$this->imp_base64_decode($xpath_data[0][0]);
							}
						}
					}
					else {	// if array

						$xpath_data = $xml->xpath($xpath);

						if($val[$j]['array'] == 'string') {
							$calculated = implode((isset($val[$j]['separator'])?$val[$j]['separator']:', '), $xpath_data);
							$translated.=$calculated;

							if(sizeof($trans_array) > 0) {
								foreach($trans_array as $key => $item) {
									$trans_array[$key] = $translated.$calculated;
								}
							}
						}
						elseif($val[$j]['array'] == 'array') {
						

							if(sizeof($trans_array) > 0) {
								$count = 0;
								foreach($xpath_data as $dataitem) {
									$trans_array[$count] = $trans_array[$count].$this->imp_base64_decode($dataitem[0]);
									$count++;
								}

							}
							else {

								foreach($xpath_data as $dataitem) {
									$trans_array[] = $translated.$this->imp_base64_decode($dataitem[0]);
								}
							}
						}

					}
				}
				else {

					if(sizeof($trans_array) > 0) {
						foreach($trans_array as $key => $item) {
							$trans_array[$key] = $trans_array[$key].$this->imp_base64_decode($val[$j]['text']);
						}
					}
					else
						$translated.=$this->imp_base64_decode($val[$j]['text']);

				}
			}

			if(sizeof($trans_array) > 0)
				return $trans_array;
			else
				return $translated;
		}


		function imp_process_batch() {
			
			$datatype = isset($_POST['datatype']) ? stripslashes($_POST['datatype']) : false;
			
			if(!$datatype)
				return;
			// sanitize the datatype
			$datatype = sanitize_text_field(str_replace(':', '_', str_replace('[*]', '', $datatype)));

			$translation = isset($_POST['translation']) ? stripslashes_deep($_POST['translation']) : false;

			if(!is_array($translation))
				return;

			// Some Important Parameters
			$batchSize = isset($_POST['batch_size']) && is_numeric($_POST['batch_size']) ? intval($_POST['batch_size']) : false;
			$batchIndex = isset($_POST['batch_index']) && is_numeric($_POST['batch_index']) ? intval($_POST['batch_index']) : false;
			$post_ID = isset($_POST['post_ID']) && is_numeric($_POST['post_ID']) ? intval($_POST['post_ID']) : false;
			
			if($batchSize === false || $batchIndex === false || $post_ID === false)
				return;

			update_post_meta($post_ID, '_imp_batch_size', $batchSize);

			// get all the data from the uploaded xml
			$dir = get_post_meta($post_ID, '_imp_data_folder', true);
			
			// if directory does not exist, create it
			if (!file_exists($dir.'/process_chunks/')) {
			    mkdir($dir.'/process_chunks/', 0777, true);
			}

			if(!file_exists($dir.'/process_chunks/'.$batchSize.'_'.$batchIndex.'_'.md5($datatype).'.xml')) {

				// clear any existing unwanted files
				foreach (glob($dir."/process_chunks/*.*") as $filename) {
				    if (is_file($filename)) {
				        unlink($filename);
				    }
				}
				$content = file_get_contents($dir.'/full.xml');
				$xml = simplexml_load_string($content);
				$xpath_data = $xml->xpath($datatype);

				for($i= 0; $i < sizeof($xpath_data); $i+= $batchSize) {
					$chunk_data = array_slice($xpath_data, $i, $batchSize);

					$fullXml = "<root>\n";
					foreach($chunk_data as $xmlElement){
					    $fullXml .= str_replace('<?xml version="1.0"?>', '',$xmlElement->asXML());
					}
					$fullXml .= "\n</root>";

					file_put_contents($dir.'/process_chunks/'.$batchSize.'_'.$i.'_'.md5($datatype).'.xml', $fullXml);

				}

			}	

			$content = file_get_contents($dir.'/process_chunks/'.$batchSize.'_'.$batchIndex.'_'.md5($datatype).'.xml');
			$xml = simplexml_load_string($content);
			
			$datatype = explode('/', $datatype);
			$datatype = $datatype[sizeof($datatype)-1];

			$i = 0;

			for($i = 1; $i <= sizeof($xml->{$datatype}); $i++ ) {
				
				$post_content = array();

				foreach($translation as $key => $val) {

					if( is_array($val)) {
						if(isset($val[0]) && $val[0] == 'imp_trans') {
							// translate it
							$post_content[$key] = $this->imp_get_translated($val, $i, $xml, $datatype);
						}
						else {
							// is a taxonomy
							if($key == 'tax_input') {
								foreach($val as $nkey => $nval) {
									if(is_array($nval) && $nval[0] == 'imp_trans') {
										// translate it
										$post_content[$key][$nkey] = $this->imp_get_translated($nval, $i, $xml, $datatype);
									}
									else {
										$post_content[$key][$nkey] = $nval;
									}
								}
							}
							elseif($key == 'imp-custom-fields') {
								
								foreach($val as $dkey => $dval) {
									foreach($dval as $nkey => $nval) {
										if(is_array($nval) && $nval[0] == 'imp_trans') {
								
											// translate it
											$post_content[$key][$dkey][$nkey] = $this->imp_get_translated($nval, $i, $xml, $datatype);
								
										}
										else {
											$post_content[$key][$dkey][$nkey] = $nval;
										}
									}
								}
							}
							else {
								// could be a multiselect or checkboxes such as post_category
								$post_content[$key] = $val;
								
							}
						}

						
					} else {
						// as it is
						$post_content[$key] = $val;
						
					}
				}

				
				// now the post_content contains the data for the post, categories, taxonomies, tags

				// lets first add the new categories into the db
				//get categories
				$newcats = isset($post_content['newcategory']) ? $post_content['newcategory'] : false;
			
				if($newcats) {
					
					$newcategory_parent = isset($post_content['newcategory_parent']) ? $post_content['newcategory_parent'] : false;


					// add new categories
					$newcatids = $this->imp_add_categories(array('parent' => $newcategory_parent, 'name' => $newcats));

					// update newly added categories to the post
					if(!isset($post_content['post_category']))
						$post_content['post_category'] = array();

					$post_content['post_category'] = array_unique(array_merge($post_content['post_category'], $newcatids));
					
				}

				// tax, tags etc will be taken care of by the post writing itself

				// post_date and post_date_gmt
				$provided_date = isset($post_content['imp-publish-date']) ? $post_content['imp-publish-date'] : false;

				if($provided_date) {
					$parsed_date = date_parse($provided_date);
					if($parsed_date['error_count'] < 1) {


						$post_content['post_date'] = sprintf( "%04d-%02d-%02d %02d:%02d:%02d", $parsed_date['year'], $parsed_date['month'], $parsed_date['day'], $parsed_date['hour'], $parsed_date['minute'], $parsed_date['second'] );
						//$valid_date = wp_checkdate( $mm, $jj, $aa, $post_data['post_date'] );

						//$post_content['post_date'] = strtotime($provided_date);

					}
					else {
						//$post_content['post_date'] = date();

					}
				}
				else {
					//$post_content['post_date'] = time();
					
				}
				//ChromePhp::log($post_content['post_date']);
				// lets write the post now
				$newpostid = $this->imp_write_post($post_content);


				//get feature images
				$featureImages = isset($post_content['imp-feature-image']) ? $post_content['imp-feature-image'] : false;
				
				if($featureImages) {
					$featureImages = explode(',', $featureImages);
					// add feature images using the newpostid
					foreach($featureImages as $featureImage) {

						//ChromePhp::log($featureImage);
						$this->imp_do_the_file_download(sanitize_url(trim($featureImage)), $newpostid);
					}
				}


				// custom fields
				$customFields = isset($post_content['imp-custom-fields']) ? $post_content['imp-custom-fields'] : false;

				//ChromePhp::log($customFields);
				if(is_array($customFields)) {
					
					// add custom fields using the newpostid
					foreach($customFields as $customField) {

						// if its an array of custom fields
						if(is_array($customField['metakeyselect'])) {
							$count = 0;
							foreach($customField['metakeyselect'] as $metakeyselect) {
								$tmparray = array('metakeyselect' => $metakeyselect, 'metavalue' => $customField['metavalue'][$count]);
								
								$this->imp_add_meta($tmparray, $newpostid);
								$count++;
							}
						}
						else {
							$this->imp_add_meta($customField, $newpostid);
						}
					}
				}


				update_post_meta($post_ID, '_imp_last_index', ($batchIndex+$i-1));
			}

			wp_send_json(array('lastIndex' => ($batchIndex+$i-2), 'recordsLoaded' => ($i-1)));

		}

		function imp_write_post($postData) {
			unset($postData['post_ID']);

			if ( isset($postData['post_type']) )
				$ptype = get_post_type_object($postData['post_type']);
			else
				$ptype = get_post_type_object('post');

			if ( !current_user_can( $ptype->cap->edit_posts ) ) {
				if ( 'page' == $ptype->name )
					return new WP_Error( 'edit_pages', __( 'You are not allowed to create pages on this site.' ) );
				else
					return new WP_Error( 'edit_posts', __( 'You are not allowed to create posts or drafts on this site.' ) );
			}

			$postData['post_mime_type'] = '';

			// Clear out any data in internal vars.
			unset( $postData['filter'] );

			// Edit don't write if we have a post id.
			if ( isset( $postData['post_ID'] ) )
				return edit_post();

			if ( isset($postData['visibility']) ) {
				switch ( $postData['visibility'] ) {
					case 'public' :
						$postData['post_password'] = '';
						break;
					case 'password' :
						unset( $postData['sticky'] );
						break;
					case 'private' :
						$postData['post_status'] = 'private';
						$postData['post_password'] = '';
						unset( $postData['sticky'] );
						break;
				}
			}

			$translated = _wp_translate_postdata( false, $postData );

			if ( is_wp_error($translated) )
				return $translated;
		//ChromePhp::log('here');
			
			$translated['post_status'] = 'publish';

			// Create the post.
			$post_ID = wp_insert_post( $translated );
			if ( is_wp_error( $post_ID ) )
				return $post_ID;

			if ( empty($post_ID) )
				return 0;

			add_meta( $post_ID);

			add_post_meta( $post_ID, '_edit_last', $GLOBALS['current_user']->ID );

			// Now that we have an ID we can fix any attachment anchor hrefs
			_fix_attachment_links( $post_ID );

			wp_set_post_lock( $post_ID );

			return $post_ID;
		}

		function imp_add_meta($customField,  $post_ID) {

			
			//$post_ID = (int) $post_ID;

			$metakeyselect = isset($customField['metakeyselect']) ? wp_unslash( trim( $customField['metakeyselect'] ) ) : '';
			$metakeyinput = isset($customField['metakeyinput']) ? wp_unslash( trim( $customField['metakeyinput'] ) ) : '';
			$metavalue = isset($customField['metavalue']) ? $customField['metavalue'] : '';
			if ( is_string( $metavalue ) )
				$metavalue = trim( $metavalue );

			if ( ('0' === $metavalue || ! empty ( $metavalue ) ) && ( ( ( '#NONE#' != $metakeyselect ) && !empty ( $metakeyselect) ) || !empty ( $metakeyinput ) ) ) {
				
				/*
				 * We have a key/value pair. If both the select and the input
				 * for the key have data, the input takes precedence.
				 */
		 		if ( '#NONE#' != $metakeyselect )
					$metakey = $metakeyselect;

				if ( $metakeyinput )
					$metakey = $metakeyinput; // default

				//is_protected_meta( $metakey, 'post' ) || 
				if ( ! current_user_can( 'add_post_meta', $post_ID, $metakey ) )
					return false;

				$metakey = wp_slash( $metakey );
				
				return add_post_meta( $post_ID, $metakey, $metavalue );
			}

			return false;
		} // add_meta




		function imp_create_post_type() {
		  register_post_type( 'imp_import',
		    array(
		      'labels' => array(
		        'name' => __( 'Imports' ),
		        'singular_name' => __( 'Import' )
		      ),
		      'supports' => array('title'),
		      'exclude_from_search' => true,
		      'publicly_queryable' => false,
		      'show_ui' => true,
		      'show_in_menu' => 'imp_dashboard_menu',
		      //'has_archive' => true,
		    )
		  );
		}

			
		function imp_data_load() {
			
			$datatype = isset($_POST['datatype']) ? stripslashes($_POST['datatype']): false;
			$post_ID = isset($_POST['post_ID']) && is_numeric($_POST['post_ID']) ? intval($_POST['post_ID']) : false;
			
			$datatype = str_replace(':', '_', str_replace('[*]', '', $datatype));
			
			if(!$datatype || !$post_ID)
				die(0);

			// get the path of data folder

			$path = get_post_meta($post_ID, '_imp_data_folder', true);
			
			$content = file_get_contents($path.'/preview.xml');
			
			$xml = simplexml_load_string($content);

			wp_send_json($xml->xpath($datatype));
		}

		function imp_save_mapping() {
			$imp_save_fields = isset($_POST['imp-saved-fields']) ? stripslashes($_POST['imp-saved-fields']): false;
			$imp_data_type =  isset($_POST['imp-data-type']) ? stripslashes($_POST['imp-data-type']): false;
			$imp_post_format =  isset($_POST['imp-post-format']) ? stripslashes($_POST['imp-post-format']): false;

			$post_ID = isset($_POST['post_ID']) && is_numeric($_POST['post_ID']) ? intval($_POST['post_ID']): false;

			if($imp_save_fields && $post_ID) {
				update_post_meta($post_ID, '_imp_saved_mapping', $imp_save_fields);
			}

			if($imp_data_type && $post_ID) {
				update_post_meta($post_ID, '_imp_data_type', $imp_data_type);
			}

			if($imp_post_format && $post_ID) {
				update_post_meta($post_ID, '_imp_post_format', $imp_post_format);
			}
		}

		function imp_load_mapping($post) {

			if($post->post_type != 'imp_import')
				return;

			$imp_save_fields = get_post_meta($post->ID, '_imp_saved_mapping', true);
			
			echo "<input type='hidden' name='imp-saved-fields' id='imp-saved-fields' value='".esc_attr($imp_save_fields)."' />";

			$imp_data_type = get_post_meta($post->ID, '_imp_data_type', true);
			
			echo "<input type='hidden' name='imp-data-type' id='imp-data-type' value='".esc_attr($imp_data_type)."' />";

			$imp_post_format = get_post_meta($post->ID, '_imp_post_format', true);
			
			echo "<input type='hidden' name='imp-post-format' id='imp-post-format' value='".esc_attr($imp_post_format)."' />";
		}
	}

	$impEverImport = new Imp_Ever_Import();
	$impEverImport->imp_ever_import_serve();
}	

?>