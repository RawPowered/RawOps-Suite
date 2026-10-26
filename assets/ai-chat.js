// AI Chat Widget - Streamlined Version
// Focus on conversation, prices, tips, and URLs - no complex forms

(function() {
    'use strict';

    // Prevent duplicate initialization
    if (window.AI_CHAT_INITIALIZED) {
        console.log('AI Chat already initialized, skipping duplicate initialization');
        return;
    }
    window.AI_CHAT_INITIALIZED = true;

    let isOpen = false;
    let conversationHistory = [];
    let isLoading = false;

    // Create chat widget
    function createChatWidget() {
        // Chat button
        const chatButton = document.createElement('button');
        chatButton.id = 'ai-chat-button';
        chatButton.innerHTML = '💬';
        chatButton.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 24px;
            cursor: pointer;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        `;

        // Chat window
        const chatWindow = document.createElement('div');
        chatWindow.id = 'ai-chat-window';
        chatWindow.style.cssText = `
            position: fixed;
            bottom: 90px;
            right: 20px;
            width: 350px;
            height: 500px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            z-index: 1001;
            display: none;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        `;

        // Header
        const header = document.createElement('div');
        header.style.cssText = `
            padding: 16px;
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            border-radius: 12px 12px 0 0;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        `;
        header.innerHTML = `
            <span>AI Sales Assistant</span>
            <button id="close-chat" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">×</button>
        `;

        // Messages container
        const messagesContainer = document.createElement('div');
        messagesContainer.id = 'ai-messages';
        messagesContainer.style.cssText = `
            flex: 1;
            padding: 16px;
            overflow-y: auto;
            background: #f9fafb;
        `;

        // Input container
        const inputContainer = document.createElement('div');
        inputContainer.style.cssText = `
            padding: 16px;
            border-top: 1px solid #e5e7eb;
            background: white;
            border-radius: 0 0 12px 12px;
        `;

        const inputForm = document.createElement('form');
        inputForm.id = 'ai-input-form';
        inputForm.style.cssText = 'display: flex; gap: 8px;';

        const messageInput = document.createElement('input');
        messageInput.id = 'ai-message-input';
        messageInput.type = 'text';
        messageInput.placeholder = 'Ask about products, prices, tips...';
        messageInput.style.cssText = `
            flex: 1;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        `;

        const sendButton = document.createElement('button');
        sendButton.type = 'submit';
        sendButton.innerHTML = '→';
        sendButton.style.cssText = `
            padding: 10px 12px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        `;

        // Assemble the widget
        inputForm.appendChild(messageInput);
        inputForm.appendChild(sendButton);
        inputContainer.appendChild(inputForm);
        
        chatWindow.appendChild(header);
        chatWindow.appendChild(messagesContainer);
        chatWindow.appendChild(inputContainer);

        document.body.appendChild(chatButton);
        document.body.appendChild(chatWindow);

        // Event handlers
        chatButton.onclick = toggleChat;
        header.querySelector('#close-chat').onclick = toggleChat;
        inputForm.onsubmit = handleSubmit;

        // Initial welcome message
        displayMessage("Hello! I'm your AI sales assistant. I can help you with product information, prices, profit calculations, and business tips. What would you like to know?", 'assistant');
    }

    function toggleChat() {
        const chatWindow = document.getElementById('ai-chat-window');
        const chatButton = document.getElementById('ai-chat-button');
        
        isOpen = !isOpen;
        chatWindow.style.display = isOpen ? 'flex' : 'none';
        
        if (isOpen) {
            chatButton.style.transform = 'scale(0.9)';
            document.getElementById('ai-message-input').focus();
        } else {
            chatButton.style.transform = 'scale(1)';
        }
    }

    async function handleSubmit(e) {
        e.preventDefault();
        
        if (isLoading) return;
        
        const messageInput = document.getElementById('ai-message-input');
        const message = messageInput.value.trim();
        
        if (!message) return;
        
        // Display user message
        displayMessage(message, 'user');
        messageInput.value = '';
        
        // Show loading
        isLoading = true;
        const loadingElement = displayMessage('Thinking...', 'assistant', true);
        
        try {
            const endpoint = (window.__BASE_PREFIX || '') + '/api/ai-chat.php';
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    history: conversationHistory
                })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            // Remove loading message
            if (loadingElement) {
                loadingElement.remove();
            }
            
            if (data.success) {
                displayMessage(data.reply, 'assistant');
                
                // Update conversation history
                conversationHistory.push(
                    { role: 'user', content: message },
                    { role: 'assistant', content: data.reply }
                );
                
                // Keep history manageable (last 10 exchanges)
                if (conversationHistory.length > 20) {
                    conversationHistory = conversationHistory.slice(-20);
                }
            } else {
                displayMessage(data.reply || 'Sorry, I encountered an error. Please try again.', 'assistant');
            }
            
        } catch (error) {
            console.error('Chat error:', error);
            
            // Remove loading message
            if (loadingElement) {
                loadingElement.remove();
            }
            
            displayMessage('Sorry, I\'m having trouble connecting. Please check your internet connection and try again.', 'assistant');
        } finally {
            isLoading = false;
        }
    }

    function displayMessage(text, sender, isLoading = false) {
        const messagesContainer = document.getElementById('ai-messages');
        
        const messageElement = document.createElement('div');
        messageElement.style.cssText = `
            margin-bottom: 12px;
            display: flex;
            ${sender === 'user' ? 'justify-content: flex-end;' : 'justify-content: flex-start;'}
        `;
        
        const bubble = document.createElement('div');
        bubble.style.cssText = `
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.4;
            ${sender === 'user' 
                ? 'background: #059669; color: white; border-bottom-right-radius: 4px;'
                : 'background: white; color: #374151; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px;'
            }
            ${isLoading ? 'opacity: 0.7; font-style: italic;' : ''}
        `;
        
        // Convert URLs to clickable links
        const linkedText = text.replace(
            /(https?:\/\/[^\s]+|\/[^\s]*\.php|\/[^\s]*\/)/g,
            '<a href="$1" target="_blank" style="color: #2563eb; text-decoration: underline;">$1</a>'
        );
        
        bubble.innerHTML = linkedText;
        messageElement.appendChild(bubble);
        messagesContainer.appendChild(messageElement);
        
        // Scroll to bottom
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        return isLoading ? messageElement : null;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createChatWidget);
    } else {
        createChatWidget();
    }

})();
