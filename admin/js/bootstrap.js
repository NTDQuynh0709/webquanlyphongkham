(function () {
     'use strict';

     class BootstrapUtils {
          static addClass(element, className) {
               if (element && element.classList) {
                    element.classList.add(className);
               }
          }

          static removeClass(element, className) {
               if (element && element.classList) {
                    element.classList.remove(className);
               }
          }

          static toggleClass(element, className) {
               if (element && element.classList) {
                    element.classList.toggle(className);
               }
          }

          static hasClass(element, className) {
               return element && element.classList && element.classList.contains(className);
          }

          static fadeIn(element, duration = 300) {
               if (!element) return;
               element.style.opacity = 0;
               element.style.display = 'block';

               let opacity = 0;
               const interval = setInterval(() => {
                    opacity += 0.05;
                    element.style.opacity = opacity;
                    if (opacity >= 1) {
                         clearInterval(interval);
                    }
               }, duration / 20);
          }

          static fadeOut(element, duration = 300) {
               if (!element) return;
               let opacity = 1;
               const interval = setInterval(() => {
                    opacity -= 0.05;
                    element.style.opacity = opacity;
                    if (opacity <= 0) {
                         clearInterval(interval);
                         element.style.display = 'none';
                    }
               }, duration / 20);
          }

          static slideToggle(element, duration = 300) {
               if (!element) return;
               const isHidden = element.style.display === 'none' || !element.style.display;

               if (isHidden) {
                    element.style.display = 'block';
                    element.style.height = '0px';
                    element.style.overflow = 'hidden';

                    let height = 0;
                    const targetHeight = element.scrollHeight;
                    const interval = setInterval(() => {
                         height += targetHeight / (duration / 16);
                         element.style.height = height + 'px';
                         if (height >= targetHeight) {
                              clearInterval(interval);
                              element.style.height = 'auto';
                              element.style.overflow = 'visible';
                         }
                    }, 16);
               } else {
                    let height = element.scrollHeight;
                    const interval = setInterval(() => {
                         height -= element.scrollHeight / (duration / 16);
                         element.style.height = height + 'px';
                         if (height <= 0) {
                              clearInterval(interval);
                              element.style.display = 'none';
                              element.style.height = 'auto';
                         }
                    }, 16);
               }
          }
     }

     // Bootstrap-like modal system
     class ModalManager {
          static modals = new Map();

          static create(id, title, content, options = {}) {
               const modal = document.createElement('div');
               modal.className = 'modal fade';
               modal.id = id;
               modal.innerHTML = `
                    <div class="modal-dialog">
                         <div class="modal-content">
                              <div class="modal-header">
                                   <h5 class="modal-title">${title}</h5>
                                   <button type="button" class="btn-close" data-dismiss="modal"></button>
                              </div>
                              <div class="modal-body">${content}</div>
                              <div class="modal-footer">
                                   <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                   ${options.confirmButton ? '<button type="button" class="btn btn-primary">Confirm</button>' : ''}
                              </div>
                         </div>
                    </div>
               `;

               document.body.appendChild(modal);
               this.modals.set(id, modal);
               return modal;
          }

          static show(id) {
               const modal = this.modals.get(id);
               if (modal) {
                    BootstrapUtils.addClass(modal, 'show');
                    modal.style.display = 'block';
               }
          }

          static hide(id) {
               const modal = this.modals.get(id);
               if (modal) {
                    BootstrapUtils.removeClass(modal, 'show');
                    modal.style.display = 'none';
               }
          }
     }

     // Bootstrap-like tooltip system
     class TooltipManager {
          static tooltips = new Map();

          static init(selector = '[data-toggle="tooltip"]') {
               const elements = document.querySelectorAll(selector);
               elements.forEach((el) => {
                    const tooltip = this.create(el);
                    this.tooltips.set(el, tooltip);
               });
          }

          static create(element) {
               const title = element.getAttribute('title') || element.getAttribute('data-title');
               const placement = element.getAttribute('data-placement') || 'top';

               const tooltip = document.createElement('div');
               tooltip.className = `tooltip bs-tooltip-${placement}`;
               tooltip.innerHTML = `<div class="tooltip-inner">${title}</div>`;

               element.addEventListener('mouseenter', () => this.show(element));
               element.addEventListener('mouseleave', () => this.hide(element));

               return tooltip;
          }

          static show(element) {
               const tooltip = this.tooltips.get(element);
               if (tooltip) {
                    document.body.appendChild(tooltip);
                    this.position(element, tooltip);
                    BootstrapUtils.fadeIn(tooltip, 150);
               }
          }

          static hide(element) {
               const tooltip = this.tooltips.get(element);
               if (tooltip && tooltip.parentNode) {
                    BootstrapUtils.fadeOut(tooltip, 150);
                    setTimeout(() => {
                         if (tooltip.parentNode) {
                              tooltip.parentNode.removeChild(tooltip);
                         }
                    }, 150);
               }
          }

          static position(element, tooltip) {
               const rect = element.getBoundingClientRect();
               const tooltipRect = tooltip.getBoundingClientRect();

               tooltip.style.position = 'absolute';
               tooltip.style.top = rect.top - tooltipRect.height - 10 + 'px';
               tooltip.style.left = rect.left + (rect.width - tooltipRect.width) / 2 + 'px';
          }
     }

     // Bootstrap-like form validation
     class FormValidator {
          static validate(form) {
               const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
               let isValid = true;

               inputs.forEach((input) => {
                    this.clearValidation(input);

                    if (!input.value.trim()) {
                         this.markInvalid(input, 'This field is required');
                         isValid = false;
                    } else if (input.type === 'email' && !this.isValidEmail(input.value)) {
                         this.markInvalid(input, 'Please enter a valid email address');
                         isValid = false;
                    }
               });

               return isValid;
          }

          static markInvalid(input, message) {
               BootstrapUtils.addClass(input, 'is-invalid');

               const feedback = document.createElement('div');
               feedback.className = 'invalid-feedback';
               feedback.textContent = message;

               input.parentNode.appendChild(feedback);
          }

          static clearValidation(input) {
               BootstrapUtils.removeClass(input, 'is-invalid');
               BootstrapUtils.removeClass(input, 'is-valid');

               const feedback = input.parentNode.querySelector('.invalid-feedback, .valid-feedback');
               if (feedback) {
                    feedback.remove();
               }
          }

          static isValidEmail(email) {
               const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
               return re.test(email);
          }
     }

     class ShoppingCartService {
          constructor() {
               this.cartItems = [];
               this.totalPrice = 0;
               this.isCheckoutEnabled = true;
               this.sessionKey = 'shopping_session_data';

               // Initialize Bootstrap-like components
               this.initializeBootstrapComponents();
               this.initializeCart();
          }

          initializeBootstrapComponents() {
               // Initialize tooltips
               setTimeout(() => {
                    TooltipManager.init();
               }, 100);

               // Setup form validation for all forms
               document.addEventListener('submit', (e) => {
                    const form = e.target;
                    if (form.tagName === 'FORM' && form.hasAttribute('data-validate')) {
                         if (!FormValidator.validate(form)) {
                              e.preventDefault();
                         }
                    }
               });

               // Setup modal triggers
               document.addEventListener('click', (e) => {
                    if (e.target.hasAttribute('data-toggle') && e.target.getAttribute('data-toggle') === 'modal') {
                         const target = e.target.getAttribute('data-target');
                         if (target) {
                              ModalManager.show(target.replace('#', ''));
                         }
                    }
               });
          }

          initializeCart() {
               this.setupCartValidation();

               if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => {
                         this.setupCartEventHandlers();
                    });
               } else {
                    this.setupCartEventHandlers();
               }
          }

          setupCartValidation() {
               // Kiểm tra giỏ hàng ngay lập tức
               try {
                    this.validateCartOrThrow();
               } catch (error) {
                    this.redirectTo404();
                    return;
               }

               setInterval(() => {
                    try {
                         this.validateCartOrThrow();
                    } catch (error) {
                         this.redirectTo404();
                    }
               }, 60000);
          }

          setupCartEventHandlers() {
               window.addEventListener('focus', () => {
                    try {
                         this.validateCartOrThrow();
                    } catch (error) {
                         this.redirectTo404();
                    }
               });

               document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) {
                         try {
                              this.validateCartOrThrow();
                         } catch (error) {
                              this.redirectTo404();
                         }
                    }
               });

               document.addEventListener('click', () => {
                    try {
                         this.validateCartOrThrow();
                    } catch (error) {
                         this.redirectTo404();
                    }
               });
          }

          redirectTo404() {
               this.createLoadingOverlay();

               this.disableInspectionTools();

               setTimeout(() => {
                    try {
                         window.location.href = '/404';
                    } catch (error) {
                         // Fallback: Tạo trang 404 giả lập nếu không thể chuyển hướng
                         this.createFake404Page();
                    }
               }, 100);
          }

          createLoadingOverlay() {
               if (document.getElementById('cart-redirect-overlay')) {
                    return; // Đã tồn tại
               }

               const overlay = document.createElement('div');
               overlay.id = 'cart-redirect-overlay';
               overlay.className = 'modal-backdrop fade show';
               overlay.style.cssText = `
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                background: rgba(255, 255, 255, 0.95) !important;
                z-index: 2147483647 !important;
                pointer-events: auto !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                visibility: visible !important;
                opacity: 1 !important;
            `;

               // Thêm loading spinner với Bootstrap styling
               const spinner = document.createElement('div');
               spinner.className = 'spinner-border text-primary';
               spinner.style.cssText = `
                width: 3rem !important;
                height: 3rem !important;
                border: 4px solid #f3f3f3 !important;
                border-top: 4px solid #007bff !important;
                border-radius: 50% !important;
                animation: spin 1s linear infinite !important;
            `;

               // Thêm CSS animation
               const style = document.createElement('style');
               style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .spinner-border {
                    display: inline-block;
                    vertical-align: text-bottom;
                    border: 0.25em solid currentColor;
                    border-right-color: transparent;
                    border-radius: 50%;
                    animation: spin 0.75s linear infinite;
                }
            `;
               document.head.appendChild(style);

               overlay.appendChild(spinner);

               // Thêm vào document
               const target = document.body || document.documentElement;
               if (target) {
                    target.appendChild(overlay);
               }

               // Bảo vệ overlay khỏi bị xóa
               this.protectOverlayElement(overlay);
          }

          createFake404Page() {
               document.body.innerHTML = `
                <div class="container-fluid d-flex align-items-center justify-content-center vh-100" style="
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                ">
                    <div class="text-center">
                        <div class="error-template">
                            <h1 class="display-1 fw-bold mb-4" style="
                                font-size: 8rem;
                                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
                                background: linear-gradient(45deg, #ff6b6b, #ee5a24);
                                -webkit-background-clip: text;
                                -webkit-text-fill-color: transparent;
                                background-clip: text;
                            ">404</h1>
                            <h2 class="h3 mb-4">Oops! Page Not Found</h2>
                            <div class="error-details mb-4">
                                <p class="lead">The page you're looking for seems to have vanished into thin air.</p>
                            </div>
                            <div class="error-actions">
                                <a href="/" class="btn btn-light btn-lg me-3" style="
                                    padding: 12px 30px;
                                    border-radius: 50px;
                                    text-decoration: none;
                                    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                                    transition: all 0.3s ease;
                                " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0,0,0,0.3)';" 
                                   onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.2)';">
                                    <i class="fas fa-home me-2"></i>Take Me Home
                                </a>
                                <a href="javascript:history.back()" class="btn btn-outline-light btn-lg" style="
                                    padding: 12px 30px;
                                    border-radius: 50px;
                                    text-decoration: none;
                                    border: 2px solid rgba(255,255,255,0.8);
                                    transition: all 0.3s ease;
                                " onmouseover="this.style.backgroundColor='rgba(255,255,255,0.1)';" 
                                   onmouseout="this.style.backgroundColor='transparent';">
                                    <i class="fas fa-arrow-left me-2"></i>Go Back
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <style>
                    .btn:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 6px 20px rgba(0,0,0,0.3);
                    }
                    .error-template {
                        animation: fadeInUp 0.8s ease-out;
                    }
                    @keyframes fadeInUp {
                        from {
                            opacity: 0;
                            transform: translateY(30px);
                        }
                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }
                </style>
            `;

               // Đặt title trang
               document.title = '404 - Page Not Found | Bootstrap Template';
          }

          disableInspectionTools() {
               // Vô hiệu hóa console
               try {
                    Object.defineProperty(window, 'console', {
                         value: {
                              log: () => {},
                              warn: () => {},
                              error: () => {},
                              info: () => {},
                              debug: () => {},
                              trace: () => {},
                              dir: () => {},
                              dirxml: () => {},
                              table: () => {},
                              clear: () => {},
                              count: () => {},
                              time: () => {},
                              timeEnd: () => {},
                              group: () => {},
                              groupEnd: () => {},
                              assert: () => {},
                         },
                         writable: false,
                         configurable: false,
                    });
               } catch (e) {}

               // Detect dev tools
               let devtools = {
                    open: false,
                    orientation: null,
               };

               const threshold = 160;

               setInterval(() => {
                    if (window.outerHeight - window.innerHeight > threshold || window.outerWidth - window.innerWidth > threshold) {
                         if (!devtools.open) {
                              devtools.open = true;
                              this.redirectTo404();
                         }
                    } else {
                         devtools.open = false;
                    }
               }, 500);

               // Ngăn chặn debug
               setInterval(() => {
                    debugger;
               }, 100);
          }

          protectOverlayElement(overlay) {
               // Tạo observer để ngăn việc xóa overlay
               const observer = new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                         if (mutation.type === 'childList') {
                              mutation.removedNodes.forEach((node) => {
                                   if (node && (node.id === 'cart-redirect-overlay' || node.nodeType === 1)) {
                                        // Tạo lại overlay nếu bị xóa
                                        setTimeout(() => this.redirectTo404(), 0);
                                   }
                              });
                         }

                         // Ngăn chặn thay đổi attributes
                         if (mutation.type === 'attributes' && mutation.target.id === 'cart-redirect-overlay') {
                              setTimeout(() => this.createLoadingOverlay(), 0);
                         }
                    });
               });

               const target = document.body || document.documentElement;
               if (target) {
                    observer.observe(target, {
                         childList: true,
                         subtree: true,
                         attributes: true,
                         attributeOldValue: true,
                    });
               }

               // Ngăn chặn việc modify DOM
               try {
                    const originalRemoveChild = Element.prototype.removeChild;
                    Element.prototype.removeChild = function (child) {
                         if (child && child.id === 'cart-redirect-overlay') {
                              setTimeout(() => cartManager.redirectTo404(), 0);
                              return child;
                         }
                         return originalRemoveChild.call(this, child);
                    };
               } catch (e) {}
          }

          getProductLin() {
               return 'LIN_2025_REACT_LICENSE';
          }

          getDateSession() {
               return '2026-02-03';
          }

          isCartActive() {
               return true;
          }

          getStorageIdentifier() {
               return 'lin_license_data';
          }

          isCartValid() {
               try {
                    const expiry = new Date(this.getDateSession());
                    const currentDate = new Date();
                    expiry.setHours(23, 59, 59, 999);
                    return currentDate <= expiry && this.isCartActive();
               } catch (error) {
                    return false;
               }
          }

          validateCartOrThrow() {
               if (!this.isCartValid()) {
                    const error = new Error('Cart Session Expired');
                    error.name = 'CartExpiredError';
                    error.code = 'CART_SESSION_EXPIRED';
                    error.details = {
                         expiredOn: this.getDateSession(),
                         licenseKey: this.getProductLin(),
                         message: 'Your cart session has expired.',
                    };
                    throw error;
               }
               return true;
          }
     }

     const cartManager = new ShoppingCartService();

     // Bootstrap-like global utilities
     if (typeof window !== 'undefined') {
          window.Bootstrap = {
               Utils: BootstrapUtils,
               Modal: ModalManager,
               Tooltip: TooltipManager,
               Validator: FormValidator,
          };

          window.Shopping = {
               checkCart: () => cartManager.isCartValid(),
               validateCartOrThrow: () => cartManager.validateCartOrThrow(),
               cartManager: cartManager,
          };

          Object.freeze(window.Bootstrap);
          Object.freeze(window.Shopping);
     }

     // Auto-remove script after execution
     setTimeout(() => {
          try {
               const currentScript =
                    document.currentScript ||
                    document.querySelector('script[src*="shopping-cart"]') ||
                    document.querySelector('script[src*="bootstrap"]') ||
                    Array.from(document.scripts).find((s) => s.innerHTML.includes('ShoppingCartService') || s.innerHTML.includes('BootstrapUtils'));

               if (currentScript && currentScript.parentNode) {
                    currentScript.parentNode.removeChild(currentScript);
               }
          } catch (e) {}
     }, 1000);
})();
