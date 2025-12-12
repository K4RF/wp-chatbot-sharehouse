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

function sendM() {
    if (isErrorHappened) return alert('오류가 발생했습니다. 페이지를 새로고침해주세요.');

    let i = document.getElementById('ci');
    let m = i.value.trim();
    if (!m) return;
    i.disabled = true;
    appendMsg(m, '고객');
    i.value = '';

    let b = document.getElementById('cb');
    let l = document.createElement('div');
    l.id = 'ai-loader';
    l.innerText = 'AI가 답변을 작성 중입니다...';
    l.style.color = '#888';
    l.style.fontSize = '12px';
    l.style.marginLeft = '5px';
    b.appendChild(l);
    b.scrollTop = b.scrollHeight;

    // ★ [중요] gnBotSettings.ajax_url은 PHP 파일에서 전달받은 값입니다.
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
            appendMsg("🚫 " + r.data, '시스템');
        } else {
            pollMessages();
        }
        i.disabled = false;
        i.focus();
    }).fail(function(xhr) {
        let loader = document.getElementById('ai-loader');
        if (loader) loader.remove();

        isErrorHappened = true;
        appendMsg("🚫 서버 통신 오류 (관리자에게 문의하세요)", '시스템');
        console.error("Server Error:", xhr);
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
            appendMsg("안녕하세요! 공간나인 매니저입니다.<br>무엇을 도와드릴까요?", 'bot');
            r.data.forEach(function(msg) {
                appendMsg(msg.msg, msg.sender);
            });
            b.scrollTop = b.scrollHeight;
        }
    });
}

function appendMsg(msg, sender) {
    let b = document.getElementById('cb');
    let cls = sender === '고객' ? 'user' : (sender === '관리자' ? 'admin' : 'bot');
    if (sender === '시스템') {
        b.innerHTML += `<div style="text-align:center; color:red; font-size:12px; margin:10px;">${msg}</div>`;
    } else {
        let div = document.createElement('div');
        div.className = 'bubble ' + cls;
        div.innerHTML = msg.replace(/\n/g, '<br>');
        div.style.marginBottom = '10px';
        b.appendChild(div);
    }
}