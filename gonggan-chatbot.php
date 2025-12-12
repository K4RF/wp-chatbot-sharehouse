<?php
/**
 * Plugin Name: 공간나인 AI 매니저 (GitHub Edition)
 * Description: API 키를 분리하여 깃허브 관리에 최적화된 버전 (AI 스마트 뮤트 기능 포함)
 * Version: 7.6
 * Author: GongganNine
 */

if (!defined('ABSPATH')) exit;

// ★ [보안] 설정 파일 불러오기 (없으면 에러 처리)
$config_file = plugin_dir_path(__FILE__) . 'gnbot-config.php';
if (file_exists($config_file)) {
    require_once $config_file;
}

class Gonggan_Chatbot_Git {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'gn_chat_logs'; 
        
        register_activation_hook(__FILE__, [$this, 'create_table']); 
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_gnbot_chat_submit', [$this, 'ajax_chat_submit']);
        add_action('wp_ajax_nopriv_gnbot_chat_submit', [$this, 'ajax_chat_submit']);
        add_action('wp_ajax_gnbot_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_nopriv_gnbot_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_gnbot_admin_list', [$this, 'ajax_admin_list']);
        add_action('wp_ajax_gnbot_send_admin', [$this, 'ajax_send_admin']);
        add_action('wp_ajax_gnbot_reset_ai', [$this, 'ajax_reset_ai']);
        add_shortcode('gn_chatbot_page', [$this, 'render_shortcode']);
    }

    // 설정 파일이 없을 때 경고 메시지 표시
    public function check_config() {
        if (!defined('GNBOT_OPENAI_KEY')) {
            return "⚠️ [설정 오류] 'gnbot-config.php' 파일이 없거나 API 키가 설정되지 않았습니다.";
        }
        return null;
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            phone varchar(20) NOT NULL,
            name varchar(50) NOT NULL,
            message text NOT NULL,
            sender varchar(10) NOT NULL, 
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function enqueue_assets() { 
        wp_enqueue_script('jquery');
        wp_enqueue_style('gnbot-style', plugin_dir_url(__FILE__) . 'style.css', [], '1.0');
        wp_enqueue_script('gnbot-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '1.0', true);
        wp_localize_script('gnbot-script', 'gnBotSettings', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }

    private function save_message($phone, $name, $msg, $sender) {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {
            $this->create_table();
        }
        $wpdb->insert($this->table_name, ['phone' => $phone, 'name' => $name, 'message' => $msg, 'sender' => $sender, 'created_at' => current_time('mysql')]);
    }

    private function fetch_room_status_safe() {
        if (!defined('GNBOT_DATABASE_ID') || !GNBOT_DATABASE_ID) return "(DB설정안됨)";

        $url = "https://api.notion.com/v1/databases/" . GNBOT_DATABASE_ID . "/query";
        $args = [
            'headers' => ['Authorization' => 'Bearer ' . GNBOT_NOTION_KEY, 'Notion-Version' => '2022-06-28', 'Content-Type' => 'application/json'],
            'method' => 'POST', 'timeout' => 10
        ];
        
        $res = wp_remote_request($url, $args);
        
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) != 200) {
            return "(노션 연결 실패 - AI 답변만 진행합니다)"; 
        }

        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        $lines = [];

        if (is_array($data) && !empty($data['results'])) {
            foreach ($data['results'] as $p) {
                $br = $p['properties']['지점명']['select']['name'] ?? '';
                $rm = $p['properties']['방번호']['title'][0]['plain_text'] ?? '';
                $st = '-';
                if (isset($p['properties']['입주현황']['select']['name'])) {
                    $st = $p['properties']['입주현황']['select']['name'];
                } elseif (isset($p['properties']['입주현황']['rollup']['array'][0]['select']['name'])) {
                    $st = $p['properties']['입주현황']['rollup']['array'][0]['select']['name'];
                }

                $dt = '';
                if (isset($p['properties']['계약기간']['date']['end'])) {
                    $dt = $p['properties']['계약기간']['date']['end'];
                } elseif (isset($p['properties']['계약기간']['rollup']['array'][0]['date']['end'])) {
                    $dt = $p['properties']['계약기간']['rollup']['array'][0]['date']['end'];
                }

                if($br && $rm) $lines[] = "- [$br] $rm호 : $st (계약만료: $dt)";
            }
        }
        return empty($lines) ? "(공실 정보 없음)" : implode("\n", $lines);
    }

    public function ajax_chat_submit() {
        // 설정 파일 체크
        $config_err = $this->check_config();
        if ($config_err) { wp_send_json_error($config_err); return; }

        $msg = sanitize_text_field($_POST['message'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');

        // 1. 고객 메시지 저장
        $this->save_message($phone, $name, $msg, '고객');

        // ★ 관리자가 개입(Mute)했는지 확인
        if (get_option('gnbot_mute_' . $phone)) {
            wp_send_json_success('관리자 상담 모드입니다. (AI 답변 없음)');
            return;
        }
        
        // 2. AI 답변 생성
        $room_info = $this->fetch_room_status_safe();

        $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . GNBOT_OPENAI_KEY, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => 'gpt-4o-mini', 
                'messages' => [
                    ['role' => 'system', 'content' => "당신은 쉐어하우스 매니저입니다. 고객명: $name. 오늘: ".date("Y-m-d")."\n[공실정보]\n$room_info\n위 정보를 참고하여 답변하세요."],
                    ['role' => 'user', 'content' => $msg]
                ]
            ]),
            'timeout' => 20
        ]);

        if (is_wp_error($res)) {
            $err = $res->get_error_message();
            $this->save_message($phone, $name, "연결 오류: $err", '시스템');
            wp_send_json_error("OpenAI 연결 실패: $err");
        } else {
            $body = json_decode(wp_remote_retrieve_body($res), true);
            if (isset($body['error'])) {
                $ai_err = $body['error']['message'];
                $this->save_message($phone, $name, "AI 키 오류: $ai_err", '시스템');
                wp_send_json_error("AI 응답 오류: $ai_err"); 
            } else {
                $reply = $body['choices'][0]['message']['content'] ?? '죄송합니다. 답변을 생성하지 못했습니다.';
                $this->save_message($phone, $name, $reply, 'AI');
                wp_send_json_success($reply);
            }
        }
    }

    public function ajax_get_history() {
        global $wpdb;
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $results = $wpdb->get_results($wpdb->prepare("SELECT message as msg, sender FROM $this->table_name WHERE phone = %s ORDER BY created_at ASC", $phone), ARRAY_A);
        wp_send_json_success($results);
    }
    
    public function ajax_admin_list() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT DISTINCT phone, name FROM $this->table_name ORDER BY created_at DESC LIMIT 50", ARRAY_A);
        wp_send_json_success($results);
    }

    public function ajax_send_admin() {
        $phone = sanitize_text_field($_POST['phone']);
        $this->save_message($phone, $_POST['name'], $_POST['message'], '관리자');
        
        // ★ 관리자 전송 시 AI 침묵 설정
        update_option('gnbot_mute_' . $phone, true);
        
        wp_send_json_success();
    }

    public function ajax_reset_ai() {
        $phone = sanitize_text_field($_POST['phone']);
        delete_option('gnbot_mute_' . $phone); // 침묵 해제
        $this->save_message($phone, '시스템', 'AI 상담이 다시 활성화되었습니다.', '시스템');
        wp_send_json_success();
    }

    public function add_admin_menu() {
        add_menu_page('AI 상담', 'AI 상담', 'manage_options', 'gnbot-admin', [$this, 'admin_page_html'], 'dashicons-groups', 6);
    }

    public function admin_page_html() {
        // 관리자 페이지 UI (기존과 동일)
        ?>
        <div class="wrap" style="display:flex; gap:20px; height:80vh;">
            <div style="width:250px; background:#fff; border:1px solid #ddd; padding:10px; overflow-y:auto;">
                <h3>📂 상담 목록</h3>
                <div id="gn-user-list">로딩중...</div>
                <button onclick="loadUserList()" class="button" style="margin-top:10px; width:100%;">새로고침</button>
            </div>
            <div style="flex:1; background:#fff; border:1px solid #ddd; display:flex; flex-direction:column;">
                <div id="gn-chat-header" style="padding:15px; background:#f0f0f1; border-bottom:1px solid #ddd; font-weight:bold; display:flex; justify-content:space-between;">
                    <span id="gn-chat-title">선택 대기중</span>
                    <button id="btn-reset-ai" class="button button-small" onclick="resetAI()" style="display:none;">🤖 AI 다시 켜기</button>
                </div>
                <div id="gn-admin-chat-box" style="flex:1; padding:20px; overflow-y:auto; background:#f9f9f9;"></div>
                <div style="padding:15px; border-top:1px solid #ddd; background:#fff; display:flex;">
                    <input type="text" id="gn-admin-input" style="flex:1; padding:8px;" placeholder="답변 입력..." onkeypress="if(event.keyCode==13) sendAdminMsg()">
                    <button class="button button-primary" onclick="sendAdminMsg()" style="margin-left:10px;">전송</button>
                </div>
            </div>
        </div>
        <script>
            var currentPhone='', currentName='';
            function loadUserList() {
                jQuery.post(ajaxurl, {action: 'gnbot_admin_list'}, function(res) {
                    if(res.success) {
                        var html = '';
                        if(res.data.length === 0) html = '<div style="padding:10px; color:#888;">데이터가 없습니다.</div>';
                        res.data.forEach(function(u) {
                            html += `<div onclick="selectUser('${u.phone}', '${u.name}')" style="padding:10px; border-bottom:1px solid #eee; cursor:pointer;"><strong>${u.name}</strong><br><small>${u.phone}</small></div>`;
                        });
                        document.getElementById('gn-user-list').innerHTML = html;
                    }
                });
            }
            function selectUser(phone, name) {
                currentPhone = phone; currentName = name;
                document.getElementById('gn-chat-title').innerText = name + ' (' + phone + ')';
                document.getElementById('btn-reset-ai').style.display = 'block'; 
                loadChatHistory();
            }
            function loadChatHistory() {
                if(!currentPhone) return;
                jQuery.post(ajaxurl, {action: 'gnbot_get_history', phone: currentPhone}, function(res) {
                    if(res.success) {
                        var box = document.getElementById('gn-admin-chat-box');
                        box.innerHTML = '';
                        res.data.forEach(function(msg) {
                            var align = msg.sender === '관리자' ? 'right' : 'left';
                            var bg = msg.sender === '관리자' ? '#e6f7ff' : (msg.sender === 'AI' ? '#eee' : '#fff');
                            if(msg.sender === '시스템') {
                                box.innerHTML += `<div style="text-align:center; margin:10px; color:#888; font-size:12px;">- ${msg.msg} -</div>`;
                            } else {
                                box.innerHTML += `<div style="text-align:${align}; margin-bottom:10px;"><div style="display:inline-block; padding:8px 12px; background:${bg}; border-radius:10px; border:1px solid #ddd; max-width:70%; text-align:left;">${msg.msg}</div></div>`;
                            }
                        });
                        box.scrollTop = box.scrollHeight;
                    }
                });
            }
            function sendAdminMsg() {
                var txt = document.getElementById('gn-admin-input').value;
                if(!txt || !currentPhone) return;
                jQuery.post(ajaxurl, {action: 'gnbot_send_admin', phone: currentPhone, name: currentName, message: txt}, function(){
                    document.getElementById('gn-admin-input').value = '';
                    loadChatHistory();
                });
            }
            function resetAI() {
                if(!currentPhone) return;
                if(!confirm('다시 AI가 답변하도록 설정하시겠습니까?')) return;
                jQuery.post(ajaxurl, {action: 'gnbot_reset_ai', phone: currentPhone}, function(){
                    loadChatHistory();
                    alert('AI 상담이 다시 활성화되었습니다.');
                });
            }
            setInterval(function(){ if(currentPhone) loadChatHistory(); }, 3000);
            loadUserList();
        </script>
        <?php
    }

    public function render_shortcode() {
        ob_start(); 
        ?>
        <div class="gn-phone-container">
            <div id="gn-login">
                <div class="gn-title">👋 입주 문의</div>
                <div class="gn-desc">공간나인 AI 매니저입니다.<br>연락처를 남겨주시면 바로 연결됩니다.</div>
                <input type="text" id="uName" class="gn-input" placeholder="예: 홍길동">
                <input type="text" id="uPhone" class="gn-input" placeholder="예: 010-1234-5678">
                <button class="gn-btn-primary" onclick="startChat()">상담 시작하기</button>
            </div>
            <div id="gn-chat">
                <div class="gn-head">
                    <span>🏡 공간나인 매니저</span>
                    <span class="gn-logout" onclick="logoutChat()">나가기</span>
                </div>
                <div class="gn-body" id="cb"></div>
                <div class="gn-foot">
                    <input type="text" id="ci" class="gn-msg-input" placeholder="메시지를 입력하세요..." onkeypress="if(event.keyCode==13) sendM()">
                    <button id="btn-send" class="gn-send-icon" onclick="sendM()">➤</button>
                </div>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}
new Gonggan_Chatbot_Git();