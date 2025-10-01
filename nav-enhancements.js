/**
 * Navigation Enhancements für Bruno Generators System
 * Zusätzliche Interaktivität und Features für die Navigationsbar
 */

(function() {
    'use strict';
    
    // Warte bis DOM geladen ist
    document.addEventListener('DOMContentLoaded', initNavigationEnhancements);
    
    function initNavigationEnhancements() {
        // Nur wenn angemeldet
        if (!window.systemConfig || !window.systemConfig.isLoggedIn) {
            return;
        }
        
        // Features initialisieren
        initStickyHeader();
        initButtonRippleEffect();
        initNavScrollBehavior();
        initKeyboardShortcuts();
        initActiveNavHighlight();
        
        console.log('Navigation Enhancements geladen');
    }
    
    /**
     * Sticky Header mit Scroll-Verhalten
     */
    function initStickyHeader() {
        const header = document.querySelector('.header');
        if (!header) return;
        
        let lastScrollTop = 0;
        let scrollTimeout;
        
        window.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            
            scrollTimeout = setTimeout(() => {
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                
                // Füge Schatten bei Scroll hinzu
                if (scrollTop > 10) {
                    header.classList.add('scrolled');
                    header.style.boxShadow = '0 8px 30px rgba(45, 80, 22, 0.4)';
                } else {
                    header.classList.remove('scrolled');
                    header.style.boxShadow = '';
                }
                
                // Auto-Hide bei schnellem Scroll nach unten
                if (scrollTop > lastScrollTop && scrollTop > 200) {
                    header.style.transform = 'translateY(-100%)';
                } else {
                    header.style.transform = 'translateY(0)';
                }
                
                lastScrollTop = scrollTop;
            }, 10);
        }, { passive: true });
    }
    
    /**
     * Material Design Ripple-Effekt für Buttons
     */
    function initButtonRippleEffect() {
        const buttons = document.querySelectorAll('.btn, .user-info');
        
        buttons.forEach(button => {
            button.addEventListener('click', function(e) {
                // Erstelle Ripple-Element
                const ripple = document.createElement('span');
                ripple.classList.add('ripple-effect');
                
                // Berechne Position
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, 0.6);
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    pointer-events: none;
                    animation: ripple-animation 0.6s ease-out;
                `;
                
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                
                // Entferne nach Animation
                setTimeout(() => ripple.remove(), 600);
            });
        });
        
        // Ripple-Animation CSS einfügen
        if (!document.getElementById('ripple-animation-style')) {
            const style = document.createElement('style');
            style.id = 'ripple-animation-style';
            style.textContent = `
                @keyframes ripple-animation {
                    from {
                        transform: scale(0);
                        opacity: 1;
                    }
                    to {
                        transform: scale(4);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    /**
     * Scroll-Verhalten für Navigation auf Mobile
     */
    function initNavScrollBehavior() {
        const navActions = document.querySelector('.nav-actions');
        if (!navActions || window.innerWidth > 768) return;
        
        // Horizontales Scrollen mit Maus-Rad auf Mobile
        navActions.addEventListener('wheel', function(e) {
            if (Math.abs(e.deltaY) > 0) {
                e.preventDefault();
                this.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    }
    
    /**
     * Keyboard Shortcuts
     */
    function initKeyboardShortcuts() {
        const shortcuts = {
            's': 'search-btn',           // Alt+S = Suche
            'n': 'add-object-btn',        // Alt+N = Neuer Marker
            'e': 'toggle-edit-mode-btn',  // Alt+E = Edit Mode
            'u': 'manage-users-btn',      // Alt+U = Benutzer
            'x': 'logout-btn'             // Alt+X = Logout
        };
        
        document.addEventListener('keydown', function(e) {
            // Nur mit Alt-Taste
            if (!e.altKey || e.ctrlKey || e.shiftKey) return;
            
            const key = e.key.toLowerCase();
            if (shortcuts[key]) {
                const button = document.getElementById(shortcuts[key]);
                if (button && !button.disabled) {
                    e.preventDefault();
                    button.click();
                    
                    // Visuelles Feedback
                    button.style.transform = 'scale(0.95)';
                    setTimeout(() => button.style.transform = '', 100);
                }
            }
        });
    }
    
    /**
     * Aktive Navigation-Highlighting
     */
    function initActiveNavHighlight() {
        const currentPage = window.location.pathname.split('/').pop() || 'index.php';
        const navLinks = document.querySelectorAll('.nav-actions a.btn');
        
        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (linkHref === currentPage) {
                link.classList.add('active-nav-item');
                link.style.borderColor = 'var(--accent-color)';
                link.style.background = 'rgba(139, 195, 74, 0.2)';
            }
        });
    }
    
    /**
     * Fügt ein Notification Badge hinzu
     */
    function addNotificationBadge(element, count, type = 'info') {
        if (!element) return;
        
        const badge = document.createElement('span');
        badge.className = `notification-badge notification-badge-${type}`;
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.cssText = `
            position: absolute;
            top: -8px;
            right: -8px;
            background: ${type === 'warning' ? '#ff9800' : type === 'error' ? '#f44336' : '#2196f3'};
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            z-index: 10;
            animation: badge-pop 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        `;
        
        element.style.position = 'relative';
        element.appendChild(badge);
        
        // Badge-Animation
        if (!document.getElementById('badge-animation-style')) {
            const style = document.createElement('style');
            style.id = 'badge-animation-style';
            style.textContent = `
                @keyframes badge-pop {
                    0% { transform: scale(0); opacity: 0; }
                    50% { transform: scale(1.2); }
                    100% { transform: scale(1); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    /**
     * Smooth Scroll für Navigation-Links
     */
    function initSmoothScroll() {
        document.querySelectorAll('.nav-actions a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    /**
     * Touch Swipe für Mobile Navigation
     */
    function initTouchSwipe() {
        if (window.innerWidth > 768) return;
        
        const navActions = document.querySelector('.nav-actions');
        if (!navActions) return;
        
        let startX = 0;
        let scrollLeft = 0;
        let isDown = false;
        
        navActions.addEventListener('touchstart', (e) => {
            isDown = true;
            startX = e.touches[0].pageX - navActions.offsetLeft;
            scrollLeft = navActions.scrollLeft;
        });
        
        navActions.addEventListener('touchmove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.touches[0].pageX - navActions.offsetLeft;
            const walk = (x - startX) * 2;
            navActions.scrollLeft = scrollLeft - walk;
        });
        
        navActions.addEventListener('touchend', () => {
            isDown = false;
        });
    }
    
    /**
     * Lazy Load Navigation Icons (Performance-Optimierung)
     */
    function optimizeNavigationPerformance() {
        // Debounce resize events
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                // Reinitialisiere Mobile-Features wenn nötig
                if (window.innerWidth <= 768) {
                    initTouchSwipe();
                }
            }, 250);
        });
    }
    
    /**
     * Navigation State Management
     */
    const NavState = {
        isSearchOpen: false,
        isEditMode: false,
        
        toggleSearch() {
            this.isSearchOpen = !this.isSearchOpen;
            sessionStorage.setItem('navSearchOpen', this.isSearchOpen);
        },
        
        toggleEditMode() {
            this.isEditMode = !this.isEditMode;
            sessionStorage.setItem('navEditMode', this.isEditMode);
        },
        
        restore() {
            this.isSearchOpen = sessionStorage.getItem('navSearchOpen') === 'true';
            this.isEditMode = sessionStorage.getItem('navEditMode') === 'true';
        }
    };
    
    // State wiederherstellen
    NavState.restore();
    
    // Initialisiere weitere Features
    initSmoothScroll();
    initTouchSwipe();
    optimizeNavigationPerformance();
    
    // Exportiere für globalen Zugriff
    window.NavEnhancements = {
        addBadge: addNotificationBadge,
        state: NavState
    };
    
})();

/**
 * Accessibility Improvements
 */
(function() {
    'use strict';
    
    document.addEventListener('DOMContentLoaded', function() {
        // Skip to Main Content Link
        const skipLink = document.createElement('a');
        skipLink.href = '#main-content';
        skipLink.className = 'skip-to-main';
        skipLink.textContent = 'Zum Hauptinhalt springen';
        skipLink.style.cssText = `
            position: absolute;
            top: -40px;
            left: 0;
            background: var(--secondary-color);
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            z-index: 10000;
            font-weight: 600;
            border-radius: 0 0 8px 0;
        `;
        
        skipLink.addEventListener('focus', function() {
            this.style.top = '0';
        });
        
        skipLink.addEventListener('blur', function() {
            this.style.top = '-40px';
        });
        
        document.body.insertBefore(skipLink, document.body.firstChild);
        
        // Main Content ID
        const mainContent = document.querySelector('.main-content');
        if (mainContent && !mainContent.id) {
            mainContent.id = 'main-content';
            mainContent.setAttribute('tabindex', '-1');
        }
        
        // ARIA Labels für Buttons
        document.querySelectorAll('.btn:not([aria-label])').forEach(btn => {
            const text = btn.textContent.trim();
            if (text) {
                btn.setAttribute('aria-label', text);
            }
        });
        
        // Focus Trap für Modals (wenn geöffnet)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                const modal = document.querySelector('.modal.show');
                if (modal) {
                    const focusableElements = modal.querySelectorAll(
                        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
                    );
                    const firstElement = focusableElements[0];
                    const lastElement = focusableElements[focusableElements.length - 1];
                    
                    if (e.shiftKey && document.activeElement === firstElement) {
                        e.preventDefault();
                        lastElement.focus();
                    } else if (!e.shiftKey && document.activeElement === lastElement) {
                        e.preventDefault();
                        firstElement.focus();
                    }
                }
            }
        });
    });
})();

/**
 * Navigation Analytics (optional)
 */
(function() {
    'use strict';
    
    if (window.systemConfig && window.systemConfig.enableAnalytics) {
        document.addEventListener('DOMContentLoaded', function() {
            const buttons = document.querySelectorAll('.nav-actions .btn');
            
            buttons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const action = this.id || this.textContent.trim();
                    console.log('[Nav Analytics]', action, 'clicked at', new Date().toISOString());
                    
                    // Hier könnte ein Analytics-Service integriert werden
                    if (typeof window.trackEvent === 'function') {
                        window.trackEvent('Navigation', 'Click', action);
                    }
                });
            });
        });
    }
})();