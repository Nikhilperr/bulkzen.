/**
 * Facebook Messenger Bulk Sender - Frontend JavaScript
 * Handles UI interactions and API communication
 */

// Global state
var conversations = [];
var filteredConversations = [];
var renderedCount = 0;
var RENDER_BATCH_SIZE = 50; // Render 50 rows at a time
var isRendering = false;
window.aiMessages = [];
window.aiAssignments = [];
window.aiGenerating = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    initializeApp();
});

/**
 * Initialize the application
 */
function initializeApp() {
    // Load pages for dropdown
    loadPages();

    // Check if a page is selected before loading conversations (check both desktop and mobile selectors)
    const pageSelector = document.getElementById('page-selector');
    const pageSelectorMobile = document.getElementById('page-selector-mobile');
    const selectedPageId = (pageSelector && pageSelector.value) || (pageSelectorMobile && pageSelectorMobile.value);

    if (selectedPageId) {
        // Load conversations only if a page is selected
        loadConversations();
    } else {
        // Show message that user needs to select a page
        const tbody = document.getElementById('conversations-tbody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="loading-message" style="font-size: 16px; font-weight: 600; color: #667eea; text-align: center; padding: 40px;">Please select a page to start</td></tr>';
        }
    }

    // Setup event listeners
    setupEventListeners();

    // Setup character counter
    setupCharacterCounter();

    // Setup AI message studio and manual/AI swipe
    setupComposeModeSwitch();
    setupAiComposer();

    // Sync mobile and desktop page selectors
    syncPageSelectors();

    // Setup dashboard nav toggle (if on dashboard page)
    setupDashboardNavToggle();

    // Logout confirm (desktop + sidebar)
    setupLogoutConfirm();

    // Setup scroll down button
    setupScrollDownButton();

    // Check subscription status
    checkSubscriptionStatus();

    // Check for payment return (only verify, don't play sound on refresh)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('payment_success') && urlParams.has('order_id')) {
        // Just check status and refresh subscription, don't play sound
        checkSubscriptionStatus();
        // Remove URL parameters to prevent sound on next refresh
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
    }
}

/**
 * Check subscription status and update UI
 */
function checkSubscriptionStatus() {
    const pageSelector = document.getElementById('page-selector');
    const pageId = pageSelector ? pageSelector.value : null;

    if (!pageId) return;

    fetch('api.php?action=get_subscription_status')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateSubscriptionUI(data);
            }
        })
        .catch(console.error);
}

/**
 * Update UI based on subscription status
 */
function updateSubscriptionUI(data) {
    // Store pending order globally for Resume Logic
    window.pendingOrder = data.pending_order || null;

    // 1. Update Header (Desktop)
    const headerCredits = document.getElementById('header-credits');
    if (headerCredits) {
        // Check plan_type FIRST to show correct plan name
        if (data.plan_type === 'standard') {
            headerCredits.innerHTML = `
                <div class="standard-badge" style="background: #ebf8ff; color: #2b6cb0; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-star"></i> Standard Plan
                    <span class="credits-left" style="background: rgba(255,255,255,0.5); padding: 2px 8px; border-radius: 10px; font-size: 12px;">${data.user_credits} credits</span>
                </div>
            `;
            sessionStorage.removeItem('upgrade_notified');
        } else if (data.is_premium && data.plan_type === 'unlimited') {
            // Calculate remaining days
            const expiresAt = new Date(data.expires_at);
            const now = new Date();
            const diffTime = Math.abs(expiresAt - now);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            let timerText = '';
            if (diffDays > 1) {
                timerText = `${diffDays} Days Left`;
            } else {
                const diffHours = Math.ceil(diffTime / (1000 * 60 * 60));
                timerText = `${diffHours} Hours Left`;
            }

            headerCredits.innerHTML = `
                <div class="premium-badge">
                    <i class="fas fa-crown"></i> Premium
                    <span class="days-left">${timerText}</span>
                </div>
            `;

            // Show notification if just upgraded via background check
            // We use a session storage flag to ensure we only show it once per session/upgrade
            if (data.just_upgraded && !sessionStorage.getItem('upgrade_notified')) {
                showPaymentSuccess(); // Reuse existing success modal/notification
                sessionStorage.setItem('upgrade_notified', 'true');
            }

        } else if (data.plan_type === 'standard') {
            headerCredits.innerHTML = `
                <div class="standard-badge" style="background: #ebf8ff; color: #2b6cb0; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-star"></i> Standard Plan
                    <span class="credits-left" style="background: rgba(255,255,255,0.5); padding: 2px 8px; border-radius: 10px; font-size: 12px;">${data.user_credits} credits</span>
                </div>
            `;
            sessionStorage.removeItem('upgrade_notified');
        } else {
            headerCredits.innerHTML = `
                <div class="free-badge">
                    Free Plan
                    <span class="credits-left">${data.remaining_free} / ${data.free_limit} msgs left</span>
                    <button onclick="showUpgradeModal()" class="btn-upgrade-small">Upgrade</button>
                </div>
            `;
            // Reset notification flag if free
            sessionStorage.removeItem('upgrade_notified');
        }
    }

    // 2. Update Mobile Menu
    const mobileCredits = document.getElementById('mobile-menu-credits');
    if (mobileCredits) {
        // Check plan_type FIRST to show correct plan name (same logic as desktop)
        if (data.plan_type === 'standard') {
            mobileCredits.innerHTML = `
                <div class="mobile-credit-item standard">
                    <div class="credit-count"><i class="fas fa-star"></i> Standard Plan</div>
                    <div class="credit-count" style="font-size: 12px; color: #718096; margin-top: 4px;">${data.user_credits} credits</div>
                </div>`;
        } else if (data.is_premium && data.plan_type === 'unlimited') {
            // Calculate remaining days
            const expiresAt = new Date(data.expires_at);
            const now = new Date();
            const diffTime = Math.abs(expiresAt - now);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            let timerText = '';
            if (diffDays > 1) {
                timerText = `Expires in ${diffDays} days`;
            } else {
                // If less than 1 day, show hours
                const diffHours = Math.ceil(diffTime / (1000 * 60 * 60));
                timerText = `Expires in ${diffHours} hours`;
            }

            mobileCredits.innerHTML = `
                <div class="mobile-credit-item premium">
                    <div class="credit-count"><i class="fas fa-crown"></i> Unlimited Plan</div>
                    <div class="credit-timer" style="font-size: 11px; color: #718096; margin-top: 2px;">
                        <i class="fas fa-clock"></i> ${timerText}
                    </div>
                </div>`;
        } else {
            // Free Plan - Show upgrade button
            mobileCredits.innerHTML = `
                <div class="mobile-credit-item free">
                    <div class="credit-count"><i class="fas fa-coins"></i> Free Plan</div>
                    <div class="credit-count" style="font-size: 12px; color: #718096; margin-top: 4px;">${data.remaining_free} / ${data.free_limit} msgs left</div>
                    <button class="btn-upgrade-mobile" onclick="showUpgradeModal()">Upgrade</button>
                </div>`;
        }
    }

    // 2. Update Page Info (Optional, keeping for context but simplifying)
    const pageInfo = document.querySelector('.page-info');
    const existingBadge = document.querySelector('.subscription-badge');
    if (existingBadge) existingBadge.remove();

    // 3. Update Send Button
    const sendBtn = document.getElementById('send-broadcast');
    if (!data.is_premium && data.remaining_free <= 0) {
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.innerHTML = 'Limit Reached <i class="fas fa-lock"></i>';
            sendBtn.title = 'Upgrade to Premium to send more messages';
            sendBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                showUpgradeModal();
            };
        }
    } else if (sendBtn) {
        sendBtn.disabled = false;
        sendBtn.innerHTML = 'Send Broadcast <i class="fas fa-paper-plane"></i>';
        sendBtn.onclick = handleSendBroadcast;
    }
}

/**
 * Show Upgrade Modal
 */
function showUpgradeModal() {
    // Fetch pending payment status first
    fetch('api.php?action=get_pending_payment')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.pending_order) {
                window.pendingOrder = data.pending_order;
            } else {
                window.pendingOrder = null;
            }
            renderUpgradeModal();
        })
        .catch(err => {
            console.error('Error checking pending payment:', err);
            window.pendingOrder = null;
            renderUpgradeModal();
        });
}

