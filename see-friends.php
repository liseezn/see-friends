<?php
/*
Plugin Name: See~Friends 友情链接管理
Plugin URI: https://github.com/liseezn/see-friends
Description: 一款专为WordPress打造的智能化全流程友情链接管理插件，基于原生链接体系深度扩展，支持前端申请、后台审核、智能反链检测、定时监控、邮件通知、用户自助修改、RSS订阅抓取、友链文章聚合、数据导入导出一站式管理
Version: 3.5.0
Author: liseezn
Author URI: https://liseezn.top
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: see-friends
Domain Path: /languages
Requires at least: 5.6
Requires PHP: 7.0
Update URI: https://api.github.com/repos/liseezn/see-friends/releases/latest
*/

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}
// 统计安装量API地址
define('FABB_STATS_API_URL', 'https://stats.see-friends.liseezn.top');
// ====================== 自动更新功能 ======================
class FABB_Plugin_Auto_Updater {
    // 配置项
    private $plugin_basename;
    private $cache_key = 'fabb_plugin_update_info';
    public function __construct() {
        $this->plugin_basename = plugin_basename(__FILE__);
        
        // 注册更新检查钩子
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        
        // 注册插件信息显示钩子
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        
        // 更新完成后执行清理操作
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);
    }
    /**
     * 检查插件更新
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        $remote_info = $this->get_remote_version_info();
        if (!$remote_info) {
            return $transient;
        }
        $current_version = $transient->checked[$this->plugin_basename];
        if (version_compare($current_version, $remote_info->version, '<')) {
            // 验证文件SHA256（如果开启）
            $verify_sha256 = fabb_get_setting('update_verify_sha256', 'on');
            if ($verify_sha256 === 'on' && !empty($remote_info->sha256)) {
                // 这里可以添加SHA256验证逻辑
                // 由于WordPress更新机制限制，实际验证在下载后进行
            }
            
            $transient->response[$this->plugin_basename] = (object) [
                'slug' => dirname($this->plugin_basename),
                'plugin' => $this->plugin_basename,
                'new_version' => $remote_info->version,
                'package' => $remote_info->download_url,
                'url' => $remote_info->homepage,
                'tested' => $remote_info->tested_up_to,
                'requires' => $remote_info->requires_wp,
                'requires_php' => $remote_info->requires_php,
                'author' => '<a href="'.$remote_info->author_url.'">'.$remote_info->author.'</a>'
            ];
        }
        return $transient;
    }
    /**
     * 获取插件详细信息（用于更新弹窗）
     */
    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->plugin_basename)) {
            return $false;
        }
        $remote_info = $this->get_remote_version_info();
        if (!$remote_info) {
            return $false;
        }
        return (object) [
            'name' => $remote_info->name,
            'slug' => dirname($this->plugin_basename),
            'version' => $remote_info->version,
            'author' => '<a href="'.$remote_info->author_url.'">'.$remote_info->author.'</a>',
            'author_profile' => $remote_info->author_url,
            'homepage' => $remote_info->homepage,
            'download_link' => $remote_info->download_url,
            'tested' => $remote_info->tested_up_to,
            'requires' => $remote_info->requires_wp,
            'requires_php' => $remote_info->requires_php,
            'sections' => [
                'description' => $remote_info->description,
                'changelog' => $remote_info->changelog,
                'installation' => $remote_info->installation
            ],
            'banners' => [
                'low' => $remote_info->banner_low,
                'high' => $remote_info->banner_high
            ]
        ];
    }
    /**
     * 获取远程版本信息（带12小时缓存）
     */
    private function get_remote_version_info() {
        $cached_info = get_transient($this->cache_key);
        if ($cached_info !== false) {
            return $cached_info;
        }
        
        // 根据设置选择更新源
        $update_source = fabb_get_setting('update_source', 'github');
        if ($update_source === 'cloudflare') {
            $version_info_url = 'https://cdn.see-friends.liseezn.top/info.json';
        } else {
            $version_info_url = 'https://raw.githubusercontent.com/liseezn/see-friends/main/info.json';
        }
        
        $response = wp_remote_get($version_info_url, [
            'timeout' => 10,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        $remote_info = json_decode(wp_remote_retrieve_body($response));
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        set_transient($this->cache_key, $remote_info, 12 * HOUR_IN_SECONDS);
        return $remote_info;
    }
    /**
     * 更新完成后执行清理操作
     */
    public function after_update($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && in_array($this->plugin_basename, $options['plugins'])) {
                // 清除所有相关缓存
                delete_transient($this->cache_key);
                wp_cache_delete('fabb_settings', 'options');
                wp_cache_delete('alloptions', 'options');
                
                // 重新初始化默认配置（确保新增配置项生效）
                fabb_plugin_init_default_settings();
                
                // 清理旧版本文件（如果存在）
                $this->cleanup_old_versions();
            }
        }
    }
    
    /**
     * 清理旧版本插件文件
     */
    private function cleanup_old_versions() {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($this->plugin_basename);
        $old_files = [
            'old-see-friends.php',
            'deprecated-functions.php',
            'legacy-backend.php'
        ];
        
        foreach ($old_files as $file) {
            $file_path = $plugin_dir . '/' . $file;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
    }
}
// 初始化自动更新器
new FABB_Plugin_Auto_Updater();
// ====================== 0. 常量定义 ======================
define('FABB_VERSION', '3.5.0');
define('FABB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FABB_PLUGIN_URL', plugin_dir_url(__FILE__));

define('FABB_BACKLINK_CHECK_DELAY', 300000);
define('FABB_BACKLINK_RETRY_DELAY', 2);
define('FABB_AUTO_CHECK_DELAY', 200000);
define('FABB_CANDIDATE_LINKS_LIMIT', 10);
define('FABB_REQUEST_TIMEOUT', 15);
define('FABB_REQUEST_RETRIES', 2);
define('FABB_CACHE_TTL', 300);
define('FABB_BACKLINK_CACHE_DEFAULT_HOURS', 6);
define('FABB_VERIFY_CODE_LENGTH', 6);
define('FABB_VERIFY_CODE_EXPIRY', 300);
define('FABB_RATE_LIMIT_SECONDS', 60);

// ====================== 0. 插件初始化与配置 ======================
// 获取配置项（带静态缓存优化）
function fabb_get_setting($key, $default = '') {
    static $settings = null;
    static $cached_time = 0;
    
    if ($settings === null || (time() - $cached_time) > FABB_CACHE_TTL) {
        $settings = get_option('fabb_settings', array());
        $cached_time = time();
    }
    
    return isset($settings[$key]) ? $settings[$key] : $default;
}

