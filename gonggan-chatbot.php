<?php
/**
 * Plugin Name: 공간나인 AI 매니저 (Logic Master v8.8)
 * Description: 성수점 지점 구분(Slot Filling), 강남점 거주중 필터링, 2인실 정규식 인식 기능 통합
 * Version: 8.8
 * Author: GongganNine
 */

if (!defined('ABSPATH')) exit;

// 설정 파일 로드 (API 키 등)
$config_file = plugin_dir_path(__FILE__) . 'gnbot-config.php';
if (file_exists($config_file)) {
    require_once $config_file;
}

class Gonggan_Chatbot_Git {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'gn_chat_logs'; 
        
        // 시간대 설정 (KST)
        date_default_timezone_set('Asia/Seoul');

        register_activation_hook(__FILE__, [$this, 'create_table']); 
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // AJAX 핸들러
        add_action('wp_ajax_gnbot_chat_submit', [$this, 'ajax_chat_submit']);
        add_action('wp_ajax_nopriv_gnbot_chat_submit', [$this, 'ajax_chat_submit']);
        add_action('wp_ajax_gnbot_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_nopriv_gnbot_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_gnbot_admin_list', [$this, 'ajax_admin_list']);
        add_action('wp_ajax_gnbot_send_admin', [$this, 'ajax_send_admin']);
        add_action('wp_ajax_gnbot_reset_ai', [$this, 'ajax_reset_ai']);
        