function renderUpgradeModal() {
    // Remove existing modal if present to prevent stacking
    const existingModal = document.getElementById('upgrade-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'upgrade-modal';
    modal.className = 'modal';

    // CHECK FOR PENDING ORDER (Resume Logic)
    let resumeBanner = '';
    if (window.pendingOrder && window.pendingOrder.remaining_seconds > 0) {
        resumeBanner = `
            <div style="background: #fffaf0; border: 1px solid #ed8936; padding: 12px; margin: 0 20px 20px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div class="spinner-pulse" style="width: 10px; height: 10px; background: #ed8936;"></div>
                    <span style="color: #c05621; font-weight: 600; font-size: 14px;">Pending Payment: ${window.pendingOrder.amount} ${window.pendingOrder.coin}</span>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button onclick="showResumeModal(document.getElementById('upgrade-modal'), window.pendingOrder)" style="background: #ed8936; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;">
                        Resume
                    </button>
                    <button onclick="startNewPayment()" style="background: #e2e8f0; color: #4a5568; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;">
                        Start New
                    </button>
                </div>
            </div>
        `;
    }

    modal.innerHTML = `
            <div class="modal-content upgrade-modal-content" style="max-width: 800px; background: #fff; color: #333;">
                <span class="close-modal" style="color: #666;">&times;</span>
                <div class="upgrade-header">
                    <h2 style="color: #333;">Choose Your Plan</h2>
                    <p style="color: #666;">Unlock the full power of BulkZen</p>
                </div>
                <div class="upgrade-body">
                    ${resumeBanner}
                    <div class="pricing-grid" style="margin-top: 20px;">
                        <!-- Standard Plan -->
                        <div class="pricing-card standard" style="border: 1px solid #eee; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                            <div class="pricing-header">
                                <span class="plan-name" style="color: #3182ce;">Standard</span>
                                <div class="plan-price" style="color: #333;">
                                    <span class="currency" style="color: #333;">$</span>0.30
                                    <span class="period" style="color: #999;">/mo</span>
                                </div>
                            </div>
                            <div class="pricing-features">
                                <ul>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #4299e1;"></i> <strong>5,000</strong> Global Credits</li>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #4299e1;"></i> Use on All Pages</li>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #4299e1;"></i> Priority Support</li>
                                </ul>
                            </div>
                            <button class="btn-select-plan" onclick="selectPlan('standard')" style="background: #ebf8ff; color: #2b6cb0;">Select Standard</button>
                        </div>

                        <!-- Unlimited Plan -->
                        <div class="pricing-card unlimited" style="border: 1px solid #d6bcfa; background: #fff; box-shadow: 0 8px 20px rgba(128, 90, 213, 0.15);">
                            <div class="popular-badge">Best Value</div>
                            <div class="pricing-header">
                                <span class="plan-name" style="color: #805ad5;">Unlimited</span>
                                <div class="plan-price" style="color: #333;">
                                    <span class="currency" style="color: #333;">$</span>49.99
                                    <span class="period" style="color: #999;">/mo</span>
                                </div>
                            </div>
                            <div class="pricing-features">
                                <ul>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #805ad5;"></i> <strong>Unlimited</strong> Messages</li>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #805ad5;"></i> All Pages Included</li>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #805ad5;"></i> VIP Support</li>
                                    <li style="color: #555;"><i class="fas fa-check" style="color: #805ad5;"></i> No Daily Limits</li>
                                </ul>
                            </div>
                            <button class="btn-select-plan" onclick="selectPlan('unlimited')" style="background: #805ad5; color: white;">Select Unlimited</button>
                        </div>
                    </div>
                    
                    <div class="promo-section" style="margin-top: 30px; max-width: 400px; margin-left: auto; margin-right: auto; border-top: 1px solid #eee;">
                        <p style="color: #666;">Have a promo code?</p>
                        <div class="promo-input-group">
                            <input type="text" id="promo-code-input" placeholder="Enter code" style="background: #f7fafc; border: 1px solid #e2e8f0; color: #333;">
                            <button id="btn-apply-promo" style="background: #edf2f7; color: #4a5568; border: 1px solid #cbd5e0;">Apply</button>
                        </div>
                        <div id="promo-message"></div>
                    </div>
                </div>
            </div>
        `;
    document.body.appendChild(modal);

    // Close logic
    const closeBtn = modal.querySelector('.close-modal');
    closeBtn.onclick = () => modal.style.display = 'none';
    window.onclick = (event) => {
        if (event.target == modal) modal.style.display = 'none';
    };

    // Promo logic
    modal.querySelector('#btn-apply-promo').onclick = applyPromoCode;

    modal.style.display = 'block';
}

/**
 * Apply Promo Code
 */
function applyPromoCode() {
    const codeInput = document.getElementById('promo-code-input');
    const messageDiv = document.getElementById('promo-message');
    const code = codeInput.value.trim();

    if (!code) return;

    fetch('api.php?action=redeem_promo_code', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ promo_code: code })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                messageDiv.className = 'success';
                messageDiv.innerText = data.message;
                setTimeout(() => {
                    location.reload(); // Reload to reflect changes
                }, 1500);
            } else {
                messageDiv.className = 'error';
                messageDiv.innerText = data.error;
            }
        })
        .catch(console.error);
}

/**
 * Select Plan Logic
 */
function selectPlan(plan) {
    // If pending order exists, we should probably warn or auto-expire?
    // User requested: "When pending payment exists -> allow Start New which resets everything"
    // So if they click a plan, we treat it as "Start New"
    if (window.pendingOrder) {
        // User explicitly clicked a plan, so we assume they want to start new.
        // No confirmation needed, just do it.

        // Fire and forget - don't wait for API
        fetch('api.php?action=start_new_payment').catch(console.error);

        // Update UI immediately
        window.pendingOrder = null;
        window.selectedPlan = plan;
        showCoinSelection();
        return;
    }

    window.selectedPlan = plan;
    showCoinSelection();
}

function showCoinSelection() {
    const modalBody = document.querySelector('.upgrade-body');
    // Save original content for back button if not already saved
    if (!window.originalUpgradeBody) {
        // We can't easily save the *state* of the DOM, so let's just re-render the plan selection when going back.
        // Or simpler: just call showUpgradeModal() again, but that might duplicate the modal.
        // Better: Re-inject the plan selection HTML.
    }

    modalBody.innerHTML = `
        <div class="payment-step-container animate-fade-in">
            <div class="step-header text-center">
                <h3 class="step-title" style="color: #333;">Select Cryptocurrency</h3>
                <p class="step-desc" style="color: #666;">Pay for <strong>${selectedPlan === 'unlimited' ? 'Unlimited' : 'Standard'} Plan</strong></p>
            </div>
            
            <div class="coin-grid">
                <button onclick="selectCoin('USDT')" class="btn-coin" style="background: #fff; border: 1px solid #e2e8f0; color: #333;">
                    <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/825.png" class="coin-img" alt="USDT">
                    <div class="coin-info">
                        <span class="coin-name" style="color: #333;">Tether</span>
                        <span class="coin-symbol" style="color: #999;">USDT</span>
                    </div>
                    <i class="fas fa-chevron-right arrow-icon" style="color: #ccc;"></i>
                </button>
                
                <button onclick="selectCoin('BTC')" class="btn-coin" style="background: #fff; border: 1px solid #e2e8f0; color: #333;">
                    <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/1.png" class="coin-img" alt="BTC">
                    <div class="coin-info">
                        <span class="coin-name" style="color: #333;">Bitcoin</span>
                        <span class="coin-symbol" style="color: #999;">BTC</span>
                    </div>
                    <i class="fas fa-chevron-right arrow-icon" style="color: #ccc;"></i>
                </button>
                
                <button onclick="selectCoin('ETH')" class="btn-coin" style="background: #fff; border: 1px solid #e2e8f0; color: #333;">
                    <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/1027.png" class="coin-img" alt="ETH">
                    <div class="coin-info">
                        <span class="coin-name" style="color: #333;">Ethereum</span>
                        <span class="coin-symbol" style="color: #999;">ETH</span>
                    </div>
                    <i class="fas fa-chevron-right arrow-icon" style="color: #ccc;"></i>
                </button>
                
                <button onclick="selectCoin('LTC')" class="btn-coin" style="background: #fff; border: 1px solid #e2e8f0; color: #333;">
                    <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/2.png" class="coin-img" alt="LTC">
                    <div class="coin-info">
                        <span class="coin-name" style="color: #333;">Litecoin</span>
                        <span class="coin-symbol" style="color: #999;">LTC</span>
                    </div>
                    <i class="fas fa-chevron-right arrow-icon" style="color: #ccc;"></i>
                </button>

                <button onclick="selectCoin('SOL')" class="btn-coin" style="background: #fff; border: 1px solid #e2e8f0; color: #333;">
                    <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/5426.png" class="coin-img" alt="SOL">
                    <div class="coin-info">
                        <span class="coin-name" style="color: #333;">Solana</span>
                        <span class="coin-symbol" style="color: #999;">SOL</span>
                    </div>
                    <i class="fas fa-chevron-right arrow-icon" style="color: #ccc;"></i>
                </button>
            </div>
            
            <button onclick="restoreUpgradeModal()" class="btn-back-link" style="color: #666;">
                <i class="fas fa-arrow-left"></i> Back to Plan
            </button>
        </div>
    `;
}

function restoreUpgradeModal() {
    // Remove the modal and re-create it to restore initial state
    const modal = document.getElementById('upgrade-modal');
    if (modal) {
        modal.remove();
    }
    showUpgradeModal();
}

/**
 * Select Coin Logic
 */
function selectCoin(coin) {
    if (coin === 'USDT') {
        showNetworkSelection(coin);
    } else {
        // For other coins, usually main network is implied, but we can check if we want to offer networks
        // For simplicity and "Professional" feel, let's just go straight to address generation for native coins
        // Or show network selection if needed. 
        // Let's go straight to address for BTC, LTC, SOL. 
        // ETH might need network selection (ERC20 vs BEP20 etc), but usually ETH means ERC20.
        getDepositAddress(coin, null);
    }
}

/**
 * Show Network Selection (for USDT)
 */
