<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';

$page_title = 'AI Tickets';
$ticket_api_endpoint = BASE_URL . 'api/v1/tickets.php';
$notification_icon = BASE_URL . 'assets/favicon.ico';

include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: white;">
                            <i class="bi bi-ticket-detailed" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">AI Tickets</h1>
                        <p class="page-subtitle">AI-generated alerts and operational tickets for the trading team - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Active queue</span>
                    <span class="badge bg-danger fs-6 px-3 py-2" id="ticket-count">0</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="card dashboard-card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-4 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1 fw-bold">Active Tickets</h5>
                <p class="mb-0 text-muted small">New, viewed, and in-progress AI tickets are refreshed automatically.</p>
            </div>
            <button type="button" id="soundToggle" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-volume-up"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div id="tickets-container">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading tickets...</span>
                    </div>
                    <p class="text-muted mt-3 mb-0">Loading tickets...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.card.dashboard-card {
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}

.ticket-item {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.ticket-item:hover {
    background-color: #f8f9fa;
    border-left-color: var(--primary-color);
}

.ticket-priority-critical {
    border-left-color: #dc2626 !important;
}

.ticket-priority-urgent {
    border-left-color: #dc2626 !important;
}

.ticket-priority-high {
    border-left-color: #f59e0b !important;
}

.ticket-priority-medium {
    border-left-color: #3b82f6 !important;
}

.ticket-priority-low {
    border-left-color: #10b981 !important;
}

.ticket-new {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        background-color: #fff;
    }
    50% {
        background-color: #fef3c7;
    }
}
</style>

<script>
const TICKET_CONFIG = {
    apiEndpoint: <?php echo json_encode($ticket_api_endpoint); ?>,
    notificationIcon: <?php echo json_encode($notification_icon); ?>,
    pollInterval: 10000,
    soundEnabled: true,
    lastCheckTime: null,
    playedTicketIds: new Set()
};

const ticketAudio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBjGM0fPTgjMGHm7A7+OZURE' +
    'OVq3n77RpHhE6ldr54I5DDxRfvOvvpmIaEkSa3/K8ZBoRLYXJ79eKOwcbabzt6JdNDg9RqOPyrGcUDECX3PLBah0Pf5nN8tiKPQcZZ7vt55hMDRBPqdzt7JxPETJWot7wumIZDz+U1fLKdSgHMILM8deKOgcabLvs5ptODg5No+PwtWcUDT+W1/LDcycGLn3J8NmLPgkcZ7rs5pxPDg5Mo+zvm2UXEz2U1/LGeCwGMIXP8duJOwkcZ7rs5ptODg5Mo+zvm2UXEz2U1/LGeCwGMIXP8duJOwkcZ7np5ZdLDAxNpebzr2cVDz+X2fLCbSEGMILL78qELgUW' +
    'ar7m4JdKCw1OpOLur2YUDz+X2fLCbSEGMILL78qELgUWarvo455LCw5OpePxsGgUDkCZ3PLAaRsPMYHJ7tqIOAcZaLrk55kMDhVQqOPxsGgUDkCZ3PLAaRsPMYHJ7tqIOAcZaLrk55kMDhVQqOPxsGgUDkCZ3PLAaRsPMYHJ7tqIOAcZaLrk55');

async function loadTickets() {
    try {
        if (TICKET_CONFIG.lastCheckTime) {
            const newResponse = await fetch(`${TICKET_CONFIG.apiEndpoint}?since=${encodeURIComponent(TICKET_CONFIG.lastCheckTime)}`);
            const newData = await newResponse.json();

            if (newData.success && newData.data && newData.data.has_new) {
                checkForNewTickets(newData.data.tickets);
            }
        }

        const response = await fetch(`${TICKET_CONFIG.apiEndpoint}?limit=50`);
        const data = await response.json();

        if (!data.success || !data.data) {
            throw new Error(data.message || 'Failed to load tickets');
        }

        const activeTickets = (data.data.tickets || []).filter(ticket =>
            ['new', 'viewed', 'in_progress'].includes(ticket.status)
        );

        displayTickets(activeTickets);
        document.getElementById('ticket-count').textContent = activeTickets.length;
        TICKET_CONFIG.lastCheckTime = new Date().toISOString();
    } catch (error) {
        console.error('Error loading tickets:', error);
        displayError(error.message || 'Failed to load tickets');
    }
}

function checkForNewTickets(newTickets) {
    if (!newTickets || newTickets.length === 0) {
        return;
    }

    const unnotifiedTickets = newTickets.filter(ticket => !TICKET_CONFIG.playedTicketIds.has(ticket.id));
    if (unnotifiedTickets.length === 0) {
        return;
    }

    if (TICKET_CONFIG.soundEnabled) {
        ticketAudio.play().catch(err => console.error('Error playing sound:', err));
    }

    unnotifiedTickets.forEach(ticket => {
        TICKET_CONFIG.playedTicketIds.add(ticket.id);
    });

    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification('New Ticket Alert', {
            body: `${unnotifiedTickets.length} new ticket(s) require attention`,
            icon: TICKET_CONFIG.notificationIcon,
            badge: TICKET_CONFIG.notificationIcon
        });
    }
}

