/**
 * Stock Exchange System JavaScript
 * Common functionality and utilities
 */

// Import Bootstrap
const bootstrap = window.bootstrap

// Initialize tooltips
document.addEventListener("DOMContentLoaded", () => {
  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
  var tooltipList = tooltipTriggerList.map((tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl))
})

// Confirm delete actions
function confirmDelete(message = "Are you sure you want to delete this item?") {
  return confirm(message)
}

// Format currency display
function formatCurrency(amount) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
  }).format(amount)
}

// Format date display
function formatDate(dateString) {
  const date = new Date(dateString)
  return date.toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  })
}

// Auto-hide alerts after 5 seconds
document.addEventListener("DOMContentLoaded", () => {
  const alerts = document.querySelectorAll(".alert:not(.alert-permanent)")
  alerts.forEach((alert) => {
    setTimeout(() => {
      const bsAlert = new bootstrap.Alert(alert)
      bsAlert.close()
    }, 5000)
  })
})

// Form validation helpers
function validateRequired(fieldId, message = "This field is required") {
  const field = document.getElementById(fieldId)
  if (!field.value.trim()) {
    showFieldError(fieldId, message)
    return false
  }
  clearFieldError(fieldId)
  return true
}

function validateEmail(fieldId) {
  const field = document.getElementById(fieldId)
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (field.value && !emailRegex.test(field.value)) {
    showFieldError(fieldId, "Please enter a valid email address")
    return false
  }
  clearFieldError(fieldId)
  return true
}

function showFieldError(fieldId, message) {
  const field = document.getElementById(fieldId)
  field.classList.add("is-invalid")

  let feedback = field.parentNode.querySelector(".invalid-feedback")
  if (!feedback) {
    feedback = document.createElement("div")
    feedback.className = "invalid-feedback"
    field.parentNode.appendChild(feedback)
  }
  feedback.textContent = message
}

function clearFieldError(fieldId) {
  const field = document.getElementById(fieldId)
  field.classList.remove("is-invalid")

  const feedback = field.parentNode.querySelector(".invalid-feedback")
  if (feedback) {
    feedback.remove()
  }
}

// Table search functionality
function initTableSearch(tableId, searchInputId) {
  const searchInput = document.getElementById(searchInputId)
  const table = document.getElementById(tableId)
  const tbody = table.querySelector("tbody")
  const rows = tbody.querySelectorAll("tr")

  searchInput.addEventListener("keyup", function () {
    const searchTerm = this.value.toLowerCase()

    rows.forEach((row) => {
      const text = row.textContent.toLowerCase()
      if (text.includes(searchTerm)) {
        row.style.display = ""
      } else {
        row.style.display = "none"
      }
    })
  })
}

// Print functionality
function printPage() {
  window.print()
}

// Export table to CSV
function exportTableToCSV(tableId, filename = "export.csv") {
  const table = document.getElementById(tableId)
  const rows = table.querySelectorAll("tr")
  const csv = []

  rows.forEach((row) => {
    const cols = row.querySelectorAll("td, th")
    const rowData = []
    cols.forEach((col) => {
      rowData.push('"' + col.textContent.replace(/"/g, '""') + '"')
    })
    csv.push(rowData.join(","))
  })

  const csvContent = csv.join("\n")
  const blob = new Blob([csvContent], { type: "text/csv" })
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement("a")
  a.href = url
  a.download = filename
  a.click()
  window.URL.revokeObjectURL(url)
}

// Sidebar Navigation
document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.getElementById("sidebar")
  const sidebarToggle = document.getElementById("sidebarToggle")
  const sidebarClose = document.getElementById("sidebarClose")
  const sidebarOverlay = document.getElementById("sidebarOverlay")
  const mainContent = document.querySelector(".main-content")
  const navbar = document.querySelector(".professional-navbar")

  // Check if we're on desktop (992px+)
  function isDesktop() {
    return window.innerWidth >= 992
  }

  // Initialize sidebar state
  function initSidebar() {
    if (isDesktop()) {
      sidebar.classList.add("show")
      mainContent.classList.add("sidebar-open")
      navbar.classList.add("sidebar-open")
      sidebarOverlay.classList.remove("show")
    } else {
      sidebar.classList.remove("show")
      mainContent.classList.remove("sidebar-open")
      navbar.classList.remove("sidebar-open")
      sidebarOverlay.classList.remove("show")
    }
  }

  // Toggle sidebar
  function toggleSidebar() {
    if (isDesktop()) return // Don't toggle on desktop

    sidebar.classList.toggle("show")
    sidebarOverlay.classList.toggle("show")

    if (sidebar.classList.contains("show")) {
      document.body.style.overflow = "hidden"
    } else {
      document.body.style.overflow = ""
    }
  }

  // Close sidebar
  function closeSidebar() {
    if (isDesktop()) return // Don't close on desktop

    sidebar.classList.remove("show")
    sidebarOverlay.classList.remove("show")
    document.body.style.overflow = ""
  }

  // Event listeners
  if (sidebarToggle) {
    sidebarToggle.addEventListener("click", toggleSidebar)
  }

  if (sidebarClose) {
    sidebarClose.addEventListener("click", closeSidebar)
  }

  if (sidebarOverlay) {
    sidebarOverlay.addEventListener("click", closeSidebar)
  }

  // Handle window resize
  window.addEventListener("resize", () => {
    initSidebar()
  })

  // Initialize on load
  initSidebar()

  // Highlight active nav item
  const currentPath = window.location.pathname
  const navLinks = document.querySelectorAll(".sidebar-nav .nav-link")

  navLinks.forEach((link) => {
    if (link.getAttribute("href") && currentPath.includes(link.getAttribute("href"))) {
      link.classList.add("active")
    }
  })
})
