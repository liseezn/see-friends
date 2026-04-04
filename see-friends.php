<?php
/**
 * Plugin Name: SEE~Friends-友情链接管理
 * Plugin URI: https://github.com/liseezn/see-friends/
 * Description: 一个友情链接插件，支持随机排序、图标+描述显示、前端申请、后台审核、智能反链检测.
 * Version: 3.1.0
 * Author: liseezn
 * Author URI: https://liseezn.top/
 * License: GPLv3 or later
 * Text Domain: fixed-advanced-bookmarks
 */

// 禁止直接访问，安全防护
if (!defined('ABSPATH')) {
    exit;
}

// ====================== 0. 初始化插件配置 ======================
// 插件激活时初始化默认配置
register_activation_hook(__FILE__, 'fabb_plugin_init_default_settings');
function fabb_plugin_init_default_settings() {
    $default_settings = array(
        // 基础设置
        'expire_days' => 30,
        'auto_clean_expired' => 'on',
        // 反链检测设置
        'auto_check_backlink' => 'on',
        'check_frequency' => 'daily',
        'alert_email' => get_option('admin_email'),
        'alert_duplicate_days' => 7,
        // 邮件通知设置
        'email_approved_notice' => 'on',
        'email_rejected_notice' => 'on',
        'email_admin_notice' => 'on',
        // 前端设置
        'apply_form_enable' => 'on',
        'default_show_image' => 'on',
        'default_show_desc' => 'on',
        'default_image_size' => 40,
    );
    // 不存在配置时才写入默认值，避免覆盖用户已有配置
    if (!get_option('fabb_settings')) {
        update_option('fabb_settings', $default_settings);
    }
    // 注册定时任务
    fabb_plugin_activation();
}

