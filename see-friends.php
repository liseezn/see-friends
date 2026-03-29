<?php
/**
 * Plugin Name: SEE~Friends-友情链接管理
 * Plugin URI: https://github.com/liseezn/see-friends/
 * Description: 一个友情链接插件，支持随机排序、图标+描述显示、前端申请、后台审核、反链检测，兼容国内环境
 * Version: 2.1.0
 * Author: liseezn
 * Author URI: https://liseezn.top/
 * License: GPLv2 or later
 * Text Domain: fixed-advanced-bookmarks
 */

// 禁止直接访问，安全防护
if (!defined('ABSPATH')) {
    exit;
}

// ====================== 1. 核心基础功能 ======================
// 开启WordPress原生链接管理器
add_filter('pre_option_link_manager_enabled', '__return_true');
// 让小工具支持短代码
add_filter('widget_text', 'do_shortcode');

// ====================== 2. 申请数据存储：自定义文章类型 ======================
add_action('init', 'fabb_register_apply_post_type');
function fabb_register_apply_post_type() {
    $args = array(
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => 'link-manager.php', // 挂载到「链接」菜单下
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

// ====================== 3. 申请详情元框 ======================
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
        32
            <th><label for="fabb_link_url">网站链接地址</label></th>
            32
                <input type="url" name="fabb_link_url" id="fabb_link_url" value="<?php echo esc_url($link_url); ?>" class="regular-text" required>
            32
        </tr>
        32
            <th><label for="fabb_link_image">网站图标地址</label></th>
            32
                <input type="url" name="fabb_link_image" id="fabb_link_image" value="<?php echo esc_url($link_image); ?>" class="regular-text">
                <p class="description">网站图标地址，可手动修改</p>
                <?php if (!empty($link_image)): ?>
                    <img src="<?php echo esc_url($link_image); ?>" style="width:32px;height:32px;margin-top:8px;border-radius:4px;" alt="网站图标">
                <?php endif; ?>
            32
        </tr>
        32
            <th><label for="fabb_contact_email">联系邮箱</label></th>
            32
                <input type="email" name="fabb_contact_email" id="fabb_contact_email" value="<?php echo esc_attr($contact_email); ?>" class="regular-text">
            32
        </tr>
        32
            <th><label for="fabb_apply_status">申请状态</label></th>
            32
                <select name="fabb_apply_status" id="fabb_apply_status" class="regular-text">
                    <?php foreach ($status_options as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($apply_status, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($link_id)): ?>
                    <p class="description">已同步到链接管理器，<a href="<?php echo admin_url('link.php?action=edit&link_id=' . $link_id); ?>" target="_blank">点击编辑链接</a></p>
                <?php endif; ?>
            32
        </tr>
        32
            <th>反链检测状态</th>
            32
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
            32
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

    // 状态变更为已通过：同步到链接管理器
    if ($new_status === 'approved' && $old_status !== 'approved') {
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

        // 发送审核通过邮件
        if (!empty($contact_email)) {
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
        if (!empty($contact_email)) {
            $subject = '您的友情链接申请未通过审核';
            $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请未通过审核，如有疑问可联系站长。';
            wp_mail($contact_email, $subject, $message);
        }
    }
}

// ====================== 4. 后台申请列表自定义列 ======================
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

// 列表行操作按钮
add_filter('post_row_actions', 'fabb_add_apply_row_actions', 10, 2);
function fabb_add_apply_row_actions($actions, $post) {
    if ($post->post_type !== 'link_apply') return $actions;

    $status = get_post_meta($post->ID, '_fabb_apply_status', true) ?: 'pending';
    $new_actions = array();

    if ($status !== 'approved') {
        $approve_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=approve&post=' . $post->ID), 'fabb_approve_apply_' . $post->ID);
        $new_actions['approve'] = '<a href="' . esc_url($approve_url) . '" style="color:#00b42a;">通过</a>';
    }
    if ($status !== 'rejected') {
        $reject_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=reject&post=' . $post->ID), 'fabb_reject_apply_' . $post->ID);
        $new_actions['reject'] = '<a href="' . esc_url($reject_url) . '" style="color:#d63638;">拒绝</a>';
    }
    $check_url = wp_nonce_url(admin_url('edit.php?post_type=link_apply&action=check_backlink&post=' . $post->ID), 'fabb_check_backlink_' . $post->ID);
    $new_actions['check_backlink'] = '<a href="' . esc_url($check_url) . '">检测反链</a>';
    $new_actions['edit'] = $actions['edit'];
    $new_actions['trash'] = $actions['trash'];

    return $new_actions;
}

// ====================== 5. 后台操作处理（通过/拒绝/反链检测） ======================
add_action('admin_init', 'fabb_handle_admin_actions');
function fabb_handle_admin_actions() {
    global $pagenow;
    if ($pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'link_apply' || !isset($_GET['action']) || !isset($_GET['post'])) {
        return;
    }

    $post_id = absint($_GET['post']);
    $action = sanitize_text_field($_GET['action']);

    // 权限校验
    if (!current_user_can('manage_links', $post_id)) {
        wp_die('您没有权限执行此操作');
    }
    // nonce校验
    if (!wp_verify_nonce($_GET['_wpnonce'], 'fabb_' . $action . '_apply_' . $post_id) && !wp_verify_nonce($_GET['_wpnonce'], 'fabb_check_backlink_' . $post_id)) {
        wp_die('安全验证失败，请刷新重试');
    }

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
            if (!empty($contact_email)) {
                $subject = '您的友情链接申请已通过';
                $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请已通过审核，链接已正式上线。';
                wp_mail($contact_email, $subject, $message);
            }

            wp_redirect(admin_url('edit.php?post_type=link_apply&approved=1'));
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
            if (!empty($contact_email)) {
                $subject = '您的友情链接申请未通过审核';
                $message = '您好，您在 ' . get_bloginfo('name') . ' 提交的友情链接申请未通过审核，如有疑问可联系站长。';
                wp_mail($contact_email, $subject, $message);
            }

            wp_redirect(admin_url('edit.php?post_type=link_apply&rejected=1'));
            exit;
            break;

        case 'check_backlink':
            $target_url = get_post_meta($post_id, '_fabb_link_url', true);
            $has_backlink = fabb_check_backlink($target_url);

            update_post_meta($post_id, '_fabb_backlink_status', $has_backlink ? 'has' : 'no');
            update_post_meta($post_id, '_fabb_backlink_check_time', time());

            wp_redirect(admin_url('edit.php?post_type=link_apply&checked=1'));
            exit;
            break;
    }
}

// 反链检测核心函数
function fabb_check_backlink($target_url) {
    if (empty($target_url)) return false;

    // 获取本站域名，兼容www/非www
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    $site_host = preg_replace('/^www\./', '', $site_host);

    // 请求目标网站
    $response = wp_remote_get($target_url, array(
        'timeout' => 15,
        'sslverify' => false,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ));

    if (is_wp_error($response)) return false;
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) return false;

    // 检查是否包含本站域名
    return stripos($body, $site_host) !== false;
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

// ====================== 6. 前端申请表单短代码 ======================
add_shortcode('link_apply_form', 'fabb_render_apply_form_shortcode');
function fabb_render_apply_form_shortcode() {
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

    // 保留手动输入图标的预览功能（已移除自动填充）
    $output .= '
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        const imageInput = document.getElementById("fabb_site_image");
        const previewWrap = document.getElementById("fabb_image_preview");
        const previewImg = document.getElementById("fabb_preview_img");

        // 手动修改图标地址时更新预览
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

    // 【修改】不再自动填充图标地址，完全由用户输入决定（如果为空则留空）
    // 之前自动获取favicon的代码已移除

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
    $admin_email = get_option('admin_email');
    $subject = '新的友情链接申请';
    $message = '网站名称：' . $site_name . "\r\n";
    $message .= '网站链接：' . $site_url . "\r\n";
    $message .= '网站介绍：' . $site_desc . "\r\n";
    $message .= '联系邮箱：' . $contact_email . "\r\n";
    $message .= '审核地址：' . admin_url('edit.php?post_type=link_apply');
    wp_mail($admin_email, $subject, $message);

    // 跳转回表单页，提示成功
    $redirect_url = add_query_arg('apply_success', '1', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}

// ====================== 7. 【修复核心】随机友情链接短代码 ======================
add_shortcode('random_bookmarks', 'fabb_random_bookmarks_shortcode');
function fabb_random_bookmarks_shortcode($atts) {
    // 短代码参数：修改 show_image 默认值为 true，使图标默认显示
    $atts = shortcode_atts(array(
        'category'         => '',
        'limit'            => -1,
        'target'           => '_blank',
        'show_description' => false,
        'show_image'       => true,   // 修改为 true，修复图标不显示问题
        'image_size'       => 40,
    ), $atts, 'random_bookmarks');

    // 【核心】随机获取友情链接
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

    $output = '<ul class="fabb-bookmarks-list">';
    foreach ($bookmarks as $bookmark) {
        $link_url    = esc_url($bookmark->link_url);
        $link_name   = esc_html($bookmark->link_name);
        $link_title  = esc_attr($bookmark->link_title ?: $bookmark->link_name);
        $link_target = esc_attr($atts['target'] ?: $bookmark->link_target);
        $link_desc   = esc_html($bookmark->link_description);
        $link_image  = esc_url($bookmark->link_image);
        $image_size  = absint($atts['image_size']);

        $output .= '<li class="fabb-bookmark-item">';
        $output .= '<a href="' . $link_url . '" target="' . $link_target . '" title="' . $link_title . '" rel="noopener noreferrer">';
        
        // 显示网站图标（只有 show_image 为 true 且图标地址非空时才输出）
        if (($atts['show_image'] === 'true' || $atts['show_image'] === true) && !empty($link_image)) {
            $output .= '<img src="' . $link_image . '" class="fabb-bookmark-image" style="width:' . $image_size . 'px;height:' . $image_size . 'px;border-radius:6px;flex-shrink:0;" alt="' . $link_name . '">';
        }

        $output .= '<div class="fabb-bookmark-content">';
        $output .= '<span class="fabb-bookmark-name">' . $link_name . '</span>';
        
        if (($atts['show_description'] === 'true' || $atts['show_description'] === true) && !empty($link_desc)) {
            $output .= '<span class="fabb-bookmark-desc">' . $link_desc . '</span>';
        }
        $output .= '</div>';

        $output .= '</a>';
        $output .= '</li>';
    }
    $output .= '</ul>';

    return $output;
}

// ====================== 8. 优化默认CSS样式 ======================
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
        background: #ffffff !important;
        border: 1px solid #e8f4f2 !important;
        border-radius: 8px !important;
        text-decoration: none !important;
        transition: all 0.3s ease !important;
        gap: 12px !important;
    }

    /* 卡片hover效果 */
    .fabb-bookmark-item a:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 4px 12px rgba(0, 150, 136, 0.1) !important;
        border-color: #4ecdc4 !important;
        background: #f7fffe !important;
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
        color: #222222 !important;
        font-size: 1em !important;
        display: block !important;
    }

    /* 网站描述 */
    .fabb-bookmark-desc {
        font-size: 0.85em !important;
        color: #666666 !important;
        display: block !important;
        margin-top: 2px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* 无链接提示 */
    .fabb-bookmarks-empty {
        color: #999 !important;
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

// ====================== 9. 插件激活/停用处理 ======================
register_activation_hook(__FILE__, 'fabb_plugin_activation');
function fabb_plugin_activation() {
    fabb_register_apply_post_type();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'fabb_plugin_deactivation');
function fabb_plugin_deactivation() {
    flush_rewrite_rules();
}