function showNetworkSelection(coin) {
    const modalBody = document.querySelector('.upgrade-body');
    modalBody.innerHTML = `
        <div class="payment-step-container">
            <div class="step-header text-center">
                <h3 class="step-title">Select Network</h3>
                <p class="step-desc">Ensure you select the correct network for <strong>${coin}</strong></p>
            </div>
            
            <div class="network-grid">
                <button onclick="getDepositAddress('${coin}', 'TRX')" class="btn-network">
                    <span class="net-badge">Recommended</span>
                    <span class="net-name">TRC20</span>
                    <span class="net-fee">Tron Network</span>
                </button>
                <button onclick="getDepositAddress('${coin}', 'BSC')" class="btn-network">
                    <span class="net-name">BEP20</span>
                    <span class="net-fee">BNB Smart Chain</span>
                </button>
                <button onclick="getDepositAddress('${coin}', 'ETH')" class="btn-network">
                    <span class="net-name">ERC20</span>
                    <span class="net-fee">Ethereum Network</span>
                </button>
                <button onclick="getDepositAddress('${coin}', 'MATIC')" class="btn-network">
                    <span class="net-name">Polygon</span>
                    <span class="net-fee">Matic Network</span>
                </button>
            </div>
            
            <button onclick="showCoinSelection()" class="btn-back-link">
                <i class="fas fa-arrow-left"></i> Back to Coins
            </button>
        </div>
    `;
}

/**
 * Get Address from API
 */
function getDepositAddress(coin, network = null) {
    const modalBody = document.querySelector('.upgrade-body');
    modalBody.innerHTML = `
        <div style="text-align:center; padding: 40px;">
            <div class="spinner-large"></div>
            <h3 style="margin-top: 20px; color: #4a5568;">Generating Address...</h3>
            <p style="color: #a0aec0;">Please wait while we connect to the blockchain</p>
        </div>
    `;

    let url = `api.php?action=get_deposit_address&coin=${coin}&plan=${selectedPlan}`;
    if (network) {
        url += `&network=${network}`;
    }

    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showCryptoPaymentDetails(data);
            } else {
                alert('Error: ' + data.error);
                showCoinSelection(); // Go back
            }
        })
        .catch(err => {
            console.error(err);
            alert('Connection Error');
            showCoinSelection();
        });
}

/**
* Show Payment Details (QR + Address)
*/
function showCryptoPaymentDetails(data) {
    const modalBody = document.querySelector('.upgrade-body');
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${data.address}`;

    const networkDisplay = data.network ? `<span class="badge-network">${data.network}</span>` : '';

    modalBody.innerHTML = `
        <div class="crypto-payment-container animate-fade-in">
            <div class="payment-header">
                <div class="amount-box">
                    <label>Send Exact Amount</label>
                    <div class="amount-value">${data.amount} <small>${data.coin}</small></div>
                </div>
                ${networkDisplay}
            </div>

            <div class="warning-box" style="background: #fff5f5; border: 1px solid #feb2b2; padding: 10px; border-radius: 8px; margin-bottom: 20px; color: #c53030; font-size: 13px; font-weight: 600;">
                <i class="fas fa-exclamation-triangle"></i> 
                IMPORTANT: Send only via <u>${data.network || data.coin}</u> network.
                <br>Sending via other networks will result in permanent loss.
            </div>

            <div class="qr-wrapper">
                <div class="qr-box">
                    <img src="${qrUrl}" alt="QR Code">
                </div>
                <p class="qr-instruction">Scan to Pay</p>
            </div>

            <div class="address-section">
                <label>Wallet Address</label>
                <div class="address-input-group">
                    <input type="text" value="${data.address}" readonly>
                    <button onclick="copyToClipboard('${data.address}', this)" class="btn-copy">
                        <i class="fas fa-copy"></i>
                        <span class="tooltip">Copied!</span>
                    </button>
                </div>
            </div>

            <div class="timer-box" style="margin-bottom: 20px; font-size: 14px; color: #4a5568;">
                Time Remaining: <span id="payment-timer" style="font-weight: bold; color: #e53e3e;">20:00</span>
            </div>

            <div class="payment-status-bar">
                <div class="status-icon"><div class="spinner-pulse"></div></div>
                <div class="status-text">
                    <strong>Awaiting Payment</strong>
                    <span>We are monitoring the blockchain...</span>
                </div>
            </div>
            
            <button onclick="confirmCancelPayment()" class="btn-cancel-link">Cancel Payment</button>
        </div>
    `;

    // Start Timer
    startPaymentTimer(20 * 60);

    // Start Polling
    pollPaymentStatus(data.order_id);
}

/**
 * Confirm Cancel Payment
 */
async function confirmCancelPayment() {
    const confirmed = await showConfirmModal(
        "Are you sure you want to cancel? Any pending payments will be processed if completed later.",
        "Yes, Cancel",
        "No, Go Back"
    );

    if (confirmed) {
        closeUpgradeModal();
    }
}

/**
 * Close Upgrade Modal
 */
function closeUpgradeModal() {
    const modal = document.getElementById('upgrade-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

var paymentTimerInterval;

function startPaymentTimer(duration) {
    let timer = duration, minutes, seconds;
    const display = document.getElementById('payment-timer');

    if (paymentTimerInterval) clearInterval(paymentTimerInterval);

    paymentTimerInterval = setInterval(function () {
        minutes = parseInt(timer / 60, 10);
        seconds = parseInt(timer % 60, 10);

        minutes = minutes < 10 ? "0" + minutes : minutes;
        seconds = seconds < 10 ? "0" + seconds : seconds;

        if (display) {
            display.textContent = minutes + ":" + seconds;
        }

        if (--timer < 0) {
            clearInterval(paymentTimerInterval);
            if (display) display.textContent = "EXPIRED";
            alert("Payment session expired. Please restart.");
            showUpgradeModal();
        }
    }, 1000);
}

/**
 * Show Resume Modal
 */
function showResumeModal(modal, order) {
    document.body.appendChild(modal);
    modal.style.display = 'block';

    // Close logic
    const closeBtn = document.createElement('span');
    closeBtn.className = 'close-modal';
    closeBtn.innerHTML = '&times;';
    closeBtn.onclick = () => modal.style.display = 'none';

    window.onclick = (event) => {
        if (event.target == modal) modal.style.display = 'none';
    };

    modal.innerHTML = `
        <div class="modal-content upgrade-modal-content" style="max-width: 500px; background: #fff; color: #333; text-align: center;">
            <span class="close-modal" style="color: #666;">&times;</span>
            <div class="upgrade-header" style="background: linear-gradient(135deg, #f6ad55 0%, #ed8936 100%);">
                <h2 style="color: white;">Pending Payment</h2>
                <p style="color: white; opacity: 0.9;">You have an active payment session</p>
            </div>
            <div class="upgrade-body" style="padding: 30px;">
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 18px; font-weight: bold; color: #2d3748;">${order.amount} ${order.coin}</div>
                    <div style="color: #718096; font-size: 14px;">${order.plan === 'unlimited' ? 'Unlimited Plan' : 'Standard Plan'}</div>
                </div>
                
                <div style="margin-bottom: 30px;">
                    <div class="timer-box" style="font-size: 24px; font-weight: bold; color: #e53e3e;">
                        <span id="resume-timer">--:--</span>
                    </div>
                    <p style="font-size: 12px; color: #a0aec0;">Time Remaining</p>
                </div>

                <div class="action-buttons" style="display: flex; gap: 10px; justify-content: center;">
                    <button onclick="resumePayment()" class="btn-select-plan" style="background: #48bb78; color: white; flex: 1;">
                        Resume Payment
                    </button>
                    <button onclick="cancelPendingOrder('${order.order_id}')" class="btn-select-plan" style="background: #e2e8f0; color: #4a5568; flex: 1;">
                        Cancel Order
                    </button>
                </div>
            </div>
        </div>
    `;

    // Re-attach close handler since we overwrote innerHTML
    modal.querySelector('.close-modal').onclick = () => modal.style.display = 'none';

    // Start mini-timer for the resume modal
    let duration = order.remaining_seconds;
    const display = document.getElementById('resume-timer');
    const interval = setInterval(() => {
        let minutes = parseInt(duration / 60, 10);
        let seconds = parseInt(duration % 60, 10);
        minutes = minutes < 10 ? "0" + minutes : minutes;
        seconds = seconds < 10 ? "0" + seconds : seconds;
        if (display) display.textContent = minutes + ":" + seconds;
        if (--duration < 0) {
            clearInterval(interval);
            if (display) display.textContent = "EXPIRED";
            setTimeout(() => location.reload(), 1000);
        }
    }, 1000);
}

/**
 * Resume Payment (Go to Details View)
 */
function resumePayment() {
    if (!window.pendingOrder) return;

    // Fix: Ensure modal width is restored to default (800px) if it was shrunk by the resume modal (500px)
    const modalContent = document.querySelector('.upgrade-modal-content');
    if (modalContent) {
        modalContent.style.maxWidth = '800px';
    }

    // Re-construct data object expected by showCryptoPaymentDetails
    const data = {
        success: true,
        order_id: window.pendingOrder.order_id,
        address: window.pendingOrder.address,
        coin: window.pendingOrder.coin,
        network: window.pendingOrder.network,
        amount: window.pendingOrder.amount,
        plan: window.pendingOrder.plan
    };

    // We need to render the modal structure first if we are coming from the small resume modal
    // But showCryptoPaymentDetails expects the modal to exist.
    // Let's just re-use the existing modal but clear content
    const modalBody = document.querySelector('.upgrade-body');
    if (modalBody) {
        showCryptoPaymentDetails(data);
        // Adjust timer to match remaining time
        if (window.pendingOrder.remaining_seconds) {
            // Stop any existing timer first? startPaymentTimer handles clearing.
            startPaymentTimer(window.pendingOrder.remaining_seconds);
        }
    }
}

/**
 * Cancel Pending Order (UI Cancel)
 * Just hides the modal. Backend keeps it alive (CANCELLED_UI).
 */
function cancelPendingOrder(orderId) {
    // We just call the API to mark it as CANCELLED_UI so we know the user explicitly closed it
    fetch('api.php?action=cancel_order&order_id=' + orderId)
        .then(() => {
            // Don't reload, just close modal or go back to plan selection
            // If we are in the resume modal, close it
            const modal = document.getElementById('upgrade-modal');
            if (modal) {
                // Remove the modal completely so it can be re-rendered fresh next time
                modal.remove();
            }
            // Refresh pending order state in background for next time
            window.pendingOrder = null;
        });
}

/**
 * Start New Payment
 * Explicitly expires old session and reloads to clear state
 * Shows custom confirmation modal first
 */
async function startNewPayment() {
    const confirmed = await showConfirmModal(
        "This will cancel your current pending payment session. Are you sure you want to start a new one?",
        "Yes, Start New",
        "No, Keep Pending",
        "danger"
    );

    if (confirmed) {
        confirmStartNew();
    }
}

function confirmStartNew() {
    // Show loading state
    const upgradeModal = document.getElementById('upgrade-modal');
    if (upgradeModal) {
        const body = upgradeModal.querySelector('.upgrade-body');
        if (body) body.innerHTML = '<div style="text-align:center; padding:40px;"><div class="spinner-large"></div><p>Starting new session...</p></div>';
    }

    fetch('api.php?action=start_new_payment')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.pendingOrder = null;
                // Instead of reload, just re-render the plan selection
                // This is smoother
                renderUpgradeModal();
            }
        });
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text);
    btn.classList.add('copied');
    setTimeout(() => btn.classList.remove('copied'), 2000);
}

/**
 * Poll Payment Status
 */
function pollPaymentStatus(orderId) {
    const pollInterval = setInterval(() => {
        // Stop polling if modal is closed or content changed
        if (!document.querySelector('.crypto-payment-container')) {
            clearInterval(pollInterval);
            return;
        }

        fetch(`api.php?action=check_deposit_status&order_id=${orderId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.status === 'PAID') {
                        clearInterval(pollInterval);
                        showPaymentSuccess();
                    } else if (data.status === 'PROCESSING') {
                        // Update UI for Processing (Payment Detected)
                        const statusText = document.querySelector('.payment-status-bar .status-text');
                        const statusIcon = document.querySelector('.payment-status-bar .status-icon');
                        const statusBar = document.querySelector('.payment-status-bar');

                        if (statusText && statusIcon) {
                            statusBar.style.background = '#ebf8ff';
                            statusBar.style.border = '1px solid #4299e1';
                            statusIcon.innerHTML = '<i class="fas fa-sync fa-spin" style="color: #3182ce;"></i>';
                            statusText.innerHTML = `
                                 <strong style="color: #2b6cb0;">Payment Detected!</strong>
                                 <span style="color: #4a5568; display:block; font-size: 12px;">Waiting for blockchain confirmation...</span>
                             `;
                        }
                    } else if (data.status === 'PARTIAL') {
                        // Update UI for Partial Payment
                        const statusText = document.querySelector('.payment-status-bar .status-text');
                        const statusIcon = document.querySelector('.payment-status-bar .status-icon');

                        if (statusText && statusIcon) {
                            statusIcon.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #ed8936;"></i>';
                            statusText.innerHTML = `
                                <strong style="color: #ed8936;">Partial Payment Received</strong>
                                <span style="color: #2d3748;">Received: <strong>${data.received} ${data.currency}</strong></span>
                                <span style="color: #e53e3e; display:block;">Remaining: <strong>${data.remaining} ${data.currency}</strong></span>
                            `;
                        }
                    }
                }
            })
            .catch(console.error);
    }, 10000); // Check every 10 seconds
}

