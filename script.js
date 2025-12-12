let myName = '', myPhone = '';
let isErrorHappened = false;

document.addEventListener('DOMContentLoaded', function() {
    const storedName = localStorage.getItem('gn_chat_name');
    const storedPhone = localStorage.getItem('gn_chat_phone');
    if (storedName && storedPhone) { 
        myName = storedName; 
        myPhone = storedPhone; 
        showChatScreen(); 
    }
});

function startChat() {
    myName = document.getElementById('uName').value.trim();
    myPhone = document.getElementById('uPhone').value.trim();
    if (!myName || !myPhone) return alert('정보를 모두 입력해주세요.');
    localStorage.setItem('gn_chat_name', myName);
    localStorage.setItem('gn_chat_phone', myPhone);
    showChatScreen();
}

function showChatScreen() {
    document.getElementById('gn-login').style.display = 'none';
    document.getElementById('gn-chat').style.display = 'flex';
    pollMessages();
    setInterval(pollMessages, 3000);
}

function logoutChat() {
    if (confirm('상담을 종료하고 나가시겠습니까?')) {
        localStorage.removeItem('gn_chat_name');
        localStorage.removeItem('gn_chat_phone');
        location.reload();
    }
}

// ★ [추가] 시간을 '오후 2:30' 형식으로 만드는 함수
function getPrettyTime(dateStr) {
    let date = new Date(); // 기본값: 현재 시간
    if(dateStr && dateStr !== '0000-00-00 00:00:00') {
        // 서버 시간(YYYY-MM-DD HH:MM:SS)을 파싱
        let t = dateStr.split(/[- :]/);
        date = new Date(t[0], t[1]-1, t[2], t[3], t[4], t[5]);
    }

    let h = date.getHours();
    let m = date.getMinutes();
    let ampm = h >= 12 ? '오후' : '오전';
    h = h % 12;
    h = h ? h : 12; // 0시는 12시로 표시
    m = m < 10 ? '0'+m : m;
    
    return `${ampm} ${h}:${m}`;
}

function sendM() {
    if (isErrorHappened) return alert('오류가 발생했습니다. 페이지를 새로고침해주세요.');

    let i = document.getElementById('ci');
    let m = i.value.trim();
    if (!m) return;
    i.disabled = true;
    
    // 내 메시지는 즉시 표시 (현재 시간 사용)
    appendMsg(m, '고객', null); 
    i.value = '';

    let b = document.getElementById('cb');
    let l = document.createElement('div');
    l.id = 'ai-loader';
    l.innerText = 'AI가 답변을 작성 중입니다...';
    l.style.color = '#555'; l.style.fontSize = '12px'; l.style.margin = '10px'; l.style.textAlign='center';
    b.appendChild(l);
    b.scrollTop = b.scrollHeight;

    jQuery.post(gnBotSettings.ajax_url, {
        action: 'gnbot_chat_submit',
        message: m,
        name: myName,
        phone: myPhone
    }, function(r) {
        let loader = document.getElementById('ai-loader');
        if (loader) loader.remove();

        if (!r.success) {
            isErrorHappened = true;
            // 시스템 에러 메시지는 시간 표시 안 함
            let b = document.getElementById('cb');
            b.innerHTML += `<div style="text-align:center; color:red; font-size:12px; margin:10px;">🚫 ${r.data}</div>`;
        } else {
            pollMessages();
        }
        i.disabled = false;
        i.focus();
    }).fail(function(xhr) {
        let loader = document.getElementById('ai-loader');
        if (loader) loader.remove();
        isErrorHappened = true;
        let b = document.getElementById('cb');
        b.innerHTML += `<div style="text-align:center; color:red; font-size:12px; margin:10px;">🚫 서버 통신 오류</div>`;
        i.disabled = false;
    });
}

function pollMessages() {
    if (!myPhone || isErrorHappened) return;

    jQuery.post(gnBotSettings.ajax_url, {
        action: 'gnbot_get_history',
        phone: myPhone
    }, function(r) {
        if (r.success) {
            let b = document.getElementById('cb');
            if (document.getElementById('ai-loader')) return;

            b.innerHTML = '';
            // 웰컴 메시지 (시간 없음)
            let welcome = document.createElement('div');
            welcome.className = 'msg-row bot-row';
            welcome.innerHTML = `<div class="bubble bot">안녕하세요! 공간나인 매니저입니다.<br>무엇을 도와드릴까요?</div>`;
            b.appendChild(welcome);

            r.data.forEach(function(msg) {
                appendMsg(msg.msg, msg.sender, msg.created_at);
            });
            b.scrollTop = b.scrollHeight;
        }
    });
}

// ★ [수정] 메시지 추가 함수 (시간 레이아웃 적용)
function appendMsg(msg, sender, timeStr) {
    let b = document.getElementById('cb');
    
    // 시스템 메시지 처리
    if (sender === '시스템') {
        b.innerHTML += `<div style="text-align:center; color:red; font-size:12px; margin:10px;">${msg}</div>`;
        return;
    }

    let prettyTime = getPrettyTime(timeStr); // 시간 포맷팅
    let row = document.createElement('div');
    let cls = sender === '고객' ? 'user' : (sender === '관리자' ? 'admin' : 'bot');
    
    // 행 클래스 설정 (user-row는 오른쪽 정렬, bot-row는 왼쪽 정렬)
    row.className = 'msg-row ' + (sender === '고객' ? 'user-row' : 'bot-row');

    let bubbleHtml = `<div class="bubble ${cls}">${msg.replace(/\n/g, '<br>')}</div>`;
    let timeHtml = `<span class="msg-time">${prettyTime}</span>`;

    // 고객이면: [시간] [말풍선] 순서 (Flex-end라 오른쪽 끝에 붙음)
    if (sender === '고객') {
        row.innerHTML = timeHtml + bubbleHtml;
    } else {
        // AI/관리자면: [말풍선] [시간] 순서
        row.innerHTML = bubbleHtml + timeHtml;
    }

    b.appendChild(row);
}