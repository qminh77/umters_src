document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const resultDiv = document.getElementById('result');
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressText.textContent = '0%';
    resultDiv.innerHTML = '';

    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', function(event) {
        if (event.lengthComputable) {
            const percentComplete = Math.round((event.loaded / event.total) * 100);
            progressBar.style.width = percentComplete + '%';
            progressText.textContent = percentComplete + '%';
        }
    });
    
    xhr.addEventListener('load', function() {
        const data = JSON.parse(xhr.responseText);
        progressContainer.style.display = 'none';
        if (data.success) {
            resultDiv.innerHTML = `
                Tải lên thành công! Link tải: 
                <a href="${data.link}" target="_blank">${data.link}</a>
                <button class="copy-btn" onclick="copyToClipboard('${data.link}')">Sao chép</button>
            `;
        } else {
            resultDiv.innerHTML = `Lỗi: ${data.message}`;
        }
    });
    
    xhr.addEventListener('error', function() {
        progressContainer.style.display = 'none';
        resultDiv.innerHTML = 'Lỗi: Không thể tải file lên!';
    });
    
    xhr.open('POST', 'upload.php', true);
    xhr.send(formData);
});

function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Link đã được sao chép vào clipboard!');
        }).catch(err => {
            fallbackCopyTextToClipboard(text);
            alert('Sao chép bằng phương pháp thay thế. Kiểm tra clipboard!');
        });
    } else {
        fallbackCopyTextToClipboard(text);
        alert('Sao chép bằng phương pháp thay thế. Kiểm tra clipboard!');
    }
}

function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.select();
    try {
        document.execCommand('copy');
    } catch (err) {
        console.error('Không thể sao chép: ', err);
    }
    document.body.removeChild(textArea);
}