function showPaymentSuccess() {
    // Only play sound if modal is open (not on page refresh)
    const modalBody = document.querySelector('.upgrade-body');
    if (modalBody) {
        // Play success sound only when payment is detected in real-time (not on page load)
        const audio = new Audio('payment.mp3');
        audio.play().catch(e => console.log('Audio play failed:', e));

        modalBody.innerHTML = `
            <div style="text-align:center; padding: 40px;">
                <div style="margin-bottom: 20px;">
                    <img src="tick.gif" alt="Success" style="width: 100px; height: 100px;">
                </div>
                <h2 style="color: #2d3748; margin-bottom: 10px;">Payment Received!</h2>
                <p style="color: #718096; margin-bottom: 30px;">
                    Your Premium subscription is now active.
                </p>
                <button class="btn btn-primary btn-large" onclick="window.location.reload()">
                    Continue
                </button>
            </div>
        `;
    }
}

/**
 * Setup dashboard navigation toggle
 */
function setupDashboardNavToggle() {
    const dashboardNavToggle = document.getElementById('dashboard-nav-toggle');
    const dashboardNavMenu = document.getElementById('dashboard-nav-menu');
    const dashboardNavOverlay = document.getElementById('dashboard-nav-overlay');

    if (!dashboardNavToggle || !dashboardNavMenu) {
        console.warn('Dashboard nav elements not found:', {
            toggle: !!dashboardNavToggle,
            menu: !!dashboardNavMenu,
            overlay: !!dashboardNavOverlay
        });
        return;
    }

    // Remove any existing event listeners by cloning and replacing
    const newToggle = dashboardNavToggle.cloneNode(true);
    dashboardNavToggle.parentNode.replaceChild(newToggle, dashboardNavToggle);
    
    // Get fresh references after cloning
    const toggle = document.getElementById('dashboard-nav-toggle');
    const menu = document.getElementById('dashboard-nav-menu');
    const overlay = document.getElementById('dashboard-nav-overlay');

    // Attach click event listener
    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Menu toggle clicked'); // Debug log
        menu.classList.toggle('active');
        toggle.classList.toggle('active');
        if (overlay) {
            overlay.classList.toggle('active');
        }
    });

    if (overlay) {
        overlay.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            menu.classList.remove('active');
            toggle.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
}

/**
 * Ask before leaving the session
 */
function setupLogoutConfirm() {
    async function confirmLogout() {
        const yes = await showConfirmModal(
            'Log out of this page? You will need Facebook again to come back.',
            'Yes, log out',
            'No'
        );
        if (yes) {
            window.location.href = 'logout.php';
        }
    }

    const desktopBtn = document.getElementById('logout-btn-desktop');
    const mobileBtn = document.getElementById('logout-btn-mobile');
    if (desktopBtn) {
        desktopBtn.addEventListener('click', confirmLogout);
    }
    if (mobileBtn) {
        mobileBtn.addEventListener('click', confirmLogout);
    }
}

/**
 * Sync mobile and desktop page selectors
 */
function syncPageSelectors() {
    const pageSelector = document.getElementById('page-selector');
    const pageSelectorHeaderMobile = document.getElementById('page-selector-header-mobile');

    // Sync desktop and mobile header selectors only
    const selectors = [pageSelector, pageSelectorHeaderMobile].filter(s => s);

    if (selectors.length === 0) return;

    selectors.forEach(selector => {
        selector.addEventListener('change', function () {
            // Update all other selectors
            selectors.forEach(otherSelector => {
                if (otherSelector !== selector) {
                    otherSelector.value = selector.value;
                }
            });

            // Trigger page switch if this is the main selector
            if (selector === pageSelector || selector === pageSelectorHeaderMobile) {
                handlePageSwitch({ target: pageSelector || selector });
            }
        });
    });
}

/**
 * Load all pages for the dropdown selector
 */
