/**
 * Signature Canvas Handler
 * Provides drawing functionality for digital signatures
 */

class SignatureCanvas {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) {
            console.error('Canvas element not found:', canvasId);
            return;
        }
        
        this.ctx = this.canvas.getContext('2d');
        this.isDrawing = false;
        this.hasSignature = false;
        this.strokes = [];
        this.currentStroke = [];
        
        // Set canvas size
        this.resizeCanvas();
        
        // Initialize drawing settings
        this.ctx.strokeStyle = '#000000';
        this.ctx.lineWidth = 2;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        
        // Bind events
        this.bindEvents();
        
        // Handle window resize
        window.addEventListener('resize', () => this.resizeCanvas());
    }
    
    resizeCanvas() {
        const rect = this.canvas.getBoundingClientRect();
        this.canvas.width = rect.width;
        this.canvas.height = rect.height;
        this.redraw();
    }
    
    bindEvents() {
        // Mouse events
        this.canvas.addEventListener('mousedown', (e) => this.startDrawing(e));
        this.canvas.addEventListener('mousemove', (e) => this.draw(e));
        this.canvas.addEventListener('mouseup', () => this.stopDrawing());
        this.canvas.addEventListener('mouseleave', () => this.stopDrawing());
        
        // Touch events
        this.canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            this.startDrawing(e.touches[0]);
        });
        this.canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            this.draw(e.touches[0]);
        });
        this.canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            this.stopDrawing();
        });
    }
    
    getCoordinates(event) {
        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;
        
        return {
            x: (event.clientX - rect.left) * scaleX,
            y: (event.clientY - rect.top) * scaleY
        };
    }
    
    startDrawing(event) {
        this.isDrawing = true;
        this.hasSignature = true;
        this.currentStroke = [];
        
        const coords = this.getCoordinates(event);
        this.currentStroke.push(coords);
        
        this.ctx.beginPath();
        this.ctx.moveTo(coords.x, coords.y);
    }
    
    draw(event) {
        if (!this.isDrawing) return;
        
        const coords = this.getCoordinates(event);
        this.currentStroke.push(coords);
        
        this.ctx.lineTo(coords.x, coords.y);
        this.ctx.stroke();
    }
    
    stopDrawing() {
        if (!this.isDrawing) return;
        
        this.isDrawing = false;
        
        if (this.currentStroke.length > 0) {
            this.strokes.push([...this.currentStroke]);
            this.currentStroke = [];
        }
        
        // Trigger change event
        this.canvas.dispatchEvent(new Event('signature-changed'));
    }
    
    clear() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.strokes = [];
        this.currentStroke = [];
        this.hasSignature = false;
        this.canvas.dispatchEvent(new Event('signature-changed'));
    }
    
    redraw() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        this.strokes.forEach(stroke => {
            if (stroke.length === 0) return;
            
            this.ctx.beginPath();
            this.ctx.moveTo(stroke[0].x, stroke[0].y);
            
            for (let i = 1; i < stroke.length; i++) {
                this.ctx.lineTo(stroke[i].x, stroke[i].y);
            }
            
            this.ctx.stroke();
        });
    }
    
    isEmpty() {
        return !this.hasSignature || this.strokes.length === 0;
    }
    
    getSignatureData() {
        if (this.isEmpty()) {
            return null;
        }
        
        return JSON.stringify({
            strokes: this.strokes,
            width: this.canvas.width,
            height: this.canvas.height,
            timestamp: Date.now()
        });
    }
    
    loadSignatureData(jsonData) {
        try {
            const data = JSON.parse(jsonData);
            this.strokes = data.strokes || [];
            this.hasSignature = this.strokes.length > 0;
            this.redraw();
        } catch (e) {
            console.error('Failed to load signature data:', e);
        }
    }
    
    toDataURL() {
        return this.canvas.toDataURL('image/png');
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SignatureCanvas;
}