// 获取配置的辅助函数
function fabb_get_setting($key, $default = false) {
    $settings = get_option('fabb_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
}

// ====================== 1. 核心基础功能 ======================
// 开启WordPress原生链接管理器
add_filter('pre_option_link_manager_enabled', '__return_true');
// 让文章、页面、小工具都支持短代码解析
add_filter('widget_text', 'do_shortcode');
add_filter('the_content', 'do_shortcode', 11);

// ====================== 2. 申请数据存储：自定义文章类型 ======================
add_action('init', 'fabb_register_apply_post_type');
function fabb_register_apply_post_type() {
    $args = array(
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => 'link-manager.php',
        'show_in_nav_menus'   => false,
        'show_in_admin_bar'   => false,
        'exclude_from_search' => true,
        'capability_type'     => 'post',
        'capabilities'        => array(
            'create_posts' => 'manage_links',
            'edit_post'    => 'manage_links',
            'delete_post'  => 'manage_links',
            'edit_posts'   => 'manage_links',
            'delete_posts' => 'manage_links',
            'read_post'    => 'manage_links',
            'read_private_posts' => 'manage_links',
        ),
        'map_meta_cap'        => true,
        'hierarchical'        => false,
        'has_archive'         => false,
        'rewrite'             => false,
        'menu_position'       => 20,
        'supports'            => array('title', 'editor'),
        'labels'              => array(
            'name'               => '链接申请',
            'singular_name'      => '链接申请',
            'menu_name'          => '链接申请',
            'all_items'          => '所有申请',
            'add_new'            => '新增申请',
            'add_new_item'       => '新增申请',
            'edit_item'          => '编辑申请',
            'new_item'           => '新申请',
            'view_item'          => '查看申请',
            'search_items'       => '搜索申请',
            'not_found'          => '未找到申请',
            'not_found_in_trash' => '回收站中未找到申请',
        ),
    );
    register_post_type('link_apply', $args);
}

// ====================== 3. 后台配置面板（新增同步功能） ======================
// 注册配置菜单
add_action('admin_menu', 'fabb_register_settings_menu');
function fabb_register_settings_menu() {
    // 挂载到「链接」菜单下
    add_submenu_page(
        'link-manager.php',
        '友链插件设置',
        '友链设置',
        'manage_links',
        'fabb-settings',
        'fabb_render_settings_page'
    );
}

// 渲染配置页面
function fabb_render_settings_page() {
    // 权限校验
    if (!current_user_can('manage_links')) {
        wp_die('您没有权限访问此页面');
    }

    // 保存配置
    if (isset($_POST['fabb_settings_save']) && wp_verify_nonce($_POST['fabb_settings_nonce'], 'fabb_save_settings')) {
        $new_settings = array();
        // 基础设置
        $new_settings['expire_days'] = absint($_POST['expire_days']);
        $new_settings['auto_clean_expired'] = isset($_POST['auto_clean_expired']) ? 'on' : 'off';
        // 反链检测设置
        $new_settings['auto_check_backlink'] = isset($_POST['auto_check_backlink']) ? 'on' : 'off';
        $new_settings['check_frequency'] = sanitize_text_field($_POST['check_frequency']);
        $new_settings['alert_email'] = sanitize_email($_POST['alert_email']);
        $new_settings['alert_duplicate_days'] = absint($_POST['alert_duplicate_days']);
        // 邮件通知设置
        $new_settings['email_approved_notice'] = isset($_POST['email_approved_notice']) ? 'on' : 'off';
        $new_settings['email_rejected_notice'] = isset($_POST['email_rejected_notice']) ? 'on' : 'off';
        $new_settings['email_admin_notice'] = isset($_POST['email_admin_notice']) ? 'on' : 'off';
        // 前端设置
        $new_settings['apply_form_enable'] = isset($_POST['apply_form_enable']) ? 'on' : 'off';
        $new_settings['default_show_image'] = isset($_POST['default_show_image']) ? 'on' : 'off';
        $new_settings['default_show_desc'] = isset($_POST['default_show_desc']) ? 'on' : 'off';
        $new_settings['default_image_size'] = absint($_POST['default_image_size']);

        // 保存配置
        update_option('fabb_settings', $new_settings);

        // 重新调度定时任务
        wp_clear_scheduled_hook('fabb_auto_check_backlink_hook');
        if ($new_settings['auto_check_backlink'] === 'on') {
            wp_schedule_event(time(), $new_settings['check_frequency'], 'fabb_auto_check_backlink_hook');
        }

        echo '<div class="notice notice-success is-dismissible"><p>设置保存成功！</p></div>';
    }

    // 手动批量检测处理（修复致命错误）
    if (isset($_POST['fabb_batch_check']) && wp_verify_nonce($_POST['fabb_batch_nonce'], 'fabb_batch_check_action')) {
        $check_result = fabb_batch_check_all_backlinks();
        if (is_wp_error($check_result)) {
            echo '<div class="notice notice-error is-dismissible"><p>检测失败：' . $check_result->get_error_message() . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>批量检测完成！共检测 ' . $check_result['total'] . ' 个友链，正常 ' . ($check_result['total'] - $check_result['invalid']) . ' 个，失效 ' . $check_result['invalid'] . ' 个</p></div>';
        }
    }

    // 新增：原生链接同步到插件申请列表
    if (isset($_POST['fabb_sync_links']) && wp_verify_nonce($_POST['fabb_sync_nonce'], 'fabb_sync_links_action')) {
        $sync_result = fabb_sync_bookmarks_to_apply();
        echo '<div class="notice notice-success is-dismissible"><p>同步完成！共同步 ' . $sync_result['total'] . ' 个链接，新增 ' . $sync_result['added'] . ' 个，已存在 ' . $sync_result['exists'] . ' 个</p></div>';
    }

    // 获取当前配置
    $settings = get_option('fabb_settings');
    ?>
    <div class="wrap">
        <h1>SEE~Friends 友链插件设置</h1>
        <hr>

        <!-- 选项卡导航 -->
        <h2 class="nav-tab-wrapper">
            <a href="#tab-base" class="nav-tab nav-tab-active">基础设置</a>
            <a href="#tab-check" class="nav-tab">反链检测设置</a>
            <a href="#tab-email" class="nav-tab">邮件通知设置</a>
            <a href="#tab-front" class="nav-tab">前端显示设置</a>
            <a href="#tab-sync" class="nav-tab">数据同步</a>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field('fabb_save_settings', 'fabb_settings_nonce'); ?>

            <!-- 基础设置选项卡 -->
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
                </table>
            </div>

            <!-- 反链检测设置选项卡 -->
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
                            <span class="description">天，同一失效友链在此间隔内只发送一次提醒，避免邮件轰炸</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">手动批量检测</th>
                        <td>
                            <?php wp_nonce_field('fabb_batch_check_action', 'fabb_batch_nonce'); ?>
                            <button type="submit" name="fabb_batch_check" class="button button-primary" onclick="return confirm('确定要立即检测所有已上线友链吗？\n\n检测过程可能需要几秒到几十秒，请勿关闭页面')">立即检测所有已上线友链</button>
                            <p class="description">点击后将立即检测所有已通过的友链，无需等待定时任务，已做防超时优化</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 邮件通知设置选项卡 -->
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
                </table>
            </div>

            <!-- 前端显示设置选项卡 -->
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
                        <th scope="row"><label for="default_show_image">默认显示网站图标</label></th>
                        <td>
                            <input type="checkbox" name="default_show_image" id="default_show_image" <?php checked($settings['default_show_image'], 'on'); ?>>
                            <label for="default_show_image">友链列表默认显示网站图标（短代码可单独覆盖）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_show_desc">默认显示网站描述</label></th>
                        <td>
                            <input type="checkbox" name="default_show_desc" id="default_show_desc" <?php checked($settings['default_show_desc'], 'on'); ?>>
                            <label for="default_show_desc">友链列表默认显示网站描述（短代码可单独覆盖）</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_image_size">默认图标尺寸</label></th>
                        <td>
                            <input type="number" name="default_image_size" id="default_image_size" value="<?php echo esc_attr($settings['default_image_size']); ?>" min="16" max="128" class="small-text">
                            <span class="description">px，友链列表默认图标尺寸（短代码可单独覆盖）</span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 新增：数据同步选项卡 -->
            <div id="tab-sync" class="tab-content" style="margin-top:20px;display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row">同步原生链接到插件</th>
                        <td>
                            <?php wp_nonce_field('fabb_sync_links_action', 'fabb_sync_nonce'); ?>
                            <button type="submit" name="fabb_sync_links" class="button button-primary" onclick="return confirm('确定要同步原生链接管理器里的所有链接到插件申请列表吗？\n\n已存在的链接不会重复创建，仅新增缺失的')">一键同步所有链接</button>
                            <p class="description">将「链接」菜单里的所有已上线友链，同步到插件的申请列表，状态设为「已通过」，统一管理反链检测</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">同步说明</th>
                        <td>
                            <ul style="margin:0;padding-left:20px;list-style-type:disc;">
                                <li>仅同步插件中不存在的链接，不会覆盖、修改已有的申请数据</li>
                                <li>同步后的链接状态为「已通过」，自动关联原生链接ID，支持反链检测</li>
                                <li>重复点击不会重复创建，可放心使用</li>
                            </ul>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="fabb_settings_save" class="button button-primary">保存设置</button>
            </p>
        </form>
    </div>

    <!-- 选项卡切换JS -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                // 移除所有激活状态
                tabs.forEach(t => t.classList.remove('nav-tab-active'));
                tabContents.forEach(c => c.style.display = 'none');
                // 激活当前选项卡
                this.classList.add('nav-tab-active');
                const targetTab = this.getAttribute('href');
                document.querySelector(targetTab).style.display = 'block';
            });
        });
    });
    </script>
    <?php
}

