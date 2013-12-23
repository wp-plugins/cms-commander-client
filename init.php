<?php
/* 
Plugin Name: CMS Commander
Plugin URI: http://cmscommander.com/
Description: Manage all your Wordpress websites remotely and enhance your articles with targeted images and ads. Visit <a href="http://cmscommander.com">CMSCommander.com</a> to sign up.
Author: CMS Commander
Version: 2.02
Author URI: http://cmscommander.com
*/

/*************************************************************
 * 
 * init.php
 * 
 * Initialize the communication with master
 * 
 * 
 * Copyright (c) 2011 Prelovac Media
 * www.prelovac.com
 **************************************************************/
if(basename($_SERVER['SCRIPT_FILENAME']) == "init.php"):
    exit;
endif;
if(!defined('CMSC_WORKER_VERSION'))
	define('CMSC_WORKER_VERSION', '2.02');

if ( !defined('CMSC_XFRAME_COOKIE')){
	$siteurl = function_exists( 'get_site_option' ) ? get_site_option( 'siteurl' ) : get_option( 'siteurl' );
	define('CMSC_XFRAME_COOKIE', $xframe = 'wordpress_'.md5($siteurl).'_xframe');
}
global $wpdb, $cmsc_plugin_dir, $cmsc_plugin_url, $wp_version, $cmsc_filters, $_cmsc_item_filter;
if (version_compare(PHP_VERSION, '5.0.0', '<')) // min version 5 supported
    exit("<p>The CMS Commander plugin requires PHP 5 or higher.</p>");


$cmsc_wp_version = $wp_version;
$cmsc_plugin_dir = WP_PLUGIN_DIR . '/' . basename(dirname(__FILE__));
$cmsc_plugin_url = WP_PLUGIN_URL . '/' . basename(dirname(__FILE__));

require_once("$cmsc_plugin_dir/helper.class.php");
require_once("$cmsc_plugin_dir/core.class.php");

require_once("$cmsc_plugin_dir/post.class.php");
require_once("$cmsc_plugin_dir/comment.class.php");
require_once("$cmsc_plugin_dir/stats.class.php");
require_once("$cmsc_plugin_dir/backup.class.php");
require_once("$cmsc_plugin_dir/installer.class.php");
require_once("$cmsc_plugin_dir/link.class.php");
require_once("$cmsc_plugin_dir/user.class.php");
require_once("$cmsc_plugin_dir/api.php");
require_once("$cmsc_plugin_dir/comment_cmsc.class.php"); // CMSC
require_once("$cmsc_plugin_dir/cmsc.class.php"); // CMSC
require_once("$cmsc_plugin_dir/plugins/search/search.php");
require_once("$cmsc_plugin_dir/plugins/cleanup/cleanup.php");
require_once("$cmsc_plugin_dir/init_cmsc.php"); // CMSC

if(!function_exists( 'json_decode' ) && !function_exists( 'json_encode' )){
	global $cmsc_plugin_dir;
	require_once ($cmsc_plugin_dir.'/lib/json/JSON.php' );
	
	function json_decode($json_object,  $assoc = false) {
		$json = $assoc == true ? new Services_JSON(SERVICES_JSON_LOOSE_TYPE) : new Services_JSON();
		return $json->decode($json_object);
	}
	
	function json_encode($object_data) {
		$json = new Services_JSON();
		return $json->encode($object_data);
	}
}

if( !function_exists ( 'cmsc_parse_data' )) {
	function cmsc_parse_data( $data = array() ){
		if( empty($data) )
			return $data;
		
		$data = (array) $data;
		if( isset($data['params']) )
			$data['params'] = cmsc_filter_params( $data['params'] );
		
		$postkeys = array('action', 'cmsc_action', 'params', 'id', 'signature', 'setting', 'cmsc' );
		
		if( !empty($data) ){
			foreach($data as $key => $items){
				if( !in_array($key, $postkeys) )
					unset($data[$key]);
			}
		}
		return $data;
	}
}

if( !function_exists ( 'cmsc_filter_params' )) {
	function cmsc_filter_params( $array = array() ){
		
		$filter = array( 'current_user', 'wpdb' );
		$return = array();
		foreach ($array as $key => $val) { 
			if( !is_int($key) && in_array($key, $filter) )
				continue;
				
			if( is_array( $val ) ) { 
				$return[$key] = cmsc_filter_params( $val );
			} else {
				$return[$key] = $val;
			}
		} 
		
		return $return;
	}
}

if( !function_exists ( 'hex2bin' )) {
	function hex2bin($h){
  		if (!is_string($h)) return null;
  		$r='';
  		for ($a=0; $a<strlen($h); $a+=2) { $r.=chr(hexdec($h{$a}.$h{($a+1)})); }
  		return $r;
  	}
}