function loadPages() {
    const pageSelector = document.getElementById('page-selector');
    const pageSelectorHeaderMobile = document.getElementById('page-selector-header-mobile');
    if (!pageSelector && !pageSelectorHeaderMobile) return;

    const selector = pageSelector || pageSelectorMobile;

    fetch('api.php?action=get_pages')
        .then(response => {
            // Check if response is ok
            if (!response.ok) {
                if (response.status === 401) {
                    // Session expired - don't show alert, just redirect to login
                    window.location.href = 'index.php';
                    return Promise.reject(new Error('Unauthorized'));
                }
                return response.json().then(data => {
                    throw new Error(data.error || 'Failed to load pages');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.pages && data.pages.length > 0) {
                // Get both selectors (desktop and mobile header only)
                const pageSelectorHeaderMobile = document.getElementById('page-selector-header-mobile');
                const allSelectors = [pageSelector, pageSelectorHeaderMobile].filter(s => s);

                // Update each selector
                allSelectors.forEach(selector => {
                    if (!selector) return;

                    // Clear existing options
                    selector.innerHTML = '';

                    // Add "Select a Page..." option if no page is currently selected
                    const currentValue = selector.value;
                    if (!currentValue || currentValue === '') {
                        const selectOption = document.createElement('option');
                        selectOption.value = '';
                        selectOption.textContent = 'Select a Page...';
                        selectOption.selected = true;
                        selector.appendChild(selectOption);
                    }

                    // Add all pages to dropdown
                    data.pages.forEach(page => {
                        const option = document.createElement('option');
                        option.value = page.id;
                        // Show full info for desktop, only name for mobile
                        if (selector === pageSelector) {
                            option.textContent = page.name + (page.id ? ' (ID: ' + page.id + ')' : '');
                        } else {
                            option.textContent = page.name;
                        }
                        if (page.is_current) {
                            option.selected = true;
                        }
                        selector.appendChild(option);
                    });

                    // Add change event listener if not already added
                    selector.removeEventListener('change', handlePageSwitch);
                    selector.addEventListener('change', handlePageSwitch);
                });
            } else {
                // No pages found
                const pageSelectorHeaderMobile = document.getElementById('page-selector-header-mobile');
                if (pageSelector) pageSelector.innerHTML = '<option value="">No pages found</option>';
                if (pageSelectorHeaderMobile) pageSelectorHeaderMobile.innerHTML = '<option value="">No pages found</option>';
            }
        })
        .catch(error => {
            if (error.message !== 'Unauthorized') {
                console.error('Error loading pages:', error);
                const pageSelectorHeaderMobile = document.getElementById('page-selector-header-mobile');
                if (pageSelector) pageSelector.innerHTML = '<option value="">Error loading pages</option>';
                if (pageSelectorHeaderMobile) pageSelectorHeaderMobile.innerHTML = '<option value="">Error loading pages</option>';
            }
        });
}

/**
 * Handle page switching
 */
function handlePageSwitch(event) {
    const pageId = event.target.value;

    // If no page selected, just show message
    if (!pageId) {
        const tbody = document.getElementById('conversations-tbody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="loading-message" style="font-size: 16px; font-weight: 600; color: #667eea; text-align: center; padding: 40px;">Please select a page to start</td></tr>';
        }
        // Clear conversation data
        conversations = [];
        filteredConversations = [];
        updateConversationCounts();
        return;
    }

    // Show loading state
    const pageSelector = document.getElementById('page-selector');
    pageSelector.disabled = true;

    // Show loading message
    const tbody = document.getElementById('conversations-tbody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" class="loading-message">Loading conversations...</td></tr>';
    }

    // Switch page via API
    const formData = new FormData();
    formData.append('page_id', pageId);

    fetch('api.php?action=switch_page', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload the page to show new page's conversations
                window.location.reload();
            } else {
                alert('Error switching page: ' + (data.error || 'Unknown error'));
                pageSelector.disabled = false;
                // Reload to reset dropdown
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error switching page:', error);
            alert('Error switching page. Please try again.');
            pageSelector.disabled = false;
            window.location.reload();
        });
}

/**
 * Setup all event listeners
 */
function setupEventListeners() {
    // Search box
    const searchBox = document.getElementById('search-box');
    if (searchBox) {
        searchBox.addEventListener('input', handleSearch);
    }

    // Select all button (selects all users, not just eligible)
    const selectAllEligible = document.getElementById('select-all-eligible');
    if (selectAllEligible) {
        selectAllEligible.addEventListener('click', selectAllUsers);
    }

    // Select none button
    const selectNone = document.getElementById('select-none');
    if (selectNone) {
        selectNone.addEventListener('click', selectNoneUsers);
    }

    // Select all checkbox in header
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            if (this.checked) {
                selectAllUsers();
            } else {
                selectNoneUsers();
            }
        });
    }

    // Refresh button
    const refreshBtn = document.getElementById('refresh-conversations');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadConversations);
    }

    // Image upload
    const imageUpload = document.getElementById('image-upload');
    if (imageUpload) {
        imageUpload.addEventListener('change', handleImageUpload);
    }

    // Remove image button
    const removeImage = document.getElementById('remove-image');
    if (removeImage) {
        removeImage.addEventListener('click', removeImagePreview);
    }

    // Send broadcast button listener is handled in updateSubscriptionUI
    // to switch between "Send" and "Upgrade" actions dynamically

    // Update selected count when checkboxes change
    document.addEventListener('change', function (e) {
        if (e.target.type === 'checkbox' && e.target.classList.contains('user-checkbox')) {
            updateSelectedCount();
        }
    });
}

/**
 * Setup character counter for message textarea
 */
function setupCharacterCounter() {
    const messageText = document.getElementById('message-text');
    const charCount = document.getElementById('char-count');

    if (messageText && charCount) {
        messageText.addEventListener('input', function () {
            charCount.textContent = this.value.length;
        });
    }
}

/**
 * Load conversations from API
 */
function loadConversations() {
    const tbody = document.getElementById('conversations-tbody');
    if (!tbody) return;

    // Show loading state with progress
    tbody.innerHTML = '<tr><td colspan="7" class="loading-message" style="text-align: center; padding: 20px;"><div style="display: inline-block;">Loading conversations... <span id="loading-progress" style="font-weight: bold; color: #667eea;">0</span></div><div style="margin-top: 10px; font-size: 12px; color: #718096;">This may take a moment for pages with many conversations</div></td></tr>';

    fetch('api.php?action=list_conversations')
        .then(response => {
            if (!response.ok) {
                if (response.status === 401) {
                    alert('Session expired – please refresh and login with Facebook again.');
                    window.location.href = 'index.php';
                    return Promise.reject(new Error('Unauthorized'));
                }
                throw new Error('Failed to load conversations');
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                conversations = data.conversations;
                filteredConversations = conversations;
                renderedCount = 0; // Reset render counter
                updateSummaryStats();
                // Render progressively to avoid lag
                renderConversationsProgressive();
            } else {
                showError('Failed to load conversations: ' + (data?.error || 'Unknown error'));
            }
        })
        .catch(error => {
            if (error.message !== 'Unauthorized') {
                console.error('Error:', error);
                tbody.innerHTML = '<tr><td colspan="7" class="error-message">Error loading conversations: ' + error.message + '</td></tr>';
            }
        });
}

/**
 * Render conversations table progressively to avoid lag
 */