// ====================== 4. 新增：原生链接同步功能 ======================
function fabb_sync_bookmarks_to_apply() {
    // 权限校验
    if (!current_user_can('manage_links')) {
        return new WP_Error('permission_denied', '您没有权限执行此操作');
    }
    // Nonce校验
    if (!isset($_POST['fabb_sync_nonce']) || !wp_verify_nonce($_POST['fabb_sync_nonce'], 'fabb_sync_links_action')) {
        return new WP_Error('nonce_error', '安全验证失败，请刷新重试');
    }

    // 获取所有原生链接
    $bookmarks = get_bookmarks(array('limit' => -1, 'hide_invisible' => 0));
    $total = count($bookmarks);
    $added = 0;
    $exists = 0;

    foreach ($bookmarks as $bookmark) {
        $link_id = $bookmark->link_id;
        $link_url = $bookmark->link_url;

        // 检查是否已经同步过
        $existing_posts = get_posts(array(
            'post_type' => 'link_apply',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_fabb_link_id',
                    'value' => $link_id,
                    'compare' => '=',
                ),
                array(
                    'key' => '_fabb_link_url',
                    'value' => $link_url,
                    'compare' => '=',
                ),
            ),
            'posts_per_page' => 1,
            'fields' => 'ids',
        ));

        // 已存在，跳过
        if (!empty($existing_posts)) {
            $exists++;
            continue;
        }

        // 创建申请记录
        $post_data = array(
            'post_title' => $bookmark->link_name,
            'post_content' => $bookmark->link_description,
            'post_type' => 'link_apply',
            'post_status' => 'publish',
        );
        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            // 保存元数据
            update_post_meta($post_id, '_fabb_link_url', $bookmark->link_url);
            update_post_meta($post_id, '_fabb_link_image', $bookmark->link_image);
            update_post_meta($post_id, '_fabb_apply_status', 'approved');
            update_post_meta($post_id, '_fabb_link_id', $link_id);
            update_post_meta($post_id, '_fabb_apply_email', '');
            $added++;
        }
    }

    return array(
        'total' => $total,
        'added' => $added,
        'exists' => $exists,
    );
}

// ====================== 5. 智能反链检测核心函数（修复致命错误） ======================
function fabb_check_backlink($target_url) {
    if (empty($target_url)) return false;

    // 本站核心信息
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    $site_host_clean = preg_replace('/^www\./', '', $site_host);
    $site_host_www = 'www.' . $site_host_clean;
    $site_url_full = home_url();
    $site_name = get_bloginfo('name');

    // 全量友链关键词库
    $friend_link_keywords = [
        '友情链接', '友链', '友人帐', '合作伙伴', '推荐网站', '友情', '友站',
        '友邻', '小伙伴', '站点推荐', '博客邻居', '友情互链', '交换链接',
        'friend', 'friends', 'friendly', 'link', 'links', 'flink',
        'blogroll', 'partner', 'partners', 'exchange', 'site', 'sites',
        'follow', 'following', 'community'
    ];

    // 请求参数（防超时、防拦截）
    $request_args = [
        'timeout' => 10,
        'sslverify' => false,
        'redirection' => 3,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'headers' => [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
        ]
    ];

    // 第一层：请求目标主页，增加错误捕获
    $response = wp_remote_get($target_url, $request_args);
    if (is_wp_error($response)) return false;
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) return false;

    // 第一层检测：主页直接有本站友链
    $host_pattern = '/\b' . preg_quote($site_host_clean, '/') . '\b/i';
    if (
        preg_match($host_pattern, $body) ||
        stripos($body, $site_host_www) !== false ||
        stripos($body, $site_url_full) !== false ||
        (mb_strlen($site_name) >= 2 && stripos($body, $site_name) !== false)
    ) {
        return true;
    }

    // 提取页面所有链接+文本，增加过滤
    $base_url = trailingslashit($target_url);
    $candidate_links = [];
    $all_links = [];
    preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $body, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $index => $href) {
            $href = trim($href);
            $link_text = trim(strip_tags($matches[2][$index]));
            
            // 过滤无效链接
            if (
                empty($href) ||
                strpos($href, 'javascript:') === 0 ||
                strpos($href, 'mailto:') === 0 ||
                strpos($href, 'tel:') === 0 ||
                strpos($href, '#') === 0 ||
                strpos($href, '?') === 0
            ) continue;

            // 相对地址转绝对地址
            if (strpos($href, 'http') !== 0) {
                $href = $base_url . ltrim($href, '/');
            }

            // 只保留同域名链接，避免跨站请求
            $link_host = parse_url($href, PHP_URL_HOST);
            $target_host = parse_url($target_url, PHP_URL_HOST);
            if (empty($link_host) || empty($target_host)) continue;
            if (preg_replace('/^www\./', '', $link_host) !== preg_replace('/^www\./', '', $target_host)) continue;

            // 去重
            $href_normalized = trailingslashit(strtolower($href));
            if (in_array($href_normalized, $all_links)) continue;
            $all_links[] = $href_normalized;

            // 智能判断友链页候选
            $has_keyword_in_url = false;
            foreach ($friend_link_keywords as $kw) {
                if (stripos($href, $kw) !== false) {
                    $has_keyword_in_url = true;
                    break;
                }
            }
            $has_keyword_in_text = false;
            if (!empty($link_text)) {
                foreach ($friend_link_keywords as $kw) {
                    if (mb_stripos($link_text, $kw) !== false) {
                        $has_keyword_in_text = true;
                        break;
                    }
                }
            }

            if ($has_keyword_in_url || $has_keyword_in_text) {
                $candidate_links[] = $href;
            }
        }
    }

    // 第二层检测：遍历候选友链页，增加错误捕获，不中断循环
    if (!empty($candidate_links)) {
        $candidate_links = array_slice($candidate_links, 0, 5); // 最多检测5个，避免超时
        foreach ($candidate_links as $flink_url) {
            usleep(200000); // 间隔200ms，避免被封
            $flink_response = wp_remote_get($flink_url, $request_args);
            if (is_wp_error($flink_response)) continue; // 出错跳过，不中断
            $flink_body = wp_remote_retrieve_body($flink_response);
            if (empty($flink_body)) continue;

            if (
                preg_match($host_pattern, $flink_body) ||
                stripos($flink_body, $site_host_www) !== false ||
                stripos($flink_body, $site_url_full) !== false ||
                (mb_strlen($site_name) >= 2 && stripos($flink_body, $site_name) !== false)
            ) {
                return true;
            }
        }
    }

    // 所有检测都未命中，返回无反链
    return false;
}