if( !function_exists('cmsc_authenticate')) {
    function cmsc_authenticate() {
	
        global $_cmsc_data, $_cmsc_auth, $cmsc_core;

        if (!isset($HTTP_RAW_POST_DATA)) {
            $HTTP_RAW_POST_DATA = file_get_contents('php://input');
        }
        /*if(substr($HTTP_RAW_POST_DATA, 0, 7) == "action="){
            $HTTP_RAW_POST_DATA = str_replace("action=", "", $HTTP_RAW_POST_DATA);
        }*/
		
        $_cmsc_data = base64_decode($HTTP_RAW_POST_DATA);
        if (!$_cmsc_data){
            return;
        }
        $_cmsc_data = cmsc_parse_data(  @unserialize($_cmsc_data)  );

        if(empty($_cmsc_data['cmsc_action'])) {
            return;
        } else {
			$_cmsc_data['action'] = $_cmsc_data['cmsc_action'];
		}
		
        if($_cmsc_data['cmsc'] !== "yes") {
            return;
        }		

        if (!$cmsc_core->check_if_user_exists($_cmsc_data['params']['username'])) {
            cmsc_response('Username <b>' . $_cmsc_data['params']['username'] . '</b> does not have administrator capabilities. Please check the Admin username.', false);
        }

        if($_cmsc_data['action'] === 'add_site') {
            $_cmsc_auth = true;
        } else {
            $_cmsc_auth = $cmsc_core->authenticate_message($_cmsc_data['action'] . $_cmsc_data['id'], $_cmsc_data['signature'], $_cmsc_data['id']);
        }

        if($_cmsc_auth !== true) {
            cmsc_response($_cmsc_auth['error'], false);
        }

        if(isset($_cmsc_data['params']['username']) && !is_user_logged_in()){
            $user = function_exists('get_user_by') ? get_user_by('login', $_cmsc_data['params']['username']) : get_userdatabylogin( $_cmsc_data['params']['username'] );
            wp_set_current_user($user->ID);
            if(@getenv('IS_WPE'))
                wp_set_auth_cookie($user->ID);			
        }

    }
}

if( !function_exists ( 'cmsc_parse_request' )) {
    function cmsc_parse_request(){
        global $cmsc_core, $wp_db_version, $wpmu_version, $_wp_using_ext_object_cache, $_cmsc_data, $_cmsc_auth;
				
        if(empty($_cmsc_auth)) {
            CMSC_Stats::set_hit_count();
            return;
        }
	
        ob_start();
        $_wp_using_ext_object_cache = false;
        @set_time_limit(600);

        if ($_cmsc_data['action'] === 'add_site') {
            cmsc_add_site($_cmsc_data['params']);
            cmsc_response('You should never see this.', false);
        }

        /* in case database upgrade required, do database backup and perform upgrade ( wordpress wp_upgrade() function ) */
        if( strlen(trim($wp_db_version)) && !defined('ACX_PLUGIN_DIR') ){
            if ( get_option('db_version') != $wp_db_version ) {
                /* in multisite network, please update database manualy */
                if (empty($wpmu_version) || (function_exists('is_multisite') && !is_multisite())){
                    if( ! function_exists('wp_upgrade'))
                        include_once(ABSPATH.'wp-admin/includes/upgrade.php');

                    ob_clean();
                    @wp_upgrade();
                    @do_action('after_db_upgrade');
                    ob_end_clean();
                }
            }
        }

        if(isset($_cmsc_data['params']['secure'])){
            if($decrypted = $cmsc_core->_secure_data($_cmsc_data['params']['secure'])){
                $decrypted = maybe_unserialize($decrypted);
                if(is_array($decrypted)){
                    foreach($decrypted as $key => $val){
                        if(!is_numeric($key))
                            $_cmsc_data['params'][$key] = $val;
                    }
                    unset($_cmsc_data['params']['secure']);
                } else $_cmsc_data['params']['secure'] = $decrypted;
            }
        }

        if( isset($_cmsc_data['setting']) ){
            $cmsc_core->save_options( $_cmsc_data['setting'] );
        }

        if( !$cmsc_core->register_action_params( $_cmsc_data['action'], $_cmsc_data['params'] ) ){
            global $_cmsc_plugin_actions;
            $_cmsc_plugin_actions[$_cmsc_data['action']] = $_cmsc_data['params'];
        }
        ob_end_clean();
    }
}

