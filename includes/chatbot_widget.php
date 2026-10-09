<?php
// includes/chatbot_widget.php - floating assistant for public pages
?>
<div class="chatbot-widget" id="chatbotWidget" data-phone="<?php echo e(school_phone()); ?>">
    <button type="button" class="chatbot-button" id="chatbotButton" aria-label="Open chat assistant" aria-expanded="false" aria-controls="chatbotContainer">
        <i class="fas fa-comment-dots" aria-hidden="true"></i>
        <span class="chatbot-notification" aria-hidden="true">1</span>
    </button>

    <section class="chatbot-container" id="chatbotContainer" role="dialog" aria-label="Chat assistant" aria-modal="false" hidden>
        <header class="chatbot-header">
            <div class="chatbot-title">
                <span class="chatbot-avatar" aria-hidden="true"><i class="fas fa-graduation-cap"></i></span>
                <div>
                    <h3>School Assistant</h3>
                    <p><span class="chatbot-status-dot"></span> Answers from official school information</p>
                </div>
            </div>
            <div class="chatbot-actions">
                <button type="button" class="chatbot-reset" id="chatbotReset" aria-label="Start a new conversation" title="New chat"><i class="fas fa-rotate-right"></i></button>
                <button type="button" class="chatbot-close" id="chatbotClose" aria-label="Close chat"><i class="fas fa-times"></i></button>
            </div>
        </header>
        <div class="chatbot-messages" id="chatbotMessages" role="log" aria-live="polite" aria-relevant="additions"></div>
        <div class="quick-replies" id="chatbotSuggestions"></div>
        <form class="chatbot-input" id="chatbotForm" autocomplete="off">
            <label for="chatbotInput" class="sr-only">Type your question</label>
            <input type="text" id="chatbotInput" maxlength="300" placeholder="Ask about admissions, fees, programmes..." enterkeyhint="send">
            <button type="submit" id="chatbotSend" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>
        </form>
        <p class="chatbot-footer">For urgent matters call <a href="tel:<?php echo e(school_phone()); ?>"><?php echo e(school_phone()); ?></a></p>
    </section>
</div>
<link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/css/chatbot.css?v=3">
<script src="<?php echo e(BASE_URL); ?>/assets/js/chatbot.js?v=3" defer></script>