        add_shortcode('gn_chatbot_page', [$this, 'render_shortcode']);
    }

    public function check_config() {
        if (!defined('GNBOT_OPENAI_KEY')) {
            return "⚠️ [설정 오류] API 키가 설정되지 않았습니다.";
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
        wp_enqueue_style('gnbot-style', plugin_dir_url(__FILE__) . 'style.css', [], '1.3'); 
        wp_enqueue_script('gnbot-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '1.3', true);
        wp_localize_script('gnbot-script', 'gnBotSettings', ['ajax_url' => admin_url('admin-ajax.php')]);
    }

    private function save_message($phone, $name, $msg, $sender) {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {
            $this->create_table();
        }
        $wpdb->insert($this->table_name, ['phone' => $phone, 'name' => $name, 'message' => $msg, 'sender' => $sender, 'created_at' => current_time('mysql')]);
    }

    // ==============================================================================
    // [기능 1] 지점 모호성 해결 (PHP 단에서 즉시 처리)
    // ==============================================================================
    private function check_branch_ambiguity($message) {
        $msg = str_replace(' ', '', $message); // 공백 제거 후 비교
        
        // 사용자가 "성수점" 혹은 "성수"라고만 했을 때 (구체적인 호점 언급 없이)
        if ((strpos($msg, '성수') !== false) && (strpos($msg, '1호') === false && strpos($msg, '2호') === false)) {
            return "성수점은 **성수 1호점**과 **성수 2호점**이 있습니다.\n어느 지점의 공실을 조회해 드릴까요?";
        }
        return false;
    }

    // ==============================================================================
    // [기능 2] 노션 데이터 조회 및 정제 (2인실 구분 & 공실 필터링)
    // ==============================================================================
    private function fetch_room_status_safe() {
        if (!defined('GNBOT_DATABASE_ID') || !GNBOT_DATABASE_ID) return "(DB설정안됨)";

        $url = "https://api.notion.com/v1/databases/" . GNBOT_DATABASE_ID . "/query";
        $args = [
            'headers' => ['Authorization' => 'Bearer ' . GNBOT_NOTION_KEY, 'Notion-Version' => '2022-06-28', 'Content-Type' => 'application/json'],
            'method' => 'POST', 'timeout' => 10
        ];
        
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) != 200) {
            return "(노션 연결 실패 - 관리자 확인 필요)"; 
        }

        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        $today = date("Y-m-d");
        $final_lines = [];

        if (is_array($data) && !empty($data['results'])) {
            foreach ($data['results'] as $p) {
                // 1. 기본 데이터 추출
                $branch = $p['properties']['지점명']['select']['name'] ?? '';
                $raw_room_name = $p['properties']['방번호']['title'][0]['plain_text'] ?? '';
                
                // 입주현황 (select 혹은 rollup)
                $status = '-';
                if (isset($p['properties']['입주현황']['select']['name'])) {
                    $status = $p['properties']['입주현황']['select']['name'];
                } elseif (isset($p['properties']['입주현황']['rollup']['array'][0]['select']['name'])) {
                    $status = $p['properties']['입주현황']['rollup']['array'][0]['select']['name'];
                }

                if (!$branch || !$raw_room_name) continue;

                // 2. [필터링] '거주중'이거나 '예약'된 방은 아예 리스트에서 제외 (강남점 오류 해결)
                // 퇴실완료, 비어있음(null)만 통과
                if (strpos($status, '거주') !== false || strpos($status, '계약') !== false || strpos($status, '예약') !== false) {
                    continue; 
                }

                // 3. 계약 기간 확인 (미래에 입주 예정인 방도 제외)
                $contract_end = null;
                $dates = $p['properties']['계약기간']['rollup']['array'] ?? [];
                // rollup이 아니라 date 타입일 경우 처리
                if (empty($dates) && isset($p['properties']['계약기간']['date'])) {
                    $dates = [$p['properties']['계약기간']];
                }

                foreach ($dates as $d) {
                    $end_date = $d['date']['end'] ?? $d['date']['start'] ?? null;
                    if ($end_date && $end_date >= $today) {
                        // 계약이 아직 안 끝난 방 -> 제외
                        continue 2; // 바깥 foreach(방 루프)로 이동
                    }
                }

                // 4. [포맷팅] 2인실 여부 및 이름 예쁘게 만들기
                // 정규식: 이름 끝에 '-숫자'가 붙으면 2인실로 간주 (예: SS2_Room1-1)
                $display_name = $raw_room_name;
                $is_double = false;

                if (preg_match('/-(\d+)$/', $raw_room_name, $matches)) {
                    $is_double = true;
                    // 예: 성수 2호점 1번방 (2인실)
                    // 기존 raw name을 그대로 보여주되 태그를 붙임
                    $display_name = "$raw_room_name (2인실)"; 
                } else {
                    $display_name = "$raw_room_name (1인실)";
                }

                // 공실 리스트에 추가
                $final_lines[] = "- [$branch] **$display_name** : 즉시 입주 가능";
            }
        }

        if (empty($final_lines)) {
            return "현재 즉시 입주 가능한 공실이 없습니다.";
        }

        return implode("\n", $final_lines);
    }

    public function ajax_chat_submit() {
        $config_err = $this->check_config();
        if ($config_err) { wp_send_json_error($config_err); return; }

        $msg = sanitize_text_field($_POST['message'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');

        $this->save_message($phone, $name, $msg, '고객');

        // 관리자 모드 체크
        if (get_option('gnbot_mute_' . $phone)) {
            wp_send_json_success('관리자 상담 모드입니다. (AI 답변 없음)');
            return;
        }

        // [Logic 1] 지점 되묻기 (AI 호출 전 가로채기)
        $ambiguity_reply = $this->check_branch_ambiguity($msg);
        if ($ambiguity_reply) {
            $this->save_message($phone, $name, $ambiguity_reply, 'AI'); // 시스템이 아닌 AI가 말한 것처럼 저장
            wp_send_json_success($ambiguity_reply);
            return;
        }
        
        // [Logic 2] 노션 데이터 가져오기
        $room_info_text = $this->fetch_room_status_safe();
        $today = date("Y-m-d");

        // [Logic 3] AI 프롬프트 구성
        $system_prompt = "당신은 쉐어하우스 '공간나인'의 친절한 매니저입니다.\n" .
                         "고객명: $name, 오늘: $today\n\n" .
                         
                         "[실시간 공실 현황 (입주 가능한 방만 표시됨)]\n" .
                         "$room_info_text\n\n" .

                         "[답변 지침]\n" .
                         "1. 위 목록에 있는 방은 모두 '즉시 입주 가능'한 상태입니다.\n" .
                         "2. 고객이 특정 지점을 물어보면 해당 지점의 공실만 안내하세요.\n" .
                         "3. 방 이름 뒤에 '(2인실)'이 있다면, 반드시 '이 방은 2인실입니다'라고 언급해주세요.\n" .
                         "4. 만약 목록에 없는 지점을 묻거나 공실이 없다면 대기 예약을 안내하세요.\n" .
                         "5. 인사는 짧게(50자 이내) 하고 바로 본론을 말해주세요.";

        $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . GNBOT_OPENAI_KEY, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => 'gpt-4o-mini', 
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $msg]
                ]
            ]),
            'timeout' => 20
        ]);

        if (is_wp_error($res)) {
            $err = $res->get_error_message();
            $this->save_message($phone, $name, "오류: $err", '시스템');
            wp_send_json_error("AI 연결 실패");
        } else {
            $body = json_decode(wp_remote_retrieve_body($res), true);
            $reply = $body['choices'][0]['message']['content'] ?? '죄송합니다. 답변을 생성하지 못했습니다.';
            $this->save_message($phone, $name, $reply, 'AI');
            wp_send_json_success($reply);
        }
    }

    // ... (이하 기존 Admin 관련 함수들은 변경 없음, 그대로 유지) ...
    
    public function ajax_get_history() {
        global $wpdb;
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $results = $wpdb->get_results($wpdb->prepare("SELECT message as msg, sender, created_at FROM $this->table_name WHERE phone = %s ORDER BY created_at ASC", $phone), ARRAY_A);
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
        update_option('gnbot_mute_' . $phone, true);
        wp_send_json_success();
    }

    public function ajax_reset_ai() {
        $phone = sanitize_text_field($_POST['phone']);
        delete_option('gnbot_mute_' . $phone);
        $this->save_message($phone, '시스템', 'AI 상담이 다시 활성화되었습니다.', '시스템');
        wp_send_json_success();
    }

    public function add_admin_menu() {
        add_menu_page('AI 상담', 'AI 상담', 'manage_options', 'gnbot-admin', [$this, 'admin_page_html'], 'dashicons-groups', 6);
    }

    public function admin_page_html() {
        // (기존 admin_page_html 코드와 동일합니다. 길이상 생략하지 않고 그대로 둡니다)
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
                <div id="gn-admin-chat-box" style="flex:1; padding:20px; overflow-y:auto; background:#f4f6f8;"></div>
                <div style="padding:15px; border-top:1px solid #ddd; background:#fff; display:flex;">
                    <input type="text" id="gn-admin-input" style="flex:1; padding:8px;" placeholder="답변 입력..." onkeypress="if(event.keyCode==13) sendAdminMsg()">
                    <button class="button button-primary" onclick="sendAdminMsg()" style="margin-left:10px;">전송</button>
                </div>
            </div>
        </div>
        <script>
            var currentPhone='', currentName='';
            function getAdminTime(dateStr) {
                if(!dateStr || dateStr === '0000-00-00 00:00:00') return '';
                let t = dateStr.split(/[- :]/);
                let date = new Date(t[0], t[1]-1, t[2], t[3], t[4], t[5]);
                let mon = date.getMonth() + 1;
                let day = date.getDate();
                let h = date.getHours();
                let m = date.getMinutes();
                let ampm = h >= 12 ? '오후' : '오전';
                h = h % 12; h = h ? h : 12;
                m = m < 10 ? '0'+m : m;
                return `${mon}/${day} ${ampm} ${h}:${m}`;
            }
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
                            var isMe = (msg.sender === '관리자'); 
                            if(msg.sender === '시스템') {
                                box.innerHTML += `<div style="text-align:center; margin:10px; color:#999; font-size:12px;">- ${msg.msg} -</div>`;
                                return;
                            }
                            var rowStyle = `display:flex; align-items:flex-end; margin-bottom:10px; justify-content:${isMe ? 'flex-end' : 'flex-start'};`;
                            var bubbleStyle = isMe 
                                ? "background:#e6f7ff; border:1px solid #91d5ff; color:#0050b3; border-radius:10px; padding:8px 12px; max-width:70%; text-align:left;"
                                : (msg.sender === 'AI' 
                                    ? "background:#fff; border:1px solid #ddd; color:#333; border-radius:10px; padding:8px 12px; max-width:70%; text-align:left;"
                                    : "background:#222; color:#fff; border-radius:10px; padding:8px 12px; max-width:70%; text-align:left;"); 
                            var timeHtml = `<span style="font-size:10px; color:#999; margin:0 5px; padding-bottom:2px; white-space:nowrap;">${getAdminTime(msg.created_at)}</span>`;
                            var bubbleHtml = `<div style="${bubbleStyle}">${msg.msg.replace(/\n/g, '<br>')}</div>`;
                            var html = `<div style="${rowStyle}">` + (isMe ? timeHtml + bubbleHtml : bubbleHtml + timeHtml) + `</div>`;
                            box.innerHTML += html;
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
        <script>
            function checkPrivacyAndStart() {
                var chk = document.getElementById('gn-privacy-agree');
                if(!chk.checked) {
                    alert('개인정보 수집 및 이용에 동의해주셔야 상담이 가능합니다.');
                    return;
                }
                startChat(); 
            }
        </script>
        <div class="gn-phone-container">
            <div id="gn-login">
                <div class="gn-title">👋 입주 문의</div>
                <div class="gn-desc">공간나인 AI 매니저입니다.<br>연락처를 남겨주시면 바로 연결됩니다.</div>
                <input type="text" id="uName" class="gn-input" placeholder="예: 홍길동">
                <input type="text" id="uPhone" class="gn-input" placeholder="예: 010-1234-5678">
                <div style="margin: 15px 0 5px 0; text-align: left;">
                    <label style="font-size: 13px; color: #555; display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="gn-privacy-agree" style="width:auto; margin-right: 8px;">
                        <span>[필수] 개인정보 수집 및 이용 동의</span>
                    </label>
                </div>
                <button class="gn-btn-primary" onclick="checkPrivacyAndStart()">상담 시작하기</button>
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