/* Main response function */
if( !function_exists ( 'cmsc_response' )) {

	function cmsc_response($response = false, $success = true)
	{
		$return = array();
		
		if ((is_array($response) && empty($response)) || (!is_array($response) && strlen($response) == 0))
			$return['error'] = 'Empty response.';
		else if ($success)
			$return['success'] = $response;
		else
			$return['error'] = $response;
		
		if( !headers_sent() ){
			header('HTTP/1.0 200 OK');
			header('Content-Type: text/plain');
		}
		exit("<CMSCHEADER>" . base64_encode(serialize($return))."<ENDCMSCHEADER>");
	}
}



if( !function_exists ( 'cmsc_add_site' )) {
	function cmsc_add_site($params) {
		global $cmsc_core;
		$num = extract($params);
		
		if ($num) {
			if (!get_option('_cmsc_action_message_id') && !get_option('_cmsc_public_key')) {
				$public_key = base64_decode($public_key);
				
				if (function_exists('openssl_verify')) {
					$verify = openssl_verify($action . $id, base64_decode($signature), $public_key);
					if ($verify == 1) {
						$cmsc_core->set_master_public_key($public_key);
						$cmsc_core->set_cmsc_message_id($id);
						$cmsc_core->get_stats_instance();
						if(isset($notifications) && is_array($notifications) && !empty($notifications)){
							$cmsc_core->stats_instance->set_notifications($notifications);
						}
						if(isset($brand) && is_array($brand) && !empty($brand)){
							update_option('cmsc_worker_brand',$brand);
						}
						
						if( isset( $add_settigns ) && is_array($add_settigns) && !empty( $add_settigns ) )
							apply_filters( 'cmsc_website_add', $add_settigns );
							
						cmsc_response($cmsc_core->stats_instance->get_initial_stats(), true);
					} else if ($verify == 0) {
																			
						//cmsc_response('Site could not be added. OpenSSL verification error: "'.openssl_error_string().'". Contact your hosting support to check the OpenSSL configuration.', false);
						
					} else {
						cmsc_response('Command not successful. Please try again.', false);
					}
				} 
					
					if (!get_option('_cmsc_nossl_key')) {
						srand();
						
						$random_key = md5(base64_encode($public_key) . rand(0, getrandmax()));
						
						$cmsc_core->set_random_signature($random_key);
						$cmsc_core->set_cmsc_message_id($id);
						$cmsc_core->set_master_public_key($public_key);
						$cmsc_core->get_stats_instance();						
						if(is_array($notifications) && !empty($notifications)){
							$cmsc_core->stats_instance->set_notifications($notifications);
						}
						
						if(is_array($brand) && !empty($brand)){
							update_option('cmsc_worker_brand',$brand);
						}
						
						cmsc_response($cmsc_core->stats_instance->get_initial_stats(), true);
					} else
						cmsc_response('Please deactivate & activate CMS Commander plugin on your site, then re-add the site to your dashboard.', false);
			
			} else {
				cmsc_response('Please deactivate & activate CMS Commander plugin on your site and re-add the site to your dashboard.', false);
			}
		} else {
			cmsc_response('Invalid parameters received. Please try again.', false);
		}
	}
}

if( !function_exists ( 'cmsc_remove_site' )) {
	function cmsc_remove_site($params)
	{
		extract($params);
		global $cmsc_core;
		$cmsc_core->uninstall( $deactivate );
		
		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		$plugin_slug = basename(dirname(__FILE__)) . '/' . basename(__FILE__);
		
		if ($deactivate) {
			deactivate_plugins($plugin_slug, true);
		}
		
		if (!is_plugin_active($plugin_slug))
			cmsc_response(array(
				'deactivated' => 'Site removed successfully. <br /><br />CMS Commander plugin successfully deactivated.'
			), true);
		else
			cmsc_response(array(
				'removed_data' => 'Site removed successfully. <br /><br /><b>CMS Commander plugin was not deactivated.</b>'
			), true);
		
	}
}
if( !function_exists ( 'cmsc_stats_get' )) {
	function cmsc_stats_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		cmsc_response($cmsc_core->stats_instance->get($params), true);
	}
}

if( !function_exists ( 'cmsc_worker_header' )) {
	function cmsc_worker_header()
	{	global $cmsc_core, $current_user;
		
		if(!headers_sent()){
			if(isset($current_user->ID))
				$expiration = time() + apply_filters('auth_cookie_expiration', 10800, $current_user->ID, false);
			else 
				$expiration = time() + 10800;
				
			setcookie(CMSC_XFRAME_COOKIE, md5(CMSC_XFRAME_COOKIE), $expiration, COOKIEPATH, COOKIE_DOMAIN, false, true);
			$_COOKIE[CMSC_XFRAME_COOKIE] = md5(CMSC_XFRAME_COOKIE);
		}
	}
}