// 清除设置缓存（保存设置后调用）
function fabb_clear_settings_cache() {
    wp_cache_delete('fabb_settings', 'options');
    wp_cache_delete('alloptions', 'options');
}
// 插件激活时初始化默认配置
function fabb_plugin_init_default_settings() {
    $default_settings = array(
        // 基础设置
        'expire_days' => 30,
        'auto_clean_expired' => 'on',
        // 更新设置
        'update_source' => 'github',
        'update_verify_sha256' => 'on',
        // 卸载设置
        'uninstall_delete_data' => 'off',
        // 自定义CSS
        'custom_css' => '',
        // 反链检测设置
        'auto_check_backlink' => 'on',
        'check_frequency' => 'daily',
        'alert_email' => get_option('admin_email'),
        'alert_duplicate_days' => 7,
        'backlink_keywords' => '友情链接,友链,友人帐,合作伙伴,推荐网站,友情,友站,友邻,小伙伴,站点推荐,博客邻居,友情互链,交换链接,friend,friends,friendly,link,links,flink,blogroll,partner,partners,exchange,site,sites,follow,following,community',
        'auto_check_common_paths' => 'on',
        'check_image_links' => 'on',
        'auto_approve_enable' => 'off',
        'auto_approve_mode' => 'days', // days/hours
        'auto_approve_value' => 7,
        // 邮件通知设置
        'email_approved_notice' => 'on',
        'email_rejected_notice' => 'on',
        'email_admin_notice' => 'on',
        'email_modified_notice' => 'on',
        'modify_email_verify' => 'on',
        // 邮件模板设置
        'email_template_approved' => '<p>您好，您在 <strong>{site_name}</strong> 提交的友情链接申请已通过审核，链接已正式上线。</p><p>网站名称：{link_name}<br>网站链接：{link_url}</p>',
        'email_template_rejected' => '<p>您好，您在 <strong>{site_name}</strong> 提交的友情链接申请未通过审核，如有疑问可联系站长。</p>',
        'email_template_admin_new' => '<p>您好，收到新的友情链接申请：</p><p>网站名称：{link_name}<br>网站链接：{link_url}<br>联系邮箱：{contact_email}<br>网站介绍：{link_desc}</p><p><a href="{admin_url}">点击进入后台审核</a></p>',
        'email_template_admin_modified' => '<p>您好，有用户申请修改友情链接信息：</p><p>原网站名称：{old_name}<br>原网站链接：{old_url}<br>申请人邮箱：{contact_email}</p><p>修改内容：<br>{modify_content}</p><p><a href="{admin_url}">点击进入后台审核</a></p>',
        'email_template_backlink_alert' => '<p>您好，检测到以下友链反链失效：</p><p>网站名称：{link_name}<br>网站链接：{link_url}<br>检测时间：{check_time}</p>',
        'email_template_auto_approved' => '<p>您好，您在 <strong>{site_name}</strong> 提交的友情链接申请已通过自动审核，链接已正式上线。</p>',
        'email_template_verify_code' => '<p>您好，您正在申请修改 <strong>{site_name}</strong> 的友情链接信息。</p><p>您的验证码是：<strong>{verify_code}</strong></p><p>验证码5分钟内有效，请勿泄露给他人。</p>',
        // RSS设置
        'rss_auto_update' => 'on',
        'rss_update_frequency' => 'daily',
        'rss_post_count' => 5,
        // 前端设置
        'apply_form_enable' => 'on',
        'modify_form_enable' => 'on',
        'default_show_image' => 'on',
        'default_show_desc' => 'on',
        'default_image_size' => 64,
        'default_sort_type' => 'random',
        'default_show_num' => 20,
        'open_new_window' => 'on',
        'default_image_placeholder' => 'https://via.placeholder.com/64',
        'image_size_min' => 16,
        'image_size_max' => 128,
        'show_rss_feed' => 'on',
        'desc_multi_line' => 'on',
        // 统计设置
        'anonymous_stats' => 'on',
        // 白名单设置
        'backlink_whitelist' => '',
    );
    
    // 合并配置，补全所有缺失字段
    $existing_settings = get_option('fabb_settings', array());
    $merged_settings = wp_parse_args($existing_settings, $default_settings);
    
    // 无论是否为空都更新，确保新增配置项生效
    update_option('fabb_settings', $merged_settings);
}
// ====================== 1. 注册自定义文章类型 ======================
add_action('init', 'fabb_register_apply_post_type');
function fabb_register_apply_post_type() {
    register_post_type('link_apply', array(
        'labels' => array(
            'name' => '链接申请',
            'singular_name' => '链接申请',
            'add_new' => '添加申请',
            'add_new_item' => '添加新申请',
            'edit_item' => '编辑申请',
            'view_item' => '查看申请',
            'search_items' => '搜索申请',
            'not_found' => '没有找到申请',
            'not_found_in_trash' => '回收站中没有找到申请',
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'link-manager.php',
        'menu_position' => 1, // 移到全部链接上面
        'supports' => array('title', 'editor'),
        'capability_type' => 'post',
        'capabilities' => array(
            'create_posts' => 'manage_links',
            'edit_post' => 'manage_links',
            'edit_posts' => 'manage_links',
            'delete_post' => 'manage_links',
            'delete_posts' => 'manage_links',
            'publish_posts' => 'manage_links',
            'read_post' => 'manage_links',
            'read_private_posts' => 'manage_links',
        ),
        'map_meta_cap' => true,
    ));
}
// ====================== 2. 后台菜单与配置页面 ======================
add_action('admin_menu', 'fabb_add_admin_menu');
function fabb_add_admin_menu() {
    add_menu_page(
        '友链管理',
        '友链管理',
        'manage_links',
        'fabb-main',
        '',
        'dashicons-admin-links',
        30
    );
    
    add_submenu_page(
        'fabb-main',
        '友链设置',
        '友链设置',
        'manage_links',
        'fabb-settings',
        'fabb_render_settings_page'
    );
    
    add_submenu_page(
        'fabb-main',
        '链接申请管理',
        '链接申请',
        'manage_links',
        'edit.php?post_type=link_apply',
        ''
    );
    
    add_submenu_page(
        'fabb-main',
        '链接分类管理',
        '链接分类',
        'manage_links',
        'edit-tags.php?taxonomy=link_category',
        ''
    );
    
    add_submenu_page(
        'fabb-main',
        '所有链接',
        '所有链接',
        'manage_links',
        'link-manager.php',
        ''
    );
}
// ====================== 3. 后台配置页面 ======================
function fabb_render_settings_page() {
    if (!current_user_can('manage_links')) {
        wp_die('您没有权限访问此页面');
    }
    
    // 版本兼容性检查
    $current_version = get_file_data(__FILE__, array('Version' => 'Version'))['Version'];
    $old_version_notice = '';
    if (version_compare($current_version, '3.4.0', '<')) {
        $old_version_notice = '<div class="notice notice-warning is-dismissible"><p><strong>重要提示：</strong>您正在使用旧版本插件，可能存在设置保存失败的问题。请先删除当前插件，然后从 GitHub 下载安装最新版本。您的所有友链数据会保留在 WordPress 原生链接管理器中。</p></div>';
    }
    
    // 保存配置
    if (isset($_POST['fabb_settings_save']) && wp_verify_nonce($_POST['fabb_settings_nonce'], 'fabb_save_settings')) {
        $old_settings = get_option('fabb_settings', array());
        $new_settings = $old_settings;
        
        // 基础设置
        $new_settings['expire_days'] = absint($_POST['expire_days']);
        $new_settings['auto_clean_expired'] = isset($_POST['auto_clean_expired']) ? 'on' : 'off';
        $new_settings['anonymous_stats'] = isset($_POST['anonymous_stats']) ? 'on' : 'off';
        
        // 更新设置
        $new_settings['update_source'] = sanitize_text_field($_POST['update_source']);
        $new_settings['update_verify_sha256'] = isset($_POST['update_verify_sha256']) ? 'on' : 'off';
        
        // 卸载设置
        $new_settings['uninstall_delete_data'] = isset($_POST['uninstall_delete_data']) ? 'on' : 'off';
        
        // 自定义CSS
        $new_settings['custom_css'] = wp_strip_all_tags($_POST['custom_css']);
        
        // 反链检测设置
        $new_settings['auto_check_backlink'] = isset($_POST['auto_check_backlink']) ? 'on' : 'off';
        $new_settings['check_frequency'] = sanitize_text_field($_POST['check_frequency']);
        $new_settings['alert_email'] = sanitize_email($_POST['alert_email']);
        $new_settings['alert_duplicate_days'] = absint($_POST['alert_duplicate_days']);
        $new_settings['backlink_keywords'] = sanitize_text_field($_POST['backlink_keywords']);
        $new_settings['auto_check_common_paths'] = isset($_POST['auto_check_common_paths']) ? 'on' : 'off';
        $new_settings['check_image_links'] = isset($_POST['check_image_links']) ? 'on' : 'off';
        $new_settings['auto_approve_enable'] = isset($_POST['auto_approve_enable']) ? 'on' : 'off';
        $new_settings['auto_approve_mode'] = sanitize_text_field($_POST['auto_approve_mode']);
        $new_settings['auto_approve_value'] = absint($_POST['auto_approve_value']);
        $new_settings['backlink_whitelist'] = sanitize_textarea_field($_POST['backlink_whitelist']);
        
        // 邮件通知设置
        $new_settings['email_approved_notice'] = isset($_POST['email_approved_notice']) ? 'on' : 'off';
        $new_settings['email_rejected_notice'] = isset($_POST['email_rejected_notice']) ? 'on' : 'off';
        $new_settings['email_admin_notice'] = isset($_POST['email_admin_notice']) ? 'on' : 'off';
        $new_settings['email_modified_notice'] = isset($_POST['email_modified_notice']) ? 'on' : 'off';
        $new_settings['modify_email_verify'] = isset($_POST['modify_email_verify']) ? 'on' : 'off';
        
        // 邮件模板设置
        $new_settings['email_template_approved'] = wp_kses_post($_POST['email_template_approved']);
        $new_settings['email_template_rejected'] = wp_kses_post($_POST['email_template_rejected']);
        $new_settings['email_template_admin_new'] = wp_kses_post($_POST['email_template_admin_new']);
        $new_settings['email_template_admin_modified'] = wp_kses_post($_POST['email_template_admin_modified']);
        $new_settings['email_template_backlink_alert'] = wp_kses_post($_POST['email_template_backlink_alert']);
        $new_settings['email_template_auto_approved'] = wp_kses_post($_POST['email_template_auto_approved']);
        $new_settings['email_template_verify_code'] = wp_kses_post($_POST['email_template_verify_code']);
        
        // RSS设置
        $new_settings['rss_auto_update'] = isset($_POST['rss_auto_update']) ? 'on' : 'off';
        $new_settings['rss_update_frequency'] = sanitize_text_field($_POST['rss_update_frequency']);
        $new_settings['rss_post_count'] = absint($_POST['rss_post_count']);
        
        // 前端显示设置
        $new_settings['apply_form_enable'] = isset($_POST['apply_form_enable']) ? 'on' : 'off';
        $new_settings['modify_form_enable'] = isset($_POST['modify_form_enable']) ? 'on' : 'off';
        $new_settings['default_show_image'] = isset($_POST['default_show_image']) ? 'on' : 'off';
        $new_settings['default_show_desc'] = isset($_POST['default_show_desc']) ? 'on' : 'off';
        $new_settings['default_image_size'] = absint($_POST['default_image_size']);
        $new_settings['default_sort_type'] = sanitize_text_field($_POST['default_sort_type']);
        $new_settings['default_show_num'] = absint($_POST['default_show_num']);
        $new_settings['open_new_window'] = isset($_POST['open_new_window']) ? 'on' : 'off';
        $new_settings['default_image_placeholder'] = esc_url_raw($_POST['default_image_placeholder']);
        $new_settings['image_size_min'] = absint($_POST['image_size_min']);
        $new_settings['image_size_max'] = absint($_POST['image_size_max']);
        $new_settings['show_rss_feed'] = isset($_POST['show_rss_feed']) ? 'on' : 'off';
        $new_settings['desc_multi_line'] = isset($_POST['desc_multi_line']) ? 'on' : 'off';
        
        // 清除缓存
        wp_cache_delete('fabb_settings', 'options');
        wp_cache_delete('alloptions', 'options');
        $save_result = update_option('fabb_settings', $new_settings);
        
        // 重新调度定时任务
        wp_clear_scheduled_hook('fabb_auto_check_backlink_hook');
        if ($new_settings['auto_check_backlink'] === 'on') {
            wp_schedule_event(time(), $new_settings['check_frequency'], 'fabb_auto_check_backlink_hook');
        }
        
        wp_clear_scheduled_hook('fabb_rss_auto_update_hook');
        if ($new_settings['rss_auto_update'] === 'on') {
            wp_schedule_event(time(), $new_settings['rss_update_frequency'], 'fabb_rss_auto_update_hook');
        }
        
        wp_clear_scheduled_hook('fabb_daily_stats_hook');
        if ($new_settings['anonymous_stats'] === 'on') {
            wp_schedule_event(time(), 'daily', 'fabb_daily_stats_hook');
        }
        
        if ($save_result) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ 设置保存成功！</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>⚠️ 设置未发生变化或保存失败</p></div>';
        }
    }
    
    // 手动批量检测
    if (isset($_POST['fabb_batch_check']) && wp_verify_nonce($_POST['fabb_batch_nonce'], 'fabb_batch_check_action')) {
        $check_result = fabb_batch_check_all_backlinks();
        if (is_wp_error($check_result)) {
            echo '<div class="notice notice-error is-dismissible"><p>检测失败：' . $check_result->get_error_message() . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>批量检测完成！共检测 ' . $check_result['total'] . ' 个友链，正常 ' . ($check_result['total'] - $check_result['invalid']) . ' 个，失效 ' . $check_result['invalid'] . ' 个</p></div>';
        }
    }
    
    // 原生链接同步
    if (isset($_POST['fabb_sync_links']) && wp_verify_nonce($_POST['fabb_sync_nonce'], 'fabb_sync_links_action')) {
        $sync_result = fabb_sync_bookmarks_to_apply();
        echo '<div class="notice notice-success is-dismissible"><p>同步完成！共同步 ' . $sync_result['total'] . ' 个链接，新增 ' . $sync_result['added'] . ' 个，已存在 ' . $sync_result['exists'] . ' 个</p></div>';
    }
    
    // 导出CSV
    if (isset($_POST['fabb_export_csv']) && wp_verify_nonce($_POST['fabb_export_nonce'], 'fabb_export_action')) {
        fabb_export_bookmarks_to_csv();
        exit;
    }
    
    // 导入CSV
    if (isset($_POST['fabb_import_csv']) && wp_verify_nonce($_POST['fabb_import_nonce'], 'fabb_import_action')) {
        $import_result = fabb_import_bookmarks_from_csv();
        if (is_wp_error($import_result)) {
            echo '<div class="notice notice-error is-dismissible"><p>导入失败：' . $import_result->get_error_message() . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>导入完成！共导入 ' . $import_result['total'] . ' 个链接，成功 ' . $import_result['success'] . ' 个，失败 ' . $import_result['failed'] . ' 个</p></div>';
        }
    }
    
    // 获取配置并补全默认值
    $settings = get_option('fabb_settings');
    $settings = wp_parse_args($settings, array(
        'expire_days' => 30,
        'auto_clean_expired' => 'on',
        'update_source' => 'github',
        'update_verify_sha256' => 'on',
        'uninstall_delete_data' => 'off',
        'custom_css' => '',
        'auto_check_backlink' => 'on',
        'check_frequency' => 'daily',
        'alert_email' => get_option('admin_email'),
        'alert_duplicate_days' => 7,
        'backlink_keywords' => '友情链接,友链,友人帐,合作伙伴,推荐网站,友情,友站,友邻,小伙伴,站点推荐,博客邻居,友情互链,交换链接,friend,friends,friendly,link,links,flink,blogroll,partner,partners,exchange,site,sites,follow,following,community',
        'auto_check_common_paths' => 'on',
        'check_image_links' => 'on',
        'auto_approve_enable' => 'off',
        'auto_approve_mode' => 'days',
        'auto_approve_value' => 7,
        'backlink_whitelist' => '',
        'email_approved_notice' => 'on',
        'email_rejected_notice' => 'on',
        'email_admin_notice' => 'on',
        'email_modified_notice' => 'on',
        'modify_email_verify' => 'on',
        'email_template_approved' => '<p>您好，您在 <strong>{site_name}</strong> 提交的友情链接申请已通过审核，链接已正式上线。</p><p>网站名称：{link_name}<br>网站链接：{link_url}</p>',
        'email_template_rejected' => '<p>您好，您在 <strong>{site_name}</strong> 提交的友情链接申请未通过审核，如有疑问可联系站长。</p>',
        'email_template_admin_new' => '<p>您好，收到新的友情链接申请：</p><p>网站名称：{link_name}<br>网站链接：{link_url}<br>联系邮箱：{contact_email}<br>网站介绍：{link_desc}</p><p><a href="{admin_url}">点击进入后台审核</a></p>',
        'email_template_admin_modified' => '<p>您好，有用户申请修改友情链接信息：</p><p>原网站名称：{old_name}<br>原网站链接：{old_url}<br>申请人邮箱：{contact_email}</p><p>修改内容：<br>{modify_content}</p><p><a href="{admin_url}">点击进入后台审核</a></p>',
        'email_template_backlink_alert' => '<p>您好，检测到以下友链反链失效：</p><p>网站名称：{link_name}<br>网站链接：{link_url}<br>检测时间：{check_time}</p>',
        'email_template_auto_approved' => '<p>您好，您在 <strong>{site_name}</strong> 提交的友情链接申请已通过自动审核，链接已正式上线。</p>',
        'email_template_verify_code' => '<p>您好，您正在申请修改 <strong>{site_name}</strong> 的友情链接信息。</p><p>您的验证码是：<strong>{verify_code}</strong></p><p>验证码5分钟内有效，请勿泄露给他人。</p>',
        'rss_auto_update' => 'on',
        'rss_update_frequency' => 'daily',
        'rss_post_count' => 5,
        'apply_form_enable' => 'on',
        'modify_form_enable' => 'on',
        'default_show_image' => 'on',
        'default_show_desc' => 'on',
        'default_image_size' => 64,
        'default_sort_type' => 'random',
        'default_show_num' => 20,
        'open_new_window' => 'on',
        'default_image_placeholder' => 'https://via.placeholder.com/64',
        'image_size_min' => 16,
        'image_size_max' => 128,
        'show_rss_feed' => 'on',
        'desc_multi_line' => 'on',
        'anonymous_stats' => 'on',
    ));
    
    // 统计信息
    $total_links = wp_count_terms('link_category', array('hide_empty' => false));
    $total_bookmarks = get_bookmarks(array('hide_invisible' => 0, 'limit' => -1, 'fields' => 'ids'));
    $total_applications = wp_count_posts('link_apply');
    $plugin_version = get_file_data(__FILE__, array('Version' => 'Version'))['Version'];
    ?>
    <div class="wrap">
        <h1>See~Friends 友链插件设置</h1>
        <?php echo $old_version_notice; ?>
        <hr>
        <!-- 统计卡片 -->
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:20px;margin:20px 0;">
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px 0;font-size:14px;color:#666;">总友链数</h3>
                <p style="margin:0;font-size:32px;font-weight:bold;color:#4ecdc4;"><?php echo count($total_bookmarks); ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px 0;font-size:14px;color:#666;">待审核申请</h3>
                <p style="margin:0;font-size:32px;font-weight:bold;color:#ffb900;"><?php echo $total_applications->pending; ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px 0;font-size:14px;color:#666;">已通过申请</h3>
                <p style="margin:0;font-size:32px;font-weight:bold;color:#00b42a;"><?php echo $total_applications->publish; ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px 0;font-size:14px;color:#666;">友链分类数</h3>
                <p style="margin:0;font-size:32px;font-weight:bold;color:#777bb4;"><?php echo $total_links; ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px 0;font-size:14px;color:#666;">全球安装量</h3>
                <p style="margin:0;font-size:32px;font-weight:bold;color:#666;" id="fabb-global-installs">加载中...</p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px 0;font-size:14px;color:#666;">插件版本</h3>
                <p style="margin:0;font-size:32px;font-weight:bold;color:#666;"><?php echo $plugin_version; ?></p>
            </div>
        </div>
        <h2 class="nav-tab-wrapper">
            <a href="#tab-base" class="nav-tab nav-tab-active">基础设置</a>
            <a href="#tab-update" class="nav-tab">更新设置</a>
            <a href="#tab-check" class="nav-tab">反链检测设置</a>
            <a href="#tab-email" class="nav-tab">邮件通知设置</a>
            <a href="#tab-email-template" class="nav-tab">邮件模板设置</a>
            <a href="#tab-rss" class="nav-tab">RSS设置</a>
            <a href="#tab-front" class="nav-tab">前端显示设置</a>
            <a href="#tab-custom-css" class="nav-tab">自定义CSS</a>
            <a href="#tab-data" class="nav-tab">数据管理</a>
        </h2>
        <form method="post" action="" enctype="multipart/form-data">
            <?php wp_nonce_field('fabb_save_settings', 'fabb_settings_nonce'); ?>
            <div id="tab-base" class="tab-content" style="margin-top:20px;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="auto_clean_expired">自动清理过期申请</label></th>
                        <td>
                            <input type="checkbox" name="auto_clean_expired" id="auto_clean_expired" <?php checked($settings['auto_clean_expired'], 'on'); ?>>
                            <label for="auto_clean_expired">开启自动清理待审核/已拒绝的过期申请</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expire_days">申请过期天数</label></th>
                        <td>
                            <input type="number" name="expire_days" id="expire_days" value="<?php echo esc_attr($settings['expire_days']); ?>" min="1" max="365" class="small-text">
                            <span class="description">天，超过此天数的待审核/已拒绝申请将被自动清理</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="anonymous_stats">匿名安装统计</label></th>
                        <td>
                            <input type="checkbox" name="anonymous_stats" id="anonymous_stats" <?php checked($settings['anonymous_stats'], 'on'); ?>>
                            <label for="anonymous_stats">帮助统计插件安装量，发送完全匿名的使用统计（仅统计活跃站点数量，不收集任何个人信息）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="uninstall_delete_data">卸载时删除数据</label></th>
                        <td>
                            <input type="checkbox" name="uninstall_delete_data" id="uninstall_delete_data" <?php checked($settings['uninstall_delete_data'], 'on'); ?>>
                            <label for="uninstall_delete_data">卸载插件时删除所有相关数据（包括申请记录和设置）</label>
                            <p class="description" style="color:#d63638;">⚠️ 警告：此操作不可逆，删除后数据无法恢复</p>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-update" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="update_source">更新源</label></th>
                        <td>
                            <select name="update_source" id="update_source" class="regular-text">
                                <option value="github" <?php selected($settings['update_source'], 'github'); ?>>GitHub (默认)</option>
                                <option value="cloudflare" <?php selected($settings['update_source'], 'cloudflare'); ?>>Cloudflare CDN (国内加速)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="update_verify_sha256">文件完整性验证</label></th>
                        <td>
                            <input type="checkbox" name="update_verify_sha256" id="update_verify_sha256" <?php checked($settings['update_verify_sha256'], 'on'); ?>>
                            <label for="update_verify_sha256">更新时验证文件SHA256哈希值（推荐开启，防止文件被篡改）</label>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-check" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="auto_check_backlink">自动定时反链检测</label></th>
                        <td>
                            <input type="checkbox" name="auto_check_backlink" id="auto_check_backlink" <?php checked($settings['auto_check_backlink'], 'on'); ?>>
                            <label for="auto_check_backlink">开启自动定时检测所有已上线友链的反链状态</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="check_frequency">检测频率</label></th>
                        <td>
                            <select name="check_frequency" id="check_frequency" class="regular-text">
                                <option value="hourly" <?php selected($settings['check_frequency'], 'hourly'); ?>>每小时检测一次</option>
                                <option value="daily" <?php selected($settings['check_frequency'], 'daily'); ?>>每天检测一次</option>
                                <option value="twicedaily" <?php selected($settings['check_frequency'], 'twicedaily'); ?>>每天检测两次</option>
                                <option value="weekly" <?php selected($settings['check_frequency'], 'weekly'); ?>>每周检测一次</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alert_email">掉链提醒邮箱</label></th>
                        <td>
                            <input type="email" name="alert_email" id="alert_email" value="<?php echo esc_attr($settings['alert_email']); ?>" class="regular-text">
                            <span class="description">检测到友链失效时，发送提醒邮件到此邮箱</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alert_duplicate_days">重复提醒间隔</label></th>
                        <td>
                            <input type="number" name="alert_duplicate_days" id="alert_duplicate_days" value="<?php echo esc_attr($settings['alert_duplicate_days']); ?>" min="1" max="30" class="small-text">
                            <span class="description">天，同一失效友链在此间隔内只发送一次提醒</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="backlink_keywords">反链检测关键词</label></th>
                        <td>
                            <textarea name="backlink_keywords" id="backlink_keywords" rows="3" class="large-text"><?php echo esc_textarea($settings['backlink_keywords']); ?></textarea>
                            <p class="description">用于识别友链页面的关键词，多个关键词用英文逗号分隔</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auto_check_common_paths">自动检测常见友链路径</label></th>
                        <td>
                            <input type="checkbox" name="auto_check_common_paths" id="auto_check_common_paths" <?php checked($settings['auto_check_common_paths'], 'on'); ?>>
                            <label for="auto_check_common_paths">自动检测 /friend, /link, /links 等常见友链路径</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="check_image_links">检测图片友链</label></th>
                        <td>
                            <input type="checkbox" name="check_image_links" id="check_image_links" <?php checked($settings['check_image_links'], 'on'); ?>>
                            <label for="check_image_links">检测图片链接中的本站信息（src、alt、title属性）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="backlink_whitelist">反链检测白名单</label></th>
                        <td>
                            <textarea name="backlink_whitelist" id="backlink_whitelist" rows="3" class="large-text"><?php echo esc_textarea($settings['backlink_whitelist']); ?></textarea>
                            <p class="description">对于无法正常检测反链的网站，添加到白名单后将自动视为有反链。每行一个域名（不带http://）</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auto_approve_enable">自动通过新申请</label></th>
                        <td>
                            <input type="checkbox" name="auto_approve_enable" id="auto_approve_enable" <?php checked($settings['auto_approve_enable'], 'on'); ?>>
                            <label for="auto_approve_enable">开启后，反链检测通过的申请将在指定时间后自动上线</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auto_approve_mode">自动通过时间</label></th>
                        <td>
                            <select name="auto_approve_mode" id="auto_approve_mode" class="regular-text">
                                <option value="hours" <?php selected($settings['auto_approve_mode'], 'hours'); ?>>小时</option>
                                <option value="days" <?php selected($settings['auto_approve_mode'], 'days'); ?>>天</option>
                            </select>
                            <input type="number" name="auto_approve_value" id="auto_approve_value" value="<?php echo esc_attr($settings['auto_approve_value']); ?>" min="1" max="90" class="small-text" style="margin-left:10px;">
                            <span class="description">后自动通过（支持1-24小时、1-7天、30天、90天）</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">手动批量检测</th>
                        <td>
                            <?php wp_nonce_field('fabb_batch_check_action', 'fabb_batch_nonce'); ?>
                            <button type="submit" name="fabb_batch_check" class="button button-primary" onclick="return confirm('确定要立即检测所有已上线友链吗？\n\n检测过程可能需要几秒到几十秒，请勿关闭页面')">立即检测所有已上线友链</button>
                            <p class="description">点击后将立即检测所有已通过的友链，无需等待定时任务</p>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-email" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="email_approved_notice">审核通过邮件通知</label></th>
                        <td>
                            <input type="checkbox" name="email_approved_notice" id="email_approved_notice" <?php checked($settings['email_approved_notice'], 'on'); ?>>
                            <label for="email_approved_notice">申请通过后，给申请人发送邮件通知</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_rejected_notice">审核拒绝邮件通知</label></th>
                        <td>
                            <input type="checkbox" name="email_rejected_notice" id="email_rejected_notice" <?php checked($settings['email_rejected_notice'], 'on'); ?>>
                            <label for="email_rejected_notice">申请拒绝后，给申请人发送邮件通知</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_admin_notice">新申请管理员通知</label></th>
                        <td>
                            <input type="checkbox" name="email_admin_notice" id="email_admin_notice" <?php checked($settings['email_admin_notice'], 'on'); ?>>
                            <label for="email_admin_notice">有新的友链申请时，给管理员发送邮件通知</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_modified_notice">友链修改管理员通知</label></th>
                        <td>
                            <input type="checkbox" name="email_modified_notice" id="email_modified_notice" <?php checked($settings['email_modified_notice'], 'on'); ?>>
                            <label for="email_modified_notice">有用户自助修改友链信息时，给管理员发送邮件通知</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="modify_email_verify">修改申请邮件验证</label></th>
                        <td>
                            <input type="checkbox" name="modify_email_verify" id="modify_email_verify" <?php checked($settings['modify_email_verify'], 'on'); ?>>
                            <label for="modify_email_verify">修改申请前需要验证邮箱所有权（推荐开启，防止恶意提交）</label>
                        </td>
                    </tr>
                </table>
            </div>
            <!-- 邮件模板设置选项卡 -->
            <div id="tab-email-template" class="tab-content" style="margin-top:20px;display:none;">
                <p class="description">支持HTML格式，可用变量：{site_name}（本站名称）、{link_name}（友链名称）、{link_url}（友链链接）、{contact_email}（联系邮箱）、{link_desc}（网站介绍）、{admin_url}（后台地址）、{check_time}（检测时间）、{verify_code}（验证码）、{old_name}（原名称）、{old_url}（原链接）、{modify_content}（修改内容）</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="email_template_approved">审核通过邮件模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_approved'], 'email_template_approved', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template_rejected">审核拒绝邮件模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_rejected'], 'email_template_rejected', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template_admin_new">新申请管理员通知模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_admin_new'], 'email_template_admin_new', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template_admin_modified">修改申请管理员通知模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_admin_modified'], 'email_template_admin_modified', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template_backlink_alert">掉链提醒邮件模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_backlink_alert'], 'email_template_backlink_alert', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template_auto_approved">自动通过邮件模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_auto_approved'], 'email_template_auto_approved', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template_verify_code">验证码邮件模板</label></th>
                        <td>
                            <?php wp_editor($settings['email_template_verify_code'], 'email_template_verify_code', array('textarea_rows' => 5, 'media_buttons' => false)); ?>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-rss" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="rss_auto_update">自动更新RSS文章</label></th>
                        <td>
                            <input type="checkbox" name="rss_auto_update" id="rss_auto_update" <?php checked($settings['rss_auto_update'], 'on'); ?>>
                            <label for="rss_auto_update">开启后，系统将自动定时更新所有友链的RSS文章缓存</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rss_update_frequency">RSS更新频率</label></th>
                        <td>
                            <select name="rss_update_frequency" id="rss_update_frequency" class="regular-text">
                                <option value="hourly" <?php selected($settings['rss_update_frequency'], 'hourly'); ?>>每小时更新一次</option>
                                <option value="twicedaily" <?php selected($settings['rss_update_frequency'], 'twicedaily'); ?>>每天更新两次</option>
                                <option value="daily" <?php selected($settings['rss_update_frequency'], 'daily'); ?>>每天更新一次</option>
                                <option value="weekly" <?php selected($settings['rss_update_frequency'], 'weekly'); ?>>每周更新一次</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rss_post_count">默认显示文章数</label></th>
                        <td>
                            <input type="number" name="rss_post_count" id="rss_post_count" value="<?php echo esc_attr($settings['rss_post_count']); ?>" min="1" class="small-text">
                            <span class="description">篇，RSS订阅文章页面默认显示的文章数量（无上限）</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">短代码使用</th>
                        <td>
                            <p>在任意页面插入短代码 <code>[friend_rss_posts]</code> 即可显示所有友链的最新文章</p>
                            <p>可选参数：<code>count="10"</code> 显示文章数量，<code>category="1"</code> 指定友链分类</p>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-front" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="apply_form_enable">前端申请表单</label></th>
                        <td>
                            <input type="checkbox" name="apply_form_enable" id="apply_form_enable" <?php checked($settings['apply_form_enable'], 'on'); ?>>
                            <label for="apply_form_enable">开启前端友链申请表单（短代码 [link_apply_form]）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="modify_form_enable">前端自助修改表单</label></th>
                        <td>
                            <input type="checkbox" name="modify_form_enable" id="modify_form_enable" <?php checked($settings['modify_form_enable'], 'on'); ?>>
                            <label for="modify_form_enable">开启前端友链自助修改表单（短代码 [link_modify_form]）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_sort_type">默认排序方式</label></th>
                        <td>
                            <select name="default_sort_type" id="default_sort_type" class="regular-text">
                                <option value="random" <?php selected($settings['default_sort_type'], 'random'); ?>>随机排序（推荐）</option>
                                <option value="name" <?php selected($settings['default_sort_type'], 'name'); ?>>按网站名称排序</option>
                                <option value="id" <?php selected($settings['default_sort_type'], 'id'); ?>>按链接ID排序</option>
                                <option value="date" <?php selected($settings['default_sort_type'], 'date'); ?>>按添加时间排序</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_show_num">默认显示数量</label></th>
                        <td>
                            <input type="number" name="default_show_num" id="default_show_num" value="<?php echo esc_attr($settings['default_show_num']); ?>" min="0" max="999" class="small-text">
                            <span class="description">友链列表默认显示的数量（填0显示全部）</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="open_new_window">新窗口打开链接</label></th>
                        <td>
                            <input type="checkbox" name="open_new_window" id="open_new_window" <?php checked($settings['open_new_window'], 'on'); ?>>
                            <label for="open_new_window">友链点击后在新窗口打开</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_show_image">默认显示网站图标</label></th>
                        <td>
                            <input type="checkbox" name="default_show_image" id="default_show_image" <?php checked($settings['default_show_image'], 'on'); ?>>
                            <label for="default_show_image">友链列表默认显示网站图标</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_image_placeholder">图标占位符</label></th>
                        <td>
                            <input type="url" name="default_image_placeholder" id="default_image_placeholder" value="<?php echo esc_attr($settings['default_image_placeholder']); ?>" class="regular-text">
                            <span class="description">网站图标为空时显示的默认图片地址</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_image_size">默认图标尺寸</label></th>
                        <td>
                            <input type="number" name="default_image_size" id="default_image_size" value="<?php echo esc_attr($settings['default_image_size']); ?>" min="<?php echo $settings['image_size_min']; ?>" max="<?php echo $settings['image_size_max']; ?>" class="small-text">
                            <span class="description">px，友链列表默认图标尺寸</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">图标尺寸限制</th>
                        <td>
                            <input type="number" name="image_size_min" id="image_size_min" value="<?php echo esc_attr($settings['image_size_min']); ?>" min="16" max="64" class="small-text">
                            <label for="image_size_min"> 最小尺寸 </label>
                            <input type="number" name="image_size_max" id="image_size_max" value="<?php echo esc_attr($settings['image_size_max']); ?>" min="64" max="256" class="small-text" style="margin-left:10px;">
                            <label for="image_size_max"> 最大尺寸 </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_show_desc">默认显示网站描述</label></th>
                        <td>
                            <input type="checkbox" name="default_show_desc" id="default_show_desc" <?php checked($settings['default_show_desc'], 'on'); ?>>
                            <label for="default_show_desc">友链列表默认显示网站描述</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="desc_multi_line">描述自动换行</label></th>
                        <td>
                            <input type="checkbox" name="desc_multi_line" id="desc_multi_line" <?php checked($settings['desc_multi_line'], 'on'); ?>>
                            <label for="desc_multi_line">描述过长时自动换行显示多行（关闭则单行截断）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="show_rss_feed">显示订阅地址</label></th>
                        <td>
                            <input type="checkbox" name="show_rss_feed" id="show_rss_feed" <?php checked($settings['show_rss_feed'], 'on'); ?>>
                            <label for="show_rss_feed">在友链卡片右上角显示网站RSS订阅图标</label>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-custom-css" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="custom_css">自定义CSS</label></th>
                        <td>
                            <textarea name="custom_css" id="custom_css" rows="15" class="large-text" placeholder="在此输入自定义CSS代码"><?php echo esc_textarea($settings['custom_css']); ?></textarea>
                            <p class="description">自定义CSS将在所有前端页面加载，用于调整友链样式</p>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="tab-data" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row">同步原生链接到插件</th>
                        <td>
                            <?php wp_nonce_field('fabb_sync_links_action', 'fabb_sync_nonce'); ?>
                            <button type="submit" name="fabb_sync_links" class="button button-primary" onclick="return confirm('确定要同步原生链接管理器里的所有链接吗？\n\n已存在的链接不会重复创建')">一键同步所有链接</button>
                            <p class="description">将「链接」菜单里的所有友链同步到插件申请列表，状态设为「已通过」</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">导出友链数据</th>
                        <td>
                            <?php wp_nonce_field('fabb_export_action', 'fabb_export_nonce'); ?>
                            <button type="submit" name="fabb_export_csv" class="button button-secondary">导出所有友链为CSV</button>
                            <p class="description">导出所有友链数据，包括名称、链接、图标、描述、RSS地址、联系邮箱、添加时间等</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">导入友链数据</th>
                        <td>
                            <?php wp_nonce_field('fabb_import_action', 'fabb_import_nonce'); ?>
                            <input type="file" name="fabb_csv_file" accept=".csv" style="margin-bottom:10px;">
                            <br>
                            <button type="submit" name="fabb_import_csv" class="button button-secondary" onclick="return confirm('确定要导入CSV文件吗？\n\n重复的链接将被跳过')">导入CSV文件</button>
                            <p class="description">导入CSV格式的友链数据，第一行必须是表头：name,url,image,description,rss,email</p>
                        </td>
                    </tr>
                </table>
            </div>
            <p class="submit">
                <button type="submit" name="fabb_settings_save" class="button button-primary">保存设置</button>
            </p>
        </form>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                tabs.forEach(t => t.classList.remove('nav-tab-active'));
                tabContents.forEach(c => c.style.display = 'none');
                this.classList.add('nav-tab-active');
                document.querySelector(this.getAttribute('href')).style.display = 'block';
            });
        });
        
        const sizeMin = document.getElementById('image_size_min');
        const sizeMax = document.getElementById('image_size_max');
        const defaultSize = document.getElementById('default_image_size');
        
        if (sizeMin && sizeMax && defaultSize) {
            sizeMin.addEventListener('change', function() {
                defaultSize.min = this.value;
                if (parseInt(defaultSize.value) < parseInt(this.value)) defaultSize.value = this.value;
                sizeMax.min = Math.max(parseInt(this.value) + 1, 64);
            });
            
            sizeMax.addEventListener('change', function() {
                defaultSize.max = this.value;
                if (parseInt(defaultSize.value) > parseInt(this.value)) defaultSize.value = this.value;
                sizeMin.max = Math.min(parseInt(this.value) - 1, 64);
            });
        }
        
        // 加载全局安装量
        fetch('<?php echo FABB_STATS_API_URL; ?>/stats')
            .then(response => response.json())
            .then(data => {
                document.getElementById('fabb-global-installs').textContent = data.active.toLocaleString();
            })
            .catch(() => {
                document.getElementById('fabb-global-installs').textContent = '--';
            });
    });
    </script>
    <?php
}
// ====================== 4. CSV导入导出功能 ======================
// 导出友链为CSV
function fabb_export_bookmarks_to_csv() {
    $bookmarks = get_bookmarks(array('hide_invisible' => 0));
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="friends-links-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    // 输出BOM，解决中文乱码
    fwrite($output, "\xEF\xBB\xBF");
    
    // 表头
    fputcsv($output, array('网站名称', '网站链接', '网站图标', '网站描述', 'RSS地址', '联系邮箱', '分类名称', '添加时间'));
    
    foreach ($bookmarks as $bookmark) {
        $categories = wp_get_object_terms($bookmark->link_id, 'link_category');
        $category_name = !empty($categories) ? $categories[0]->name : '未分类';
        
        // 获取额外信息
        $rss = '';
        $email = '';
        $add_time = '';
        $apply_posts = get_posts(array(
            'post_type' => 'link_apply',
            'meta_key' => '_fabb_link_id',
            'meta_value' => $bookmark->link_id,
            'numberposts' => 1,
        ));
        if (!empty($apply_posts)) {
            $rss = get_post_meta($apply_posts[0]->ID, '_fabb_link_rss', true);
            $email = get_post_meta($apply_posts[0]->ID, '_fabb_apply_email', true);
            $add_time = $apply_posts[0]->post_date;
        }
        
        // 转义特殊字符
        $row = array(
            htmlspecialchars_decode($bookmark->link_name, ENT_QUOTES),
            $bookmark->link_url,
            $bookmark->link_image,
            htmlspecialchars_decode($bookmark->link_description, ENT_QUOTES),
            $rss,
            $email,
            $category_name,
            $add_time
        );
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}
// 从CSV导入友链
function fabb_import_bookmarks_from_csv() {
    if (!isset($_FILES['fabb_csv_file']) || $_FILES['fabb_csv_file']['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', '文件上传失败');
    }
    
    $file = $_FILES['fabb_csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) {
        return new WP_Error('file_error', '无法打开文件');
    }
    
    // 跳过表头
    fgetcsv($handle);
    
    $total = 0;
    $success = 0;
    $failed = 0;
    
    while (($row = fgetcsv($handle)) !== false) {
        $total++;
        if (count($row) < 2) {
            $failed++;
            continue;
        }
        
        $name = sanitize_text_field($row[0]);
        $url = sanitize_url($row[1]);
        $image = isset($row[2]) ? sanitize_url($row[2]) : '';
        $description = isset($row[3]) ? sanitize_textarea_field($row[3]) : '';
        $rss = isset($row[4]) ? sanitize_url($row[4]) : '';
        $email = isset($row[5]) ? sanitize_email($row[5]) : '';
        $category_name = isset($row[6]) ? sanitize_text_field($row[6]) : '未分类';
        
        if (empty($name) || empty($url)) {
            $failed++;
            continue;
        }
        
        // 检查是否已存在
        $existing = get_bookmarks(array(
            'search' => $url,
            'search_columns' => array('link_url'),
            'number' => 1,
        ));
        if (!empty($existing)) {
            $failed++;
            continue;
        }
        
        // 获取或创建分类
        $category = get_term_by('name', $category_name, 'link_category');
        if (!$category) {
            $category = wp_insert_term($category_name, 'link_category');
            if (is_wp_error($category)) {
                $category_id = 0;
            } else {
                $category_id = $category['term_id'];
            }
        } else {
            $category_id = $category->term_id;
        }
        
        // 创建链接
        $link_data = array(
            'link_name' => $name,
            'link_url' => $url,
            'link_image' => $image,
            'link_description' => $description,
            'link_target' => '_blank',
            'link_visible' => 'Y',
            'link_category' => array($category_id),
        );
        
        $link_id = wp_insert_link($link_data);
        if (is_wp_error($link_id)) {
            $failed++;
            continue;
        }
        
        // 创建申请记录
        $post_data = array(
            'post_title' => $name,
            'post_content' => $description,
            'post_type' => 'link_apply',
            'post_status' => 'publish',
        );
        
        $post_id = wp_insert_post($post_data);
        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_fabb_link_id', $link_id);
            update_post_meta($post_id, '_fabb_link_url', $url);
            update_post_meta($post_id, '_fabb_link_image', $image);
            update_post_meta($post_id, '_fabb_link_rss', $rss);
            update_post_meta($post_id, '_fabb_apply_status', 'approved');
            update_post_meta($post_id, '_fabb_apply_email', $email);
        }
        
        $success++;
    }
    
    fclose($handle);
    return array('total' => $total, 'success' => $success, 'failed' => $failed);
}
// ====================== 5. RSS自动更新与文章订阅 ======================
// 自动更新RSS文章缓存
function fabb_rss_auto_update() {
    if (!class_exists('SimplePie')) {
        require_once ABSPATH . WPINC . '/class-simplepie.php';
    }
    
    $bookmarks = get_bookmarks(array('hide_invisible' => 1));
    
    foreach ($bookmarks as $bookmark) {
        $apply_posts = get_posts(array(
            'post_type' => 'link_apply',
            'meta_key' => '_fabb_link_id',
            'meta_value' => $bookmark->link_id,
            'numberposts' => 1,
        ));
        
        if (empty($apply_posts)) continue;
        
        $rss_url = get_post_meta($apply_posts[0]->ID, '_fabb_link_rss', true);
        if (empty($rss_url)) continue;
        
        // 验证RSS源有效性
        if (!wp_http_validate_url($rss_url)) {
            update_post_meta($apply_posts[0]->ID, '_fabb_rss_error', '无效的RSS地址');
            continue;
        }
        
        // 获取RSS内容，增加超时和重试
        $rss = fetch_feed($rss_url);
        if (is_wp_error($rss)) {
            update_post_meta($apply_posts[0]->ID, '_fabb_rss_error', $rss->get_error_message());
            continue;
        }
        
        $max_items = fabb_get_setting('rss_post_count', 5);
        $items = $rss->get_items(0, $max_items);
        
        $posts = array();
        foreach ($items as $item) {
            $posts[] = array(
                'title' => $item->get_title(),
                'link' => $item->get_permalink(),
                'date' => $item->get_date('Y-m-d H:i:s'),
                'description' => wp_trim_words(strip_tags($item->get_description()), 50),
            );
        }
        
        // 保存缓存
        update_post_meta($apply_posts[0]->ID, '_fabb_rss_posts', $posts);
        update_post_meta($apply_posts[0]->ID, '_fabb_rss_update_time', time());
        delete_post_meta($apply_posts[0]->ID, '_fabb_rss_error');
        
        $rss->__destruct();
        unset($rss);
        usleep(300000); // 增加延迟，避免请求过快
    }
}
add_action('fabb_rss_auto_update_hook', 'fabb_rss_auto_update');
// RSS订阅文章短代码
add_shortcode('friend_rss_posts', 'fabb_rss_posts_shortcode');
function fabb_rss_posts_shortcode($atts) {
    $atts = shortcode_atts(array(
        'count' => fabb_get_setting('rss_post_count', 5),
        'category' => '',
    ), $atts, 'friend_rss_posts');
    
    $bookmark_args = array(
        'hide_invisible' => 1,
        'category' => $atts['category'],
    );
    $bookmarks = get_bookmarks($bookmark_args);
    
    if (empty($bookmarks)) {
        return '<p class="fabb-rss-empty">暂无订阅文章</p>';
    }
    
    $all_posts = array();
    foreach ($bookmarks as $bookmark) {
        $apply_posts = get_posts(array(
            'post_type' => 'link_apply',
            'meta_key' => '_fabb_link_id',
            'meta_value' => $bookmark->link_id,
            'numberposts' => 1,
        ));
        
        if (empty($apply_posts)) continue;
        
        $posts = get_post_meta($apply_posts[0]->ID, '_fabb_rss_posts', true);
        if (empty($posts)) continue;
        
        foreach ($posts as $post) {
            $post['site_name'] = $bookmark->link_name;
            $post['site_url'] = $bookmark->link_url;
            $all_posts[] = $post;
        }
    }
    
    // 按时间排序
    usort($all_posts, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    // 截取指定数量
    $all_posts = array_slice($all_posts, 0, $atts['count']);
    
    if (empty($all_posts)) {
        return '<p class="fabb-rss-empty">暂无订阅文章</p>';
    }
    
    $output = '<ul class="fabb-rss-posts-list">';
    foreach ($all_posts as $post) {
        $output .= '<li class="fabb-rss-post-item">';
        $output .= '<a href="' . esc_url($post['link']) . '" target="_blank" rel="noopener noreferrer" class="fabb-rss-post-title">' . esc_html($post['title']) . '</a>';
        $output .= '<div class="fabb-rss-post-meta">';
        $output .= '<span class="fabb-rss-post-site"><a href="' . esc_url($post['site_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($post['site_name']) . '</a></span>';
        $output .= '<span class="fabb-rss-post-date">' . esc_html(date('Y-m-d', strtotime($post['date']))) . '</span>';
        $output .= '</div>';
        if (!empty($post['description'])) {
            $output .= '<p class="fabb-rss-post-desc">' . esc_html($post['description']) . '</p>';
        }
        $output .= '</li>';
    }
    $output .= '</ul>';
    
    // 添加插件标识
    $output .= fabb_get_plugin_footer();
    
    // 添加RSS文章样式
    $output .= '
    <style>
    .fabb-rss-posts-list {
        list-style: none !important;
        padding: 0 !important;
        margin: 30px 0 !important;
    }
    .fabb-rss-post-item {
        padding: 15px 0 !important;
        border-bottom: 1px solid var(--fabb-border-color) !important;
    }
    .fabb-rss-post-title {
        font-size: 1.1em !important;
        font-weight: 600 !important;
        color: var(--fabb-text-color) !important;
        text-decoration: none !important;
        display: block !important;
        margin-bottom: 8px !important;
    }
    .fabb-rss-post-title:hover {
        color: #4ecdc4 !important;
    }
    .fabb-rss-post-meta {
        font-size: 0.9em !important;
        color: var(--fabb-text-color) !important;
        opacity: 0.7 !important;
        margin-bottom: 8px !important;
    }
    .fabb-rss-post-meta span {
        margin-right: 15px !important;
    }
    .fabb-rss-post-meta a {
        color: inherit !important;
        text-decoration: none !important;
    }
    .fabb-rss-post-desc {
        font-size: 0.95em !important;
        color: var(--fabb-text-color) !important;
        opacity: 0.8 !important;
        margin: 0 !important;
        line-height: 1.5 !important;
    }
    .fabb-rss-empty {
        color: var(--fabb-text-color) !important;
        opacity: 0.6 !important;
        padding: 20px 0 !important;
        text-align: center !important;
    }
    </style>
    ';
    
    return $output;
}
// ====================== 6. 申请列表自定义列 ======================
add_filter('manage_link_apply_posts_columns', 'fabb_add_apply_columns');
function fabb_add_apply_columns($columns) {
    $new_columns = array(
        'cb' => $columns['cb'],
        'title' => '网站名称',
        'link_url' => '网站链接',
        'apply_status' => '审核状态',
        'backlink_status' => '反链状态',
        'apply_email' => '联系邮箱',
        'date' => '申请时间',
    );
    return $new_columns;
}
add_action('manage_link_apply_posts_custom_column', 'fabb_render_apply_columns', 10, 2);
function fabb_render_apply_columns($column, $post_id) {
    switch ($column) {
        case 'link_url':
            $url = get_post_meta($post_id, '_fabb_link_url', true);
            echo $url ? '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a>' : '-';
            break;
        case 'apply_status':
            $status = get_post_meta($post_id, '_fabb_apply_status', true) ?: 'pending';
            $status_map = array(
                'pending' => '<span style="color:#ffb900;">待审核</span>',
                'approved' => '<span style="color:#00b42a;">已通过</span>',
                'rejected' => '<span style="color:#d63638;">已拒绝</span>',
            );
            echo isset($status_map[$status]) ? $status_map[$status] : '<span style="color:#999;">未知</span>';
            if (get_post_meta($post_id, '_fabb_modify_pending', true) === 'yes') {
                echo '<br><span style="color:#ff6b35;">有修改待审核</span>';
            }
            break;
        case 'backlink_status':
            $status = get_post_meta($post_id, '_fabb_backlink_status', true);
            $check_time = get_post_meta($post_id, '_fabb_backlink_check_time', true);
            if ($status === 'has') {
                echo '<span style="color:#00b42a;">正常</span>';
            } elseif ($status === 'no') {
                echo '<span style="color:#d63638;">无反链</span>';
            } elseif ($status === 'whitelisted') {
                echo '<span style="color:#777bb4;">白名单</span>';
            } else {
                echo '<span style="color:#999;">未检测</span>';
            }
            if ($check_time) {
                echo '<br><span style="font-size:12px;color:#999;">' . date('Y-m-d H:i', $check_time) . '</span>';
            }
            break;
        case 'apply_email':
            $email = get_post_meta($post_id, '_fabb_apply_email', true);
            echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '-';
            break;
    }
}
// 添加筛选器
add_filter('views_edit-link_apply', 'fabb_add_apply_filters');
function fabb_add_apply_filters($views) {
    global $wpdb;
    
    // 统计各状态数量
    $counts = array(
        'all' => wp_count_posts('link_apply')->publish,
        'pending' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_fabb_apply_status' AND meta_value = 'pending'"),
        'approved' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_fabb_apply_status' AND meta_value = 'approved'"),
        'rejected' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_fabb_apply_status' AND meta_value = 'rejected'"),
        'no_backlink' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_fabb_backlink_status' AND meta_value = 'no'"),
        'whitelisted' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_fabb_backlink_status' AND meta_value = 'whitelisted'"),
    );
    
    // 添加筛选链接
    $new_views = array();
    $new_views['all'] = '<a href="' . admin_url('edit.php?post_type=link_apply') . '"' . (!isset($_GET['backlink_status']) && !isset($_GET['apply_status']) ? ' class="current"' : '') . '>全部 <span class="count">(' . $counts['all'] . ')</span></a>';
    $new_views['pending'] = '<a href="' . admin_url('edit.php?post_type=link_apply&apply_status=pending') . '"' . (isset($_GET['apply_status']) && $_GET['apply_status'] === 'pending' ? ' class="current"' : '') . '>待审核 <span class="count">(' . $counts['pending'] . ')</span></a>';
    $new_views['approved'] = '<a href="' . admin_url('edit.php?post_type=link_apply&apply_status=approved') . '"' . (isset($_GET['apply_status']) && $_GET['apply_status'] === 'approved' ? ' class="current"' : '') . '>已通过 <span class="count">(' . $counts['approved'] . ')</span></a>';
    $new_views['rejected'] = '<a href="' . admin_url('edit.php?post_type=link_apply&apply_status=rejected') . '"' . (isset($_GET['apply_status']) && $_GET['apply_status'] === 'rejected' ? ' class="current"' : '') . '>已拒绝 <span class="count">(' . $counts['rejected'] . ')</span></a>';
    $new_views['no_backlink'] = '<a href="' . admin_url('edit.php?post_type=link_apply&backlink_status=no') . '"' . (isset($_GET['backlink_status']) && $_GET['backlink_status'] === 'no' ? ' class="current"' : '') . '>无反链 <span class="count">(' . $counts['no_backlink'] . ')</span></a>';
    $new_views['whitelisted'] = '<a href="' . admin_url('edit.php?post_type=link_apply&backlink_status=whitelisted') . '"' . (isset($_GET['backlink_status']) && $_GET['backlink_status'] === 'whitelisted' ? ' class="current"' : '') . '>白名单 <span class="count">(' . $counts['whitelisted'] . ')</span></a>';
    
    return $new_views;
}
// 处理筛选查询
add_action('pre_get_posts', 'fabb_handle_apply_filters');
function fabb_handle_apply_filters($query) {
    global $pagenow;
    
    if ($pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'link_apply') {
        return;
    }
    
    if (isset($_GET['apply_status'])) {
        $query->set('meta_key', '_fabb_apply_status');
        $query->set('meta_value', sanitize_text_field($_GET['apply_status']));
    }
    
    if (isset($_GET['backlink_status'])) {
        $query->set('meta_key', '_fabb_backlink_status');
        $query->set('meta_value', sanitize_text_field($_GET['backlink_status']));
    }
}
// ====================== 7. 智能反链检测核心函数 ======================
// 反链检测结果缓存获取
function fabb_get_backlink_cache($target_url) {
    $cache_key = 'fabb_backlink_' . md5($target_url);
    return get_transient($cache_key);
}

// 反链检测结果缓存设置
function fabb_set_backlink_cache($target_url, $result) {
    $cache_key = 'fabb_backlink_' . md5($target_url);
    $cache_hours = absint(fabb_get_setting('backlink_cache_hours', FABB_BACKLINK_CACHE_DEFAULT_HOURS));
    $cache_hours = max(1, min(24, $cache_hours));
    set_transient($cache_key, $result, $cache_hours * HOUR_IN_SECONDS);
}

// 防SSRF验证
function fabb_validate_url_safe($url) {
    if (empty($url) || !wp_http_validate_url($url)) {
        return false;
    }
    
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    
    if (empty($host)) {
        return false;
    }
    
    $forbidden_hosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
    if (in_array(strtolower($host), $forbidden_hosts)) {
        return false;
    }
    
    if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host)) {
        return false;
    }
    
    $ip = gethostbyname($host);
    if ($ip !== $host && preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|127\.)/', $ip)) {
        return false;
    }
    
    return true;
}

