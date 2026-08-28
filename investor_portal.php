<?php
/**
 * Investor Portal - KYC Management
 * Secure public-facing page with enterprise-level security
 */

require_once '../config/config.php';
require_once '../includes/security.php';

// Initialize security
$security = new SecurityManager();

// Generate CSRF token
$csrf_token = $security->generateCSRFToken();

// Rate limiting for page views
$ip = $_SERVER['REMOTE_ADDR'];
if (!$security->checkRateLimit($ip, 'portal_page', 30, 60)) {
    die('Too many page requests. Please try again later.');
}

include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investor Portal - Secure KYC</title>
    <meta http-equiv="Content-Security-Policy" content="
        default-src 'self';
        script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;
        style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;
        img-src 'self' data:;
        font-src 'self' https://cdn.jsdelivr.net;
        connect-src 'self';
    ">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        .security-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            color: #4ade80;
            padding: 10px 15px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .security-badge i {
            margin-right: 8px;
        }
        .rate-limit-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: none;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99999;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.active {
            display: flex;
        }
        .loading-spinner {
            background: white;
            padding: 30px 40px;
            border-radius: 15px;
            text-align: center;
        }
        .loading-spinner .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        .otp-section {
            background: #f0f9ff;
            border: 2px dashed #3b82f6;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
        .field-masked {
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-3 mb-0">Processing your request...</p>
        </div>
    </div>

    <!-- Security Badge -->
    <div class="security-badge">
        <i class="bi bi-shield-lock-fill"></i>
        Secure Connection | Encrypted
    </div>

    <div class="container mt-4 mb-5">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-person-badge" style="font-size: 3rem; color: #4f46e5;"></i>
                    </div>
                    <div>
                        <h1 class="h2 mb-1">Investor Portal</h1>
                        <p class="text-muted mb-0">Secure KYC Registration and Profile Management</p>
                    </div>
                </div>
                <hr>
            </div>
        </div>

        <!-- Rate Limit Warning -->
        <div class="rate-limit-warning" id="rateLimitWarning">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            Too many attempts. Please wait a moment before trying again.
        </div>

        <!-- Status Messages -->
        <div id="messageContainer"></div>

        <!-- CDS Lookup Section -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-search me-2"></i>Find Your Account
                </h5>
                <p class="text-muted">Enter your CDS account number to retrieve your information or register as a new client.</p>
                
                <form id="lookupForm" class="row g-3">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-person-vcard"></i>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   id="cdsLookup" 
                                   name="cds_account" 
                                   placeholder="Enter CDS Account (e.g., CDS123456)"
                                   pattern="[A-Z0-9]{5,20}"
                                   maxlength="20"
                                   autocomplete="off"
                                   required>
                            <button class="btn btn-primary" type="submit" id="lookupBtn">
                                <i class="bi bi-search"></i> Lookup
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-outline-secondary" onclick="clearForm()">
                            <i class="bi bi-arrow-counterclockwise"></i> Clear
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Client Info Display -->
        <div id="clientInfo" style="display: none;">
            <div class="card border-primary shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="card-title text-primary">
                                <i class="bi bi-person-check me-2"></i>Client Found
                            </h5>
                            <p class="mb-1"><strong>Name:</strong> <span id="clientName"></span></p>
                            <p class="mb-1"><strong>CDS Account:</strong> <span id="clientCDS" class="badge bg-primary"></span></p>
                            <p class="mb-1"><strong>Status:</strong> <span id="clientStatus" class="badge"></span></p>
                            <p class="mb-0"><strong>Client Type:</strong> <span id="clientType"></span></p>
                        </div>
                        <div class="text-end">
                            <i class="bi bi-shield-check text-success" style="font-size: 2rem;"></i>
                            <p class="small text-muted mb-0">Verified Account</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KYC Form -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-data me-2"></i>KYC Information
                    <span class="badge bg-secondary ms-2">Secure</span>
                </h5>
            </div>
            <div class="card-body">
                <form id="kycForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="submit_kyc">
                    <input type="hidden" name="is_existing" id="isExisting" value="0">
                    <input type="hidden" name="cds_account" id="formCdsAccount" value="">

                    <div class="row g-4">
                        <!-- Personal Information -->
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">
                                <i class="bi bi-person me-2"></i>Personal Information
                            </h6>
                        </div>

                        <div class="col-md-6">
                            <label for="clientName" class="form-label required-field">Full Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="clientName" 
                                   name="client_name" 
                                   pattern="[a-zA-Z\s\-']{2,100}"
                                   maxlength="100"
                                   required>
                            <div class="invalid-feedback">Please enter a valid full name (2-100 characters).</div>
                        </div>

                        <div class="col-md-6">
                            <label for="clientType" class="form-label required-field">Client Type</label>
                            <select class="form-select" id="clientType" name="client_type" required>
                                <option value="individual">Individual</option>
                                <option value="institution">Institution</option>
                                <option value="corporate">Corporate</option>
                                <option value="joint">Joint Account</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="nationalId" class="form-label required-field">National ID / Passport</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="nationalId" 
                                   name="national_id" 
                                   placeholder="e.g., 2001010-12345-6789"
                                   pattern="[0-9\-]{5,20}"
                                   maxlength="20"
                                   required>
                            <div class="invalid-feedback">Please enter a valid National ID.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="dateOfBirth" class="form-label required-field">Date of Birth</label>
                            <input type="date" class="form-control" id="dateOfBirth" name="date_of_birth" required>
                            <div class="invalid-feedback">You must be at least 18 years old.</div>
                        </div>

                        <!-- Contact Information -->
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mt-2">
                                <i class="bi bi-envelope me-2"></i>Contact Information
                            </h6>
                        </div>

                        <div class="col-md-6">
                            <label for="phone" class="form-label required-field">Phone Number</label>
                            <input type="tel" 
                                   class="form-control" 
                                   id="phone" 
                                   name="phone" 
                                   placeholder="0712-345-678"
                                   pattern="[0-9\-]{10,15}"
                                   maxlength="15"
                                   required>
                            <div class="invalid-feedback">Please enter a valid phone number.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" 
                                   class="form-control" 
                                   id="email" 
                                   name="email" 
                                   placeholder="example@email.com"
                                   maxlength="100">
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>

                        <div class="col-md-12">
                            <label for="address" class="form-label">Physical Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2" maxlength="200"></textarea>
                        </div>

                        <!-- Banking Information -->
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mt-2">
                                <i class="bi bi-bank me-2"></i>Banking Information
                            </h6>
                        </div>

                        <div class="col-md-4">
                            <label for="bankAccount" class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="bankAccount" name="bank_account_number" maxlength="30">
                        </div>

                        <div class="col-md-4">
                            <label for="bankName" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="bankName" name="bank_name" maxlength="100">
                        </div>

                        <div class="col-md-4">
                            <label for="bankBranch" class="form-label">Bank Branch</label>
                            <input type="text" class="form-control" id="bankBranch" name="bank_branch" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label for="currency" class="form-label">Preferred Currency</label>
                            <select class="form-select" id="currency" name="currency">
                                <option value="TZS">TZS - Tanzanian Shilling</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="clientCode" class="form-label">Client Code (Optional)</label>
                            <input type="text" class="form-control" id="clientCode" name="client_code" maxlength="50">
                        </div>

                        <!-- OTP Verification -->
                        <?php if (defined('ENABLE_OTP_VERIFICATION') && ENABLE_OTP_VERIFICATION): ?>
                        <div class="col-12">
                            <div class="otp-section">
                                <h6 class="mb-2">
                                    <i class="bi bi-shield-lock me-2"></i>OTP Verification
                                </h6>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <button type="button" class="btn btn-outline-primary w-100" onclick="sendOTP()">
                                            <i class="bi bi-send me-1"></i> Send OTP
                                        </button>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" 
                                               class="form-control" 
                                               id="otpInput" 
                                               name="otp" 
                                               placeholder="Enter 6-digit OTP"
                                               pattern="[0-9]{6}"
                                               maxlength="6">
                                    </div>
                                    <div class="col-md-4">
                                        <span id="otpTimer" class="text-muted"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Terms and Consent -->
                        <div class="col-12">
                            <div class="border-top pt-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="termsCheck" required>
                                    <label class="form-check-label" for="termsCheck">
                                        I confirm that all information provided is accurate and complete.
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="consentCheck" required>
                                    <label class="form-check-label" for="consentCheck">
                                        I consent to the collection and processing of my personal data for KYC purposes.
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="privacyCheck" required>
                                    <label class="form-check-label" for="privacyCheck">
                                        I have read and agree to the Privacy Policy and Terms of Service.
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-lg w-100" id="submitBtn">
                                <i class="bi bi-check-circle me-2"></i>
                                <span id="submitBtnText">Submit KYC Information</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Security: Prevent form submission via enter key on non-submit elements
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            const target = e.target;
            if (target.form && target.form.querySelector('[type="submit"]')) {
                e.preventDefault();
            }
        }
    });

    // Auto-format functions
    function formatPhone(input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length <= 3) {
            input.value = value;
        } else if (value.length <= 6) {
            input.value = value.slice(0, 3) + '-' + value.slice(3);
        } else {
            input.value = value.slice(0, 3) + '-' + value.slice(3, 6) + '-' + value.slice(6, 10);
        }
    }

    function formatNationalId(input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length <= 2) {
            input.value = value;
        } else if (value.length <= 7) {
            input.value = value.slice(0, 2) + '-' + value.slice(2);
        } else {
            input.value = value.slice(0, 2) + '-' + value.slice(2, 7) + '-' + value.slice(7, 9);
        }
    }

    // Event listeners for formatting
    document.getElementById('phone')?.addEventListener('input', function() { formatPhone(this); });
    document.getElementById('nationalId')?.addEventListener('input', function() { formatNationalId(this); });

    // CDS Lookup
    document.getElementById('lookupForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const cdsAccount = document.getElementById('cdsLookup').value.trim();
        
        if (!cdsAccount) {
            showMessage('warning', 'Please enter a CDS account number.');
            return;
        }

        showLoading(true);

        try {
            const formData = new FormData();
            formData.append('action', 'lookup_cds');
            formData.append('cds_account', cdsAccount);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');

            const response = await fetch('api/kyc_api.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success && data.found) {
                // Existing client found
                const client = data.client;
                document.getElementById('clientInfo').style.display = 'block';
                document.getElementById('clientName').textContent = client.client_name;
                document.getElementById('clientCDS').textContent = client.cds_account;
                document.getElementById('clientType').textContent = client.client_type.charAt(0).toUpperCase() + client.client_type.slice(1);
                
                // Status badge
                const statusBadge = document.getElementById('clientStatus');
                statusBadge.textContent = client.status.toUpperCase();
                statusBadge.className = 'badge';
                if (client.status === 'active') {
                    statusBadge.classList.add('bg-success');
                } else if (client.status === 'pending') {
                    statusBadge.classList.add('bg-warning');
                } else {
                    statusBadge.classList.add('bg-secondary');
                }

                // Populate form
                document.getElementById('clientName').value = client.client_name;
                document.getElementById('clientType').value = client.client_type;
                document.getElementById('dateOfBirth').value = client.date_of_birth || '';
                document.getElementById('address').value = client.address || '';
                document.getElementById('bankAccount').value = client.bank_account_number || '';
                document.getElementById('bankName').value = client.bank_name || '';
                document.getElementById('bankBranch').value = client.bank_branch || '';
                document.getElementById('currency').value = client.currency || 'TZS';
                document.getElementById('clientCode').value = client.client_code || '';
                
                // Mask sensitive fields for display
                document.getElementById('phone').value = client.phone_masked || '';
                document.getElementById('phone').classList.add('field-masked');
                document.getElementById('email').value = client.email_masked || '';
                document.getElementById('email').classList.add('field-masked');
                document.getElementById('nationalId').value = client.national_id_masked || '';
                document.getElementById('nationalId').classList.add('field-masked');

                // Set hidden fields
                document.getElementById('formCdsAccount').value = client.cds_account;
                document.getElementById('isExisting').value = 1;
                
                // Update submit button
                document.getElementById('submitBtnText').textContent = 'Update KYC Information';

                // Clear lookup field
                document.getElementById('cdsLookup').value = '';

                showMessage('success', 'Client found! Please review and update your information.');

            } else if (data.success && !data.found) {
                // New client
                document.getElementById('clientInfo').style.display = 'none';
                document.getElementById('formCdsAccount').value = cdsAccount;
                document.getElementById('isExisting').value = 0;
                document.getElementById('submitBtnText').textContent = 'Submit Registration';
                
                // Clear form except CDS
                clearFormFields();
                document.getElementById('cdsLookup').value = '';
                
                showMessage('info', 'CDS account not found. Please fill in the form to register as a new client.');

            } else {
                showMessage('danger', data.error || 'An error occurred. Please try again.');
            }
        } catch (error) {
            showMessage('danger', 'Network error. Please check your connection and try again.');
        } finally {
            showLoading(false);
        }
    });

    // KYC Form Submission
    document.getElementById('kycForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Validate form
        if (!this.checkValidity()) {
            this.classList.add('was-validated');
            return;
        }

        // Check terms
        if (!document.getElementById('termsCheck').checked ||
            !document.getElementById('consentCheck').checked ||
            !document.getElementById('privacyCheck').checked) {
            showMessage('warning', 'Please accept all terms and conditions.');
            return;
        }

        showLoading(true);

        try {
            const formData = new FormData(this);
            formData.append('action', 'submit_kyc');

            const response = await fetch('api/kyc_api.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showMessage('success', data.message);
                if (data.action === 'registered') {
                    // New registration - clear form
                    clearFormFields();
                    document.getElementById('isExisting').value = 0;
                    document.getElementById('submitBtnText').textContent = 'Submit Registration';
                    document.getElementById('clientInfo').style.display = 'none';
                }
            } else {
                if (data.errors) {
                    // Show all errors
                    const errorList = data.errors.join('<br>');
                    showMessage('danger', errorList);
                } else {
                    showMessage('danger', data.error || 'Submission failed. Please try again.');
                }
            }
        } catch (error) {
            showMessage('danger', 'Network error. Please check your connection and try again.');
        } finally {
            showLoading(false);
            // Reset form validation state
            document.getElementById('kycForm').classList.remove('was-validated');
        }
    });

    // OTP Functions
    async function sendOTP() {
        const phone = document.getElementById('phone').value;
        if (!phone) {
            showMessage('warning', 'Please enter your phone number first.');
            return;
        }

        showLoading(true);

        try {
            const formData = new FormData();
            formData.append('action', 'send_otp');
            formData.append('phone', phone);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');

            const response = await fetch('api/kyc_api.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showMessage('success', 'OTP sent successfully! Check your phone.');
                startOTPTimer();
                
                // In development, show OTP for testing
                <?php if (defined('ENVIRONMENT') && ENVIRONMENT === 'development'): ?>
                if (data.debug_otp) {
                    showMessage('info', '🔑 Development OTP: ' + data.debug_otp);
                }
                <?php endif; ?>
            } else {
                showMessage('danger', data.error || 'Failed to send OTP.');
            }
        } catch (error) {
            showMessage('danger', 'Network error. Please try again.');
        } finally {
            showLoading(false);
        }
    }

    function startOTPTimer() {
        let timeLeft = 60;
        const timerDisplay = document.getElementById('otpTimer');
        const sendBtn = document.querySelector('[onclick="sendOTP()"]');
        
        sendBtn.disabled = true;
        timerDisplay.textContent = `Resend in ${timeLeft}s`;
        
        const interval = setInterval(() => {
            timeLeft--;
            if (timeLeft <= 0) {
                clearInterval(interval);
                timerDisplay.textContent = 'Ready';
                sendBtn.disabled = false;
            } else {
                timerDisplay.textContent = `Resend in ${timeLeft}s`;
            }
        }, 1000);
    }

    // Utility Functions
    function showMessage(type, message) {
        const container = document.getElementById('messageContainer');
        const alertTypes = {
            success: 'alert-success',
            danger: 'alert-danger',
            warning: 'alert-warning',
            info: 'alert-info'
        };

        container.innerHTML = `
            <div class="alert ${alertTypes[type] || 'alert-info'} alert-dismissible fade show" role="alert">
                <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        // Auto-close after 10 seconds
        setTimeout(() => {
            const alert = container.querySelector('.alert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }
        }, 10000);
    }

    function showLoading(show) {
        document.getElementById('loadingOverlay').classList.toggle('active', show);
    }

    function clearForm() {
        document.getElementById('cdsLookup').value = '';
        document.getElementById('clientInfo').style.display = 'none';
        document.getElementById('isExisting').value = 0;
        document.getElementById('submitBtnText').textContent = 'Submit Registration';
        document.getElementById('formCdsAccount').value = '';
        clearFormFields();
    }

    function clearFormFields() {
        const fields = ['clientName', 'clientType', 'nationalId', 'dateOfBirth', 
                        'phone', 'email', 'address', 'bankAccount', 'bankName', 
                        'bankBranch', 'currency', 'clientCode', 'otpInput'];
        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                if (el.tagName === 'SELECT') {
                    el.selectedIndex = 0;
                } else {
                    el.value = '';
                }
                el.classList.remove('field-masked');
            }
        });
        
        // Reset checkboxes
        document.querySelectorAll('.form-check-input').forEach(cb => cb.checked = false);
    }

    // Form validation on blur
    document.querySelectorAll('#kycForm input, #kycForm select').forEach(field => {
        field.addEventListener('blur', function() {
            if (this.checkValidity && !this.checkValidity()) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    });

    // Prevent paste into sensitive fields (security)
    document.querySelectorAll('#nationalId, #phone, #email').forEach(field => {
        field.addEventListener('paste', function(e) {
            e.preventDefault();
            showMessage('warning', 'Pasting is not allowed in this field for security reasons.');
        });
    });

    // Rate limit warning
    document.addEventListener('DOMContentLoaded', function() {
        // Check if rate limit exceeded (from server response)
        <?php if (isset($rate_limited) && $rate_limited): ?>
        document.getElementById('rateLimitWarning').style.display = 'block';
        <?php endif; ?>
    });
    </script>

<?php include '../includes/footer.php'; ?>
</body>
</html>
