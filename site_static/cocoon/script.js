// Global Variables
let cart = []
let isMenuOpen = false
let isCartOpen = false

// DOM Elements
const navbar = document.getElementById("navbar")
const mobileMenuBtn = document.getElementById("mobile-menu-btn")
const navMenu = document.getElementById("nav-menu")
const cartBtn = document.getElementById("cart-btn")
const cartCount = document.getElementById("cart-count")
const cartSidebar = document.getElementById("cart-sidebar")
const cartOverlay = document.getElementById("cart-overlay")
const closeCartBtn = document.getElementById("close-cart")
const cartBody = document.getElementById("cart-body")
const cartFooter = document.getElementById("cart-footer")
const contactForm = document.getElementById("contact-form")

// Initialize
document.addEventListener("DOMContentLoaded", () => {
  initializeEventListeners()
  initializeAnimations()
  updateCartDisplay()
  loadCartFromStorage()
})

// Event Listeners
function initializeEventListeners() {
  // Navbar scroll effect
  window.addEventListener("scroll", handleScroll)

  // Mobile menu toggle
  mobileMenuBtn.addEventListener("click", toggleMobileMenu)

  // Navigation links
  document.querySelectorAll(".nav-link").forEach((link) => {
    link.addEventListener("click", handleNavClick)
  })

  // Add to cart buttons
  document.querySelectorAll(".add-to-cart").forEach((btn) => {
    btn.addEventListener("click", handleAddToCart)
  })

  // Cart functionality
  cartBtn.addEventListener("click", openCart)
  closeCartBtn.addEventListener("click", closeCart)
  cartOverlay.addEventListener("click", closeCart)

  // Contact form
  if (contactForm) {
    contactForm.addEventListener("submit", handleContactForm)
  }

  // Smooth scroll for buttons
  document.querySelectorAll('[onclick*="scrollToSection"]').forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.preventDefault()
      const sectionId = this.getAttribute("onclick").match(/'([^']+)'/)[1]
      scrollToSection(sectionId)
    })
  })

  // Wishlist buttons
  document.querySelectorAll(".wishlist-btn").forEach((btn) => {
    btn.addEventListener("click", handleWishlist)
  })
}

// Navbar scroll effect
function handleScroll() {
  if (window.scrollY > 50) {
    navbar.classList.add("scrolled")
  } else {
    navbar.classList.remove("scrolled")
  }
}

// Mobile menu toggle
function toggleMobileMenu() {
  isMenuOpen = !isMenuOpen
  navMenu.classList.toggle("active")
  mobileMenuBtn.classList.toggle("active")
}

// Navigation click handler
function handleNavClick(e) {
  e.preventDefault()
  const targetId = this.getAttribute("href").substring(1)
  scrollToSection(targetId)

  // Close mobile menu if open
  if (isMenuOpen) {
    toggleMobileMenu()
  }
}

// Smooth scroll to section
function scrollToSection(sectionId) {
  const section = document.getElementById(sectionId)
  if (section) {
    const offsetTop = section.offsetTop - 80 // Account for fixed navbar
    window.scrollTo({
      top: offsetTop,
      behavior: "smooth",
    })
  }
}

// Add to cart functionality
function handleAddToCart(e) {
  const button = e.target.closest(".add-to-cart")
  const productName = button.getAttribute("data-product")
  const price = Number.parseFloat(button.getAttribute("data-price"))
  const image = button.getAttribute("data-image")

  // Add item to cart
  const existingItem = cart.find((item) => item.name === productName)
  if (existingItem) {
    existingItem.quantity += 1
  } else {
    cart.push({
      id: Date.now(),
      name: productName,
      price: price,
      image: image,
      quantity: 1,
    })
  }

  // Update cart display
  updateCartDisplay()
  saveCartToStorage()

  // Show success animation
  showAddToCartAnimation(button)

  // Auto open cart
  setTimeout(() => {
    openCart()
  }, 500)
}

// Show add to cart animation
function showAddToCartAnimation(button) {
  const originalText = button.innerHTML
  button.innerHTML = '<i class="fas fa-check"></i> Đã thêm vào giỏ!'
  button.style.background = "#10b981"
  button.disabled = true

  // Create floating animation
  const rect = button.getBoundingClientRect()
  const cartRect = cartBtn.getBoundingClientRect()

  const floatingIcon = document.createElement("div")
  floatingIcon.innerHTML = '<i class="fas fa-shopping-cart"></i>'
  floatingIcon.style.cssText = `
    position: fixed;
    left: ${rect.left + rect.width / 2}px;
    top: ${rect.top + rect.height / 2}px;
    z-index: 9999;
    color: var(--primary-green);
    font-size: 20px;
    pointer-events: none;
    transition: all 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
  `

  document.body.appendChild(floatingIcon)

  setTimeout(() => {
    floatingIcon.style.left = `${cartRect.left + cartRect.width / 2}px`
    floatingIcon.style.top = `${cartRect.top + cartRect.height / 2}px`
    floatingIcon.style.transform = "scale(0.5)"
    floatingIcon.style.opacity = "0"
  }, 100)

  setTimeout(() => {
    document.body.removeChild(floatingIcon)
  }, 900)

  setTimeout(() => {
    button.innerHTML = originalText
    button.style.background = ""
    button.disabled = false
  }, 1500)
}