// 添加AJAX端点用于JS动态网页检测
add_action('wp_ajax_fabb_check_backlink_js', 'fabb_check_backlink_js_ajax');
add_action('wp_ajax_nopriv_fabb_check_backlink_js', 'fabb_check_backlink_js_ajax');

function fabb_check_backlink_js_ajax() {
    check_ajax_referer('fabb_check_js_nonce', 'nonce');
    
    $url = sanitize_url($_POST['url'] ?? '');
    if (empty($url) || !wp_http_validate_url($url)) {
        wp_send_json_error(['message' => '无效的URL']);
        return;
    }
    
    $force_check = isset($_POST['force']) && $_POST['force'] === '1';
    $result = fabb_check_backlink($url, $force_check);
    
    wp_send_json_success(['has_backlink' => $result]);
}

// 反链检测核心函数（优化版）
function fabb_check_backlink($target_url, $force_check = false) {
    if (empty($target_url)) return false;
    
    $target_url = trim($target_url);
    
    if (!fabb_validate_url_safe($target_url)) {
        return false;
    }
    
    if (!$force_check) {
        $cached = fabb_get_backlink_cache($target_url);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    $whitelist_result = fabb_check_whitelist($target_url);
    if ($whitelist_result === 'whitelisted') {
        fabb_set_backlink_cache($target_url, 'whitelisted');
        return 'whitelisted';
    }
    if ($whitelist_result === 'skip') {
        return false;
    }
    
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    $site_host_clean = preg_replace('/^www\./', '', $site_host);
    $site_url_full = trailingslashit(home_url());
    $site_url_http = str_replace('https://', 'http://', $site_url_full);
    $site_url_https = str_replace('http://', 'https://', $site_url_full);
    $site_name = get_bloginfo('name');
    $site_host_www = 'www.' . $site_host_clean;
    
    $host_pattern = '/\b' . preg_quote($site_host_clean, '/') . '\b/i';
    
    $check_page_for_backlink = function($body) use ($host_pattern, $site_host_clean, $site_host_www, $site_url_full, $site_url_http, $site_url_https, $site_name) {
        if (empty($body) || !is_string($body)) return false;
        
        if (preg_match($host_pattern, $body)) return true;
        if (stripos($body, $site_host_www) !== false) return true;
        if (stripos($body, $site_url_full) !== false) return true;
        if (stripos($body, $site_url_http) !== false) return true;
        if (stripos($body, $site_url_https) !== false) return true;
        
        if (mb_strlen($site_name) >= 2) {
            if (stripos($body, $site_name) !== false) return true;
            
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $title_match);
            if (!empty($title_match[1]) && (stripos($title_match[1], $site_name) !== false || stripos($title_match[1], $site_host_clean) !== false)) {
                return true;
            }
            
            preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $body, $meta_match);
            if (!empty($meta_match[1]) && (stripos($meta_match[1], $site_name) !== false || stripos($meta_match[1], $site_host_clean) !== false)) {
                return true;
            }
        }
        
        if (fabb_get_setting('check_image_links', 'on') === 'on') {
            preg_match_all('/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/is', $body, $img_matches);
            if (!empty($img_matches[1])) {
                foreach ($img_matches[1] as $img_src) {
                    if (preg_match($host_pattern, $img_src) || stripos($img_src, $site_host_www) !== false || stripos($img_src, $site_url_full) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    };
    
    $request_args = [
        'timeout' => FABB_REQUEST_TIMEOUT,
        'sslverify' => false,
        'redirection' => 5,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        'headers' => [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
        ]
    ];
    
    $safe_remote_get = function($url, $args) {
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            sleep(FABB_BACKLINK_RETRY_DELAY);
            $response = wp_remote_get($url, $args);
        }
        return $response;
    };
    
    $response = $safe_remote_get($target_url, $request_args);
    if (is_wp_error($response)) return false;
    
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) return false;
    
    if ($check_page_for_backlink($body)) {
        fabb_set_backlink_cache($target_url, true);
        return true;
    }
    
    $base_url = trailingslashit($target_url);
    $candidate_links = [];
    $all_links = [];
    
    preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $body, $matches);
    
    if (!empty($matches[1])) {
        $keywords_str = fabb_get_setting('backlink_keywords', '友情链接,友链,友人帐,合作伙伴,推荐网站,友情,友站,友邻,小伙伴,站点推荐,博客邻居,友情互链,交换链接,friend,friends,friendly,link,links,flink,blogroll,partner,partners,exchange,site,sites,follow,following,community');
        $friend_link_keywords = array_filter(array_map('trim', explode(',', $keywords_str)));
        
        foreach ($matches[1] as $index => $href) {
            $href = trim($href);
            $link_text = trim(strip_tags($matches[2][$index] ?? ''));
            
            if (empty($href) || strpos($href, 'javascript:') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0 || $href[0] === '#' || $href[0] === '?') continue;
            
            if (strpos($href, 'http') !== 0) {
                $href = $base_url . ltrim($href, '/');
            }
            
            $link_host = parse_url($href, PHP_URL_HOST);
            $target_host = parse_url($target_url, PHP_URL_HOST);
            if (empty($link_host) || empty($target_host)) continue;
            if (preg_replace('/^www\./', '', $link_host) !== preg_replace('/^www\./', '', $target_host)) continue;
            
            $href_normalized = trailingslashit(strtolower($href));
            if (in_array($href_normalized, $all_links)) continue;
            $all_links[] = $href_normalized;
            
            $has_keyword = false;
            foreach ($friend_link_keywords as $kw) {
                if (stripos($href, $kw) !== false || (!empty($link_text) && mb_stripos($link_text, $kw) !== false)) {
                    $has_keyword = true;
                    break;
                }
            }
            
            if ($has_keyword) {
                $candidate_links[] = $href;
            }
        }
    }
    
    if (fabb_get_setting('auto_check_common_paths', 'on') === 'on') {
        $common_paths = ['friend', 'link', 'links', 'friends', 'blogroll', 'flink', 'partner', 'partners', 'site', 'sites'];
        foreach ($common_paths as $path) {
            $test_url = $base_url . $path;
            $test_url_normalized = trailingslashit(strtolower($test_url));
            if (!in_array($test_url_normalized, $all_links)) {
                $candidate_links[] = $test_url;
                $all_links[] = $test_url_normalized;
            }
        }
    }
    
    if (!empty($candidate_links)) {
        $candidate_links = array_slice($candidate_links, 0, FABB_CANDIDATE_LINKS_LIMIT);
        foreach ($candidate_links as $flink_url) {
            usleep(FABB_BACKLINK_CHECK_DELAY);
            $flink_response = $safe_remote_get($flink_url, $request_args);
            if (is_wp_error($flink_response)) continue;
            $flink_body = wp_remote_retrieve_body($flink_response);
            if (empty($flink_body)) continue;
            if ($check_page_for_backlink($flink_body)) {
                fabb_set_backlink_cache($target_url, true);
                return true;
            }
        }
    }
    
    fabb_set_backlink_cache($target_url, false);
    return false;
}

