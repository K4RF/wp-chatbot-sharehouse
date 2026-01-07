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
    
    // 개인정보 동의 체크 여부 확인 (기능 유지)
    var chk = document.getElementById('gn-privacy-agree');
    if(chk && !chk.checked) {
        return alert('개인정보 수집 및 이용에 동의해주셔야 상담이 가능합니다.');
    }

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

function getPrettyTime(dateStr) {
    let date = new Date();
    if(dateStr && dateStr !== '0000-00-00 00:00:00') {
        let t = dateStr.split(/[- :]/);
        date = new Date(t[0], t[1]-1, t[2], t[3], t[4], t[5]);
    }
    let h = date.getHours();
    let m = date.getMinutes();
    let ampm = h >= 12 ? '오후' : '오전';
    h = h % 12; h = h ? h : 12;
    m = m < 10 ? '0'+m : m;
    return `${ampm} ${h}:${m}`;
}

function sendM() {
    if (isErrorHappened) return alert('오류가 발생했습니다. 페이지를 새로고침해주세요.');

    let i = document.getElementById('ci');
    let m = i.value.trim();
    if (!m) return;
    i.disabled = true;
    
    appendMsg(m, '고객', null); 
    i.value = '';

    let b = document.getElementById('cb');
    
    // 로딩 표시 전 스크롤 강제 하단 이동
    b.scrollTop = b.scrollHeight; 

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
            let b = document.getElementById('cb');
            b.innerHTML += `<div style="text-align:center; color:red; font-size:12px; margin:10px;">🚫 ${r.data}</div>`;
        } else {
            pollMessages(); // 전송 성공 시 즉시 새로고침
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
            if (document.getElementById('ai-loader')) return; // 답변 작성 중엔 갱신 건너뜀

            // ★ [수정됨] 스크롤 위치 계산 로직 (여기가 핵심입니다!)
            // 현재 스크롤이 맨 밑에서 50px 이내에 있는지 확인
            let isNearBottom = b.scrollHeight - b.scrollTop - b.clientHeight < 50;
            let previousScrollTop = b.scrollTop; // 현재 보고 있는 위치 저장

            b.innerHTML = ''; // 내용 초기화

            // 웰컴 메시지
            let welcome = document.createElement('div');
            welcome.className = 'msg-row bot-row';
            welcome.innerHTML = `<div class="bubble bot">안녕하세요! 공간나인 매니저입니다.<br>무엇을 도와드릴까요?</div>`;
            b.appendChild(welcome);

            // 대화 내역 다시 그리기
            r.data.forEach(function(msg) {
                appendMsg(msg.msg, msg.sender, msg.created_at);
            });

            // ★ [수정됨] 스크롤 처리
            if (isNearBottom) {
                // 원래 맨 밑을 보고 있었다면 -> 새 메시지가 왔으니 맨 밑으로 내림
                b.scrollTop = b.scrollHeight;
            } else {
                // 옛날 대화를 읽고 있었다면 -> 위치 유지 (튕김 방지)
                b.scrollTop = previousScrollTop;
            }
        }
    });
}

function appendMsg(msg, sender, timeStr) {
    let b = document.getElementById('cb');
    
    if (sender === '시스템') {
        b.innerHTML += `<div style="text-align:center; color:red; font-size:12px; margin:10px;">${msg}</div>`;
        return;
    }

    let prettyTime = getPrettyTime(timeStr);
    let row = document.createElement('div');
    let cls = sender === '고객' ? 'user' : (sender === '관리자' ? 'admin' : 'bot');
    
    row.className = 'msg-row ' + (sender === '고객' ? 'user-row' : 'bot-row');

    let bubbleHtml = `<div class="bubble ${cls}">${msg.replace(/\n/g, '<br>')}</div>`;
    let timeHtml = `<span class="msg-time">${prettyTime}</span>`;

    if (sender === '고객') {
        row.innerHTML = timeHtml + bubbleHtml;
    } else {
        row.innerHTML = bubbleHtml + timeHtml;
    }

    b.appendChild(row);
}