function renderConversationsProgressive() {
    const tbody = document.getElementById('conversations-tbody');
    if (!tbody) return;

    if (filteredConversations.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">No conversations found</td></tr>';
        renderedCount = 0;
        return;
    }

    // Clear and start fresh
    if (renderedCount === 0) {
        tbody.innerHTML = '';
    }

    // Prevent multiple simultaneous renders
    if (isRendering) {
        return;
    }
    isRendering = true;

    // Render in batches using requestAnimationFrame for smooth rendering
    function renderBatch() {
        const endIndex = Math.min(renderedCount + RENDER_BATCH_SIZE, filteredConversations.length);
        const batch = filteredConversations.slice(renderedCount, endIndex);
        
        const fragment = document.createDocumentFragment();
        
        batch.forEach(conv => {
            const isEligible = conv.is_within_24h;
            const statusClass = isEligible ? 'status-eligible' : 'status-outside';
            const statusText = isEligible ? '✅ Within 24h' : '⏰ Outside 24h (can still send)';

            // Avatar HTML
            const firstLetter = conv.name !== 'Unknown' && conv.name.length > 0 ? conv.name.charAt(0).toUpperCase() : '?';
            let avatarHtml = '';
            if (conv.picture_url) {
                avatarHtml = '<img src="' + escapeHtml(conv.picture_url) + '" alt="' + escapeHtml(conv.name) + '" class="user-avatar" onerror="this.onerror=null; this.style.display=\'none\';">';
            }
            avatarHtml += '<div class="user-avatar placeholder" style="' + (conv.picture_url ? 'display:none;' : '') + '">' + firstLetter + '</div>';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <input type="checkbox" 
                           class="user-checkbox" 
                           data-psid="${conv.psid}">
                </td>
                <td>
                    <div class="avatar-container">
                        ${avatarHtml}
                    </div>
                </td>
                <td>${escapeHtml(conv.name)}</td>
                <td><code>${escapeHtml(conv.psid)}</code></td>
                <td>${escapeHtml(conv.last_message_snippet || 'N/A')}</td>
                <td>${escapeHtml(conv.last_message_time || 'N/A')}</td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
            `;
            fragment.appendChild(tr);
        });

        tbody.appendChild(fragment);
        renderedCount = endIndex;
        updateSelectedCount();

        // Update progress if still loading
        const progressEl = document.getElementById('loading-progress');
        if (progressEl && renderedCount < filteredConversations.length) {
            const percent = Math.round((renderedCount / filteredConversations.length) * 100);
            progressEl.textContent = `${renderedCount}/${filteredConversations.length} (${percent}%)`;
        }

        // Continue rendering if there are more items
        if (renderedCount < filteredConversations.length) {
            // Use requestAnimationFrame for smoother rendering, fallback to setTimeout
            if (window.requestAnimationFrame) {
                requestAnimationFrame(renderBatch);
            } else {
                setTimeout(renderBatch, 10); // Small delay to allow browser to render
            }
        } else {
            isRendering = false;
            // Remove progress indicator after a brief delay
            setTimeout(() => {
                const progressEl = document.getElementById('loading-progress');
                if (progressEl) {
                    progressEl.textContent = '';
                }
            }, 500);
        }
    }

    // Start rendering
    renderBatch();
}

/**
 * Render conversations table (legacy function for search/filter)
 */
function renderConversations() {
    renderedCount = 0; // Reset counter when filtering
    renderConversationsProgressive();
}

/**
 * Handle search input
 */
function handleSearch(e) {
    const query = e.target.value.toLowerCase().trim();

    if (!query) {
        filteredConversations = conversations;
    } else {
        filteredConversations = conversations.filter(conv => {
            const name = (conv.name || '').toLowerCase();
            const psid = (conv.psid || '').toLowerCase();
            return name.includes(query) || psid.includes(query);
        });
    }

    renderConversations();
}

/**
 * Select all users (removed 24h restriction)
 */
function selectAllUsers() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    updateSelectedCount();

    // Update header checkbox
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = true;
    }
}

/**
 * Select all eligible users (kept for backward compatibility, but now selects all)
 */
function selectAllEligibleUsers() {
    selectAllUsers();
}

/**
 * Select none users
 */
function selectNoneUsers() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    updateSelectedCount();

    // Update header checkbox
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
    }
}

/**
 * Update selected count display
 */
function updateSelectedCount() {
    const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
    const count = selectedCheckboxes.length;
    const selectedCountEl = document.getElementById('selected-count');

    if (selectedCountEl) {
        selectedCountEl.textContent = `${count} user${count !== 1 ? 's' : ''} selected`;
    }
    refreshAiGenerateLabel();
    if (window.composeMode === 'ai' && window.aiAssignments && window.aiAssignments.length && !window.aiGenerating) {
        if (!aiAssignmentsCoverSelection()) {
            setAiStatus('Selection changed. Generate again so every selected person gets a unique line.', '');
        }
    }
}

/**
 * Update summary statistics
 */
function updateSummaryStats() {
    const totalEl = document.getElementById('total-conversations');
    const eligibleEl = document.getElementById('eligible-count');

    if (totalEl) {
        totalEl.textContent = `Total: ${conversations.length}`;
    }

    if (eligibleEl) {
        const eligibleCount = conversations.filter(c => c.is_within_24h).length;
        eligibleEl.textContent = `Eligible: ${eligibleCount}`;
    }
}

/**
 * Handle image upload preview
 */
function handleImageUpload(e) {
    const file = e.target.files[0];
    if (!file) {
        removeImagePreview();
        return;
    }

    // Validate file type
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    if (!validTypes.includes(file.type)) {
        alert('Please select a valid image file (JPG, JPEG, or PNG)');
        e.target.value = '';
        return;
    }

    // Validate file size (5MB)
    if (file.size > 5 * 1024 * 1024) {
        alert('File size must be less than 5MB');
        e.target.value = '';
        return;
    }

    // Show preview
    const reader = new FileReader();
    reader.onload = function (e) {
        const preview = document.getElementById('image-preview');
        const previewImg = document.getElementById('preview-img');

        if (preview && previewImg) {
            previewImg.src = e.target.result;
            preview.style.display = 'block';
        }
    };
    reader.readAsDataURL(file);
}

/**
 * Remove image preview
 */
function removeImagePreview() {
    const imageUpload = document.getElementById('image-upload');
    const preview = document.getElementById('image-preview');
    const previewImg = document.getElementById('preview-img');

    if (imageUpload) imageUpload.value = '';
    if (preview) preview.style.display = 'none';
    if (previewImg) previewImg.src = '';
}

/**
 * Handle send broadcast
 */
async function handleSendBroadcast() {
    if (window.aiGenerating) {
        alert('Wait until the AI finishes writing the messages.');
        return;
    }

    // Get selected PSIDs (all users can be selected now)
    const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
    const selectedPSIDs = Array.from(selectedCheckboxes).map(cb => cb.dataset.psid);
    const selectedUsers = getSelectedUsers();

    if (selectedPSIDs.length === 0) {
        alert('Please select at least one user');
        return;
    }

    const isAiMode = window.composeMode === 'ai';
    const aiMap = getAiMessageMap();
    const messageText = document.getElementById('message-text').value.trim();

    if (isAiMode) {
        if (!aiAssignmentsCoverSelection()) {
            alert('Generate messages first for the people you selected. Each person needs a different line.');
            return;
        }
    } else if (!messageText) {
        alert('Please enter a message');
        document.getElementById('message-text').focus();
        return;
    }

    // Get image file
    const imageFile = document.getElementById('image-upload').files[0];

    const outsideCount = selectedUsers.filter(function (user) {
        return !user.isWithin24h;
    }).length;
    const minutes = Math.max(1, Math.ceil((selectedPSIDs.length * 3.5) / 60));
    let confirmCopy = isAiMode
        ? `Send a different message to each of ${selectedPSIDs.length} people? About ${minutes} min, 3–4 seconds apart.`
        : `Send the same message to ${selectedPSIDs.length} people? About ${minutes} min, 3–4 seconds apart.`;
    if (outsideCount > 0) {
        confirmCopy += ` ${outsideCount} selected ${outsideCount === 1 ? 'person is' : 'people are'} outside the 24-hour window. That can risk the Page.`;
    }
    const confirmed = await showConfirmModal(confirmCopy);
    if (!confirmed) {
        return;
    }

    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'send_broadcast');
    formData.append('message_text', messageText);
    selectedPSIDs.forEach(psid => {
        formData.append('selected_psids[]', psid);
    });

    if (imageFile) {
        formData.append('image_file', imageFile);
    }

    // Disable send button and show loading
    const sendBtn = document.getElementById('send-broadcast');
    const sendBtnText = document.getElementById('send-btn-text');
    const sendBtnLoading = document.getElementById('send-btn-loading');

    sendBtn.disabled = true;
    if (sendBtnText) sendBtnText.style.display = 'none';
    if (sendBtnLoading) sendBtnLoading.style.display = 'inline';

    // Clear previous log and show stop button
    const progressLog = document.getElementById('progress-log');
    const stopBtn = document.getElementById('stop-broadcast');

    // Initialize Progress UI
    if (progressLog) {
        renderProgressList(selectedPSIDs, conversations);
    }

    // Global stop flag
    window.isBroadcastStopped = false;
    if (window.BulkZenAndroid && typeof window.BulkZenAndroid.sendStarted === 'function') {
        window.BulkZenAndroid.sendStarted();
    }

    if (stopBtn) {
        // Reset button state: enabled, visible, with correct text
        stopBtn.disabled = false;
        stopBtn.textContent = 'Stop Sending Messages';
        stopBtn.style.display = 'block';
        // Remove any existing event listeners
        stopBtn.onclick = null;
        stopBtn.addEventListener('click', async function () {
            const confirmed = await showConfirmModal('Are you sure you want to stop sending messages?', 'Stop', 'Cancel');
            if (confirmed) {
                window.isBroadcastStopped = true;
                stopBroadcast(); // Also tell server
            }
        });
    }

    // Initialize Stats
    let total = selectedPSIDs.length;
    let sent = 0;
    let failed = 0;
    updateBroadcastSummary(total, sent, failed);

    // Process sequentially — skip failures and keep going
    for (let i = 0; i < selectedPSIDs.length; i++) {
        const psid = selectedPSIDs[i];
        if (window.isBroadcastStopped) {
            updateProgressRow(psid, 'error', 'Stopped by user');
            failed++; // Count as failed or skipped
            updateBroadcastSummary(total, sent, failed);
            continue;
        }

        const outboundText = isAiMode
            ? (aiMap[psid] || window.aiMessages[i])
            : messageText;

        // Update status to sending
        updateProgressRow(psid, 'sending', 'Sending...');

        // Prepare form data for SINGLE user
        const singleFormData = new FormData();
        singleFormData.append('action', 'send_broadcast');
        singleFormData.append('message_text', outboundText);
        singleFormData.append('selected_psids[]', psid); // Send as array of 1
        if (imageFile) {
            singleFormData.append('image_file', imageFile);
        }

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                body: singleFormData
            });

            let data;
            try {
                const text = await response.text();
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Invalid server response');
            }

            if (data && data.success) {
                // Check individual result from the batch of 1
                const result = data.results && data.results[0];
                if (result && result.status === 'success') {
                    updateProgressRow(psid, 'success', 'Sent');
                    sent++;
                } else {
                    const errorMsg = result ? result.error_message : (data.error || 'Unknown error');
                    updateProgressRow(psid, 'error', errorMsg);
                    failed++;
                }
            } else {
                updateProgressRow(psid, 'error', data.error || 'Request failed');
                failed++;
            }

        } catch (error) {
            console.error('Error sending to ' + psid, error);
            updateProgressRow(psid, 'error', error.message);
            failed++;
        }

        // Update Summary
        updateBroadcastSummary(total, sent, failed);

        // Natural 3–4 second gap between messages (skip after the last one)
        if (psid !== selectedPSIDs[selectedPSIDs.length - 1] && !window.isBroadcastStopped) {
            const delayMs = 3000 + Math.floor(Math.random() * 1001);
            await new Promise(resolve => setTimeout(resolve, delayMs));
        }
    }

    // Finished
    sendBtn.disabled = false;
    if (sendBtnText) sendBtnText.style.display = 'inline';
    if (sendBtnLoading) sendBtnLoading.style.display = 'none';
    if (stopBtn) stopBtn.style.display = 'none';

    // Refresh subscription status to update credits
    checkSubscriptionStatus();

    if (window.BulkZenAndroid && typeof window.BulkZenAndroid.sendFinished === 'function') {
        window.BulkZenAndroid.sendFinished();
    }

    const eligibleCount = selectedUsers.filter(function (user) {
        return user.isWithin24h;
    }).length;
    showSuccessNotification({
        sent: sent,
        failed: failed,
        total: total,
        eligible: eligibleCount,
        stopped: !!window.isBroadcastStopped
    });
}

/**
 * Render initial progress list
 */
function renderProgressList(psids, allConversations) {
    const progressLog = document.getElementById('progress-log');
    if (!progressLog) return;

    let html = `
        <div class="log-summary" id="live-summary">
            <h4>Broadcast Progress</h4>
            <div class="summary-item"><strong>Total:</strong> <span id="summary-total">0</span></div>
            <div class="summary-item"><strong style="color: #28a745;">Sent:</strong> <span id="summary-sent">0</span></div>
            <div class="summary-item"><strong style="color: #dc3545;">Failed:</strong> <span id="summary-failed">0</span></div>
        </div>
        <div class="progress-list">
    `;

    psids.forEach(psid => {
        const conv = allConversations.find(c => c.psid === psid) || { name: 'Unknown User', picture_url: null };
        const name = escapeHtml(conv.name);

        html += `
            <div class="log-entry pending" id="row-${psid}">
                <div class="d-flex align-items-center justify-content-between" style="width: 100%;">
                    <div class="d-flex align-items-center">
                        <span class="psid" style="min-width: 120px;">${name}</span>
                        <span class="psid-small" style="color: #888; font-size: 12px; margin-left: 5px;">(${psid})</span>
                    </div>
                    <span class="status" id="status-${psid}" style="color: #6c757d;">Pending...</span>
                </div>
                <div class="error-msg" id="error-${psid}" style="display:none;"></div>
            </div>
        `;
    });

    html += '</div>';
    progressLog.innerHTML = html;
}

/**
 * Update individual row status
 */
function updateProgressRow(psid, status, message) {
    const row = document.getElementById(`row-${psid}`);
    const statusEl = document.getElementById(`status-${psid}`);
    const errorEl = document.getElementById(`error-${psid}`);

    if (!row || !statusEl) return;

    row.className = `log-entry ${status}`;

    if (status === 'sending') {
        statusEl.textContent = '⏳ Sending...';
        statusEl.style.color = '#ffc107'; // Yellow/Orange
    } else if (status === 'success') {
        statusEl.textContent = '✅ Sent';
        statusEl.style.color = '#28a745'; // Green
    } else if (status === 'error') {
        statusEl.textContent = '❌ Failed';
        statusEl.style.color = '#dc3545'; // Red
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
    }

    // Auto-scroll to keep active item in view
    if (status === 'sending') {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

/**
 * Update summary stats
 */
function updateBroadcastSummary(total, sent, failed) {
    const totalEl = document.getElementById('summary-total');
    const sentEl = document.getElementById('summary-sent');
    const failedEl = document.getElementById('summary-failed');

    if (totalEl) totalEl.textContent = total;
    if (sentEl) sentEl.textContent = sent;
    if (failedEl) failedEl.textContent = failed;
}

/**
 * Stop broadcast
 */
function stopBroadcast() {
    const stopBtn = document.getElementById('stop-broadcast');
    if (stopBtn) {
        stopBtn.disabled = true;
        stopBtn.textContent = 'Stopping...';
    }

    fetch('api.php?action=stop_broadcast&ts=' + Date.now())
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to stop broadcast');
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    return { success: false };
                }
            });
        })
        .then(data => {
            if (data && data.success) {
                const progressLog = document.getElementById('progress-log');
                if (progressLog) {
                    const currentHtml = progressLog.innerHTML;
                    progressLog.innerHTML = '<div class="log-entry error" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-left: 3px solid #dc3545;"><strong>⏹ Stop signal sent.</strong> Broadcast will stop after current message.</div>' + currentHtml;
                }
            } else {
                alert('Failed to send stop signal. Please try again.');
                if (stopBtn) {
                    stopBtn.disabled = false;
                    stopBtn.textContent = 'Stop Sending Messages';
                }
            }
        })
        .catch(error => {
            console.error('Error stopping broadcast:', error);
            alert('Error stopping broadcast: ' + error.message);
            if (stopBtn) {
                stopBtn.disabled = false;
                stopBtn.textContent = 'Stop Sending Messages';
            }
        });
}

/**
 * Display broadcast results in progress log
 */
function displayBroadcastResults(data) {
    const progressLog = document.getElementById('progress-log');
    if (!progressLog) return;

    const summary = data.summary;
    const results = data.results;

    let html = `
        <div class="log-summary">
            <h4>Broadcast Summary</h4>
            <div class="summary-item"><strong>Total:</strong> ${summary.total}</div>
            <div class="summary-item"><strong style="color: #28a745;">Sent:</strong> ${summary.sent}</div>
            <div class="summary-item"><strong style="color: #dc3545;">Failed:</strong> ${summary.failed}</div>
        </div>
    `;

    results.forEach(result => {
        const statusClass = result.status === 'success' ? 'success' : 'error';
        const statusText = result.status === 'success' ? 'SUCCESS' : 'ERROR';
        const statusColor = result.status === 'success' ? '#28a745' : '#dc3545';

        html += `
            <div class="log-entry ${statusClass}">
                <div>
                    <span class="psid">PSID ${escapeHtml(result.psid)}:</span>
                    <span class="status" style="color: ${statusColor};">${statusText}</span>
                </div>
                ${result.error_message ? `<div class="error-msg">${escapeHtml(result.error_message)}</div>` : ''}
            </div>
        `;
    });

    progressLog.innerHTML = html;

    // Scroll to bottom
    progressLog.scrollTop = progressLog.scrollHeight;
}

/**
 * Show error message
 */
function showError(message) {
    const progressLog = document.getElementById('progress-log');
    if (progressLog) {
        progressLog.innerHTML = `<div class="error-message">${escapeHtml(message)}</div>`;
    } else {
        alert(message);
    }
}

/**
 * Show custom confirmation modal
 */
/**
 * Show custom confirmation modal (Unified)
 */
function showConfirmModal(message, confirmText = 'Yes', cancelText = 'No', type = 'warning') {
    return new Promise((resolve) => {
        // Remove existing modal if any
        const existingModal = document.getElementById('unified-confirm-modal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'unified-confirm-modal';
        modal.className = 'modal';
        modal.style.display = 'flex'; // Use flex for centering
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.style.position = 'fixed';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.width = '100%';
        modal.style.height = '100%';
        modal.style.backgroundColor = 'rgba(0,0,0,0.5)'; // Backdrop
        modal.style.zIndex = '10002'; // Highest priority

        const iconColor = type === 'danger' ? '#e53e3e' : '#ed8936';
        const btnColor = type === 'danger' ? '#e53e3e' : '#3182ce';
        const iconClass = type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle';

        modal.innerHTML = `
            <div class="modal-content animate-fade-in" style="background: #ffffff; max-width: 400px; text-align: center; padding: 30px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
                <div style="margin-bottom: 20px;">
                    <img src="fav.png" alt="Logo" style="width: 50px; height: 50px; margin-bottom: 15px; object-fit: contain;">
                    <div style="width: 60px; height: 60px; background: ${type === 'danger' ? '#fff5f5' : '#ebf8ff'}; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                        <i class="fas ${iconClass}" style="color: ${iconColor}; font-size: 28px;"></i>
                    </div>
                    <h3 style="color: #2d3748; margin-bottom: 10px; font-size: 20px; font-weight: 700;">Are you sure?</h3>
                    <p style="color: #718096; font-size: 15px; line-height: 1.5;">${message}</p>
                </div>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button id="unified-modal-cancel" style="background: #edf2f7; color: #4a5568; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; flex: 1; transition: all 0.2s;">${cancelText}</button>
                    <button id="unified-modal-confirm" style="background: ${btnColor}; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; flex: 1; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">${confirmText}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const cancelBtn = document.getElementById('unified-modal-cancel');
        const confirmBtn = document.getElementById('unified-modal-confirm');

        // Handle cancel
        const handleCancel = () => {
            modal.style.opacity = '0';
            setTimeout(() => modal.remove(), 200);
            resolve(false);
        };

        // Handle confirm
        const handleConfirm = () => {
            modal.style.opacity = '0';
            setTimeout(() => modal.remove(), 200);
            resolve(true);
        };

        cancelBtn.onclick = handleCancel;
        confirmBtn.onclick = handleConfirm;

        // Close on overlay click
        modal.onclick = (e) => {
            if (e.target === modal) handleCancel();
        };
    });
}

/**
 * Setup scroll down button
 */
function setupScrollDownButton() {
    const scrollBtn = document.getElementById('scroll-down-btn');
    if (!scrollBtn) return;

    // Show button from start
    scrollBtn.classList.add('visible');

    let isPointingUp = false; // Start with arrow pointing down

    // Update button direction based on scroll position
    function updateScrollButtonState() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;

        // If arrow is pointing down and we reach bottom, flip to up
        if (!isPointingUp && (scrollTop + windowHeight) >= (documentHeight - 100)) {
            scrollBtn.classList.add('scroll-up');
            isPointingUp = true;
        }

        // If arrow is pointing up and we reach top, flip to down
        if (isPointingUp && scrollTop < 100) {
            scrollBtn.classList.remove('scroll-up');
            isPointingUp = false;
        }
    }

    window.addEventListener('scroll', updateScrollButtonState);

    // Scroll up or down based on current state
    scrollBtn.addEventListener('click', function () {
        const scrollAmount = window.innerHeight * 0.8; // Scroll 80% of viewport height

        if (isPointingUp) {
            // Arrow pointing up - scroll up
            window.scrollBy({
                top: -scrollAmount,
                behavior: 'smooth'
            });
        } else {
            // Arrow pointing down - scroll down
            window.scrollBy({
                top: scrollAmount,
                behavior: 'smooth'
            });
        }
    });
}