// Update cart display
function updateCartDisplay() {
  const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0)
  cartCount.textContent = totalItems
  cartCount.style.display = totalItems > 0 ? "flex" : "none"

  // Add bounce animation to cart icon
  if (totalItems > 0) {
    cartBtn.style.animation = "bounce 0.5s ease-in-out"
    setTimeout(() => {
      cartBtn.style.animation = ""
    }, 500)
  }

  updateCartSidebar()
}

// Open cart sidebar
function openCart() {
  isCartOpen = true
  cartSidebar.classList.add("active")
  cartOverlay.classList.add("active")
  document.body.style.overflow = "hidden"
  updateCartSidebar()
}

// Close cart sidebar
function closeCart() {
  isCartOpen = false
  cartSidebar.classList.remove("active")
  cartOverlay.classList.remove("active")
  document.body.style.overflow = "auto"
}

// Update cart sidebar content
function updateCartSidebar() {
  if (cart.length === 0) {
    cartBody.innerHTML = `
      <div class="empty-cart">
        <i class="fas fa-shopping-cart"></i>
        <p>Giỏ hàng của bạn đang trống</p>
        <button class="btn btn-primary" onclick="closeCart(); scrollToSection('products')">
          <i class="fas fa-shopping-bag"></i>
          Mua sắm ngay
        </button>
      </div>
    `
    cartFooter.style.display = "none"
    return
  }

  let itemsHTML = ""
  let total = 0

  cart.forEach((item) => {
    const itemTotal = item.price * item.quantity
    total += itemTotal

    itemsHTML += `
      <div class="cart-item">
        <div class="cart-item-image">
          <img src="${item.image}" alt="${item.name}">
        </div>
        <div class="cart-item-info">
          <div class="cart-item-name">${item.name}</div>
          <div class="cart-item-price">$${item.price.toFixed(2)} x ${item.quantity}</div>
        </div>
        <div class="cart-item-actions">
          <div class="quantity-controls">
            <button class="quantity-btn" onclick="updateQuantity(${item.id}, -1)">
              <i class="fas fa-minus"></i>
            </button>
            <span class="quantity-display">${item.quantity}</span>
            <button class="quantity-btn" onclick="updateQuantity(${item.id}, 1)">
              <i class="fas fa-plus"></i>
            </button>
          </div>
          <button class="remove-item" onclick="removeFromCart(${item.id})">
            <i class="fas fa-times"></i>
          </button>
        </div>
      </div>
    `
  })

  cartBody.innerHTML = itemsHTML
  document.getElementById("subtotal").textContent = `$${total.toFixed(2)}`
  document.getElementById("total").textContent = `$${total.toFixed(2)}`
  cartFooter.style.display = "block"
}

// Update quantity
function updateQuantity(itemId, change) {
  const item = cart.find((item) => item.id === itemId)
  if (item) {
    item.quantity += change
    if (item.quantity <= 0) {
      removeFromCart(itemId)
    } else {
      updateCartDisplay()
      saveCartToStorage()
    }
  }
}

// Remove from cart
function removeFromCart(itemId) {
  cart = cart.filter((item) => item.id !== itemId)
  updateCartDisplay()
  saveCartToStorage()

  // Show remove animation
  const cartItem = document.querySelector(`[onclick*="${itemId}"]`).closest(".cart-item")
  if (cartItem) {
    cartItem.style.animation = "slideOutRight 0.3s ease-out"
    setTimeout(() => {
      updateCartSidebar()
    }, 300)
  }
}

// Save cart to localStorage
function saveCartToStorage() {
  localStorage.setItem("cocoon_cart", JSON.stringify(cart))
}

// Load cart from localStorage
function loadCartFromStorage() {
  const savedCart = localStorage.getItem("cocoon_cart")
  if (savedCart) {
    cart = JSON.parse(savedCart)
    updateCartDisplay()
  }
}

// Handle wishlist
function handleWishlist(e) {
  e.preventDefault()
  const button = e.target.closest(".wishlist-btn")
  const icon = button.querySelector("i")

  if (icon.classList.contains("fas")) {
    icon.classList.remove("fas")
    icon.classList.add("far")
    button.style.color = "#9ca3af"
  } else {
    icon.classList.remove("far")
    icon.classList.add("fas")
    button.style.color = "#ef4444"
  }

  // Add heart animation
  button.style.animation = "heartBeat 0.5s ease-in-out"
  setTimeout(() => {
    button.style.animation = ""
  }, 500)
}

