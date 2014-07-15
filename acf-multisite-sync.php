<?php
/*
Plugin Name: ACF Multisite Sync
Plugin URI: http://www.philipp-kuehn.com/
Description: Sync your Advanced Custom Fields to all of your multisite installations with just one click. Needs ACF Pro 5.0+
Version: 0.0.1
Author: Philipp Kühn
Author URI: http://www.philipp-kuehn.com/
License: GPLv3

Copyright (c) 2014 Philipp Kühn

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class acf_multisite_sync {
	
	function __construct() {
		
		// language pack
		$language_file = plugin_dir_path(__FILE__) . '/lang/acf-multisite-sync-' . get_locale() . '.mo';
		$language = (file_exists($language_file)) ? get_locale() : 'en_US';
		load_textdomain('acf-multisite-sync', plugin_dir_path(__FILE__) . '/lang/acf-multisite-sync-' . $language . '.mo');
		
		// check if multisite
		if (is_multisite()) {
			
			// start after loaded all plugins
			add_action('plugins_loaded', array($this, 'acf_sync_init'));
			
		} else {
			
			// no multisite
			add_action('admin_notices', array($this, 'acf_sync_error_multisite'));
			
		}

	}
	
	function acf_sync_init() {
		
		// include to have access to get_plugins()
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		// get all activated plugins
		$plugins = get_plugins();
		
		// check if this multisite install is your main page
		if (is_main_site()) {
			
			// check if acf is activated
			if (isset($plugins['advanced-custom-fields-pro/acf.php'])) {
				
				// check if acf version is 5+
				if ($plugins['advanced-custom-fields-pro/acf.php']['Version'] >= 5 
					&& is_plugin_active('advanced-custom-fields-pro/acf.php')) {
					
					// add submenu
					add_action('admin_menu', array($this, 'acf_sync_submenu'), 100);
					
				} else {
					
					// not compatible
					add_action('admin_notices', array($this, 'acf_sync_error_compatibility'));
					
				}
				
			} else {
				
				// not compatible
				add_action('admin_notices', array($this, 'acf_sync_error_compatibility'));
				
			}
			
		} else {
				
			// no main site
			add_action('admin_notices', array($this, 'acf_sync_error_mainsite'));
			
		}
	
	}
	
	function acf_sync_error_compatibility() {
		
		echo '<div class="error">';
			echo '<p>' . __('Compatibility Error', 'acf-multisite-sync') . '</p>';
		echo '</div>';

	}
	
	function acf_sync_error_multisite() {
		
		echo '<div class="error">';
			echo '<p>' . __('Multisite Error', 'acf-multisite-sync') . '</p>';
		echo '</div>';

	}
	
	function acf_sync_error_mainsite() {
		
		echo '<div class="error">';
			echo '<p>' . __('Mainsite Error', 'acf-multisite-sync') . '</p>';
		echo '</div>';

	}
	
	function acf_sync_submenu() {
		
		add_submenu_page(
			'edit.php?post_type=acf-field-group', 
			__('Multisite', 'acf-multisite-sync'), 
			__('Multisite', 'acf-multisite-sync'), 
			'manage_options', 
			'acf-sync', 
			array($this, 'acf_sync_submenu_content')
		);
		
	}
	
	function acf_sync_submenu_content() {
		
		echo '<div class="wrap acf-settings-wrap">';
			echo '<h2>' . __('Multisite', 'acf-multisite-sync') . '</h2>';
			
			// sync if submited
			if (isset($_POST['custom_acf_sync'])) {
			
				$this->acf_sync_run();
				
				echo '<div id="message" class="updated below-h2">';
					echo '<p>' . __('Copied all', 'acf-multisite-sync') . '</p>';
				echo '</div>';
			
			}
			
			echo '<div class="acf-box">';
				echo '<div class="title">';
					echo '<h3>' . __('Headline-1', 'acf-multisite-sync') . '</h3>';
				echo '</div>';
				echo '<div class="inner">';
					echo '<form method="post" action="">';
						echo '<p>' . __('Description-1', 'acf-multisite-sync') . '</p>';
						echo '<p class="description">' . __('Description-2', 'acf-multisite-sync') . '</p>';
		            	submit_button(__('Sync Button', 'acf-multisite-sync'), 'primary', 'custom_acf_sync');
		            echo '</form>';
				echo '</div>';
			echo '</div>';
		echo '</div>';
	
	}
	
	function acf_sync_run() {
		
		// data to sync
		$export_data = $this->acf_sync_export();
		
		// get all multisite blogs
		$sites = wp_get_sites();
	
		foreach($sites as $site) {
			
			// only subsites
			if (!is_main_site($site['blog_id'])) {
				
				// connect to new multisite
				switch_to_blog($site['blog_id']);
					
				// remove old acf
			    $this->acf_sync_clear();
			    
			    // add new acf
			    $this->acf_sync_import($export_data);
				
				// quit multisite connection
				restore_current_blog();
				
			}
		    
		}
		
	}
	
	function acf_sync_clear() {
		
		// get all acf post from db
		$acf_posts = query_posts(array(
			'post_type' => array('acf-field-group', 'acf-field'),
			'showposts' => -1,
			'post_status' => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash')
		));
		
		// remove them
		foreach ($acf_posts as $acf_post) {
			wp_delete_post($acf_post->ID, true);
		}
	
	}
	
	// taken from: advanced-custom-fields-pro/admin/settings-export.php
	function acf_sync_export() {
		
		// get all field groups
		$field_groups = acf_get_field_groups();
		
		foreach($field_groups as $field_group_single) {
			
			// load field group
			$field_group = acf_get_field_group( $field_group_single['key'] );
			
			// load fields
			$fields = acf_get_fields( $field_group );
	
			// prepare fields
			$fields = acf_prepare_fields_for_export( $fields );
			
			// add to field group
			$field_group['fields'] = $fields;
			
			// extract field group ID
			$id = acf_extract_var( $field_group, 'ID' );
			
			// add to json array
			$data[] = $field_group;
			
		}
	
		return $data;
		
	}
	
	// taken from: advanced-custom-fields-pro/admin/settings-export.php
	function acf_sync_import($data) {
	
		// vars
		$ref = array();
		$order = array();
		
		foreach($data as $field_group) {
	    	
	    	// remove fields
			$fields = acf_extract_var($field_group, 'fields');
			
			// format fields
			$fields = acf_prepare_fields_for_import( $fields );
			
			// save field group
			$field_group = acf_update_field_group( $field_group );
			
			// add to ref
			$ref[ $field_group['key'] ] = $field_group['ID'];
			
			// add to order
			$order[ $field_group['ID'] ] = 0;
			
			// add fields
			foreach( $fields as $field ) {
				
				// add parent
				if ( empty($field['parent']) ) {
					
					$field['parent'] = $field_group['ID'];
					
				} elseif( isset($ref[ $field['parent'] ]) ) {
					
					$field['parent'] = $ref[ $field['parent'] ];
						
				}
				
				// add field menu_order
				if ( !isset($order[ $field['parent'] ]) ) {
					
					$order[ $field['parent'] ] = 0;
					
				}
				
				$field['menu_order'] = $order[ $field['parent'] ];
				$order[ $field['parent'] ]++;
				
				// save field
				$field = acf_update_field( $field );
				
				// add to ref
				$ref[ $field['key'] ] = $field['ID'];
				
			}
			
		}
		
	}

}

new acf_multisite_sync();