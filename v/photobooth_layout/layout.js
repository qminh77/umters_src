class PhotoboothLayout {
    constructor() {
        this.scale = 2;
        this.photoWidth = 240 * this.scale;
        this.photoHeight = 180 * this.scale;
        this.padding = 16 * this.scale;
        this.frameWidth = 8 * this.scale;
        this.frameColor = '#FFFFFF'; // Khung mặc định là trắng
    }

    // Hàm đổi màu khung (sẽ dùng sau khi chụp xong)
    setFrameColor(color) {
        this.frameColor = color;
    }

    createFrame(canvas, width, height) {
        return new Promise((resolve) => {
            const ctx = canvas.getContext('2d');
            ctx.save();
            ctx.lineWidth = this.frameWidth;
            ctx.strokeStyle = this.frameColor;
            ctx.strokeRect(0, 0, width, height);
            ctx.restore();
            resolve();
        });
    }

    // Không cần roundRect nữa, giữ lại cho tương lai nếu cần
    roundRect(ctx, x, y, w, h, r) {}

    addTimestamp(canvas, text) {
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
        ctx.fillRect(0, height - 30 * this.scale, width, 30 * this.scale);
        ctx.fillStyle = '#FFFFFF';
        ctx.font = `${14 * this.scale}px Arial`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, width / 2, height - 15 * this.scale);
    }

    addWebsiteInfo(canvas, domain) {
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
        ctx.fillRect(0, height - 60 * this.scale, width, 30 * this.scale);
        ctx.fillStyle = '#FFFFFF';
        ctx.font = `${14 * this.scale}px Arial`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(domain, width / 2, height - 45 * this.scale);
    }

    async renderLayout(layout, images) {
        let canvasWidth, canvasHeight;
        switch(layout) {
            case 'A': // 4 vertical
                canvasWidth = this.photoWidth + this.padding * 2;
                canvasHeight = (this.photoHeight * 4) + (this.padding * 5);
                break;
            case 'B': // 3 vertical
                canvasWidth = this.photoWidth + this.padding * 2;
                canvasHeight = (this.photoHeight * 3) + (this.padding * 4);
                break;
            case 'C': // 2 vertical
                canvasWidth = this.photoWidth + this.padding * 2;
                canvasHeight = (this.photoHeight * 2) + (this.padding * 3);
                break;
            case 'D': // 2x3 grid
                canvasWidth = (this.photoWidth * 3) + (this.padding * 4);
                canvasHeight = (this.photoHeight * 2) + (this.padding * 3);
                break;
        }
        const canvas = document.createElement('canvas');
        canvas.width = canvasWidth;
        canvas.height = canvasHeight + 60 * this.scale; // Add space for timestamp and website info
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        // Chờ vẽ frame xong
        await this.createFrame(canvas, canvasWidth, canvasHeight);
        // Draw images (wait for all images loaded)
        await Promise.all(images.map((imgSrc, index) => {
            return new Promise(resolve => {
                const img = new Image();
                img.src = imgSrc;
                img.onload = () => {
                    let x, y;
                    switch(layout) {
                        case 'A':
                            x = this.padding;
                            y = this.padding + (index * (this.photoHeight + this.padding));
                            break;
                        case 'B':
                            x = this.padding;
                            y = this.padding + (index * (this.photoHeight + this.padding));
                            break;
                        case 'C':
                            x = this.padding;
                            y = this.padding + (index * (this.photoHeight + this.padding));
                            break;
                        case 'D':
                            x = this.padding + ((index % 3) * (this.photoWidth + this.padding));
                            y = this.padding + (Math.floor(index / 3) * (this.photoHeight + this.padding));
                            break;
                    }
                    ctx.drawImage(img, x, y, this.photoWidth, this.photoHeight);
                    resolve();
                };
                img.onerror = resolve;
            });
        }));
        // Add timestamp and website info
        const now = new Date();
        const timestamp = now.toLocaleString('vi-VN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        this.addTimestamp(canvas, timestamp);
        this.addWebsiteInfo(canvas, window.location.hostname);
        return canvas;
    }
} 