// 检查白名单
function fabb_check_whitelist($target_url) {
    $whitelist = fabb_get_setting('backlink_whitelist', '');
    if (empty($whitelist)) return 'not_in_list';
    
    $whitelist_domains = array_map('trim', explode("\n", $whitelist));
    $target_host = parse_url($target_url, PHP_URL_HOST);
    $target_host_clean = preg_replace('/^www\./', '', $target_host);
    
    foreach ($whitelist_domains as $domain) {
        $domain_clean = preg_replace('/^www\./', '', trim($domain));
        if ($target_host_clean === $domain_clean) {
            return 'whitelisted';
        }
    }
    
    return 'not_in_list';
}
// 批量检测所有已上线友链（优化版）
function fabb_batch_check_all_backlinks($force_check = false) {
    @set_time_limit(0);
    
    $approved_posts = get_posts(array(
        'post_type' => 'link_apply',
        'post_status' => 'publish',
        'meta_key' => '_fabb_apply_status',
        'meta_value' => 'approved',
        'numberposts' => -1,
        'fields' => 'ids',
    ));
    
    $total = count($approved_posts);
    $invalid = 0;
    $checked = 0;
    
    foreach ($approved_posts as $post_id) {
        $link_url = get_post_meta($post_id, '_fabb_link_url', true);
        if (empty($link_url)) continue;
        
        if (!$force_check) {
            $cached = fabb_get_backlink_cache($link_url);
            if ($cached !== false) {
                $has_backlink = $cached;
                $checked++;
            } else {
                try {
                    $has_backlink = fabb_check_backlink($link_url);
                    $checked++;
                } catch (Exception $e) {
                    $has_backlink = false;
                }
            }
        } else {
            try {
                $has_backlink = fabb_check_backlink($link_url, true);
                $checked++;
            } catch (Exception $e) {
                $has_backlink = false;
            }
        }
        
        if ($has_backlink === 'whitelisted') {
            update_post_meta($post_id, '_fabb_backlink_status', 'whitelisted');
        } else {
            update_post_meta($post_id, '_fabb_backlink_status', $has_backlink ? 'has' : 'no');
        }
        
        update_post_meta($post_id, '_fabb_backlink_check_time', time());
        
        if (!$has_backlink && $has_backlink !== 'whitelisted') {
            $invalid++;
            $alert_email = fabb_get_setting('alert_email', get_option('admin_email'));
            if (!empty($alert_email)) {
                $last_alert_time = get_post_meta($post_id, '_fabb_last_backlink_alert_time', true);
                $alert_duplicate_days = fabb_get_setting('alert_duplicate_days', 7);
                if (!$last_alert_time || (time() - $last_alert_time) > ($alert_duplicate_days * 86400)) {
                    $link_name = get_the_title($post_id);
                    $check_time = date('Y-m-d H:i:s');
                    $subject = '友链反链失效提醒';
                    $message = fabb_get_email_template('backlink_alert', array(
                        'site_name' => get_bloginfo('name'),
                        'link_name' => $link_name,
                        'link_url' => $link_url,
                        'check_time' => $check_time,
                    ));
                    fabb_send_html_email($alert_email, $subject, $message);
                    update_post_meta($post_id, '_fabb_last_backlink_alert_time', time());
                }
            }
        }
        usleep(FABB_AUTO_CHECK_DELAY);
    }
    
    return array('total' => $total, 'invalid' => $invalid, 'checked' => $checked);
}

// 添加AJAX端点用于JS动态网页检测
add_action('wp_ajax_fabb_check_backlink_js', 'fabb_check_backlink_js_ajax');
add_action('wp_ajax_nopriv_fabb_check_backlink_js', 'fabb_check_backlink_js_ajax');