/**
 * Show success notification with sound
 */
function showSuccessNotification(summary) {
    const notification = document.getElementById('success-notification');
    const sound = document.getElementById('notification-sound');
    let payload = summary;
    if (typeof summary === 'string') {
        payload = { message: summary };
    } else if (!summary || typeof summary !== 'object') {
        payload = {};
    }

    const sent = Number(payload.sent || 0);
    const failed = Number(payload.failed || 0);
    const total = Number(payload.total || 0);
    const eligible = Number(payload.eligible || 0);
    const text = payload.message
        ? String(payload.message)
        : ('Sent ' + sent + ' of ' + total + '. Failed ' + failed + '. ' + eligible + ' were inside the 24-hour window.');

    if (notification) {
        const messageEl = notification.querySelector('.notification-message');
        if (messageEl) {
            messageEl.textContent = text;
        }
        notification.classList.add('show');
        setTimeout(function () {
            notification.classList.remove('show');
        }, 5000);
    }

    if (sound) {
        sound.currentTime = 0;
        sound.play().catch(function () {});
    }

    if (window.BulkZenAndroid && typeof window.BulkZenAndroid.notifySendComplete === 'function') {
        window.BulkZenAndroid.notifySendComplete(JSON.stringify({
            sent: sent,
            failed: failed,
            total: total,
            eligible: eligible,
            message: text
        }));
    }
}