function displayTickets(tickets) {
    const container = document.getElementById('tickets-container');

    if (tickets.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5">
                <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                <h5 class="mt-3 text-muted">No active tickets</h5>
                <p class="text-muted mb-0">All caught up. There are no active AI-generated tickets right now.</p>
            </div>
        `;
        return;
    }

    container.innerHTML = tickets.map(ticket => {
        const priorityColors = {
            critical: 'danger',
            high: 'warning',
            medium: 'info',
            low: 'success'
        };

        const priorityIcons = {
            critical: 'exclamation-triangle-fill',
            high: 'exclamation-circle-fill',
            medium: 'info-circle-fill',
            low: 'check-circle-fill'
        };

        const color = priorityColors[ticket.priority] || 'secondary';
        const icon = priorityIcons[ticket.priority] || 'circle-fill';
        const isNew = ticket.status === 'new';

        return `
            <div class="ticket-item border-bottom p-4 ticket-priority-${ticket.priority} ${isNew ? 'ticket-new' : ''}" data-ticket-id="${ticket.id}">
                <div class="row align-items-center g-3">
                    <div class="col-lg-8">
                        <div class="d-flex align-items-start">
                            <div class="me-3">
                                <i class="bi bi-${icon} text-${color}" style="font-size: 1.5rem;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <h6 class="mb-0 fw-bold">${escapeHtml(ticket.title)}</h6>
                                    <span class="badge bg-${color}">${String(ticket.priority || '').toUpperCase()}</span>
                                    ${isNew ? '<span class="badge bg-warning text-dark">NEW</span>' : ''}
                                    ${ticket.status === 'in_progress' ? '<span class="badge bg-primary">IN PROGRESS</span>' : ''}
                                </div>
                                <p class="mb-2 text-muted">${escapeHtml(ticket.description)}</p>
                                <div class="d-flex flex-wrap align-items-center gap-3 small text-muted">
                                    <span><i class="bi bi-ticket-detailed me-1"></i>${escapeHtml(ticket.ticket_number)}</span>
                                    ${ticket.category ? `<span><i class="bi bi-tag me-1"></i>${escapeHtml(ticket.category)}</span>` : ''}
                                    ${ticket.related_entity_type ? `<span><i class="bi bi-link-45deg me-1"></i>${escapeHtml(ticket.related_entity_type)}</span>` : ''}
                                    <span><i class="bi bi-clock me-1"></i>${formatTime(ticket.created_at)}</span>
                                    ${ticket.comment_count > 0 ? `<span><i class="bi bi-chat me-1"></i>${ticket.comment_count} comments</span>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        ${isNew ? `
                            <button class="btn btn-sm btn-outline-info me-2 mb-2 mb-lg-0" onclick="markAsViewed(${ticket.id})">
                                <i class="bi bi-eye me-1"></i>Mark Viewed
                            </button>
                        ` : ''}
                        <button class="btn btn-sm btn-outline-success me-2 mb-2 mb-lg-0" onclick="resolveTicket(${ticket.id})">
                            <i class="bi bi-check-circle me-1"></i>Resolve
                        </button>
                        ${ticket.status !== 'in_progress' ? `
                            <button class="btn btn-sm btn-outline-secondary" onclick="updateTicketStatus(${ticket.id}, 'in_progress')">
                                <i class="bi bi-hourglass-split me-1"></i>In Progress
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function displayError(message) {
    const container = document.getElementById('tickets-container');
    container.innerHTML = `
        <div class="alert alert-danger m-4 mb-0">
            <i class="bi bi-exclamation-triangle me-2"></i>${escapeHtml(message)}
        </div>
    `;
}

async function markAsViewed(ticketId) {
    await updateTicketStatus(ticketId, 'viewed');
}

async function resolveTicket(ticketId) {
    if (!confirm('Mark this ticket as resolved?')) {
        return;
    }

    await updateTicketStatus(ticketId, 'resolved');
}

async function updateTicketStatus(ticketId, status) {
    try {
        const response = await fetch(`${TICKET_CONFIG.apiEndpoint}?id=${ticketId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ status: status })
        });

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to update ticket');
        }

        await loadTickets();
        showToast('success', `Ticket marked as ${status.replace('_', ' ')}`);
    } catch (error) {
        console.error('Error updating ticket:', error);
        showToast('error', error.message || 'Failed to update ticket');
    }
}

function showToast(type, message) {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} position-fixed top-0 end-0 m-3`;
    toast.style.zIndex = '9999';
    toast.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>${escapeHtml(message)}`;
    document.body.appendChild(toast);

    setTimeout(() => toast.remove(), 3000);
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };

    return text ? String(text).replace(/[&<>"']/g, character => map[character]) : '';
}

function formatTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;

    return date.toLocaleDateString();
}

document.addEventListener('DOMContentLoaded', function() {
    const soundToggle = document.getElementById('soundToggle');
    if (soundToggle) {
        soundToggle.addEventListener('click', function() {
            TICKET_CONFIG.soundEnabled = !TICKET_CONFIG.soundEnabled;
            this.innerHTML = TICKET_CONFIG.soundEnabled
                ? '<i class="bi bi-volume-up"></i>'
                : '<i class="bi bi-volume-mute"></i>';
            showToast('success', `Notification sound ${TICKET_CONFIG.soundEnabled ? 'enabled' : 'disabled'}`);
        });
    }

    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    loadTickets();
    setInterval(loadTickets, TICKET_CONFIG.pollInterval);
});
</script>

<?php include '../includes/footer.php'; ?>