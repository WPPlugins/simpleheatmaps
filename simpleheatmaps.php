<?php 
/*
Plugin Name: SimpleHeatmaps
Plugin URI: http://wordpress.org/plugins/simpleheatmaps/
Description: The simplest & fastest way to see how customers are interacting for your WordPress site (see https://www.simpleheatmaps.com)
Version: 0.1.0
Author: SimpleHeatmaps, Inc
Author URI: https://www.simpleheatmaps.com
License: GPL2
*/
/*
Copyright 2015 - SimpleHeatmaps, Inc - https://www.simpleheatmaps.com

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class simpleheatmapsWP {
	/**
	 * Singleton
	 */
	private static $instance = null;
	private static $PLUGIN_DIR;
	private static $PLUGIN_SLUG;
	private static $ACTION_PREFIX;
	private static $OPTION_NAME = 'simpleheatmapsWP_options';
	private static $JS_TRIGGER = '<script>
!function(){window.SimpleHeatmapsLoader="shldr","shldr"in window||(window.shldr=function(){window.shldr.q.push(arguments)},window.shldr.q=[]),window.shldr.l=(new Date).getTime();var e=document.createElement("script");e.src="//d3qy04aabho0yp.cloudfront.net/assets/sh.min.js",e.async=!0;var n=document.getElementsByTagName("script")[0];n.parentNode.insertBefore(e,n)}();
</script>';
	/**
	 * Main function of the singleton class
	 */
	private function __construct() {
		if (is_admin()) {
			if (!$this->get_option('active')) {
				$this->check_account_active(3600); // while not active, check every hour
			}
			add_action('admin_menu', array($this, 'admin_menu'));
			if ($this->get_page_type() == 'admin' && false !== stristr($_GET['page'], self::$PLUGIN_SLUG)) {
				// do nothing
			} else {
				add_action('admin_notices', array($this, 'admin_notices'));
			}
			if ($this->get_option('active')) {
				add_action('admin_post_'.self::$ACTION_PREFIX.'save', array($this, 'admin_post_save'));
			} else {
				add_action('admin_post_'.self::$ACTION_PREFIX.'check', array($this, 'admin_post_check'));
			}
			// TODO: load_plugin_textdomain($PLUGIN_SLUG, false, self::$PLUGIN_DIR.'languages' );
		} else {
			if ($this->get_option('active')) {
				add_action('wp_head', array($this, 'front_write_script'));
			}
		}
		// make sure we have the cron
		if (is_admin() && !wp_next_scheduled(self::$ACTION_PREFIX.'cron_check_account')) {
			wp_schedule_event(time(), 'daily', self::$ACTION_PREFIX.'cron_check_account');
		}
		add_action(self::$ACTION_PREFIX.'cron_check_account', array($this, 'cron_check_account'));
		
		register_activation_hook(self::$PLUGIN_DIR.basename(__FILE__), array($this, 'activate_plugin'));
		register_deactivation_hook(self::$PLUGIN_DIR.basename(__FILE__), array($this, 'deactivate_plugin'));
	}
	public static function init() {
		if (!self::$instance) {
			self::$PLUGIN_SLUG = basename(__FILE__, '.php');
			self::$PLUGIN_DIR = self::$PLUGIN_SLUG.DIRECTORY_SEPARATOR;
			self::$ACTION_PREFIX = preg_replace('/[^a-z]+/', '_', strtolower(self::$PLUGIN_SLUG)).'_';
			self::$instance = new self();
		}
	}
	public function activate_plugin() {
		if (!$this->check_account_active()) {
			if ($check_err = $this->get_option('active_last_check_err')) {
				die('Error while activating the plugin: please contact support (error: '.$check_err.')');
			}
		}
	}
	public function deactivate_plugin() {
		wp_clear_scheduled_hook(self::$ACTION_PREFIX.'cron_check_account');
	}
	public function cron_check_account() {
		$this->check_account_active();
	}
	/**
	 * Adding the settings menu
	 */
	public function admin_menu() {
		add_menu_page('SimpleHeatmaps', 'SimpleHeatmaps', 'manage_options', self::$PLUGIN_SLUG, array($this, 'admin_options_page'), $this->get_asset('icon-16.png'));
	}
	public function admin_notices() {
		$this->show_notice(
			$this->get_page_type() == 'plugins' && !$this->get_option('active'),
			'error', 'SimpleHeatmaps',
			__('You are almost done!', self::$PLUGIN_SLUG).' '.
				sprintf('<a href="%s">%s</a>', admin_url('admin.php?'.http_build_query(array('page' => self::$PLUGIN_SLUG))), __('Click here to setup the plugin', self::$PLUGIN_SLUG))
		);
	}
	/**
	 */
	public function admin_options_page() {
		$this->show_options_page();
	}
	public function admin_post_check() {
		check_admin_referer(self::$ACTION_PREFIX.'check');
		$this->check_account_active();
		wp_safe_redirect(wp_get_referer());
	}
	public function admin_post_save() {
		check_admin_referer(self::$ACTION_PREFIX.'save');
		$new_values = array();
		$this->set_options($new_values, true);
		wp_safe_redirect(add_query_arg('saved', '1', wp_get_referer()));
	}
	/**
	 * Writing the script on the front-end pages
	 */
	public function front_write_script() {
		?>
<script>
!function(){window.SimpleHeatmapsLoader="shldr","shldr"in window||(window.shldr=function(){window.shldr.q.push(arguments)},window.shldr.q=[]),window.shldr.l=(new Date).getTime();var e=document.createElement("script");e.src="//d3qy04aabho0yp.cloudfront.net/assets/sh.min.js",e.async=!0;var n=document.getElementsByTagName("script")[0];n.parentNode.insertBefore(e,n)}();
</script>
		<?php
	}
	
	private $options = array();
	private function array_get($array, $key, $default = '') {
		return array_key_exists($key, $array) ? $array[$key] : $default;
	}
	/**
	 * Check if the current site has an active account with SimpleHeatmaps, Inc
	 * @return boolean
	 */
	private function check_account_active($frequency = 0) {
		if ($this->get_option('active_last_check', 0) < time() - $frequency) {
			$params = array(
				'u' => get_bloginfo('url'),
				'callback' => 'wp'
			);
			if (defined('WP_HEATMAP_AFFILIATEID')) {
				$params['aff'] = WP_HEATMAP_AFFILIATEID;
			}
			$check_url = '//www.simpleheatmaps.com/api/users/wp_check?'.http_build_query($params);
			$check_response = wp_remote_get('https:'.$check_url, array('timeout' => 10, 'sslverify' => false));
			$check_result = false;
			$check_err = '';
			if (is_wp_error($check_response)) {
				// fallback to http
				$check_response = wp_remote_get('http:'.$check_url, array('timeout' => 10));
			}
			if (is_wp_error($check_response)) {
				$check_err = $check_response->get_error_message();
			} else {
				$json_result = trim(preg_replace(array('/[\n\r]/', '/^wp/'), array('', ''), substr($check_response['body'], 4)), '();');
				$check_result = json_decode($json_result, true);
				if (!is_array($check_result) || !array_key_exists('active', $check_result)) {
					$check_err = 'cannot parse jsonp response '.print_r($json_result,true);
				}
			}
			$options_new_values = array(
				'active_last_check' => time(),
				'active_last_check_err' => $check_err,
			);
			if ('' === $check_err) {
				$options_new_values['active'] = $this->array_get($check_result, 'active', false);
			} elseif ($this->get_option('active_last_check_err')) {
				// on second successive error, we disable the plugin 
				$options_new_values['active'] = false;
			}
			$this->set_options($options_new_values);
		}
		return $this->get_option('active');
	}
	
	private function get_asset($filename) {
		return plugins_url(self::$PLUGIN_DIR.'assets'.DIRECTORY_SEPARATOR.$filename);
	}
	private function get_page_type() {
		global $pagenow;
		return str_replace('.php', '', $pagenow);
	}
	private function load_options() {
		if (count($this->options) > 0) return;
		$default_options = array(
			'active' => false,
			'active_last_check' => 0,
			'active_last_check_err' => ''
		);
		$option_db_value = get_option(self::$OPTION_NAME);
		if (empty($option_db_value)) {
			$this->set_options($default_options); // make sure to save the options in DB so WP won't try to load them separately each time
		} else {
			$this->set_options(wp_parse_args($option_db_value, $default_options), false); 
		}
	}
	private function get_option($option_key, $default = false) {
		$this->load_options();
		return $this->array_get($this->options, $option_key, $default);
	}
	private function set_options($new_values, $save_in_db = true) {
		$this->options = array_merge($this->options, $new_values);
		if ($save_in_db) {
			update_option(self::$OPTION_NAME, $this->options);
		}
	}
	private function show_notice($condition, $type, $title, $message) {
		if (!$condition) return;
		echo '<div id="message" class="', $type, '">',
			'<p>',
				'<strong>', $title, '</strong>: ',
				$message,
			'</p>',
		'</div>';
	}
	private function show_options_page() {
		?>
<div class="wrap">
	<div class="hm-content">
		<h2>SimpleHeatmaps settings</h2>
		<?php
		$this->show_notice($this->array_get($_GET, 'saved'), 'updated', 'Notice', __('Your changes have been saved', self::$PLUGIN_SLUG));
		$this->show_notice(!$this->get_option('active'), 'error', 'Error', 
			__('The plugin cannot find your SimpleHeatmaps account', self::$PLUGIN_SLUG).'<br>'.
			('' !== $this->get_option('active_last_check_err') ? sprintf('<small>[ error: %s ]</small>', $this->get_option('active_last_check_err')) : '')
		);
		?>
		<form action="admin-post.php" method="POST">
			<p class="hm-status">
				<?php _e('Plugin status:', self::$PLUGIN_SLUG) ?>
				<?php if ($this->get_option('active')): ?>
					<span class="hm-active"><?php _e('Active', self::$PLUGIN_SLUG) ?></span>
				<?php else: ?>
					<span class="hm-inactive"><?php _e('Inactive', self::$PLUGIN_SLUG) ?></span>
				<?php endif; ?>
			</p>
			<?php if (!$this->get_option('active')): ?>
				<?php $action = self::$ACTION_PREFIX.'check'; ?>
				<?php 
				$since_last_check = (time() - $this->get_option('active_last_check')) / 60;
				if ($since_last_check < 1) {
					$check_text = __('few seconds', self::$PLUGIN_SLUG);
				} elseif ($since_last_check < 60) {
					$check_text = __('few minutes', self::$PLUGIN_SLUG);
				} else {
					$check_text = __('some hours', self::$PLUGIN_SLUG);
				}
				?>
				<hr>
				<h3><?php _e('Getting heatmap for your site', self::$PLUGIN_SLUG) ?></h3>
				<ol>
					<li>
						<?php printf(__('Create your account on %s.', self::$PLUGIN_SLUG), '<a href="https://www.simpleheatmaps.com/" target="_blank">https://www.simpleheatmaps.com</a>'); ?>
						<em><strong><?php _e('Free trial available', self::$PLUGIN_SLUG) ?></strong></em>
						<br>
					</li>
					<li>
						<?php printf(__('Come back here and click the "%s" button below', self::$PLUGIN_SLUG), __('Check now', self::$PLUGIN_SLUG)) ?>
						<br>
					</li>
				</ol>
				<p style="text-align:center;">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e('Check now', self::$PLUGIN_SLUG) ?>" /><br>
					<small><?php printf(__('Last check: %s ago', self::$PLUGIN_SLUG), $check_text); ?></small>
				</p>
			<?php else: ?>
				<hr>
				<?php $action = self::$ACTION_PREFIX.'save'; ?>
				<h3><?php _e('How to use SimpleHeatmaps', self::$PLUGIN_SLUG) ?></h3>
				<p>
					<?php _e('Your site is configured and analyzing how visitors are interacting with it! As people visit your website SimpleHeatmaps will generate different types of heatmaps for you to view. To view & organize your heatmaps go to', self::$PLUGIN_SLUG) ?>
					<a href="https://www.simpleheatmaps.com">simpleheatmaps.com</a> and log in to your account.
					<br/>
					<br/>
					<a href="https://www.simpleheatmaps.com/sites">View My Heatmaps</a>
				</p>
			<?php endif; ?>
			<input type="hidden" name="action" value="<?php echo $action; ?>">
			<?php wp_nonce_field($action); ?>
		</form>	
		<?php if($this->get_option('active')): ?>
		<hr>
		<div>
			<h3><?php _e('Like this plugin?', self::$PLUGIN_SLUG); ?></h3>
			<ul class="ul-square">
				<li><a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/simpleheatmaps?rate=5#postform"><?php _e('Leave a review on WordPress.org', self::$PLUGIN_SLUG); ?></a></li>
				<li><?php _e('Recommend the plugin to your friends:', self::$PLUGIN_SLUG); ?><iframe src="//www.facebook.com/plugins/like.php?href=http%3A%2F%2Fwordpress.org%2Fplugins%2Fsimpleheatmaps&amp;width&amp;layout=standard&amp;action=recommend&amp;show_faces=false&amp;share=true&amp;height=35&amp;appId=259460820829840" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:450px; height:20px; margin:0 0 -3px 8px;" allowTransparency="true"></iframe></li>
				<li><a target="_blank" href="http://wordpress.org/plugins/simpleheatmaps/"><?php _e('Vote "works" on the plugin page', self::$PLUGIN_SLUG); ?></a></li>
				<li><?php _e('You can also promote heatmap on Facebook:', self::$PLUGIN_SLUG); ?><iframe src="//www.facebook.com/plugins/like.php?href=https%3A%2F%2Fwww.facebook.com%simpleheatmaps.com&amp;width&amp;layout=standard&amp;action=recommend&amp;show_faces=false&amp;share=true&amp;height=35&amp;appId=259460820829840" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:450px; height:20px; margin:0 0 -3px 8px;" allowTransparency="true"></iframe></li>
			</ul>
		</div>
		<?php endif; ?>
	</div>
	<div class="hm-sidebar">
		<h4><?php _e('Keep up to date by liking us on Facebook', self::$PLUGIN_SLUG) ?></h4>
		<iframe src="//www.facebook.com/plugins/likebox.php?href=https%3A%2F%2Fwww.facebook.com%2Fsimpleheatmaps&amp;height=558&amp;colorscheme=light&amp;show_faces=true&amp;header=false&amp;stream=true&amp;show_border=false&amp;appId=259460820829840" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:100%; height:558px;" allowTransparency="true"></iframe>
	</div>
</div>
	<?php
	}
}

simpleheatmapsWP::init();