/**
 * Manual / AI swipe switch
 */
function setupComposeModeSwitch() {
    window.composeMode = 'ai';
    window.aiMessages = [];
    window.aiAssignments = [];
    window.aiGenerating = false;

    const tabs = document.querySelectorAll('.mode-tab');
    const track = document.getElementById('mode-track');
    if (!tabs.length || !track) {
        return;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            if (window.aiGenerating) {
                return;
            }
            setComposeMode(tab.getAttribute('data-mode'));
        });
    });

    let touchStartX = 0;
    const viewport = document.getElementById('mode-viewport');
    if (viewport) {
        viewport.addEventListener('touchstart', function (e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        viewport.addEventListener('touchend', function (e) {
            if (window.aiGenerating) {
                return;
            }
            const diff = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(diff) < 40) {
                return;
            }
            if (diff < 0) {
                setComposeMode('ai');
            } else {
                setComposeMode('manual');
            }
        }, { passive: true });
    }
}

function setComposeMode(mode) {
    window.composeMode = mode === 'ai' ? 'ai' : 'manual';
    const track = document.getElementById('mode-track');
    const tabs = document.querySelectorAll('.mode-tab');
    if (track) {
        track.classList.toggle('ai-active', window.composeMode === 'ai');
    }
    tabs.forEach(function (tab) {
        const isActive = tab.getAttribute('data-mode') === window.composeMode;
        tab.classList.toggle('active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
}

/**
 * AI Message Studio — unique casino-style lines per person
 */
function setupAiComposer() {
    const generateBtn = document.getElementById('ai-generate-btn');
    const toneButtons = document.querySelectorAll('.ai-tone-btn');

    if (!generateBtn) {
        return;
    }

    toneButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            toneButtons.forEach(function (other) {
                other.classList.remove('active');
            });
            btn.classList.add('active');
        });
    });

    generateBtn.addEventListener('click', generateAiMessage);
    refreshAiGenerateLabel();
}

function setAiStatus(text, type) {
    const statusEl = document.getElementById('ai-status');
    if (!statusEl) {
        return;
    }
    statusEl.textContent = text || '';
    statusEl.className = 'ai-status' + (type ? ' ' + type : '');
}

function getSelectedUserCount() {
    return document.querySelectorAll('.user-checkbox:checked').length;
}

function getSelectedUsers() {
    return Array.from(document.querySelectorAll('.user-checkbox:checked')).map(function (cb) {
        const psid = cb.dataset.psid;
        const conv = conversations.find(function (item) {
            return String(item.psid) === String(psid);
        });
        return {
            psid: psid,
            name: (conv && conv.name) ? conv.name : 'Unknown',
            isWithin24h: !!(conv && conv.is_within_24h)
        };
    });
}

function getAiMessageMap() {
    const map = {};
    (window.aiAssignments || []).forEach(function (row) {
        if (row && row.psid && row.message) {
            map[row.psid] = row.message;
        }
    });
    return map;
}

function aiAssignmentsCoverSelection() {
    const users = getSelectedUsers();
    if (users.length === 0) {
        return false;
    }
    const map = getAiMessageMap();
    if (users.every(function (user) {
        return !!map[user.psid];
    })) {
        return true;
    }
    return Array.isArray(window.aiMessages) && window.aiMessages.length >= users.length;
}

function normalizeAiLine(text) {
    return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function refreshAiGenerateLabel() {
    const labelEl = document.getElementById('ai-generate-label');
    if (!labelEl || window.aiGenerating) {
        return;
    }
    const count = getSelectedUserCount();
    labelEl.textContent = count > 0 ? ('Generate ' + count + ' messages') : 'Generate messages';
}

function setAiBusy(busy) {
    window.aiGenerating = !!busy;
    const lockedIds = [
        'ai-generate-btn',
        'ai-topic',
        'send-broadcast',
        'mode-tab-manual',
        'mode-tab-ai',
        'page-selector',
        'page-selector-header-mobile',
        'select-all-eligible',
        'select-none',
        'select-all-checkbox',
        'refresh-conversations',
        'search-box'
    ];
    lockedIds.forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = !!busy;
        }
    });
    document.querySelectorAll('.ai-tone-btn, .user-checkbox').forEach(function (el) {
        el.disabled = !!busy;
    });
    const composer = document.getElementById('ai-composer');
    if (composer) {
        composer.classList.toggle('is-busy', !!busy);
    }
}

function renderAiPreview(rows, totalNeeded) {
    const preview = document.getElementById('ai-preview');
    const list = document.getElementById('ai-preview-list');
    const heading = document.getElementById('ai-preview-heading');
    if (!preview || !list) {
        return;
    }
    list.innerHTML = '';
    (rows || []).forEach(function (row, index) {
        const item = document.createElement('div');
        item.className = 'ai-preview-item';
        const name = document.createElement('strong');
        name.className = 'ai-preview-name';
        name.textContent = (index + 1) + '. ' + (row.name || 'Unknown');
        const text = document.createElement('span');
        text.className = 'ai-preview-text';
        text.textContent = row.message || '';
        item.appendChild(name);
        item.appendChild(text);
        list.appendChild(item);
    });
    const ready = (rows || []).length;
    const total = totalNeeded || ready;
    if (heading) {
        heading.textContent = ready < total
            ? ('Writing · ' + ready + ' of ' + total + ' unique lines')
            : ('Preview · ' + ready + ' unique lines');
    }
    if (ready) {
        preview.removeAttribute('hidden');
    } else {
        preview.setAttribute('hidden', '');
    }
}

function requestAiBatch(payload) {
    return fetch('api.php?action=generate_ai_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            provider: 'kira',
            topic: payload.topic,
            tone: payload.tone,
            count: payload.count,
            avoid: payload.avoid || []
        })
    }).then(function (response) {
        return response.text().then(function (text) {
            let data = {};
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                throw new Error('AI service returned an invalid response. Try again.');
            }
            return { ok: response.ok, data: data };
        });
    }).then(function (result) {
        const messages = (result.data && result.data.messages) || [];
        if (result.data && result.data.success && messages.length) {
            return messages;
        }
        throw new Error((result.data && result.data.error) || 'Could not generate messages.');
    });
}

async function generateAiMessage() {
    if (window.aiGenerating) {
        return;
    }

    const labelEl = document.getElementById('ai-generate-label');
    const topicEl = document.getElementById('ai-topic');
    const activeTone = document.querySelector('.ai-tone-btn.active');
    let users = getSelectedUsers();
    const topic = topicEl ? topicEl.value.trim() : '';
    const tone = activeTone ? activeTone.getAttribute('data-tone') : 'friendly';

    if (!topic) {
        setAiStatus('Write the bonus or offer first, then generate.', 'error');
        if (topicEl) {
            topicEl.focus();
        }
        return;
    }

    if (users.length === 0) {
        for (let i = 0; i < 12; i++) {
            users.push({
                psid: 'preview-' + i,
                name: 'Line ' + (i + 1),
                isWithin24h: true
            });
        }
    }

    setAiBusy(true);
    window.aiMessages = [];
    window.aiAssignments = [];
    const collected = [];
    const seen = {};
    let emptyStreak = 0;

    try {
        while (collected.length < users.length) {
            const remaining = users.length - collected.length;
            const want = Math.min(15, remaining + 2);
            if (labelEl) {
                labelEl.textContent = 'Writing ' + collected.length + '/' + users.length + '...';
            }
            setAiStatus('Writing ' + collected.length + ' of ' + users.length + ' unique messages. Please wait...', '');

            const batch = await requestAiBatch({
                topic: topic,
                tone: tone,
                count: want,
                avoid: collected.slice(-40)
            });

            let added = 0;
            batch.forEach(function (msg) {
                const key = normalizeAiLine(msg);
                if (!key || seen[key] || collected.length >= users.length) {
                    return;
                }
                seen[key] = true;
                collected.push(msg);
                added += 1;
            });

            const previewRows = users.slice(0, collected.length).map(function (user, index) {
                return {
                    psid: user.psid,
                    name: user.name,
                    message: collected[index]
                };
            });
            renderAiPreview(previewRows, users.length);

            if (added === 0) {
                emptyStreak += 1;
                if (emptyStreak >= 3) {
                    throw new Error('Could not create enough unique lines. Try a clearer bonus brief.');
                }
            } else {
                emptyStreak = 0;
            }
        }

        const assignments = users.map(function (user, index) {
            return {
                psid: user.psid,
                name: user.name,
                message: collected[index]
            };
        });
        window.aiMessages = assignments.map(function (row) {
            return row.message;
        });
        window.aiAssignments = assignments;
        renderAiPreview(assignments, users.length);
        setAiStatus(assignments.length + ' unique messages ready. Check the list, then Send. Each person gets a different line.', 'success');
    } catch (error) {
        window.aiMessages = [];
        window.aiAssignments = [];
        setAiStatus((error && error.message) || 'Could not generate messages.', 'error');
    } finally {
        setAiBusy(false);
        refreshAiGenerateLabel();
    }
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