// ====================== 6. 批量反链检测函数（彻底修复致命错误） ======================
function fabb_batch_check_all_backlinks() {
    // 权限校验
    if (!current_user_can('manage_links')) {
        return new WP_Error('permission_denied', '您没有权限执行此操作');
    }

    // 取消PHP执行时间限制，避免超时
    @set_time_limit(0);
    @ini_set('memory_limit', '256M');

    // 获取所有已上线友链
    $bookmarks = get_bookmarks(array('hide_invisible' => 0, 'limit' => -1));
    if (empty($bookmarks)) {
        return array(
            'total' => 0,
            'invalid' => 0,
            'invalid_links' => array()
        );
    }

    $total = count($bookmarks);
    $invalid = 0;
    $invalid_links = array();
    $alert_email = fabb_get_setting('alert_email', get_option('admin_email'));
    $duplicate_days = fabb_get_setting('alert_duplicate_days', 7);
    $current_time = time();
    $need_send_alert = false;

    foreach ($bookmarks as $bookmark) {
        $link_id = $bookmark->link_id;
        $link_url = $bookmark->link_url;
        $link_name = $bookmark->link_name;

        // 跳过空链接
        if (empty($link_url)) continue;

        // 执行检测，增加错误捕获
        try {
            $has_backlink = fabb_check_backlink($link_url);
        } catch (Exception $e) {
            $has_backlink = false;
        }

        // 获取上次提醒时间
        $last_alert_time = get_link_meta($link_id, '_fabb_last_alert_time', true);
        $last_alert_time = !empty($last_alert_time) ? absint($last_alert_time) : 0;

        // 更新检测状态
        update_link_meta($link_id, '_fabb_backlink_status', $has_backlink ? 'has' : 'no');
        update_link_meta($link_id, '_fabb_backlink_check_time', $current_time);

        // 处理失效链接
        if (!$has_backlink) {
            $invalid++;
            $invalid_links[] = $link_name . ' (' . $link_url . ')';
            
            // 判断是否需要发送提醒
            if (
                !empty($alert_email) &&
                ($current_time - $last_alert_time) > ($duplicate_days * 86400)
            ) {
                update_link_meta($link_id, '_fabb_last_alert_time', $current_time);
                $need_send_alert = true;
            }
        }

        // 清理内存
        unset($has_backlink, $link_url, $link_name);
    }

    // 发送提醒邮件
    if ($need_send_alert && $invalid > 0) {
        $subject = '【友链提醒】检测到 ' . $invalid . ' 个失效友链';
        $message = "您好，\r\n\r\n本次共检测 " . $total . " 个友链，发现以下 " . $invalid . " 个友链未检测到反链：\r\n\r\n";
        $message .= implode("\r\n", $invalid_links);
        $message .= "\r\n\r\n请及时登录后台查看处理。\r\n" . get_bloginfo('name') . "\r\n" . home_url();
        wp_mail($alert_email, $subject, $message);
    }

    return array(
        'total' => $total,
        'invalid' => $invalid,
        'invalid_links' => $invalid_links
    );
}

// 注册定时批量检测钩子
add_action('fabb_auto_check_backlink_hook', 'fabb_batch_check_all_backlinks');

// ====================== 7. 申请详情元框 ======================
add_action('add_meta_boxes', 'fabb_add_apply_meta_boxes');
function fabb_add_apply_meta_boxes() {
    add_meta_box(
        'fabb_apply_details',
        '申请详情',
        'fabb_render_apply_meta_box',
        'link_apply',
        'normal',
        'high'
    );
}