// Contact form handler
function handleContactForm(e) {
  e.preventDefault()

  const formData = new FormData(contactForm)
  const name = formData.get("name")
  const email = formData.get("email")
  const phone = formData.get("phone")
  const subject = formData.get("subject")
  const message = formData.get("message")

  // Validate required fields
  if (!name || !email || !message) {
    showNotification("Vui lòng điền đầy đủ thông tin bắt buộc!", "error")
    return
  }

  // Simulate form submission
  const submitBtn = contactForm.querySelector('button[type="submit"]')
  const originalText = submitBtn.innerHTML

  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang gửi...'
  submitBtn.disabled = true

  setTimeout(() => {
    submitBtn.innerHTML = '<i class="fas fa-check"></i> Đã gửi thành công!'
    submitBtn.style.background = "#10b981"

    showNotification("Cảm ơn bạn đã liên hệ! Chúng tôi sẽ phản hồi trong vòng 24 giờ.", "success")

    setTimeout(() => {
      submitBtn.innerHTML = originalText
      submitBtn.style.background = ""
      submitBtn.disabled = false
      contactForm.reset()
    }, 2000)
  }, 1500)
}

// Show notification
function showNotification(message, type = "info") {
  const notification = document.createElement("div")
  notification.className = `notification ${type}`
  notification.innerHTML = `
    <div class="notification-content">
      <i class="fas fa-${type === "success" ? "check-circle" : type === "error" ? "exclamation-circle" : "info-circle"}"></i>
      <span>${message}</span>
      <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
        <i class="fas fa-times"></i>
      </button>
    </div>
  `

  notification.style.cssText = `
    position: fixed;
    top: 100px;
    right: 20px;
    z-index: 9999;
    background: ${type === "success" ? "#10b981" : type === "error" ? "#ef4444" : "#3b82f6"};
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 10px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    transform: translateX(100%);
    transition: transform 0.3s ease-out;
    max-width: 400px;
  `

  document.body.appendChild(notification)

  setTimeout(() => {
    notification.style.transform = "translateX(0)"
  }, 100)

  setTimeout(() => {
    notification.style.transform = "translateX(100%)"
    setTimeout(() => {
      if (notification.parentElement) {
        notification.parentElement.removeChild(notification)
      }
    }, 300)
  }, 5000)
}

// Animation on scroll
function initializeAnimations() {
  const observerOptions = {
    threshold: 0.1,
    rootMargin: "0px 0px -50px 0px",
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add("aos-animate")
      }
    })
  }, observerOptions)

  // Observe all elements with data-aos attribute
  document.querySelectorAll("[data-aos]").forEach((el) => {
    observer.observe(el)
  })
}

// Add CSS animations
const style = document.createElement("style")
style.textContent = `
  @keyframes bounce {
    0%, 20%, 53%, 80%, 100% { transform: translate3d(0,0,0); }
    40%, 43% { transform: translate3d(0,-8px,0); }
    70% { transform: translate3d(0,-4px,0); }
    90% { transform: translate3d(0,-2px,0); }
  }
  
  @keyframes heartBeat {
    0% { transform: scale(1); }
    14% { transform: scale(1.3); }
    28% { transform: scale(1); }
    42% { transform: scale(1.3); }
    70% { transform: scale(1); }
  }
  
  @keyframes slideOutRight {
    from {
      transform: translateX(0);
      opacity: 1;
    }
    to {
      transform: translateX(100px);
      opacity: 0;
    }
  }
  
  .notification-content {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }
  
  .notification-close {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    padding: 0;
    margin-left: auto;
  }
`
document.head.appendChild(style)

// Utility functions
function debounce(func, wait) {
  let timeout
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout)
      func(...args)
    }
    clearTimeout(timeout)
    timeout = setTimeout(later, wait)
  }
}

// Add smooth scrolling for all internal links
document.addEventListener("click", (e) => {
  if (e.target.matches('a[href^="#"]')) {
    e.preventDefault()
    const targetId = e.target.getAttribute("href").substring(1)
    scrollToSection(targetId)
  }
})

// Performance optimization: Lazy loading for images
if ("IntersectionObserver" in window) {
  const imageObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const img = entry.target
        if (img.dataset.src) {
          img.src = img.dataset.src
          img.removeAttribute("data-src")
          imageObserver.unobserve(img)
        }
      }
    })
  })

  document.querySelectorAll("img[data-src]").forEach((img) => {
    imageObserver.observe(img)
  })
}

// Keyboard shortcuts
document.addEventListener("keydown", (e) => {
  // ESC to close cart
  if (e.key === "Escape" && isCartOpen) {
    closeCart()
  }

  // Ctrl/Cmd + K to focus search (if implemented)
  if ((e.ctrlKey || e.metaKey) && e.key === "k") {
    e.preventDefault()
    // Focus search input if exists
  }
})

// Auto-save cart periodically
setInterval(() => {
  if (cart.length > 0) {
    saveCartToStorage()
  }
}, 30000) // Save every 30 seconds