// 添加批量检测AJAX端点
add_action('wp_ajax_fabb_batch_check_ajax', 'fabb_batch_check_ajax');
function fabb_batch_check_ajax() {
    check_ajax_referer('fabb_batch_check_nonce', 'nonce');
    
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $result = fabb_batch_check_all_backlinks($force);
    
    wp_send_json_success($result);
}
// 同步原生链接到插件
function fabb_sync_bookmarks_to_apply() {
    $bookmarks = get_bookmarks(array('hide_invisible' => 0));
    $total = count($bookmarks);
    $added = 0;
    $exists = 0;
    foreach ($bookmarks as $bookmark) {
        $existing = get_posts(array(
            'post_type' => 'link_apply',
            'meta_key' => '_fabb_link_id',
            'meta_value' => $bookmark->link_id,
            'numberposts' => 1,
        ));
        if (!empty($existing)) {
            $exists++;
            continue;
        }
        $post_data = array(
            'post_title' => $bookmark->link_name,
            'post_content' => $bookmark->link_description,
            'post_type' => 'link_apply',
            'post_status' => 'publish',
        );
        $post_id = wp_insert_post($post_data);
        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_fabb_link_id', $bookmark->link_id);
            update_post_meta($post_id, '_fabb_link_url', $bookmark->link_url);
            update_post_meta($post_id, '_fabb_link_image', $bookmark->link_image);
            update_post_meta($post_id, '_fabb_apply_status', 'approved');
            update_post_meta($post_id, '_fabb_apply_email', '');
            $added++;
        }
    }
    return array('total' => $total, 'added' => $added, 'exists' => $exists);
}
// ====================== 8. 自动通过新申请功能 ======================
function fabb_auto_approve_applications() {
    if (fabb_get_setting('auto_approve_enable', 'off') !== 'on') {
        return;
    }
    
    $auto_approve_mode = fabb_get_setting('auto_approve_mode', 'days');
    $auto_approve_value = fabb_get_setting('auto_approve_value', 7);
    
    // 计算截止时间
    if ($auto_approve_mode === 'hours') {
        $cutoff_time = time() - ($auto_approve_value * 3600);
    } else {
        $cutoff_time = time() - ($auto_approve_value * 86400);
    }
    
    $pending_applications = get_posts(array(
        'post_type' => 'link_apply',
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_fabb_apply_status',
                'value' => 'pending',
                'compare' => '=',
            ),
            array(
                'key' => 'post_date',
                'value' => date('Y-m-d H:i:s', $cutoff_time),
                'compare' => '<',
                'type' => 'DATETIME',
            ),
        ),
        'numberposts' => -1,
        'fields' => 'ids',
    ));
    if (empty($pending_applications)) {
        return;
    }
    $email_approved = fabb_get_setting('email_approved_notice', 'on') === 'on';
    $approved_count = 0;
    foreach ($pending_applications as $post_id) {
        $link_url = get_post_meta($post_id, '_fabb_link_url', true);
        if (empty($link_url)) continue;
        try {
            $has_backlink = fabb_check_backlink($link_url);
        } catch (Exception $e) {
            $has_backlink = false;
        }
        // 白名单也自动通过
        if ($has_backlink || $has_backlink === 'whitelisted') {
            update_post_meta($post_id, '_fabb_apply_status', 'approved');
            
            $link_id = get_post_meta($post_id, '_fabb_link_id', true);
            $link_data = array(
                'link_name' => get_the_title($post_id),
                'link_url' => $link_url,
                'link_description' => get_post_field('post_content', $post_id),
                'link_image' => get_post_meta($post_id, '_fabb_link_image', true),
                'link_target' => '_blank',
                'link_visible' => 'Y',
            );
            if (empty($link_id)) {
                $link_id = wp_insert_link($link_data);
                if ($link_id) {
                    update_post_meta($post_id, '_fabb_link_id', $link_id);
                }
            } else {
                $link_data['link_id'] = $link_id;
                wp_update_link($link_data);
            }
            $contact_email = get_post_meta($post_id, '_fabb_apply_email', true);
            if ($email_approved && !empty($contact_email)) {
                $subject = '您的友情链接申请已自动通过';
                $message = fabb_get_email_template('auto_approved', array(
                    'site_name' => get_bloginfo('name'),
                    'link_name' => get_the_title($post_id),
                    'link_url' => $link_url,
                ));
                fabb_send_html_email($contact_email, $subject, $message);
            }
            $approved_count++;
        }
        unset($has_backlink, $link_url);
        usleep(FABB_AUTO_CHECK_DELAY);
    }
    if ($approved_count > 0) {
        $admin_email = fabb_get_setting('alert_email', get_option('admin_email'));
        if (!empty($admin_email)) {
            $subject = '【自动审核通知】' . $approved_count . ' 个友链申请已自动通过';
            $message = "您好，\r\n\r\n系统已自动审核通过 " . $approved_count . " 个符合条件的友链申请，链接已上线。\r\n\r\n请登录后台查看详情：" . admin_url('edit.php?post_type=link_apply');
            wp_mail($admin_email, $subject, $message);
        }
    }
}
add_action('fabb_auto_approve_applications_hook', 'fabb_auto_approve_applications');
// ====================== 9. 自动反链检测定时任务 ======================
function fabb_auto_check_backlinks() {
    fabb_batch_check_all_backlinks();
}
add_action('fabb_auto_check_backlink_hook', 'fabb_auto_check_backlinks');
// ====================== 10. 列表行操作按钮 + 批量操作 + 回收站显示 ======================
add_filter('post_row_actions', 'fabb_add_apply_row_actions', 10, 2);
add_filter('views_edit-link_apply', 'fabb_add_apply_views');
add_filter('query_vars', 'fabb_add_trash_query_var');

function fabb_add_trash_query_var($vars) {
    $vars[] = 'trash_view';
    return $vars;
}