// 渲染元框内容
function fabb_render_apply_meta_box($post) {
    wp_nonce_field('fabb_apply_meta_nonce', 'fabb_apply_meta_nonce_field');
    
    // 获取元数据
    $link_url = get_post_meta($post->ID, '_fabb_link_url', true);
    $link_image = get_post_meta($post->ID, '_fabb_link_image', true);
    $apply_status = get_post_meta($post->ID, '_fabb_apply_status', true) ?: 'pending';
    $backlink_status = get_post_meta($post->ID, '_fabb_backlink_status', true);
    $backlink_check_time = get_post_meta($post->ID, '_fabb_backlink_check_time', true);
    $link_id = get_post_meta($post->ID, '_fabb_link_id', true);
    $contact_email = get_post_meta($post->ID, '_fabb_apply_email', true);
    
    // 状态选项
    $status_options = array(
        'pending'  => '待审核',
        'approved' => '已通过',
        'rejected' => '已拒绝'
    );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="fabb_link_url">网站链接地址</label></th>
            <td>
                <input type="url" name="fabb_link_url" id="fabb_link_url" value="<?php echo esc_url($link_url); ?>" class="regular-text" required>
            </td>
        </tr>
        <tr>
            <th><label for="fabb_link_image">网站图标地址</label></th>
            <td>
                <input type="url" name="fabb_link_image" id="fabb_link_image" value="<?php echo esc_url($link_image); ?>" class="regular-text">
                <p class="description">网站图标地址，可手动修改</p>
                <?php if (!empty($link_image)): ?>
                    <img src="<?php echo esc_url($link_image); ?>" style="width:32px;height:32px;margin-top:8px;border-radius:4px;" alt="网站图标">
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="fabb_contact_email">联系邮箱</label></th>
            <td>
                <input type="email" name="fabb_contact_email" id="fabb_contact_email" value="<?php echo esc_attr($contact_email); ?>" class="regular-text">
            </td>
        </tr>
        <tr>
            <th><label for="fabb_apply_status">申请状态</label></th>
            <td>
                <select name="fabb_apply_status" id="fabb_apply_status" class="regular-text">
                    <?php foreach ($status_options as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($apply_status, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($link_id)): ?>
                    <p class="description">已同步到链接管理器，<a href="<?php echo admin_url('link.php?action=edit&link_id=' . $link_id); ?>" target="_blank">点击编辑链接</a></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>反链检测状态</th>
            <td>
                <?php if ($backlink_status === 'has'): ?>
                    <span style="color:green;font-weight:bold;">✅ 已检测到反链</span>
                <?php elseif ($backlink_status === 'no'): ?>
                    <span style="color:red;font-weight:bold;">❌ 未检测到反链</span>
                <?php else: ?>
                    <span style="color:#999;">未检测</span>
                <?php endif; ?>
                <?php if (!empty($backlink_check_time)): ?>
                    <span style="margin-left:10px;color:#666;">检测时间：<?php echo esc_html(date('Y-m-d H:i', $backlink_check_time)); ?></span>
                <?php endif; ?>
                <br>
                <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=check_backlink&post=' . $post->ID), 'fabb_check_backlink_' . $post->ID); ?>" class="button button-small" style="margin-top:8px;">立即检测反链</a>
            </td>
        </tr>
    </table>
    <?php
}

// 保存元数据 + 状态同步
add_action('save_post', 'fabb_save_apply_meta_data');
function fabb_save_apply_meta_data($post_id) {
    // 安全校验
    if (!isset($_POST['fabb_apply_meta_nonce_field']) || !wp_verify_nonce($_POST['fabb_apply_meta_nonce_field'], 'fabb_apply_meta_nonce')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('manage_links', $post_id) || get_post_type($post_id) !== 'link_apply') {
        return;
    }

    // 保存字段
    $old_status = get_post_meta($post_id, '_fabb_apply_status', true);
    $new_status = sanitize_text_field($_POST['fabb_apply_status']);
    $link_url = sanitize_url($_POST['fabb_link_url']);
    $link_image = sanitize_url($_POST['fabb_link_image']);
    $contact_email = sanitize_email($_POST['fabb_contact_email']);

    update_post_meta($post_id, '_fabb_link_url', $link_url);
    update_post_meta($post_id, '_fabb_link_image', $link_image);
    update_post_meta($post_id, '_fabb_apply_status', $new_status);
    update_post_meta($post_id, '_fabb_apply_email', $contact_email);

    // 邮件开关
    $email_approved = fabb_get_setting('email_approved_notice', 'on') === 'on';
    $email_rejected = fabb_get_setting('email_rejected_notice', 'on') === 'on';

    // 状态变更为已通过：同步到链接管理器
    if ($new_status === 'approved') {
        $link_id = get_post_meta($post_id, '_fabb_link_id', true);
        $link_data = array(
            'link_name' => get_the_title($post_id),
            'link_url' => $link_url,
            'link_description' => get_post_field('post_content', $post_id),
            'link_image' => $link_image,
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

        // 发送通过邮件
        if ($old_status !== 'approved' && $email_approved && !empty($contact_email)) {
            $subject = '您的友情链接申请已通过';
            $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请已通过审核，链接已正式上线。';
            wp_mail($contact_email, $subject, $message);
        }
    }

    // 状态变更为非通过：移除链接管理器中的链接
    if ($new_status !== 'approved' && $old_status === 'approved') {
        $link_id = get_post_meta($post_id, '_fabb_link_id', true);
        if (!empty($link_id)) {
            wp_delete_link($link_id);
            delete_post_meta($post_id, '_fabb_link_id');
        }

        // 发送拒绝邮件
        if ($email_rejected && !empty($contact_email)) {
            $subject = '您的友情链接申请未通过审核';
            $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请未通过审核，如有疑问可联系站长。';
            wp_mail($contact_email, $subject, $message);
        }
    }
}

// ====================== 8. 后台申请列表自定义列 ======================
add_filter('manage_link_apply_posts_columns', 'fabb_add_apply_list_columns');
function fabb_add_apply_list_columns($columns) {
    $columns = array(
        'cb' => $columns['cb'],
        'title' => '网站名称',
        'link_url' => '链接地址',
        'link_image' => '网站图标',
        'apply_status' => '申请状态',
        'backlink_status' => '反链状态',
        'date' => '申请时间',
    );
    return $columns;
}

add_action('manage_link_apply_posts_custom_column', 'fabb_render_apply_list_columns', 10, 2);
function fabb_render_apply_list_columns($column, $post_id) {
    switch ($column) {
        case 'link_url':
            $url = get_post_meta($post_id, '_fabb_link_url', true);
            echo !empty($url) ? '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a>' : '-';
            break;
        case 'link_image':
            $image = get_post_meta($post_id, '_fabb_link_image', true);
            echo !empty($image) ? '<img src="' . esc_url($image) . '" style="width:32px;height:32px;border-radius:4px;" alt="图标">' : '-';
            break;
        case 'apply_status':
            $status = get_post_meta($post_id, '_fabb_apply_status', true) ?: 'pending';
            $status_labels = array(
                'pending'  => '<span style="color:#d63638;background:#fef0f0;padding:2px 8px;border-radius:4px;">待审核</span>',
                'approved' => '<span style="color:#00b42a;background:#f0fff4;padding:2px 8px;border-radius:4px;">已通过</span>',
                'rejected' => '<span style="color:#999;background:#f5f5f5;padding:2px 8px;border-radius:4px;">已拒绝</span>',
            );
            echo $status_labels[$status] ?? '-';
            break;
        case 'backlink_status':
            $backlink = get_post_meta($post_id, '_fabb_backlink_status', true);
            if ($backlink === 'has') {
                echo '<span style="color:green;">✅ 有反链</span>';
            } elseif ($backlink === 'no') {
                echo '<span style="color:red;">❌ 无反链</span>';
            } else {
                echo '<span style="color:#999;">未检测</span>';
            }
            break;
    }
}

// ====================== 9. 列表行操作按钮（解决误触问题，加二次确认+样式优化） ======================
add_filter('post_row_actions', 'fabb_add_apply_row_actions', 10, 2);
function fabb_add_apply_row_actions($actions, $post) {
    if ($post->post_type !== 'link_apply') return $actions;

    // 获取当前页面状态，兼容回收站场景
    $current_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : 'all';
    $status_param = $current_status !== 'all' ? '&post_status=' . $current_status : '';

    $status = get_post_meta($post->ID, '_fabb_apply_status', true) ?: 'pending';
    $new_actions = array();

    // 非已通过状态显示通过按钮（绿色+二次确认）
    if ($status !== 'approved') {
        $approve_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=approve&post=' . $post->ID . $status_param), 'fabb_approve_apply_' . $post->ID);
        $new_actions['approve'] = '<a href="' . esc_url($approve_url) . '" style="color:#00b42a;font-weight:bold;margin-right:8px;" onclick="return confirm(\'确定要通过这个友链申请吗？\n通过后将自动同步到链接管理器并上线\')">通过</a>';
    }

    // 非已拒绝状态显示拒绝按钮（红色+二次确认）
    if ($status !== 'rejected') {
        $reject_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=reject&post=' . $post->ID . $status_param), 'fabb_reject_apply_' . $post->ID);
        $new_actions['reject'] = '<a href="' . esc_url($reject_url) . '" style="color:#d63638;font-weight:bold;margin-right:8px;" onclick="return confirm(\'确定要拒绝这个友链申请吗？\n拒绝后将自动移除已上线的链接\')">拒绝</a>';
    }

    // 反链检测按钮（普通样式，加间距）
    $check_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=check_backlink&post=' . $post->ID . $status_param), 'fabb_check_backlink_' . $post->ID);
    $new_actions['check_backlink'] = '<a href="' . esc_url($check_url) . '" style="margin-right:8px;">检测反链</a>';

    // 编辑按钮（分隔开危险操作）
    $new_actions['edit'] = $actions['edit'];
    // 回收站按钮（加二次确认）
    if (isset($actions['trash'])) {
        $trash_url = $actions['trash'];
        $trash_url = str_replace('href=', 'onclick="return confirm(\'确定要将这个申请移到回收站吗？\')" href=', $trash_url);
        $new_actions['trash'] = $trash_url;
    }

    return $new_actions;
}

// ====================== 10. 后台操作处理（通过/拒绝/反链检测） ======================
add_action('admin_init', 'fabb_handle_admin_actions');
function fabb_handle_admin_actions() {
    global $pagenow;
    // 仅在申请列表页且包含指定操作时处理
    if ($pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'link_apply' || !isset($_GET['action']) || !isset($_GET['post'])) {
        return;
    }

    $post_id = absint($_GET['post']);
    $action = sanitize_text_field($_GET['action']);
    $allowed_actions = array('approve', 'reject', 'check_backlink');

    if (!in_array($action, $allowed_actions)) {
        return;
    }

    // 权限校验
    if (!current_user_can('manage_links', $post_id)) {
        wp_die('您没有权限执行此操作');
    }

    // Nonce 校验
    $nonce_name = 'fabb_' . $action . '_apply_' . $post_id;
    if ($action === 'check_backlink') {
        $nonce_name = 'fabb_check_backlink_' . $post_id;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_name)) {
        wp_die('安全验证失败，请刷新重试');
    }

    // 获取当前页面状态，操作后跳回原页面
    $current_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : 'all';
    $status_param = $current_status !== 'all' ? '&post_status=' . $current_status : '';

    // 邮件开关
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

            // 发送邮件
            $contact_email = get_post_meta($post_id, '_fabb_apply_email', true);
            if ($email_approved && !empty($contact_email)) {
                $subject = '您的友情链接申请已通过';
                $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请已通过审核，链接已正式上线。';
                wp_mail($contact_email, $subject, $message);
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

            // 发送邮件
            $contact_email = get_post_meta($post_id, '_fabb_apply_email', true);
            if ($email_rejected && !empty($contact_email)) {
                $subject = '您的友情链接申请未通过审核';
                $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请未通过审核，如有疑问可联系站长。';
                wp_mail($contact_email, $subject, $message);
            }

            wp_redirect(admin_url('edit.php?post_type=link_apply&rejected=1' . $status_param));
            exit;
            break;

        case 'check_backlink':
            $target_url = get_post_meta($post_id, '_fabb_link_url', true);
            $has_backlink = fabb_check_backlink($target_url);
            update_post_meta($post_id, '_fabb_backlink_status', $has_backlink ? 'has' : 'no');
            update_post_meta($post_id, '_fabb_backlink_check_time', time());

            wp_redirect(admin_url('edit.php?post_type=link_apply&checked=1' . $status_param));
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
}

// ====================== 11. 前端申请表单短代码 ======================
add_shortcode('link_apply_form', 'fabb_render_apply_form_shortcode');
function fabb_render_apply_form_shortcode() {
    // 表单开关
    if (fabb_get_setting('apply_form_enable', 'on') !== 'on') {
        return '<div class="fabb-form-notice" style="padding:15px;background:#f5f5f5;border-radius:8px;color:#666;">友情链接申请通道已关闭</div>';
    }

    // 提交结果提示
    $output = '';
    if (isset($_GET['apply_success']) && $_GET['apply_success'] === '1') {
        $output .= '<div class="fabb-form-success" style="padding:15px;background:#f0fff4;border:1px solid #00b42a;border-radius:8px;color:#00b42a;margin-bottom:20px;">✅ 您的申请已提交成功，我们会尽快审核</div>';
    }
    if (isset($_GET['apply_error']) && !empty($_GET['apply_error'])) {
        $error_msg = sanitize_text_field(urldecode($_GET['apply_error']));
        $output .= '<div class="fabb-form-error" style="padding:15px;background:#fef0f0;border:1px solid #d63638;border-radius:8px;color:#d63638;margin-bottom:20px;">❌ ' . esc_html($error_msg) . '</div>';
    }
    // 表单HTML
    $output .= '<form class="fabb-apply-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:800px;margin:0 auto;">';
    $output .= wp_nonce_field('fabb_apply_form_nonce', 'fabb_apply_form_nonce_field', true, false);
    $output .= '<input type="hidden" name="action" value="link_apply_submit">';
    // 表单字段
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
    // 图标预览JS
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
    // 安全校验
    if (!isset($_POST['fabb_apply_form_nonce_field']) || !wp_verify_nonce($_POST['fabb_apply_form_nonce_field'], 'fabb_apply_form_nonce')) {
        $redirect_url = add_query_arg('apply_error', urlencode('安全验证失败，请刷新页面重试'), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    // 获取并验证字段
    $site_name = sanitize_text_field($_POST['fabb_site_name']);
    $site_url = sanitize_url($_POST['fabb_site_url']);
    $site_image = sanitize_url($_POST['fabb_site_image']);
    $site_desc = sanitize_textarea_field($_POST['fabb_site_desc']);
    $contact_email = sanitize_email($_POST['fabb_contact_email']);
    // 验证必填项
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
    // 检查重复提交
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
    // 创建申请记录
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
    // 保存元数据
    update_post_meta($post_id, '_fabb_link_url', $site_url);
    update_post_meta($post_id, '_fabb_link_image', $site_image);
    update_post_meta($post_id, '_fabb_apply_status', 'pending');
    update_post_meta($post_id, '_fabb_apply_email', $contact_email);
    // 给管理员发送通知邮件
    $email_admin = fabb_get_setting('email_admin_notice', 'on') === 'on';
    $admin_email = fabb_get_setting('alert_email', get_option('admin_email'));
    if ($email_admin && !empty($admin_email)) {
        $subject = '新的友情链接申请';
        $message = '网站名称：' . $site_name . "\r\n";
        $message .= '网站链接：' . $site_url . "\r\n";
        $message .= '网站介绍：' . $site_desc . "\r\n";
        $message .= '联系邮箱：' . $contact_email . "\r\n";
        $message .= '审核地址：' . admin_url('edit.php?post_type=link_apply');
        wp_mail($admin_email, $subject, $message);
    }
    // 跳转回表单页，提示成功
    $redirect_url = add_query_arg('apply_success', '1', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}

// ====================== 12. 随机友情链接短代码 ======================
add_shortcode('random_bookmarks', 'fabb_random_bookmarks_shortcode');
function fabb_random_bookmarks_shortcode($atts) {
    // 从后台获取默认配置
    $default_show_image = fabb_get_setting('default_show_image', 'on') === 'on';
    $default_show_desc = fabb_get_setting('default_show_desc', 'on') === 'on';
    $default_image_size = fabb_get_setting('default_image_size', 40);

    // 短代码参数
    $atts = shortcode_atts(array(
        'category'         => '',
        'limit'            => -1,
        'target'           => '_blank',
        'show_description' => $default_show_desc,
        'show_image'       => $default_show_image,
        'image_size'       => $default_image_size,
    ), $atts, 'random_bookmarks');

    // 修复布尔值判断，兼容带空格的参数
    $show_image = (trim(strtolower($atts['show_image'])) === 'true' || $atts['show_image'] === true || $atts['show_image'] === 'on');
    $show_description = (trim(strtolower($atts['show_description'])) === 'true' || $atts['show_description'] === true || $atts['show_description'] === 'on');
    $image_size = absint($atts['image_size']);

    // 查询友情链接
    $bookmark_args = array(
        'orderby'        => 'rand',
        'order'          => 'ASC',
        'limit'          => $atts['limit'],
        'category'       => $atts['category'],
        'hide_invisible' => 1,
    );
    $bookmarks = get_bookmarks($bookmark_args);

    if (empty($bookmarks)) {
        return '<p class="fabb-bookmarks-empty">暂无友情链接</p>';
    }

    // 生成HTML
    $output = '<ul class="fabb-bookmarks-list">';
    foreach ($bookmarks as $bookmark) {
        $link_url    = esc_url($bookmark->link_url);
        $link_name   = esc_html($bookmark->link_name);
        $link_title  = esc_attr($bookmark->link_title ?: $bookmark->link_name);
        $link_target = esc_attr($atts['target'] ?: $bookmark->link_target);
        $link_desc   = esc_html($bookmark->link_description);
        $link_image  = esc_url($bookmark->link_image);

        $output .= '<li class="fabb-bookmark-item">';
        $output .= '<a href="' . $link_url . '" target="' . $link_target . '" title="' . $link_title . '" rel="noopener noreferrer">';
        
        if ($show_image && !empty($link_image)) {
            $output .= '<img src="' . $link_image . '" class="fabb-bookmark-image" style="width:' . $image_size . 'px;height:' . $image_size . 'px;border-radius:6px;flex-shrink:0;" alt="' . $link_name . '">';
        }

        $output .= '<div class="fabb-bookmark-content">';
        $output .= '<span class="fabb-bookmark-name">' . $link_name . '</span>';
        
        if ($show_description && !empty($link_desc)) {
            $output .= '<span class="fabb-bookmark-desc">' . $link_desc . '</span>';
        }
        $output .= '</div>';
        $output .= '</a>';
        $output .= '</li>';
    }
    $output .= '</ul>';

    return $output;
}

// ====================== 13. CSS样式（兼容深色主题） ======================
add_action('wp_enqueue_scripts', 'fabb_enqueue_bookmarks_styles');
function fabb_enqueue_bookmarks_styles() {
    $css = '
    /* 友情链接列表容器 */
    .fabb-bookmarks-list {
        list-style: none !important;
        padding: 0 !important;
        margin: 30px 0 !important;
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)) !important;
        gap: 15px !important;
    }
    /* 单个链接卡片 */
    .fabb-bookmark-item {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .fabb-bookmark-item a {
        width: 100% !important;
        box-sizing: border-box !important;
        display: flex !important;
        align-items: center !important;
        padding: 12px 15px !important;
        background: rgba(255,255,255,0.05) !important;
        border: 1px solid rgba(78, 205, 196, 0.3) !important;
        border-radius: 8px !important;
        text-decoration: none !important;
        transition: all 0.3s ease !important;
        gap: 12px !important;
        color: inherit !important;
    }
    /* 卡片hover效果 */
    .fabb-bookmark-item a:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 4px 12px rgba(0, 150, 136, 0.2) !important;
        border-color: #4ecdc4 !important;
        background: rgba(78, 205, 196, 0.1) !important;
    }
    /* 网站图标 */
    .fabb-bookmark-image {
        display: inline-block !important;
        vertical-align: middle !important;
        object-fit: cover !important;
    }
    /* 文本内容容器 */
    .fabb-bookmark-content {
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        overflow: hidden !important;
        line-height: 1.4 !important;
    }
    /* 网站名称 */
    .fabb-bookmark-name {
        font-weight: 600 !important;
        color: inherit !important;
        font-size: 1em !important;
        display: block !important;
    }
    /* 网站描述 */
    .fabb-bookmark-desc {
        font-size: 0.85em !important;
        color: rgba(255,255,255,0.7) !important;
        display: block !important;
        margin-top: 2px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }
    /* 无链接提示 */
    .fabb-bookmarks-empty {
        color: rgba(255,255,255,0.6) !important;
        padding: 10px !important;
    }
    /* 申请表单样式 */
    .fabb-apply-form input:focus,
    .fabb-apply-form textarea:focus {
        outline: none !important;
        border-color: #4ecdc4 !important;
        box-shadow: 0 0 0 2px rgba(78, 205, 196, 0.1) !important;
    }
    .fabb-form-submit button:hover {
        background: #3dbbb3 !important;
    }
    ';
    wp_add_inline_style('wp-block-library', $css);
}

// ====================== 14. 自动清理过期申请 ======================
add_action('fabb_cleanup_expired_applications_hook', 'fabb_cleanup_expired_applications');
function fabb_cleanup_expired_applications() {
    // 从后台获取配置
    $auto_clean = fabb_get_setting('auto_clean_expired', 'on') === 'on';
    $expire_days = fabb_get_setting('expire_days', 30);
    
    if (!$auto_clean) return;

    // 查询过期申请
    $expired_date = date('Y-m-d H:i:s', strtotime('-' . $expire_days . ' days'));
    $args = array(
        'post_type'      => 'link_apply',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_fabb_apply_status',
                'value'   => array('pending', 'rejected'),
                'compare' => 'IN',
            ),
        ),
        'date_query'     => array(
            array(
                'before'    => $expired_date,
                'inclusive' => true,
            ),
        ),
    );
    $expired_apps = get_posts($args);
    if (empty($expired_apps)) {
        return;
    }
    foreach ($expired_apps as $post) {
        // 删除前检查关联链接
        $link_id = get_post_meta($post->ID, '_fabb_link_id', true);
        if (!empty($link_id)) {
            wp_delete_link($link_id);
        }
        // 强制删除
        wp_delete_post($post->ID, true);
    }
}

// 确保删除申请时同步删除链接
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

// ====================== 15. 插件激活/停用处理 ======================
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
}

register_deactivation_hook(__FILE__, 'fabb_plugin_deactivation');
function fabb_plugin_deactivation() {
    flush_rewrite_rules();
    // 清除所有定时任务
    wp_clear_scheduled_hook('fabb_cleanup_expired_applications_hook');
    wp_clear_scheduled_hook('fabb_auto_check_backlink_hook');
}
