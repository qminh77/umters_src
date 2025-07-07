document.addEventListener('DOMContentLoaded', function () {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

function showQuiz() {
    let answer = prompt("‘Khay phủ khăn trắng’ tượng trưng cho điều gì?\nA. Tình yêu bị giết chết\nB. Chuẩn mực xã hội");
    if (answer && answer.toUpperCase() === 'A') {
        alert("Đúng! Nó biểu trưng cho tình yêu và dục vọng bị hủy hoại.");
    } else {
        alert("Sai rồi! Hãy thử lại nhé.");
    }
}