if( !function_exists ( 'cmsc_pre_init_stats' )) {
	function cmsc_pre_init_stats( $params )
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		return $cmsc_core->stats_instance->pre_init_stats($params);
	}
}

if( !function_exists ( 'cmsc_datasend' )) {
	function cmsc_datasend( $params = array() )
	{
		global $cmsc_core, $_cmsc_item_filter, $_cmsc_options;
		if( isset($_cmsc_options['datacron']) ){
			
			$_cmsc_remoteurl = get_option('home');
			$_cmsc_remoteown = isset($_cmsc_options['dataown']) && !empty($_cmsc_options['dataown']) ? $_cmsc_options['dataown'] : false;
			
			if( empty($_cmsc_remoteown) )
				return;
			
			$_cmsc_item_filter['pre_init_stats'] = array( 'core_update', 'hit_counter', 'comments', 'backups', 'posts', 'drafts', 'scheduled' );
			$_cmsc_item_filter['get'] = array( 'updates', 'errors' );
			$cmsc_core->get_stats_instance();
			
			$filter = array(
				'refresh' => '',
				'item_filter' => array(
					'get_stats' => array(
						array('updates', array('plugins' => true, 'themes' => true, 'premium' => true )),
						array('core_update', array('core' => true )),
						array('posts', array('numberposts' => 5 )),
						array('drafts', array('numberposts' => 5 )),
						array('scheduled', array('numberposts' => 5 )),
						array('hit_counter'),
						array('comments', array('numberposts' => 5 )),
						array('backups'),
						'plugins' => array('cleanup' => array(
										'overhead' => array(),
										'revisions' => array( 'num_to_keep' => 'r_5'),
										'spam' => array(),
									)
						),
					),
				)
			);
			
			$pre_init_data = $cmsc_core->stats_instance->pre_init_stats($filter);
			$init_data = $cmsc_core->stats_instance->get($filter);
			
			$data = array_merge($init_data, $pre_init_data);
			$hash = $cmsc_core->get_secure_hash();
			
			$datasend['datasend'] = $cmsc_core->encrypt_data($data);
			$datasend['sitehome'] = base64_encode($_cmsc_remoteown.'[]'.$_cmsc_remoteurl);
			$datasend['sitehash'] = md5($hash.$_cmsc_remoteown.$_cmsc_remoteurl);
			
			if ( !class_exists('WP_Http') )
                include_once(ABSPATH . WPINC . '/class-http.php');
			
			$remote = array();
			$remote['body'] = $datasend;
			$result = wp_remote_post($_cmsc_options['datacron'], $remote);
			if(!is_wp_error($result)){
				if(isset($result['body']) && !empty($result['body'])){
					$settings = @unserialize($result['body']);
					/* rebrand worker or set default */
					$brand = '';
					if($settings['worker_brand']){
						$brand = $settings['worker_brand'];
					}
					update_option("cmsc_worker_brand",$brand);
					/* change worker version */
					$w_version = $settings['worker_updates']['version'];
					$w_url = $settings['worker_updates']['url'];
					if(version_compare(CMSC_WORKER_VERSION, $w_version, '<')){
						//automatic update
						$cmsc_core->update_worker_plugin(array("download_url" => $w_url));
					}					
				}
			}else{
				//$cmsc_core->_log($result);
			}			
		}
	}
}

//post
if( !function_exists ( 'cmsc_post_create' )) {
	function cmsc_post_create($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		$return = $cmsc_core->post_instance->create($params);
		if (is_int($return))
			cmsc_response($return, true);
		else{
			if(isset($return['error'])){
				cmsc_response($return['error'], false);
			} else {
				cmsc_response($return, false);
			}
		}
	}
}

if( !function_exists ( 'cmsc_change_post_status' )) {
	function cmsc_change_post_status($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		$return = $cmsc_core->post_instance->change_status($params);
		cmsc_response($return, true);

	}
}

//comments
if( !function_exists ( 'cmsc_change_comment_status' )) {
	function cmsc_change_comment_status($params)
	{
		global $cmsc_core;
		$cmsc_core->get_comment_instance();
		$return = $cmsc_core->comment_instance->change_status($params);
		//cmsc_response($return, true);
		if ($return){
			$cmsc_core->get_stats_instance();
			cmsc_response($cmsc_core->stats_instance->get_comments_stats($params), true);
		}else
			cmsc_response('Comment not updated', false);
	}

}
if( !function_exists ( 'cmsc_comment_stats_get' )) {
	function cmsc_comment_stats_get($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		cmsc_response($cmsc_core->stats_instance->get_comments_stats($params), true);
	}
}