function fabb_add_apply_views($views) {
    global $wpdb;
    
    $trash_count = wp_count_posts('link_apply');
    $trash_num = $trash_count->trash;
    
    if ($trash_num > 0) {
        $current = isset($_GET['post_status']) && $_GET['post_status'] === 'trash' ? ' class="current"' : '';
        $views['trash'] = sprintf(
            '<a href="%s"%s>回收站 <span class="count">(%d)</span></a>',
            esc_url(admin_url('edit.php?post_type=link_apply&post_status=trash')),
            $current,
            $trash_num
        );
    }
    
    return $views;
}
function fabb_add_apply_row_actions($actions, $post) {
    if ($post->post_type !== 'link_apply') return $actions;
    
    // 移除默认的快速编辑
    unset($actions['inline hide-if-no-js']);
    // 缩短"移至回收站"为"回收"
    if (isset($actions['trash'])) {
        $actions['trash'] = str_replace('移至回收站', '回收', $actions['trash']);
    }
    
    $current_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : 'all';
    $status_param = $current_status !== 'all' ? '&post_status=' . $current_status : '';
    $status = get_post_meta($post->ID, '_fabb_apply_status', true) ?: 'pending';
    
    $new_actions = array();
    
    // 修复：确保所有非已通过状态都显示通过按钮
    if ($status !== 'approved') {
        $approve_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=approve&post=' . $post->ID . $status_param), 'fabb_approve_apply_' . $post->ID);
        $new_actions['approve'] = '<a href="' . esc_url($approve_url) . '" style="color:#00b42a;font-weight:bold;margin-right:8px;" onclick="return confirm(\'确定要通过这个友链申请吗？\n通过后将自动同步到链接管理器并上线\')">通过</a>';
    }
    
    // 修复：确保所有非已拒绝状态都显示拒绝按钮
    if ($status !== 'rejected') {
        $reject_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=reject&post=' . $post->ID . $status_param), 'fabb_reject_apply_' . $post->ID);
        $new_actions['reject'] = '<a href="' . esc_url($reject_url) . '" style="color:#d63638;font-weight:bold;margin-right:8px;" onclick="return confirm(\'确定要拒绝这个友链申请吗？\n拒绝后将自动移除已上线的链接\')">拒绝</a>';
    }
    
    $check_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=check_backlink&post=' . $post->ID . $status_param), 'fabb_check_backlink_' . $post->ID);
    $new_actions['check_backlink'] = '<a href="' . esc_url($check_url) . '" style="margin-right:8px;">检测反链</a>';
    
    // 添加白名单操作
    $backlink_status = get_post_meta($post->ID, '_fabb_backlink_status', true);
    if ($backlink_status !== 'whitelisted') {
        $whitelist_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=add_to_whitelist&post=' . $post->ID . $status_param), 'fabb_add_to_whitelist_' . $post->ID);
        $new_actions['add_to_whitelist'] = '<a href="' . esc_url($whitelist_url) . '" style="color:#777bb4;margin-right:8px;" onclick="return confirm(\'确定要将此网站添加到反链白名单吗？\n添加后将自动视为有反链\')">加入白名单</a>';
    }
    
    $new_actions['edit'] = $actions['edit'];
    
    if (isset($actions['trash'])) {
        $trash_url = $actions['trash'];
        $trash_url = str_replace('href=', 'onclick="return confirm(\'确定要将这个申请移到回收站吗？\')" href=', $trash_url);
        $new_actions['trash'] = $trash_url;
    }
    
    return $new_actions;
}
// 批量操作
add_filter('bulk_actions-edit-link_apply', 'fabb_add_bulk_actions');
function fabb_add_bulk_actions($bulk_actions) {
    $bulk_actions['bulk_approve'] = '批量通过';
    $bulk_actions['bulk_reject'] = '批量拒绝';
    $bulk_actions['bulk_check_backlink'] = '批量检测反链';
    $bulk_actions['bulk_add_to_whitelist'] = '批量加入白名单';
    return $bulk_actions;
}
add_action('handle_bulk_actions-edit-link_apply', 'fabb_handle_bulk_actions', 10, 3);
function fabb_handle_bulk_actions($redirect_to, $doaction, $post_ids) {
    if ($doaction === 'bulk_approve') {
        $count = 0;
        foreach ($post_ids as $post_id) {
            $status = get_post_meta($post_id, '_fabb_apply_status', true) ?: 'pending';
            if ($status !== 'approved') {
                update_post_meta($post_id, '_fabb_apply_status', 'approved');
                
                $link_id = get_post_meta($post_id, '_fabb_link_id', true);
                $link_data = array(
                    'link_name' => get_the_title($post_id),
                    'link_url' => get_post_meta($post_id, '_fabb_link_url', true),
                    'link_description' => get_post_field('post_content', $post_id),
                    'link_image' => get_post_meta($post_id, '_fabb_link_image', true),
                    'link_target' => '_blank',
                    'link_visible' => 'Y',
                );
                if (empty($link_id)) {
                    $link_id = wp_insert_link($link_data);
                    if ($link_id) update_post_meta($post_id, '_fabb_link_id', $link_id);
                } else {
                    $link_data['link_id'] = $link_id;
                    wp_update_link($link_data);
                }
                $count++;
            }
        }
        $redirect_to = add_query_arg('bulk_approved', $count, $redirect_to);
    } elseif ($doaction === 'bulk_reject') {
        $count = 0;
        foreach ($post_ids as $post_id) {
            $status = get_post_meta($post_id, '_fabb_apply_status', true) ?: 'pending';
            if ($status !== 'rejected') {
                update_post_meta($post_id, '_fabb_apply_status', 'rejected');
                
                $link_id = get_post_meta($post_id, '_fabb_link_id', true);
                if (!empty($link_id)) {
                    wp_delete_link($link_id);
                    delete_post_meta($post_id, '_fabb_link_id');
                }
                $count++;
            }
        }
        $redirect_to = add_query_arg('bulk_rejected', $count, $redirect_to);
    } elseif ($doaction === 'bulk_check_backlink') {
        $count = 0;
        $invalid = 0;
        foreach ($post_ids as $post_id) {
            $link_url = get_post_meta($post_id, '_fabb_link_url', true);
            if (empty($link_url)) continue;
            try {
                $has_backlink = fabb_check_backlink($link_url);
            } catch (Exception $e) {
                $has_backlink = false;
            }
            
            if ($has_backlink === 'whitelisted') {
                update_post_meta($post_id, '_fabb_backlink_status', 'whitelisted');
            } else {
                update_post_meta($post_id, '_fabb_backlink_status', $has_backlink ? 'has' : 'no');
            }
            
            update_post_meta($post_id, '_fabb_backlink_check_time', time());
            if (!$has_backlink) $invalid++;
            $count++;
            usleep(FABB_AUTO_CHECK_DELAY);
        }
        $redirect_to = add_query_arg(array('bulk_checked' => $count, 'bulk_invalid' => $invalid), $redirect_to);
    } elseif ($doaction === 'bulk_add_to_whitelist') {
        $count = 0;
        $whitelist = fabb_get_setting('backlink_whitelist', '');
        $whitelist_domains = array_map('trim', explode("\n", $whitelist));
        $whitelist_domains = array_filter($whitelist_domains);
        
        foreach ($post_ids as $post_id) {
            $link_url = get_post_meta($post_id, '_fabb_link_url', true);
            if (empty($link_url)) continue;
            
            $link_host = parse_url($link_url, PHP_URL_HOST);
            $link_host_clean = preg_replace('/^www\./', '', $link_host);
            
            if (!in_array($link_host_clean, $whitelist_domains)) {
                $whitelist_domains[] = $link_host_clean;
                update_post_meta($post_id, '_fabb_backlink_status', 'whitelisted');
                $count++;
            }
        }
        
        $new_whitelist = implode("\n", $whitelist_domains);
        $settings = get_option('fabb_settings');
        $settings['backlink_whitelist'] = $new_whitelist;
        update_option('fabb_settings', $settings);
        
        $redirect_to = add_query_arg('bulk_whitelisted', $count, $redirect_to);
    }
    return $redirect_to;
}
// 批量操作成功提示
add_action('admin_notices', 'fabb_bulk_action_notices');
function fabb_bulk_action_notices() {
    if (isset($_GET['bulk_approved'])) {
        $count = intval($_GET['bulk_approved']);
        echo '<div class="notice notice-success is-dismissible"><p>批量通过 ' . $count . ' 个友链申请，链接已同步到链接管理器</p></div>';
    }
    if (isset($_GET['bulk_rejected'])) {
        $count = intval($_GET['bulk_rejected']);
        echo '<div class="notice notice-success is-dismissible"><p>批量拒绝 ' . $count . ' 个友链申请，对应链接已从链接管理器移除</p></div>';
    }
    if (isset($_GET['bulk_checked'])) {
        $count = intval($_GET['bulk_checked']);
        $invalid = intval($_GET['bulk_invalid']);
        echo '<div class="notice notice-success is-dismissible"><p>批量检测完成！共检测 ' . $count . ' 个友链，正常 ' . ($count - $invalid) . ' 个，失效 ' . $invalid . ' 个</p></div>';
    }
    if (isset($_GET['bulk_whitelisted'])) {
        $count = intval($_GET['bulk_whitelisted']);
        echo '<div class="notice notice-success is-dismissible"><p>批量加入白名单完成！共添加 ' . $count . ' 个网站到反链白名单</p></div>';
    }
}
// ====================== 11. 后台操作处理 ======================
add_action('admin_init', 'fabb_handle_admin_actions');
function fabb_handle_admin_actions() {
    global $pagenow;
    if ($pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'link_apply' || !isset($_GET['action']) || !isset($_GET['post'])) {
        return;
    }
    $post_id = absint($_GET['post']);
    $action = sanitize_text_field($_GET['action']);
    $allowed_actions = array('approve', 'reject', 'check_backlink', 'add_to_whitelist');
    if (!in_array($action, $allowed_actions)) {
        return;
    }
    if (!current_user_can('manage_links', $post_id)) {
        wp_die('您没有权限执行此操作');
    }
    $nonce_name = 'fabb_' . $action . '_apply_' . $post_id;
    if ($action === 'check_backlink') {
        $nonce_name = 'fabb_check_backlink_' . $post_id;
    } elseif ($action === 'add_to_whitelist') {
        $nonce_name = 'fabb_add_to_whitelist_' . $post_id;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_name)) {
        wp_die('安全验证失败，请刷新重试');
    }
    $current_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : 'all';
    $status_param = $current_status !== 'all' ? '&post_status=' . $current_status : '';
    $email_approved = fabb_get_setting('email_approved_notice', 'on') === 'on';
    $email_rejected = fabb_get_setting('email_rejected_notice', 'on') === 'on';
    switch ($action) {
        case 'approve':
            update_post_meta($post_id, '_fabb_apply_status', 'approved');
            
            $link_id = get_post_meta($post_id, '_fabb_link_id', true);
            $link_data = array(
                'link_name' => get_the_title($post_id),
                'link_url' => get_post_meta($post_id, '_fabb_link_url', true),
                'link_description' => get_post_field('post_content', $post_id),
                'link_image' => get_post_meta($post_id, '_fabb_link_image', true),
                'link_target' => '_blank',
                'link_visible' => 'Y',
            );
            if (empty($link_id)) {
                $link_id = wp_insert_link($link_data);
                if ($link_id) update_post_meta($post_id, '_fabb_link_id', $link_id);
            } else {
                $link_data['link_id'] = $link_id;
                wp_update_link($link_data);
            }
            $contact_email = get_post_meta($post_id, '_fabb_apply_email', true);
            if ($email_approved && !empty($contact_email)) {
                $subject = '您的友情链接申请已通过';
                $message = fabb_get_email_template('approved', array(
                    'site_name' => get_bloginfo('name'),
                    'link_name' => get_the_title($post_id),
                    'link_url' => get_post_meta($post_id, '_fabb_link_url', true),
                ));
                fabb_send_html_email($contact_email, $subject, $message);
            }
            wp_redirect(admin_url('edit.php?post_type=link_apply&approved=1' . $status_param));
            exit;
            break;
        case 'reject':
            update_post_meta($post_id, '_fabb_apply_status', 'rejected');
            
            $link_id = get_post_meta($post_id, '_fabb_link_id', true);
            if (!empty($link_id)) {
                wp_delete_link($link_id);
                delete_post_meta($post_id, '_fabb_link_id');
            }
            $contact_email = get_post_meta($post_id, '_fabb_apply_email', true);
            if ($email_rejected && !empty($contact_email)) {
                $subject = '您的友情链接申请未通过审核';
                $message = fabb_get_email_template('rejected', array(
                    'site_name' => get_bloginfo('name'),
                    'link_name' => get_the_title($post_id),
                    'link_url' => get_post_meta($post_id, '_fabb_link_url', true),
                ));
                fabb_send_html_email($contact_email, $subject, $message);
            }
            wp_redirect(admin_url('edit.php?post_type=link_apply&rejected=1' . $status_param));
            exit;
            break;
        case 'check_backlink':
            $target_url = get_post_meta($post_id, '_fabb_link_url', true);
            $has_backlink = fabb_check_backlink($target_url);
            
            if ($has_backlink === 'whitelisted') {
                update_post_meta($post_id, '_fabb_backlink_status', 'whitelisted');
            } else {
                update_post_meta($post_id, '_fabb_backlink_status', $has_backlink ? 'has' : 'no');
            }
            
            update_post_meta($post_id, '_fabb_backlink_check_time', time());
            wp_redirect(admin_url('edit.php?post_type=link_apply&checked=1' . $status_param));
            exit;
            break;
        case 'add_to_whitelist':
            $link_url = get_post_meta($post_id, '_fabb_link_url', true);
            if (!empty($link_url)) {
                $link_host = parse_url($link_url, PHP_URL_HOST);
                $link_host_clean = preg_replace('/^www\./', '', $link_host);
                
                $whitelist = fabb_get_setting('backlink_whitelist', '');
                $whitelist_domains = array_map('trim', explode("\n", $whitelist));
                $whitelist_domains = array_filter($whitelist_domains);
                
                if (!in_array($link_host_clean, $whitelist_domains)) {
                    $whitelist_domains[] = $link_host_clean;
                    $new_whitelist = implode("\n", $whitelist_domains);
                    
                    $settings = get_option('fabb_settings');
                    $settings['backlink_whitelist'] = $new_whitelist;
                    update_option('fabb_settings', $settings);
                    
                    update_post_meta($post_id, '_fabb_backlink_status', 'whitelisted');
                }
            }
            wp_redirect(admin_url('edit.php?post_type=link_apply&whitelisted=1' . $status_param));
            exit;
            break;
    }
}
// 操作成功提示
add_action('admin_notices', 'fabb_admin_notices');
function fabb_admin_notices() {
    global $pagenow;
    if ($pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'link_apply') return;
    if (isset($_GET['approved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>申请已通过，链接已同步到链接管理器</p></div>';
    }
    if (isset($_GET['rejected'])) {
        echo '<div class="notice notice-success is-dismissible"><p>申请已拒绝，对应链接已从链接管理器移除</p></div>';
    }
    if (isset($_GET['checked'])) {
        echo '<div class="notice notice-success is-dismissible"><p>反链检测完成</p></div>';
    }
    if (isset($_GET['modified'])) {
        echo '<div class="notice notice-success is-dismissible"><p>友链信息已修改，等待管理员审核</p></div>';
    }
    if (isset($_GET['whitelisted'])) {
        echo '<div class="notice notice-success is-dismissible"><p>已添加到反链白名单</p></div>';
    }
}
// ====================== 12. 前端申请表单短代码 ======================
add_shortcode('link_apply_form', 'fabb_render_apply_form_shortcode');
function fabb_render_apply_form_shortcode() {
    if (fabb_get_setting('apply_form_enable', 'on') !== 'on') {
        return '<div class="fabb-form-notice" style="padding:15px;background:#f5f5f5;border-radius:8px;color:#666;">友情链接申请通道已关闭</div>';
    }
    $output = '';
    if (isset($_GET['apply_success']) && $_GET['apply_success'] === '1') {
        $output .= '<div class="fabb-form-success" style="padding:15px;background:#f0fff4;border:1px solid #00b42a;border-radius:8px;color:#00b42a;margin-bottom:20px;">✅ 您的申请已提交成功，我们会尽快审核</div>';
    }
    if (isset($_GET['apply_error']) && !empty($_GET['apply_error'])) {
        $error_msg = sanitize_text_field(urldecode($_GET['apply_error']));
        $output .= '<div class="fabb-form-error" style="padding:15px;background:#fef0f0;border:1px solid #d63638;border-radius:8px;color:#d63638;margin-bottom:20px;">❌ ' . esc_html($error_msg) . '</div>';
    }
    $output .= '<form class="fabb-apply-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:800px;margin:0 auto;">';
    $output .= wp_nonce_field('fabb_apply_form_nonce', 'fabb_apply_form_nonce_field', true, false);
    $output .= '<input type="hidden" name="action" value="link_apply_submit">';
    
    $output .= '
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_site_name" style="display:block;margin-bottom:8px;font-weight:600;">网站名称 <span style="color:red;">*</span></label>
        <input type="text" name="fabb_site_name" id="fabb_site_name" required style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请输入您的网站名称">
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_site_url" style="display:block;margin-bottom:8px;font-weight:600;">网站链接地址 <span style="color:red;">*</span></label>
        <input type="url" name="fabb_site_url" id="fabb_site_url" required style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请输入您的网站完整链接（https://开头）">
        <p class="description" style="margin-top:5px;color:#666;">请确保网站可正常访问，且已添加本站友情链接</p>
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_site_image" style="display:block;margin-bottom:8px;font-weight:600;">网站图标地址</label>
        <input type="url" name="fabb_site_image" id="fabb_site_image" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请填写网站图标完整URL（可选）">
        <div id="fabb_image_preview" style="margin-top:8px;display:none;">
            <img src="" id="fabb_preview_img" style="width:32px;height:32px;border-radius:4px;" alt="网站图标预览">
        </div>
        <p class="description" style="margin-top:5px;color:#666;">如果不填写，则链接列表中将不显示图标</p>
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_site_rss" style="display:block;margin-bottom:8px;font-weight:60;">网站RSS订阅地址</label>
        <input type="url" name="fabb_site_rss" id="fabb_site_rss" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请填写网站RSS订阅地址（可选）">
        <p class="description" style="margin-top:5px;color:#666;">填写后将在友链卡片右上角显示RSS订阅图标</p>
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_site_desc" style="display:block;margin-bottom:8px;font-weight:600;">网站介绍 <span style="color:red;">*</span></label>
        <textarea name="fabb_site_desc" id="fabb_site_desc" rows="4" required style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;resize:vertical;" placeholder="请简单介绍您的网站，10-200字"></textarea>
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_contact_email" style="display:block;margin-bottom:8px;font-weight:600;">联系邮箱 <span style="color:red;">*</span></label>
        <input type="email" name="fabb_contact_email" id="fabb_contact_email" required style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请输入您的联系邮箱，用于接收审核通知">
    </div>
    <div class="fabb-form-submit" style="margin-top:30px;">
        <button type="submit" style="padding:12px 30px;background:#4ecdc4;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;">提交申请</button>
    </div>
    ';
    $output .= '</form>';
    
    // 添加插件标识
    $output .= fabb_get_plugin_footer();
    
    $output .= '
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        const imageInput = document.getElementById("fabb_site_image");
        const previewWrap = document.getElementById("fabb_image_preview");
        const previewImg = document.getElementById("fabb_preview_img");
        imageInput.addEventListener("blur", function() {
            const imgUrl = this.value.trim();
            if (imgUrl) {
                previewImg.src = imgUrl;
                previewWrap.style.display = "block";
            } else {
                previewWrap.style.display = "none";
            }
        });
    });
    </script>
    ';
    return $output;
}
// 处理前端表单提交
add_action('admin_post_nopriv_link_apply_submit', 'fabb_handle_frontend_apply_submit');
add_action('admin_post_link_apply_submit', 'fabb_handle_frontend_apply_submit');
function fabb_handle_frontend_apply_submit() {
    if (!isset($_POST['fabb_apply_form_nonce_field']) || !wp_verify_nonce($_POST['fabb_apply_form_nonce_field'], 'fabb_apply_form_nonce')) {
        $redirect_url = add_query_arg('apply_error', urlencode('安全验证失败，请刷新页面重试'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    $site_name = sanitize_text_field($_POST['fabb_site_name']);
    $site_url = sanitize_url($_POST['fabb_site_url']);
    $site_image = sanitize_url($_POST['fabb_site_image']);
    $site_rss = sanitize_url($_POST['fabb_site_rss']);
    $site_desc = sanitize_textarea_field($_POST['fabb_site_desc']);
    $contact_email = sanitize_email($_POST['fabb_contact_email']);
    if (empty($site_name) || empty($site_url) || empty($site_desc) || empty($contact_email)) {
        $redirect_url = add_query_arg('apply_error', urlencode('请填写所有必填项'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    if (!is_email($contact_email)) {
        $redirect_url = add_query_arg('apply_error', urlencode('请输入正确的邮箱地址'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    if (!wp_http_validate_url($site_url)) {
        $redirect_url = add_query_arg('apply_error', urlencode('请输入正确的网站链接地址'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    $existing_posts = get_posts(array(
        'post_type' => 'link_apply',
        'meta_key' => '_fabb_link_url',
        'meta_value' => $site_url,
        'post_status' => 'any',
        'numberposts' => 1,
    ));
    if (!empty($existing_posts)) {
        $redirect_url = add_query_arg('apply_error', urlencode('该网站已提交过申请，请勿重复提交'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    $post_data = array(
        'post_title' => $site_name,
        'post_content' => $site_desc,
        'post_type' => 'link_apply',
        'post_status' => 'publish',
    );
    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        $redirect_url = add_query_arg('apply_error', urlencode('提交失败，请稍后重试'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    update_post_meta($post_id, '_fabb_link_url', $site_url);
    update_post_meta($post_id, '_fabb_link_image', $site_image);
    update_post_meta($post_id, '_fabb_link_rss', $site_rss);
    update_post_meta($post_id, '_fabb_apply_status', 'pending');
    update_post_meta($post_id, '_fabb_apply_email', $contact_email);
    $email_admin = fabb_get_setting('email_admin_notice', 'on') === 'on';
    $admin_email = fabb_get_setting('alert_email', get_option('admin_email'));
    if ($email_admin && !empty($admin_email)) {
        $subject = '新的友情链接申请';
        $message = fabb_get_email_template('admin_new', array(
            'site_name' => get_bloginfo('name'),
            'link_name' => $site_name,
            'link_url' => $site_url,
            'contact_email' => $contact_email,
            'link_desc' => $site_desc,
            'admin_url' => admin_url('edit.php?post_type=link_apply'),
        ));
        fabb_send_html_email($admin_email, $subject, $message);
    }
    $redirect_url = add_query_arg('apply_success', '1', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}
// ====================== 13. 前端自助修改表单（邮件验证码验证） ======================
add_shortcode('link_modify_form', 'fabb_render_modify_form_shortcode');
function fabb_render_modify_form_shortcode() {
    if (fabb_get_setting('modify_form_enable', 'on') !== 'on') {
        return '<div class="fabb-form-notice" style="padding:15px;background:#f5f5f5;border-radius:8px;color:#666;">友情链接自助修改通道已关闭</div>';
    }
    $email_verify = fabb_get_setting('modify_email_verify', 'on') === 'on';
    $output = '';
    if (isset($_GET['modify_success']) && $_GET['modify_success'] === '1') {
        $output .= '<div class="fabb-form-success" style="padding:15px;background:#f0fff4;border:1px solid #00b42a;border-radius:8px;color:#00b42a;margin-bottom:20px;">✅ 您的修改申请已提交成功，我们会尽快审核</div>';
    }
    if (isset($_GET['modify_error']) && !empty($_GET['modify_error'])) {
        $error_msg = sanitize_text_field(urldecode($_GET['modify_error']));
        $output .= '<div class="fabb-form-error" style="padding:15px;background:#fef0f0;border:1px solid #d63638;border-radius:8px;color:#d63638;margin-bottom:20px;">❌ ' . esc_html($error_msg) . '</div>';
    }
    if (isset($_GET['code_sent']) && $_GET['code_sent'] === '1') {
        $output .= '<div class="fabb-form-success" style="padding:15px;background:#f0fff4;border:1px solid #00b42a;border-radius:8px;color:#00b42a;margin-bottom:20px;">✅ 验证码已发送至您的邮箱，5分钟内有效</div>';
    }
    $output .= '<form class="fabb-modify-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:800px;margin:0 auto;">';
    $output .= wp_nonce_field('fabb_modify_form_nonce', 'fabb_modify_form_nonce_field', true, false);
    $output .= '<input type="hidden" name="action" value="link_modify_submit">';
    
    $output .= '
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_verify_url" style="display:block;margin-bottom:8px;font-weight:600;">您的网站链接地址 <span style="color:red;">*</span></label>
        <input type="url" name="fabb_verify_url" id="fabb_verify_url" required style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请输入您已添加的网站完整链接">
        <p class="description" style="margin-top:5px;color:#666;">用于验证您的身份，必须与申请时填写的一致</p>
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_verify_email" style="display:block;margin-bottom:8px;font-weight:600;">申请时的联系邮箱 <span style="color:red;">*</span></label>
        <div style="display:flex;gap:10px;align-items:flex-end;">
            <input type="email" name="fabb_verify_email" id="fabb_verify_email" required style="flex:1;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请输入申请时填写的联系邮箱">
            ' . ($email_verify ? '
            <button type="button" id="fabb_send_code_btn" style="padding:12px 20px;background:#4ecdc4;color:#fff;border:none;border-radius:8px;cursor:pointer;white-space:nowrap;">发送验证码</button>
            ' : '') . '
        </div>
    </div>
    ';
    if ($email_verify) {
        $output .= '
        <div class="fabb-form-group" style="margin-bottom:20px;">
            <label for="fabb_verify_code" style="display:block;margin-bottom:8px;font-weight:600;">邮箱验证码 <span style="color:red;">*</span></label>
            <input type="text" name="fabb_verify_code" id="fabb_verify_code" required style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="请输入邮箱收到的6位数字验证码">
        </div>
        ';
    }
    $output .= '
    <hr style="margin:30px 0;border:none;border-top:1px solid #eee;">
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_new_name" style="display:block;margin-bottom:8px;font-weight:600;">新的网站名称</label>
        <input type="text" name="fabb_new_name" id="fabb_new_name" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="不修改请留空">
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_new_url" style="display:block;margin-bottom:8px;font-weight:600;">新的网站链接地址</label>
        <input type="url" name="fabb_new_url" id="fabb_new_url" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="不修改请留空">
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_new_image" style="display:block;margin-bottom:8px;font-weight:600;">新的网站图标地址</label>
        <input type="url" name="fabb_new_image" id="fabb_new_image" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="不修改请留空">
        <div id="fabb_new_image_preview" style="margin-top:8px;display:none;">
            <img src="" id="fabb_new_preview_img" style="width:32px;height:32px;border-radius:4px;" alt="新图标预览">
        </div>
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_new_rss" style="display:block;margin-bottom:8px;font-weight:600;">新的RSS订阅地址</label>
        <input type="url" name="fabb_new_rss" id="fabb_new_rss" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;" placeholder="不修改请留空">
    </div>
    <div class="fabb-form-group" style="margin-bottom:20px;">
        <label for="fabb_new_desc" style="display:block;margin-bottom:8px;font-weight:600;">新的网站介绍</label>
        <textarea name="fabb_new_desc" id="fabb_new_desc" rows="4" style="width:100%;padding:12px;border:1px solid #e0e0e0;border-radius:8px;box-sizing:border-box;resize:vertical;" placeholder="不修改请留空"></textarea>
    </div>
    <div class="fabb-form-submit" style="margin-top:30px;">
        <button type="submit" style="padding:12px 30px;background:#4ecdc4;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;">提交修改申请</button>
    </div>
    ';
    $output .= '</form>';
    
    // 添加插件标识
    $output .= fabb_get_plugin_footer();
    
    if ($email_verify) {
        $output .= '
        <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            const sendCodeBtn = document.getElementById("fabb_send_code_btn");
            let countdown = 0;
            sendCodeBtn.addEventListener("click", function() {
                if (countdown > 0) return;
                
                const email = document.getElementById("fabb_verify_email").value.trim();
                const url = document.getElementById("fabb_verify_url").value.trim();
                
                if (!email || !isValidEmail(email)) {
                    alert("请输入正确的邮箱地址");
                    return;
                }
                if (!url) {
                    alert("请先输入网站链接地址");
                    return;
                }
                fetch("' . esc_url(admin_url('admin-post.php')) . '", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "action=fabb_send_verify_code&email=" + encodeURIComponent(email) + "&url=" + encodeURIComponent(url) + "&_wpnonce=' . wp_create_nonce('fabb_send_verify_code') . '"
                }).then(response => response.text()).then(result => {
                    if (result === "success") {
                        countdown = 60;
                        sendCodeBtn.disabled = true;
                        const timer = setInterval(() => {
                            sendCodeBtn.textContent = countdown + "s 后重发";
                            countdown--;
                            if (countdown <= 0) {
                                clearInterval(timer);
                                sendCodeBtn.textContent = "发送验证码";
                                sendCodeBtn.disabled = false;
                            }
                        }, 1000);
                        window.location.href = "' . add_query_arg('code_sent', '1', wp_get_referer()) . '";
                    } else if (result === "rate_limited") {
                        alert("发送过于频繁，请60秒后再试");
                    } else {
                        alert("验证码发送失败，请稍后重试");
                    }
                });
            });
            function isValidEmail(email) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            }
            const newImageInput = document.getElementById("fabb_new_image");
            const newPreviewWrap = document.getElementById("fabb_new_image_preview");
            const newPreviewImg = document.getElementById("fabb_new_preview_img");
            newImageInput.addEventListener("blur", function() {
                const imgUrl = this.value.trim();
                if (imgUrl) {
                    newPreviewImg.src = imgUrl;
                    newPreviewWrap.style.display = "block";
                } else {
                    newPreviewWrap.style.display = "none";
                }
            });
        });
        </script>
        ';
    } else {
        $output .= '
        <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            const newImageInput = document.getElementById("fabb_new_image");
            const newPreviewWrap = document.getElementById("fabb_new_image_preview");
            const newPreviewImg = document.getElementById("fabb_new_preview_img");
            newImageInput.addEventListener("blur", function() {
                const imgUrl = this.value.trim();
                if (imgUrl) {
                    newPreviewImg.src = imgUrl;
                    newPreviewWrap.style.display = "block";
                } else {
                    newPreviewWrap.style.display = "none";
                }
            });
        });
        </script>
        ';
    }
    return $output;
}
// 发送修改验证码
add_action('admin_post_nopriv_fabb_send_verify_code', 'fabb_send_modify_verify_code');
add_action('admin_post_fabb_send_verify_code', 'fabb_send_modify_verify_code');
function fabb_send_modify_verify_code() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'fabb_send_verify_code')) {
        echo 'error';
        exit;
    }
    
    $email = sanitize_email($_POST['email']);
    $url = sanitize_url($_POST['url']);
    if (!is_email($email) || !wp_http_validate_url($url)) {
        echo 'error';
        exit;
    }
    
    $rate_limit_key = 'fabb_verify_rate_' . md5($email);
    $last_send = get_transient($rate_limit_key);
    if ($last_send !== false) {
        echo 'rate_limited';
        exit;
    }
    
    $existing_posts = get_posts(array(
        'post_type' => 'link_apply',
        'meta_query' => array(
            'relation' => 'AND',
            array('key' => '_fabb_link_url', 'value' => $url, 'compare' => '='),
            array('key' => '_fabb_apply_email', 'value' => $email, 'compare' => '='),
            array('key' => '_fabb_apply_status', 'value' => 'approved', 'compare' => '='),
        ),
        'numberposts' => 1,
    ));
    if (empty($existing_posts)) {
        echo 'error';
        exit;
    }
    
    $code = str_pad(rand(0, 999999), FABB_VERIFY_CODE_LENGTH, '0', STR_PAD_LEFT);
    $transient_key = 'fabb_modify_code_' . md5($email . $url);
    set_transient($transient_key, $code, FABB_VERIFY_CODE_EXPIRY);
    set_transient($rate_limit_key, time(), FABB_RATE_LIMIT_SECONDS);
    
    $subject = '友链修改验证码';
    $message = fabb_get_email_template('verify_code', array(
        'site_name' => get_bloginfo('name'),
        'verify_code' => $code,
    ));
    if (fabb_send_html_email($email, $subject, $message)) {
        echo 'success';
    } else {
        echo 'error';
    }
    exit;
}
// 处理前端修改表单提交
add_action('admin_post_nopriv_link_modify_submit', 'fabb_handle_frontend_modify_submit');
add_action('admin_post_link_modify_submit', 'fabb_handle_frontend_modify_submit');
function fabb_handle_frontend_modify_submit() {
    if (!isset($_POST['fabb_modify_form_nonce_field']) || !wp_verify_nonce($_POST['fabb_modify_form_nonce_field'], 'fabb_modify_form_nonce')) {
        $redirect_url = add_query_arg('modify_error', urlencode('安全验证失败，请刷新页面重试'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    $verify_url = sanitize_url($_POST['fabb_verify_url']);
    $verify_email = sanitize_email($_POST['fabb_verify_email']);
    
    if (empty($verify_url) || empty($verify_email)) {
        $redirect_url = add_query_arg('modify_error', urlencode('请填写身份验证信息'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    if (!is_email($verify_email)) {
        $redirect_url = add_query_arg('modify_error', urlencode('请输入正确的邮箱地址'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    if (!wp_http_validate_url($verify_url)) {
        $redirect_url = add_query_arg('modify_error', urlencode('请输入正确的网站链接地址'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    // 验证邮箱验证码
    if (fabb_get_setting('modify_email_verify', 'on') === 'on') {
        $verify_code = sanitize_text_field($_POST['fabb_verify_code']);
        if (empty($verify_code) || strlen($verify_code) !== 6) {
            $redirect_url = add_query_arg('modify_error', urlencode('请输入正确的6位数字验证码'), wp_get_referer());
            wp_redirect($redirect_url);
            exit;
        }
        $transient_key = 'fabb_modify_code_' . md5($verify_email . $verify_url);
        $saved_code = get_transient($transient_key);
        if (!$saved_code || $saved_code !== $verify_code) {
            $redirect_url = add_query_arg('modify_error', urlencode('验证码错误或已过期'), wp_get_referer());
            wp_redirect($redirect_url);
            exit;
        }
        // 验证成功后删除验证码
        delete_transient($transient_key);
    }
    // 查找对应的申请记录
    $existing_posts = get_posts(array(
        'post_type' => 'link_apply',
        'meta_query' => array(
            'relation' => 'AND',
            array('key' => '_fabb_link_url', 'value' => $verify_url, 'compare' => '='),
            array('key' => '_fabb_apply_email', 'value' => $verify_email, 'compare' => '='),
        ),
        'post_status' => 'any',
        'numberposts' => 1,
    ));
    
    if (empty($existing_posts)) {
        $redirect_url = add_query_arg('modify_error', urlencode('身份验证失败，未找到对应的友链记录'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    
    $post_id = $existing_posts[0]->ID;
    $apply_status = get_post_meta($post_id, '_fabb_apply_status', true);
    
    if ($apply_status !== 'approved') {
        $redirect_url = add_query_arg('modify_error', urlencode('该友链尚未通过审核，无法修改'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    // 获取修改信息
    $new_name = sanitize_text_field($_POST['fabb_new_name']);
    $new_url = sanitize_url($_POST['fabb_new_url']);
    $new_image = sanitize_url($_POST['fabb_new_image']);
    $new_rss = sanitize_url($_POST['fabb_new_rss']);
    $new_desc = sanitize_textarea_field($_POST['fabb_new_desc']);
    
    if (empty($new_name) && empty($new_url) && empty($new_image) && empty($new_rss) && empty($new_desc)) {
        $redirect_url = add_query_arg('modify_error', urlencode('请至少填写一项修改内容'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    
    // 更新申请记录
    update_post_meta($post_id, '_fabb_modify_pending', 'yes');
    update_post_meta($post_id, '_fabb_modify_data', array(
        'name' => $new_name,
        'url' => $new_url,
        'image' => $new_image,
        'rss' => $new_rss,
        'desc' => $new_desc,
        'time' => current_time('mysql'),
    ));
    
    // 给管理员发送通知邮件
    $email_admin = fabb_get_setting('email_modified_notice', 'on') === 'on';
    $admin_email = fabb_get_setting('alert_email', get_option('admin_email'));
    if ($email_admin && !empty($admin_email)) {
        $modify_content = '';
        if (!empty($new_name)) $modify_content .= '新网站名称：' . $new_name . '<br>';
        if (!empty($new_url)) $modify_content .= '新网站链接：' . $new_url . '<br>';
        if (!empty($new_image)) $modify_content .= '新网站图标：' . $new_image . '<br>';
        if (!empty($new_rss)) $modify_content .= '新RSS订阅：' . $new_rss . '<br>';
        if (!empty($new_desc)) $modify_content .= '新网站介绍：' . $new_desc . '<br>';
        
        $subject = '友链信息修改申请';
        $message = fabb_get_email_template('admin_modified', array(
            'site_name' => get_bloginfo('name'),
            'old_name' => get_the_title($post_id),
            'old_url' => get_post_meta($post_id, '_fabb_link_url', true),
            'contact_email' => $verify_email,
            'modify_content' => $modify_content,
            'admin_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
        ));
        fabb_send_html_email($admin_email, $subject, $message);
    }
    
    $redirect_url = add_query_arg('modify_success', '1', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}
// ====================== 14. 随机友情链接短代码 ======================
add_shortcode('random_bookmarks', 'fabb_random_bookmarks_shortcode');
function fabb_random_bookmarks_shortcode($atts) {
    $default_show_image = fabb_get_setting('default_show_image', 'on') === 'on';
    $default_show_desc = fabb_get_setting('default_show_desc', 'on') === 'on';
    $default_image_size = fabb_get_setting('default_image_size', 64);
    $show_rss_feed = fabb_get_setting('show_rss_feed', 'on') === 'on';
    $default_target = fabb_get_setting('open_new_window', 'on') === 'on' ? '_blank' : '_self';
    $desc_multi_line = fabb_get_setting('desc_multi_line', 'on') === 'on';
    $atts = shortcode_atts(array(
        'category'         => '',
        'limit'            => fabb_get_setting('default_show_num', 20),
        'target'           => $default_target,
        'show_description' => $default_show_desc,
        'show_image'       => $default_show_image,
        'image_size'       => $default_image_size,
        'show_rss'         => $show_rss_feed,
    ), $atts, 'random_bookmarks');
    $show_image = (trim(strtolower($atts['show_image'])) === 'true' || $atts['show_image'] === true || $atts['show_image'] === 'on');
    $show_description = (trim(strtolower($atts['show_description'])) === 'true' || $atts['show_description'] === true || $atts['show_description'] === 'on');
    $show_rss = (trim(strtolower($atts['show_rss'])) === 'true' || $atts['show_rss'] === true || $atts['show_rss'] === 'on');
    $image_size = absint($atts['image_size']);
    $bookmark_args = array(
        'orderby'        => 'rand',
        'order'          => 'ASC',
        'limit'          => $atts['limit'] == 0 ? -1 : $atts['limit'],
        'category'       => $atts['category'],
        'hide_invisible' => 1,
    );
    $bookmarks = get_bookmarks($bookmark_args);
    if (empty($bookmarks)) {
        return '<p class="fabb-bookmarks-empty">暂无友情链接</p>';
    }
    $output = '<ul class="fabb-bookmarks-list">';
    foreach ($bookmarks as $bookmark) {
        $link_url    = esc_url($bookmark->link_url);
        $link_name   = esc_html($bookmark->link_name);
        $link_title  = esc_attr($bookmark->link_title ?: $bookmark->link_name);
        $link_target = esc_attr($atts['target'] ?: $bookmark->link_target);
        $link_desc   = esc_html($bookmark->link_description);
        $link_image  = esc_url($bookmark->link_image);
        $default_image = esc_url(fabb_get_setting('default_image_placeholder', 'https://via.placeholder.com/64'));
        
        $link_rss = '';
        if ($show_rss) {
            $apply_posts = get_posts(array(
                'post_type' => 'link_apply',
                'meta_key' => '_fabb_link_id',
                'meta_value' => $bookmark->link_id,
                'numberposts' => 1,
            ));
            if (!empty($apply_posts)) {
                $link_rss = get_post_meta($apply_posts[0]->ID, '_fabb_link_rss', true);
            }
        }
        // 卡片结构调整为右上角RSS
        $output .= '<li class="fabb-bookmark-item">';
        $output .= '<div class="fabb-bookmark-card" style="position:relative;width:100%;">';
        
        // 右上角RSS图标
        if ($show_rss && !empty($link_rss)) {
            $output .= '<a href="' . esc_url($link_rss) . '" target="_blank" title="订阅 ' . $link_name . ' 的RSS" class="fabb-bookmark-rss" style="position:absolute;top:8px;right:8px;color:#ff6600;text-decoration:none;font-size:16px;z-index:10;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 11a9 9 0 0 1 9 9"></path>
                    <path d="M4 4a16 16 0 0 1 16 16"></path>
                    <circle cx="5" cy="19" r="1"></circle>
                </svg>
            </a>';
        }
        $output .= '<a href="' . $link_url . '" target="' . $link_target . '" title="' . $link_title . '" rel="noopener noreferrer" style="display:block;text-decoration:none;">';
        
        if ($show_image) {
            $img_src = !empty($link_image) ? $link_image : $default_image;
            $output .= '<img src="' . $img_src . '" class="fabb-bookmark-image" style="width:' . $image_size . 'px;height:' . $image_size . 'px;border-radius:6px;flex-shrink:0;" alt="' . $link_name . '">';
        }
        $output .= '<div class="fabb-bookmark-content">';
        $output .= '<span class="fabb-bookmark-name">' . $link_name . '</span>';
        
        if ($show_description && !empty($link_desc)) {
            $output .= '<span class="fabb-bookmark-desc ' . ($desc_multi_line ? 'fabb-desc-multi-line' : 'fabb-desc-single-line') . '">' . $link_desc . '</span>';
        }
        $output .= '</div>';
        $output .= '</a>';
        $output .= '</div>';
        $output .= '</li>';
    }
    $output .= '</ul>';
    
    // 添加插件标识
    $output .= fabb_get_plugin_footer();
    
    return $output;
}
// ====================== 15. CSS样式（优化版） ======================
add_action('wp_enqueue_scripts', 'fabb_enqueue_bookmarks_styles');
add_action('admin_enqueue_scripts', 'fabb_enqueue_admin_styles');

function fabb_enqueue_admin_styles($hook) {
    if (strpos($hook, 'link_apply') === false && strpos($hook, 'fabb') === false) {
        return;
    }
    
    wp_enqueue_style('fabb-admin-style', false, array(), '3.5.0');
    $admin_css = '
    .fabb-stats-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 20px;
        margin: 20px 0;
    }
    .fabb-stat-card {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border: 1px solid #e0e0e0;
    }
    .fabb-stat-card h3 {
        margin: 0 0 10px 0;
        font-size: 14px;
        color: #666;
        font-weight: 500;
    }
    .fabb-stat-card p {
        margin: 0;
        font-size: 28px;
        font-weight: 600;
        color: #4ecdc4;
    }
    .fabb-stat-card .stat-pending { color: #ffb900; }
    .fabb-stat-card .stat-approved { color: #00b42a; }
    .fabb-stat-card .stat-categories { color: #777bb4; }
    @media (max-width: 1200px) {
        .fabb-stats-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 782px) {
        .fabb-stats-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 480px) {
        .fabb-stats-grid { grid-template-columns: 1fr; }
    }
    ';
    wp_add_inline_style('fabb-admin-style', $admin_css);
}

function fabb_enqueue_bookmarks_styles() {
    $css = '
    :root {
        --fabb-bg-color: #ffffff;
        --fabb-text-color: #333333;
        --fabb-border-color: rgba(78, 205, 196, 0.3);
        --fabb-hover-bg: rgba(78, 205, 196, 0.1);
        --fabb-desc-opacity: 0.7;
        --fabb-accent: #4ecdc4;
        --fabb-accent-hover: #3dbbb3;
        --fabb-success: #00b42a;
        --fabb-warning: #ffb900;
        --fabb-error: #d63638;
    }
    
    @media (prefers-color-scheme: dark) {
        :root {
            --fabb-bg-color: #1a1a1a;
            --fabb-text-color: #e0e0e0;
            --fabb-border-color: rgba(78, 205, 196, 0.25);
            --fabb-hover-bg: rgba(78, 205, 196, 0.15);
            --fabb-desc-opacity: 0.8;
        }
    }
    
    body.admin_color_midnight .fabb-stat-card,
    body.admin_color_sunrise .fabb-stat-card,
    body.admin_color_ocean .fabb-stat-card,
    body.admin_colorectric .fabb-stat-card,
    .dark-theme,
    body.dark-mode,
    [data-theme="dark"],
    .site-dark,
    body.theme-dark {
        --fabb-bg-color: #1a1a1a;
        --fabb-text-color: #e0e0e0;
        --fabb-border-color: rgba(78, 205, 196, 0.25);
        --fabb-hover-bg: rgba(78, 205, 196, 0.15);
        --fabb-desc-opacity: 0.8;
    }
    
    .fabb-bookmarks-list,
    ul.fabb-bookmarks-list {
        list-style: none !important;
        padding: 0 !important;
        margin: 30px 0 !important;
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)) !important;
        gap: 15px !important;
    }
    
    .fabb-bookmark-item,
    li.fabb-bookmark-item {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        list-style: none !important;
    }
    
    .fabb-bookmark-card {
        box-sizing: border-box !important;
        background: var(--fabb-bg-color) !important;
        border: 1px solid var(--fabb-border-color) !important;
        border-radius: 8px !important;
        transition: all 0.3s ease !important;
        height: 100%;
    }
    
    .fabb-bookmark-card:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 4px 12px rgba(78, 205, 196, 0.2) !important;
        border-color: var(--fabb-accent) !important;
        background: var(--fabb-hover-bg) !important;
    }
    
    .fabb-bookmark-card a {
        display: flex !important;
        align-items: center !important;
        padding: 12px 15px !important;
        text-decoration: none !important;
        gap: 12px !important;
        color: var(--fabb-text-color) !important;
        height: 100%;
    }
    
    .fabb-bookmark-image {
        display: inline-block !important;
        vertical-align: middle !important;
        object-fit: cover !important;
        flex-shrink: 0;
    }
    
    .fabb-bookmark-content {
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        overflow: hidden !important;
        line-height: 1.4 !important;
        flex: 1 !important;
        min-width: 0;
    }
    
    .fabb-bookmark-name {
        font-weight: 600 !important;
        color: inherit !important;
        font-size: 1em !important;
        display: block !important;
        word-break: break-word;
    }
    
    .fabb-bookmark-desc {
        font-size: 0.85em !important;
        color: inherit !important;
        opacity: var(--fabb-desc-opacity) !important;
        display: block !important;
        margin-top: 4px !important;
        line-height: 1.4 !important;
    }
    
    .fabb-desc-single-line {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }
    
    .fabb-desc-multi-line {
        white-space: normal !important;
        display: -webkit-box !important;
        -webkit-line-clamp: 2 !important;
        -webkit-box-orient: vertical !important;
        overflow: hidden !important;
    }
    
    .fabb-bookmarks-empty {
        color: var(--fabb-text-color) !important;
        opacity: 0.6 !important;
        padding: 20px !important;
        text-align: center;
    }
    
    .fabb-form-notice,
    .fabb-form-success,
    .fabb-form-error {
        padding: 15px !important;
        border-radius: 8px !important;
        margin-bottom: 20px !important;
    }
    
    .fabb-form-notice {
        background: var(--fabb-bg-color) !important;
        border: 1px solid var(--fabb-border-color) !important;
        color: var(--fabb-text-color) !important;
    }
    
    .fabb-form-success {
        background: rgba(0, 180, 42, 0.1) !important;
        border: 1px solid var(--fabb-success) !important;
        color: var(--fabb-success) !important;
    }
    
    .fabb-form-error {
        background: rgba(214, 54, 56, 0.1) !important;
        border: 1px solid var(--fabb-error) !important;
        color: var(--fabb-error) !important;
    }
    
    .fabb-apply-form input:focus,
    .fabb-apply-form textarea:focus,
    .fabb-modify-form input:focus,
    .fabb-modify-form textarea:focus {
        outline: none !important;
        border-color: var(--fabb-accent) !important;
        box-shadow: 0 0 0 3px rgba(78, 205, 196, 0.15) !important;
    }
    
    .fabb-form-submit button,
    .fabb-form-submit input[type="submit"],
    #fabb_send_code_btn {
        background: var(--fabb-accent) !important;
        color: #fff !important;
        padding: 12px 30px !important;
        border: none !important;
        border-radius: 8px !important;
        font-size: 16px !important;
        cursor: pointer !important;
        transition: background 0.3s ease !important;
    }
    
    .fabb-form-submit button:hover,
    .fabb-form-submit input[type="submit"]:hover,
    #fabb_send_code_btn:hover:not(:disabled) {
        background: var(--fabb-accent-hover) !important;
    }
    
    #fabb_send_code_btn:disabled {
        background: #999 !important;
        cursor: not-allowed !important;
    }
    
    .fabb-apply-form,
    .fabb-modify-form {
        background: var(--fabb-bg-color) !important;
        padding: 25px !important;
        border-radius: 12px !important;
        border: 1px solid var(--fabb-border-color) !important;
        max-width: 800px;
        margin: 0 auto;
    }
    
    .fabb-form-group {
        margin-bottom: 20px !important;
    }
    
    .fabb-form-group label {
        display: block !important;
        margin-bottom: 8px !important;
        font-weight: 600 !important;
        color: var(--fabb-text-color) !important;
    }
    
    .fabb-form-group input,
    .fabb-form-group textarea,
    .fabb-form-group select {
        width: 100% !important;
        padding: 12px !important;
        border: 1px solid var(--fabb-border-color) !important;
        border-radius: 8px !important;
        box-sizing: border-box !important;
        background: var(--fabb-bg-color) !important;
        color: var(--fabb-text-color) !important;
        font-size: 14px !important;
    }
    
    .fabb-form-group textarea {
        resize: vertical !important;
    }
    
    .fabb-form-group .description,
    .fabb-form-group p.description {
        color: var(--fabb-text-color) !important;
        opacity: 0.7 !important;
        margin-top: 5px !important;
        font-size: 13px !important;
    }
    
    .fabb-bookmark-rss {
        position: absolute !important;
        top: 8px !important;
        right: 8px !important;
        color: #ff6600 !important;
        text-decoration: none !important;
        font-size: 16px !important;
        z-index: 10 !important;
        padding: 4px !important;
        background: rgba(255,255,255,0.9) !important;
        border-radius: 4px !important;
        transition: all 0.2s ease !important;
    }
    
    .dark-theme .fabb-bookmark-rss,
    body.dark-mode .fabb-bookmark-rss,
    [data-theme="dark"] .fabb-bookmark-rss {
        background: rgba(0,0,0,0.5) !important;
    }
    
    .fabb-bookmark-rss:hover {
        color: #ff8800 !important;
        transform: scale(1.1) !important;
    }
    
    .fabb-bookmark-rss svg {
        display: block !important;
    }
    
    .fabb-plugin-footer {
        text-align: right !important;
        margin-top: 20px !important;
        font-size: 12px !important;
        opacity: 0.6 !important;
        color: var(--fabb-text-color) !important;
    }
    
    .fabb-plugin-footer a {
        color: var(--fabb-accent) !important;
        text-decoration: none !important;
        transition: opacity 0.2s ease !important;
    }
    
    .fabb-plugin-footer a:hover {
        opacity: 0.8 !important;
    }
    
    @media (max-width: 600px) {
        .fabb-bookmarks-list {
            grid-template-columns: 1fr !important;
        }
        
        .fabb-bookmark-card a {
            padding: 15px !important;
        }
        
        .fabb-apply-form,
        .fabb-modify-form {
            padding: 15px !important;
        }
    }
    ';
    
    $custom_css = fabb_get_setting('custom_css', '');
    if (!empty($custom_css)) {
        $css .= "\n\n/* Custom CSS */\n" . $custom_css;
    }
    
    wp_add_inline_style('wp-block-library', $css);
}
// ====================== 16. 删除申请时同步删除链接 ======================
add_action('before_delete_post', 'fabb_before_delete_apply_cleanup', 10, 1);
function fabb_before_delete_apply_cleanup($post_id) {
    if (get_post_type($post_id) !== 'link_apply') {
        return;
    }
    $link_id = get_post_meta($post_id, '_fabb_link_id', true);
    if (!empty($link_id)) {
        wp_delete_link($link_id);
    }
}
// ====================== 17. 自动清理过期申请定时任务 ======================
add_action('fabb_cleanup_expired_applications_hook', 'fabb_cleanup_expired_applications');
function fabb_cleanup_expired_applications() {
    $expire_days = fabb_get_setting('expire_days', 30);
    $auto_clean = fabb_get_setting('auto_clean_expired', 'on') === 'on';
    
    if (!$auto_clean) {
        return;
    }
    
    $expire_time = time() - ($expire_days * 86400);
    
    $expired_posts = get_posts(array(
        'post_type' => 'link_apply',
        'post_status' => 'any',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_fabb_apply_status',
                'value' => array('pending', 'rejected'),
                'compare' => 'IN',
            ),
            array(
                'key' => 'post_date',
                'value' => date('Y-m-d H:i:s', $expire_time),
                'compare' => '<',
                'type' => 'DATETIME',
            ),
        ),
        'numberposts' => -1,
        'fields' => 'ids',
    ));
    
    foreach ($expired_posts as $post_id) {
        wp_delete_post($post_id, true);
    }
}
// ====================== 18. 匿名统计功能 ======================
// 注册每日统计定时任务
function fabb_schedule_stats_task() {
    if (!wp_next_scheduled('fabb_daily_stats_hook')) {
        wp_schedule_event(time(), 'daily', 'fabb_daily_stats_hook');
    }
}
function fabb_clear_stats_task() {
    wp_clear_scheduled_hook('fabb_daily_stats_hook');
}
add_action('fabb_daily_stats_hook', 'fabb_send_anonymous_stats');
function fabb_send_anonymous_stats() {
    if (fabb_get_setting('anonymous_stats', 'on') !== 'on') {
        return;
    }
    
    // 生成唯一匿名ID（不可逆，无法识别具体站点）
    $site_id = get_option('fabb_anonymous_site_id');
    if (!$site_id) {
        $site_id = hash('sha256', uniqid(mt_rand(), true));
        update_option('fabb_anonymous_site_id', $site_id);
    }
    
    $plugin_version = get_file_data(__FILE__, array('Version' => 'Version'))['Version'];
    
    // 发送心跳包（仅包含匿名ID和插件版本）
    wp_remote_post(FABB_STATS_API_URL . '/heartbeat', array(
        'body' => json_encode(array(
            'site_id' => $site_id,
            'version' => $plugin_version
        )),
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 5,
        'blocking' => false, // 非阻塞，不影响页面加载
        'sslverify' => true
    ));
}
// ====================== 19. 通用函数 ======================
// 插件底部标识
function fabb_get_plugin_footer() {
    return '<div class="fabb-plugin-footer">Powered by <a href="https://github.com/liseezn/see-friends" target="_blank" rel="noopener noreferrer">See~Friends</a></div>';
}
// 获取并解析邮件模板
function fabb_get_email_template($template_name, $variables = array()) {
    $template = fabb_get_setting('email_template_' . $template_name, '');
    if (empty($template)) {
        return '';
    }
    // 替换变量
    foreach ($variables as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }
    return $template;
}
// 发送HTML邮件
function fabb_send_html_email($to, $subject, $message) {
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
    );
    return wp_mail($to, $subject, $message, $headers);
}
// 修复：手动编辑申请时同步更新链接
add_action('save_post', 'fabb_sync_link_on_save', 10, 2);
function fabb_sync_link_on_save($post_id, $post) {
    if ($post->post_type !== 'link_apply' || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    $status = get_post_meta($post_id, '_fabb_apply_status', true);
    if ($status !== 'approved') {
        return;
    }
    $link_id = get_post_meta($post_id, '_fabb_link_id', true);
    if (empty($link_id)) {
        return;
    }
    // 同步更新链接信息
    $link_data = array(
        'link_id' => $link_id,
        'link_name' => $post->post_title,
        'link_description' => $post->post_content,
        'link_url' => get_post_meta($post_id, '_fabb_link_url', true),
        'link_image' => get_post_meta($post_id, '_fabb_link_image', true),
    );
    wp_update_link($link_data);
}
// ====================== 20. 申请编辑页面元数据框 ======================
add_action('add_meta_boxes', 'fabb_add_apply_meta_boxes');
function fabb_add_apply_meta_boxes() {
    add_meta_box(
        'fabb_apply_details',
        '友链详细信息',
        'fabb_render_apply_meta_box',
        'link_apply',
        'normal',
        'high'
    );
    
    add_meta_box(
        'fabb_backlink_status',
        '反链检测状态',
        'fabb_render_backlink_meta_box',
        'link_apply',
        'side',
        'default'
    );
}
function fabb_render_apply_meta_box($post) {
    // 获取元数据
    $link_url = get_post_meta($post->ID, '_fabb_link_url', true);
    $link_image = get_post_meta($post->ID, '_fabb_link_image', true);
    $link_rss = get_post_meta($post->ID, '_fabb_link_rss', true);
    $apply_email = get_post_meta($post->ID, '_fabb_apply_email', true);
    $apply_status = get_post_meta($post->ID, '_fabb_apply_status', true) ?: 'pending';
    
    // 非ce验证
    wp_nonce_field('fabb_save_apply_meta', 'fabb_apply_meta_nonce');
    ?>
    <table class="form-table">
        <tr>
            <th scope="row"><label for="fabb_link_url">网站链接地址</label></th>
            <td>
                <input type="url" name="fabb_link_url" id="fabb_link_url" value="<?php echo esc_attr($link_url); ?>" class="regular-text">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fabb_link_image">网站图标地址</label></th>
            <td>
                <input type="url" name="fabb_link_image" id="fabb_link_image" value="<?php echo esc_attr($link_image); ?>" class="regular-text">
                <?php if (!empty($link_image)): ?>
                    <br><img src="<?php echo esc_url($link_image); ?>" style="width:32px;height:32px;border-radius:4px;margin-top:8px;" alt="网站图标">
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fabb_link_rss">RSS订阅地址</label></th>
            <td>
                <input type="url" name="fabb_link_rss" id="fabb_link_rss" value="<?php echo esc_attr($link_rss); ?>" class="regular-text">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fabb_apply_email">联系邮箱</label></th>
            <td>
                <input type="email" name="fabb_apply_email" id="fabb_apply_email" value="<?php echo esc_attr($apply_email); ?>" class="regular-text">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fabb_apply_status">审核状态</label></th>
            <td>
                <select name="fabb_apply_status" id="fabb_apply_status" class="regular-text">
                    <option value="pending" <?php selected($apply_status, 'pending'); ?>>待审核</option>
                    <option value="approved" <?php selected($apply_status, 'approved'); ?>>已通过</option>
                    <option value="rejected" <?php selected($apply_status, 'rejected'); ?>>已拒绝</option>
                </select>
            </td>
        </tr>
    </table>
    <?php
}
function fabb_render_backlink_meta_box($post) {
    $backlink_status = get_post_meta($post->ID, '_fabb_backlink_status', true);
    $backlink_check_time = get_post_meta($post->ID, '_fabb_backlink_check_time', true);
    $last_alert_time = get_post_meta($post->ID, '_fabb_last_backlink_alert_time', true);
    
    ?>
    <p>
        <strong>当前状态：</strong>
        <?php
        if ($backlink_status === 'has') {
            echo '<span style="color:#00b42a;">正常</span>';
        } elseif ($backlink_status === 'no') {
            echo '<span style="color:#d63638;">无反链</span>';
        } elseif ($backlink_status === 'whitelisted') {
            echo '<span style="color:#777bb4;">白名单</span>';
        } else {
            echo '<span style="color:#999;">未检测</span>';
        }
        ?>
    </p>
    <p>
        <strong>最后检测时间：</strong>
        <?php echo $backlink_check_time ? date('Y-m-d H:i:s', $backlink_check_time) : '从未检测'; ?>
    </p>
    <p>
        <strong>最后提醒时间：</strong>
        <?php echo $last_alert_time ? date('Y-m-d H:i:s', $last_alert_time) : '从未提醒'; ?>
    </p>
    <p>
        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=check_backlink&post=' . $post->ID), 'fabb_check_backlink_' . $post->ID); ?>" class="button button-secondary">立即检测反链</a>
    </p>
    <?php
}
// 保存申请编辑页面的元数据
add_action('save_post', 'fabb_save_apply_meta', 10, 2);
function fabb_save_apply_meta($post_id, $post) {
    if ($post->post_type !== 'link_apply' || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    
    if (!isset($_POST['fabb_apply_meta_nonce']) || !wp_verify_nonce($_POST['fabb_apply_meta_nonce'], 'fabb_save_apply_meta')) {
        return;
    }
    
    if (!current_user_can('manage_links', $post_id)) {
        return;
    }
    
    // 保存元数据
    update_post_meta($post_id, '_fabb_link_url', sanitize_url($_POST['fabb_link_url']));
    update_post_meta($post_id, '_fabb_link_image', sanitize_url($_POST['fabb_link_image']));
    update_post_meta($post_id, '_fabb_link_rss', sanitize_url($_POST['fabb_link_rss']));
    update_post_meta($post_id, '_fabb_apply_email', sanitize_email($_POST['fabb_apply_email']));
    
    // 处理审核状态变更
    $old_status = get_post_meta($post_id, '_fabb_apply_status', true);
    $new_status = sanitize_text_field($_POST['fabb_apply_status']);
    
    if ($old_status !== $new_status) {
        update_post_meta($post_id, '_fabb_apply_status', $new_status);
        
        $link_id = get_post_meta($post_id, '_fabb_link_id', true);
        
        if ($new_status === 'approved') {
            // 创建或更新链接
            $link_data = array(
                'link_name' => $post->post_title,
                'link_url' => sanitize_url($_POST['fabb_link_url']),
                'link_description' => $post->post_content,
                'link_image' => sanitize_url($_POST['fabb_link_image']),
                'link_target' => '_blank',
                'link_visible' => 'Y',
            );
            
            if (empty($link_id)) {
                $link_id = wp_insert_link($link_data);
                if ($link_id) {
                    update_post_meta($post_id, '_fabb_link_id', $link_id);
                }
            } else {
                $link_data['link_id'] = $link_id;
                wp_update_link($link_data);
            }
        } elseif ($new_status === 'rejected') {
            // 删除链接
            if (!empty($link_id)) {
                wp_delete_link($link_id);
                delete_post_meta($post_id, '_fabb_link_id');
            }
        }
    }
}
// ====================== 21. 更新包SHA256安全验证 ======================
/**
 * 插件更新包SHA256完整性校验
 * 配置从info.json读取，可全局开关，在解压安装前强制验证
 */
add_filter('upgrader_pre_install', 'see_friends_verify_update_sha256', 10, 2);
function see_friends_verify_update_sha256($reply, $package) {
    // 只验证本插件的更新包
    $plugin_basename = plugin_basename(__FILE__);
    $plugin_slug = 'see-friends';
    
    // 检查是否是本插件的更新
    if (!isset($package['source']) || strpos($package['source'], $plugin_slug) === false) {
        return $reply;
    }

    // 从info.json读取验证配置
    $config = see_friends_get_sha256_config();
    
    // 如果验证功能已关闭，直接跳过
    if (!$config['enabled']) {
        return $reply;
    }

    // 获取下载的zip包路径
    $zip_file = $package['source'];
    if (!file_exists($zip_file)) {
        return new WP_Error(
            'see_friends_update_file_missing',
            __('更新包下载失败，文件不存在', 'see-friends')
        );
    }

    // 计算本地下载文件的SHA256哈希
    $local_sha256 = hash_file('sha256', $zip_file);
    if (!$local_sha256) {
        return new WP_Error(
            'see_friends_hash_calc_failed',
            __('无法计算更新包哈希值，更新终止', 'see-friends')
        );
    }

    // 检查官方哈希是否存在
    if (empty($config['hash']) || strlen($config['hash']) !== 64) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>See~Friends 更新警告：</strong>';
            echo 'info.json中未找到有效的SHA256校验值，跳过完整性验证。';
            echo '</p></div>';
        });
        return $reply;
    }

    // 安全哈希比对（防止时序攻击）
    if (hash_equals(strtolower($config['hash']), strtolower($local_sha256))) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>';
            echo '<strong>See~Friends 更新验证：</strong>';
            echo '更新包SHA256校验通过，正在安全安装...';
            echo '</p></div>';
        });
        return $reply;
    } else {
        // 验证失败，立即删除可疑文件并终止更新
        @unlink($zip_file);
        
        return new WP_Error(
            'see_friends_sha256_mismatch',
            sprintf(
                __('更新包SHA256校验失败！文件可能已被篡改，更新已强制终止。<br>本地哈希：%s<br>官方哈希：%s', 'see-friends'),
                substr($local_sha256, 0, 16) . '...',
                substr($config['hash'], 0, 16) . '...'
            )
        );
    }
}

/**
 * 从info.json读取SHA256验证配置
 * 带缓存机制，避免重复读取文件
 */
function see_friends_get_sha256_config() {
    $transient_key = 'see_friends_sha256_config';
    $cache_time = 86400; // 缓存24小时
    
    // 先从缓存获取
    $cached_config = get_transient($transient_key);
    if ($cached_config !== false) {
        return $cached_config;
    }

    // 默认配置（验证关闭）
    $default_config = array(
        'enabled' => false,
        'hash' => ''
    );

    // 读取info.json文件
    $info_file = plugin_dir_path(__FILE__) . 'info.json';
    if (!file_exists($info_file)) {
        return $default_config;
    }

    $info_content = file_get_contents($info_file);
    if (!$info_content) {
        return $default_config;
    }

    $info_data = json_decode($info_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $default_config;
    }

    // 提取验证配置
    $config = isset($info_data['sha256_verify']) ? $info_data['sha256_verify'] : $default_config;
    
    // 缓存配置
    set_transient($transient_key, $config, $cache_time);
    
    return $config;
}

/**
 * 插件更新完成后清除配置缓存
 * 确保下次更新使用新版本的info.json配置
 */
add_action('upgrader_process_complete', 'see_friends_clear_sha256_cache', 10, 2);
function see_friends_clear_sha256_cache($upgrader, $options) {
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        if (isset($options['plugins']) && in_array(plugin_basename(__FILE__), $options['plugins'])) {
            delete_transient('see_friends_sha256_config');
        }
    }
}

// ====================== 22. 插件激活/停用/卸载处理 ======================
register_activation_hook(__FILE__, 'fabb_plugin_activation');
function fabb_plugin_activation() {
    fabb_register_apply_post_type();
    flush_rewrite_rules();
    fabb_plugin_init_default_settings();
    // 注册过期清理定时任务
    if (!wp_next_scheduled('fabb_cleanup_expired_applications_hook')) {
        wp_schedule_event(time(), 'daily', 'fabb_cleanup_expired_applications_hook');
    }
    // 注册反链检测定时任务
    if (fabb_get_setting('auto_check_backlink', 'on') === 'on') {
        $frequency = fabb_get_setting('check_frequency', 'daily');
        if (!wp_next_scheduled('fabb_auto_check_backlink_hook')) {
            wp_schedule_event(time(), $frequency, 'fabb_auto_check_backlink_hook');
        }
    }
    // 注册自动通过定时任务
    if (!wp_next_scheduled('fabb_auto_approve_applications_hook')) {
        wp_schedule_event(strtotime('tomorrow 02:00'), 'daily', 'fabb_auto_approve_applications_hook');
    }
    // 注册RSS自动更新定时任务
    if (fabb_get_setting('rss_auto_update', 'on') === 'on') {
        $frequency = fabb_get_setting('rss_update_frequency', 'daily');
        if (!wp_next_scheduled('fabb_rss_auto_update_hook')) {
            wp_schedule_event(time(), $frequency, 'fabb_rss_auto_update_hook');
        }
    }
    // 注册统计定时任务
    if (fabb_get_setting('anonymous_stats', 'on') === 'on') {
        fabb_schedule_stats_task();
    }
}
register_deactivation_hook(__FILE__, 'fabb_plugin_deactivation');
function fabb_plugin_deactivation() {
    flush_rewrite_rules();
    // 清除所有定时任务
    wp_clear_scheduled_hook('fabb_cleanup_expired_applications_hook');
    wp_clear_scheduled_hook('fabb_auto_check_backlink_hook');
    wp_clear_scheduled_hook('fabb_auto_approve_applications_hook');
    wp_clear_scheduled_hook('fabb_rss_auto_update_hook');
    fabb_clear_stats_task();
}
// 卸载插件时清理数据
register_uninstall_hook(__FILE__, 'fabb_plugin_uninstall');
function fabb_plugin_uninstall() {
    // 清除所有定时任务
    wp_clear_scheduled_hook('fabb_cleanup_expired_applications_hook');
    wp_clear_scheduled_hook('fabb_auto_check_backlink_hook');
    wp_clear_scheduled_hook('fabb_auto_approve_applications_hook');
    wp_clear_scheduled_hook('fabb_rss_auto_update_hook');
    wp_clear_scheduled_hook('fabb_daily_stats_hook');
    
    // 检查是否需要删除数据
    $uninstall_delete_data = get_option('fabb_settings')['uninstall_delete_data'] ?? 'off';
    
    if ($uninstall_delete_data === 'on') {
        // 删除所有申请记录
        $apply_posts = get_posts(array(
            'post_type' => 'link_apply',
            'numberposts' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
        ));
        
        foreach ($apply_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        
        // 删除选项表中的插件设置
        delete_option('fabb_settings');
        delete_option('fabb_anonymous_site_id');
    } else {
        // 仅删除匿名ID，保留其他设置
        delete_option('fabb_anonymous_site_id');
    }
    
    // 清除相关缓存
    wp_cache_delete('fabb_settings', 'options');
    wp_cache_delete('alloptions', 'options');
    
    // 刷新重写规则
    flush_rewrite_rules();
}
?>