if( !function_exists ( 'cmsc_backup_now' )) {
//backup
	function cmsc_backup_now($params)
	{
		global $cmsc_core;
		
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->backup($params);
		
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_run_task_now' )) {
	function cmsc_run_task_now($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		
		$task_name = isset($params['task_name']) ? $params['task_name'] : false;
		$google_drive_token = isset($params['google_drive_token']) ? $params['google_drive_token'] : false;
		
		if ($task_name) {
			$return = $cmsc_core->backup_instance->task_now($task_name, $google_drive_token);
			if (is_array($return) && array_key_exists('error', $return))
				cmsc_response($return['error'], false);
			else {
				cmsc_response($return, true);
			}		
		} else {
			cmsc_response("Task name is not provided.", false);
		}
	}
}

if( !function_exists ( 'cmsc_email_backup' )) {
	function cmsc_email_backup($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->email_backup($params);
		
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_check_backup_compat' )) {
	function cmsc_check_backup_compat($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->check_backup_compat($params);
		
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_get_backup_req' )) {
	function cmsc_get_backup_req( $params )
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		$return = $cmsc_core->stats_instance->get_backup_req($params);
		
		cmsc_response($return, true);
	}
}

// Fires when Backup Now, or some backup task is saved.
if( !function_exists ( 'cmsc_scheduled_backup' )) {
	function cmsc_scheduled_backup($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->set_backup_task($params);
		cmsc_response($return, $return);
	}
}

if( !function_exists ( 'cmsc_delete_backup' )) {
	function cmsc_delete_backup($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->delete_backup($params);
		cmsc_response($return, $return);
	}
}

if( !function_exists ( 'cmsc_optimize_tables' )) {
	function cmsc_optimize_tables($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->optimize_tables();
		if ($return)
			cmsc_response($return, true);
		else
			cmsc_response(false, false);
	}
}
if( !function_exists ( 'cmsc_restore_now' )) {
	function cmsc_restore_now($params)
	{
		global $cmsc_core;
		$cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->restore($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else
			cmsc_response($return, true);
		
	}
}

if( !function_exists ( 'cmsc_remote_backup_now' )) {
	function cmsc_remote_backup_now($params)
	{
		global $cmsc_core;
		$backup_instance = $cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->remote_backup_now($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else
			cmsc_response($return, true);
	}
}

if( !function_exists ( 'cmsc_clean_orphan_backups' )) {
	function cmsc_clean_orphan_backups()
	{
		global $cmsc_core;
		$backup_instance = $cmsc_core->get_backup_instance();
		$return = $cmsc_core->backup_instance->cleanup();
		if(is_array($return))
			cmsc_response($return, true);
		else
			cmsc_response($return, false);
	}
}

add_filter( 'cmsc_website_add', 'cmsc_readd_backup_task' );

function cmsc_run_backup_action() {
	if(isset($_POST['cmsc_backup_nonce']))
		if (!wp_verify_nonce($_POST['cmsc_backup_nonce'], 'cmsc-backup-nonce')) return false;
	$public_key = get_option('_cmsc_public_key');
	if (!isset($_POST['public_key']) || $public_key !== $_POST['public_key']) return false;
	$args = @json_decode(stripslashes($_POST['args']), true);
	if (!$args) return false;
	$cron_action = isset($_POST['backup_cron_action']) ? $_POST['backup_cron_action'] : false;
	if ($cron_action) {
		do_action($cron_action, $args);
	}
	//unset($_POST['public_key']);
	unset($_POST['cmsc_backup_nonce']);
	unset($_POST['args']);
	unset($_POST['backup_cron_action']);
	return true;
}

if (!function_exists('cmsc_readd_backup_task')) {
	function cmsc_readd_backup_task($params = array()) {
		global $cmsc_core;
		$backup_instance = $cmsc_core->get_backup_instance();
		$settings = $backup_instance->readd_tasks($params);
		return $settings;
	}
}

if( !function_exists ( 'cmsc_update_cmsc_plugin' )) {
	function cmsc_update_cmsc_plugin($params)
	{
		global $cmsc_core;
		cmsc_response($cmsc_core->update_cmsc_plugin($params), true);
	}
}

if( !function_exists ( 'cmsc_wp_checkversion' )) {
	function cmsc_wp_checkversion($params)
	{
		include_once(ABSPATH . 'wp-includes/version.php');
		global $cmsc_wp_version, $cmsc_core;
		cmsc_response($cmsc_wp_version, true);
	}
}
if( !function_exists ( 'cmsc_cmsc_search_posts_by_term' )) {
	function cmsc_cmsc_search_posts_by_term($params)
	{
		global $cmsc_core;
		$cmsc_core->get_search_instance();
		
		$search_type = trim($params['search_type']);
		$search_term = strtolower(trim($params['search_term']));

		switch ($search_type){
			case 'page_post':
				$return = $cmsc_core->search_instance->cmsc_search_posts_by_term($params);
				if($return){
					$return = serialize($return);
					cmsc_response($return, true);
				}else{
					cmsc_response('No posts found', false);
				}
				break;
				
			case 'plugin':
				$plugins = get_option('active_plugins');
				
				$have_plugin = false;
				foreach ($plugins as $plugin) {
					if(strpos($plugin, $search_term)>-1){
						$have_plugin = true;
					}
				}
				if($have_plugin){
					cmsc_response(serialize($plugin), true);
				}else{
					cmsc_response(false, false);
				}
				break;
			case 'theme':
				$theme = strtolower(get_option('template'));
				if(strpos($theme, $search_term)>-1){
					cmsc_response($theme, true);
				}else{
					cmsc_response(false, false);
				}
				break;
			default: cmsc_response(false, false);		
		}
		$return = $cmsc_core->search_instance->cmsc_search_posts_by_term($params);
		
		
		
		if ($return_if_true) {
			cmsc_response($return_value, true);
		} else {
			cmsc_response($return_if_false, false);
		}
	}
}

if( !function_exists ( 'cmsc_install_addon' )) {
	function cmsc_install_addon($params)
	{
		global $cmsc_core;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->install_remote_file($params);
		cmsc_response($return, true);
		
	}
}

if( !function_exists ( 'cmsc_do_upgrade' )) {
	function cmsc_do_upgrade($params)
	{
		global $cmsc_core, $cmsc_upgrading;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->do_upgrade($params);
		cmsc_response($return, true);
		
	}
}

if( !function_exists ('cmsc_get_links')) {
	function cmsc_get_links($params)
	{
		global $cmsc_core;
		$cmsc_core->get_link_instance();
			$return = $cmsc_core->link_instance->get_links($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_add_link' )) {
	function cmsc_add_link($params)
	{
		global $cmsc_core;
		$cmsc_core->get_link_instance();
			$return = $cmsc_core->link_instance->add_link($params);
		if (is_array($return) && array_key_exists('error', $return))
		
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
		
	}
}

if( !function_exists ('cmsc_delete_link')) {
	function cmsc_delete_link($params)
	{
		global $cmsc_core;
		$cmsc_core->get_link_instance();
		
			$return = $cmsc_core->link_instance->delete_link($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_links')) {
	function cmsc_delete_links($params)
	{
		global $cmsc_core;
		$cmsc_core->get_link_instance();
		
			$return = $cmsc_core->link_instance->delete_links($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_add_user' )) {
	function cmsc_add_user($params)
	{
		global $cmsc_core;
		$cmsc_core->get_user_instance();
			$return = $cmsc_core->user_instance->add_user($params);
		if (is_array($return) && array_key_exists('error', $return))
		
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
		
	}
}

if( !function_exists ('cmsc_get_users')) {
	function cmsc_get_users($params)
	{
		global $cmsc_core;
		$cmsc_core->get_user_instance();
			$return = $cmsc_core->user_instance->get_users($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_edit_users')) {
	function cmsc_edit_users($params)
	{
		global $cmsc_core;
		$cmsc_core->get_user_instance();
		$return = $cmsc_core->user_instance->edit_users($params);
		cmsc_response($return, true);
	}
}

/* NEW in update 3.26 if( !function_exists ('cmsc_edit_users')) {
	function cmsc_edit_users($params)
    {
        global $cmsc_core;
        $cmsc_core->get_user_instance();
        $users = $cmsc_core->user_instance->edit_users($params);
        $response = 'User updated.';
        $check_error = false;
        foreach ($users as $username => $user) {
            $check_error = array_key_exists('error', $user);
            if($check_error){
                $response = $username.': '.$user['error'];
            }
        }
        cmsc_response($response, !$check_error);
    }
}*/

if( !function_exists ('cmsc_get_posts')) {
	function cmsc_get_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->get_posts($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_post')) {
	function cmsc_delete_post($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->delete_post($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_posts')) {
	function cmsc_delete_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->delete_posts($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_edit_posts')) {
	function cmsc_edit_posts($params)
	{
		global $cmsc_core;
		$cmsc_core->get_posts_instance();
		$return = $cmsc_core->posts_instance->edit_posts($params);
		cmsc_response($return, true);
	}
}

if( !function_exists ('cmsc_get_pages')) {
	function cmsc_get_pages($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->get_pages($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ('cmsc_delete_page')) {
	function cmsc_delete_page($params)
	{
		global $cmsc_core;
		$cmsc_core->get_post_instance();
		
			$return = $cmsc_core->post_instance->delete_page($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists ( 'cmsc_iframe_plugins_fix' )) {
	function cmsc_iframe_plugins_fix($update_actions)
	{
		foreach($update_actions as $key => $action)
		{
			$update_actions[$key] = str_replace('target="_parent"','',$action);
		}
		
		return $update_actions;
		
	}
}
if( !function_exists ( 'cmsc_execute_php_code' )) {
	function cmsc_execute_php_code($params)
	{
		ob_start();
		eval($params['code']);
		$return = ob_get_flush();
		cmsc_response(print_r($return, true), true);
	}
}

if( !function_exists ( 'cmsc_set_notifications' )) {
	function cmsc_set_notifications($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
			$return = $cmsc_core->stats_instance->set_notifications($params);
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
		
	}
}

if( !function_exists ('cmsc_get_dbname')) {
	function cmsc_get_dbname($params)
	{
		global $cmsc_core;
		$cmsc_core->get_stats_instance();
		
		$return = $cmsc_core->stats_instance->get_active_db();
		if (is_array($return) && array_key_exists('error', $return))
			cmsc_response($return['error'], false);
		else {
			cmsc_response($return, true);
		}
	}
}

if( !function_exists('cmsc_more_reccurences') ){
	//Backup Tasks
	add_filter('cron_schedules', 'cmsc_more_reccurences');
	function cmsc_more_reccurences($schedules) {
		$schedules['halfminute'] = array('interval' => 30, 'display' => 'Once in a half minute');
		$schedules['minutely'] = array('interval' => 60, 'display' => 'Once in a minute');
		$schedules['fiveminutes'] = array('interval' => 300, 'display' => 'Once every five minutes');
		$schedules['tenminutes'] = array('interval' => 600, 'display' => 'Once every ten minutes');
		$schedules['sixhours'] = array('interval' => 21600, 'display' => 'Every six hours');
		$schedules['fourhours'] = array('interval' => 14400, 'display' => 'Every four hours');
		$schedules['threehours'] = array('interval' => 10800, 'display' => 'Every three hours');
	
		return $schedules;
	}
}
	
	add_action('cmsc_backup_tasks', 'cmsc_check_backup_tasks');
	
if( !function_exists('cmsc_check_backup_tasks') ){
 	function cmsc_check_backup_tasks() {
		global $cmsc_core, $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;
		
		$cmsc_core->get_backup_instance();
		$cmsc_core->backup_instance->check_backup_tasks();
	}
}

// Remote upload in the second request.
//add_action('cmsc_scheduled_remote_upload', 'cmsc_call_scheduled_remote_upload');
add_action('cmsc_remote_upload', 'cmsc_call_scheduled_remote_upload');

if( !function_exists('cmsc_call_scheduled_remote_upload') ){
	function cmsc_call_scheduled_remote_upload($args) {
		global $cmsc_core, $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;
		
		$cmsc_core->get_backup_instance();
		if (isset($args['task_name'])) {
			$cmsc_core->backup_instance->remote_backup_now($args);
		}
	}
}

// if (!wp_next_scheduled('cmsc_notifications')) {
	// wp_schedule_event( time(), 'twicedaily', 'cmsc_notifications' );
// }
// add_action('cmsc_notifications', 'cmsc_check_notifications');
	

if (!wp_next_scheduled('cmsc_datasend')) {
	wp_schedule_event( time(), 'threehours', 'cmsc_datasend' );
}
add_action('cmsc_datasend', 'cmsc_datasend');
	
if( !function_exists('cmsc_check_notifications') ){
 	function cmsc_check_notifications() {
		global $cmsc_core, $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;
		
		$cmsc_core->get_stats_instance();
		$cmsc_core->stats_instance->check_notifications();
	}
}


if( !function_exists('cmsc_get_plugins_themes') ){
 	function cmsc_get_plugins_themes($params) {
		global $cmsc_core;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->get($params);
		cmsc_response($return, true);
	}
}

if( !function_exists('cmsc_get_autoupdate_plugins_themes') ){
    function cmsc_get_autoupdate_plugins_themes($params) {
        $return = CMSC_Updater::getSettings($params);
        cmsc_response($return, true);
    }
}

if( !function_exists('cmsc_edit_plugins_themes') ){
 	function cmsc_edit_plugins_themes($params) {
		global $cmsc_core;
		$cmsc_core->get_installer_instance();
		$return = $cmsc_core->installer_instance->edit($params);
		cmsc_response($return, true);
	}
}

if( !function_exists('cmsc_edit_autoupdate_plugins_themes') ){
    function cmsc_edit_autoupdate_plugins_themes($params) {
        $return = CMSC_Updater::setSettings($params);
        cmsc_response($return, true);
    }
}

if( !function_exists('cmsc_worker_brand')){
 	function cmsc_worker_brand($params) {
		update_option("cmsc_worker_brand",$params['brand']);
		cmsc_response(true, true);
	}
}

if( !function_exists('cmsc_maintenance_mode')){
 	function cmsc_maintenance_mode( $params ) {
		global $wp_object_cache;
		
		$default = get_option('cmsc_maintenace_mode');
		$params = empty($default) ? $params : array_merge($default, $params);
		update_option("cmsc_maintenace_mode", $params);
		
		if(!empty($wp_object_cache))
			@$wp_object_cache->flush(); 
		cmsc_response(true, true);
	}
}

if( !function_exists('cmsc_plugin_actions') ){
 	function cmsc_plugin_actions() {
		global $cmsc_actions, $cmsc_core;
		
		if(!empty($cmsc_actions)){
			global $_cmsc_plugin_actions;
			if(!empty($_cmsc_plugin_actions)){
				$failed = array();
				foreach($_cmsc_plugin_actions as $action => $params){
					if(isset($cmsc_actions[$action]))
						call_user_func($cmsc_actions[$action], $params);
					else 
						$failed[] = $action;
				}
				if(!empty($failed)){
					$f = implode(', ', $failed);
					$s = count($f) > 1 ? 'Actions "' . $f . '" do' : 'Action "' . $f . '" does';
					cmsc_response($s.' not exist. Please update your CMS Commander plugin.', false);
				}
					
			}
		}
		
		global $pagenow, $current_user, $mmode;
		if( !is_admin() && !in_array($pagenow, array( 'wp-login.php' ))){
			$mmode = get_option('cmsc_maintenace_mode');
			if( !empty($mmode) ){
				if(isset($mmode['active']) && $mmode['active'] == true){
					if(isset($current_user->data) && !empty($current_user->data) && isset($mmode['hidecaps']) && !empty($mmode['hidecaps'])){
						$usercaps = array();
						if(isset($current_user->caps) && !empty($current_user->caps)){
							$usercaps = $current_user->caps;
						}
						foreach($mmode['hidecaps'] as $cap => $hide){
							if(!$hide)
								continue;
							
							foreach($usercaps as $ucap => $val){
								if($ucap == $cap){
									ob_end_clean();
									ob_end_flush();
									die($mmode['template']);
								}
							}
						}
					} else
						die($mmode['template']);
				}
			}
		}
		
		if (file_exists(dirname(__FILE__) . '/log')) {
			unlink(dirname(__FILE__) . '/log');
		}		
	}
} 

$cmsc_core = new CMSC_Core();

if(isset($_GET['auto_login']))
	$cmsc_core->automatic_login();	

require_once dirname(__FILE__) . '/updater.php';
CMSC_Updater::register();	
	
if (function_exists('register_activation_hook'))
    register_activation_hook( __FILE__ , array( $cmsc_core, 'install' ));

if (function_exists('register_deactivation_hook'))
    register_deactivation_hook(__FILE__, array( $cmsc_core, 'uninstall' ));

if (function_exists('add_action'))
	add_action('init', 'cmsc_plugin_actions', 99999);

if (function_exists('add_filter'))
	add_filter('install_plugin_complete_actions','cmsc_iframe_plugins_fix');
	
if(	isset($_COOKIE[CMSC_XFRAME_COOKIE]) ){
	remove_action( 'admin_init', 'send_frame_options_header');
	remove_action( 'login_init', 'send_frame_options_header');
}

function cmsc_error_handler($errno, $errstr, $errfile, $errline, $errcontext)
{
    $errorId = 'cmsc_error_' . md5($errfile . $errline);
    $error = sprintf("%s\nError [%s]: %s\nIn file: %s:%s", date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline);
    set_transient($errorId, $error, 3600);
}

function cmsc_fatal_error_handler()
{
    $isError = false;
    if ($error = error_get_last()) {
        switch ($error['type']) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $isError = true;
                break;
        }
    }
    if ($isError) {
        cmsc_error_handler($error['type'], $error['message'], $error['file'], $error['line'], array());
    }
}


if (get_option('cmsc_debug_enable')) {
    set_error_handler('cmsc_error_handler');
    register_shutdown_function('cmsc_fatal_error_handler');
}

?>