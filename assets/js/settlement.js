console.log('settlement_js_loaded');

let currentTradeId = null;
let selectedTradeIds = [];

function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.trade-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        if (checkbox.checked) {
            if (!selectedTradeIds.includes(cb.value)) selectedTradeIds.push(cb.value);
        } else {
            const idx = selectedTradeIds.indexOf(cb.value);
            if (idx > -1) selectedTradeIds.splice(idx, 1);
        }
    });
    updateBulkActions();
}

function updateBulkActions() {
    const cbs = document.querySelectorAll('.trade-checkbox:checked');
    selectedTradeIds = Array.from(cbs).map(cb => cb.value);
    document.getElementById('selectedCountBadge').textContent = selectedTradeIds.length;
    const fb = document.getElementById('floatingBulkPayment');
    if (fb) fb.style.display = selectedTradeIds.length > 0 ? 'block' : 'none';
}

function clearSelection() {
    document.querySelectorAll('.trade-checkbox').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAll');
    if (sa) sa.checked = false;
    selectedTradeIds = [];
    updateBulkActions();
}

function resetFilters() { window.location.href = 'settlement'; }

function showPaymentModal(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('paymentTradeId').value = tradeId;
    const pf = document.getElementById('paymentForm');
    if (pf) pf.reset();
    document.getElementById('bankAccountField').style.display = 'none';
    document.getElementById('bankBalanceInfo').textContent = '';
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function toggleBankSelection() {
    const mode = document.getElementById('payment_mode').value;
    const f = document.getElementById('bankAccountField');
    const s = document.getElementById('bank_account');
    const i = document.getElementById('bankBalanceInfo');
    if (['1','2','3'].includes(mode)) {
        f.style.display = 'block'; s.required = true;
        s.onchange = function() {
            const o = this.options[this.selectedIndex];
            i.textContent = o && o.value ? 'Current Balance: ' + o.getAttribute('data-currency') + ' ' + parseFloat(o.getAttribute('data-balance')).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2}) : '';
        };
        s.selectedIndex = 0; s.onchange();
    } else {
        f.style.display = 'none'; s.required = false; i.textContent = '';
    }
}

function showBulkPaymentModal() {
    if (selectedTradeIds.length === 0) { alert('Please select at least one trade to pay.'); return; }
    let valid = [], invalid = [];
    document.querySelectorAll('.trade-checkbox:checked').forEach(cb => {
        const st = cb.closest('tr').querySelector('.badge').textContent.trim();
        if (st.includes('Paid') || st.includes('Linked') || st.includes('Failed')) {
            invalid.push(cb.closest('tr').querySelector('td:nth-child(2) .fw-semibold').textContent);
        } else { valid.push(cb.value); }
    });
    if (invalid.length > 0) alert('Some trades already paid/linked/failed:\n' + invalid.join(',') + '\n\nOnly unpaid will be processed.');
    if (valid.length === 0) { alert('No valid unpaid trades.'); return; }
    const c = document.getElementById('bulkPaymentTradeIds'); c.innerHTML = '';
    valid.forEach(id => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'trade_ids[]'; i.value = id; c.appendChild(i); });
    document.getElementById('bulkPaymentCount').textContent = valid.length;
    const bf = document.getElementById('bulkPaymentForm'); if (bf) bf.reset();
    document.getElementById('bulkBankAccountField').style.display = 'none';
    document.getElementById('bulkBankBalanceInfo').textContent = '';
    new bootstrap.Modal(document.getElementById('bulkPaymentModal')).show();
}

function toggleBulkBankSelection() {
    const mode = document.getElementById('bulk_payment_mode').value;
    const f = document.getElementById('bulkBankAccountField');
    const s = document.getElementById('bulk_bank_account');
    const i = document.getElementById('bulkBankBalanceInfo');
    if (['1','2','3'].includes(mode)) {
        f.style.display = 'block'; s.required = true;
        s.onchange = function() {
            const o = this.options[this.selectedIndex];
            i.textContent = o && o.value ? 'Current Balance: ' + o.getAttribute('data-currency') + ' ' + parseFloat(o.getAttribute('data-balance')).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2}) : '';
        };
        s.selectedIndex = 0; s.onchange();
    } else {
        f.style.display = 'none'; s.required = false; i.textContent = '';
    }
}

function showLinkTradeModal(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('linkTradeId').value = tradeId;
    const lf = document.getElementById('linkTradeForm'); if (lf) lf.reset();
    const sd = document.getElementById('currentSaleDetails');
    if (sd) sd.innerHTML = '<span class="text-muted">Loading...</span>';
    fetch('?ajax=get_trade_details&trade_id=' + tradeId).then(r => r.json()).then(d => {
        if (d && d.trade_reference) {
            sd.innerHTML = '<strong>Trade Ref:</strong> ' + d.trade_reference + '<br><strong>Client:</strong> ' + d.client_name + '<br><strong>Security:</strong> ' + d.security_id + '<br><strong>Amount:</strong> TZS ' + parseFloat(d.total_consideration||d.consideration||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + '<br><strong>Side:</strong> ' + d.trade_side.toUpperCase() + (d.trade_count>1?'<br><strong>Grouped:</strong> '+d.trade_count+' trades':'');
        } else { sd.innerHTML = '<span class="text-muted">Could not load</span>'; }
    }).catch(() => { sd.innerHTML = '<span class="text-danger">Error loading</span>'; });
    const cont = document.getElementById('buyTradesContainer');
    const warn = document.getElementById('noBuyTradesWarning');
    if (cont) cont.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span class="ms-2">Loading buy trades...</span></div>';
    if (warn) warn.style.display = 'none';
    fetch('?ajax=get_grouped_buy_trades&trade_id=' + tradeId).then(r => { if(!r.ok) throw Error('Network error'); return r.json(); }).then(data => {
        if (data && data.length > 0) {
            if (warn) warn.style.display = 'none';
            let h = '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th><input type="checkbox" id="selectAllBuy" onchange="toggleSelectAllBuy(this)"></th><th>Reference(s)</th><th>Security</th><th class="text-end">Qty</th><th class="text-end">Amount</th><th>Date</th><th>Status</th></tr></thead><tbody>';
            data.forEach(t => {
                const tc = parseInt(t.trade_count)||1, refs = t.trade_references?t.trade_references.split(','):[], dr = refs.length>0?refs[0]:(t.trade_reference||'N/A');
                h += '<tr><td><input type="checkbox" class="buy-trade-checkbox" name="linked_trade_ids[]" value="'+t.id+'" onchange="updateLinkSelection()"></td><td>'+dr+(tc>1?'<br><small class="text-muted">+'+(tc-1)+' more</small>':'')+'</td><td>'+t.security_id+'</td><td class="text-end">'+parseFloat(t.total_quantity).toLocaleString()+'</td><td class="text-end">TZS '+parseFloat(t.total_consideration).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+'</td><td>'+t.trade_date+'</td><td><span class="badge bg-'+(t.settlement_status?'secondary':'warning')+'">'+(t.settlement_status||'Pending')+'</span></td></tr>';
            });
            h += '</tbody></table></div><div class="mt-2"><small class="text-muted">Select buy trades to link.</small></div>';
            if (cont) cont.innerHTML = h;
            updateLinkSelection();
        } else {
            if (cont) cont.innerHTML = '';
            if (warn) { warn.style.display = 'block'; warn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>No available buy trades found for this client.'; }
        }
    }).catch(e => { console.error(e); if(cont) cont.innerHTML = '<div class="alert alert-danger">Error loading buy trades.</div>'; if(warn) warn.style.display = 'none'; });
    new bootstrap.Modal(document.getElementById('linkTradeModal')).show();
}

function toggleSelectAllBuy(cb) { document.querySelectorAll('.buy-trade-checkbox').forEach(c => c.checked = cb.checked); updateLinkSelection(); }

function updateLinkSelection() {
    const n = document.querySelectorAll('.buy-trade-checkbox:checked').length;
    const btn = document.getElementById('linkSubmitBtn');
    if (btn) { btn.innerHTML = n > 0 ? 'Link ' + n + ' Trade' + (n>1?'s':'') : 'Select at least one trade'; btn.disabled = n === 0; }
}

function showGroupedTrades(tradeId) {
    const m = new bootstrap.Modal(document.getElementById('groupedTradesModal'));
    const c = document.getElementById('groupedTradesContent');
    c.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span class="ms-2">Loading...</span></div>'; m.show();
    fetch('?ajax=get_grouped_trade_details&trade_id=' + tradeId).then(r => r.json()).then(d => {
        if (d && d.length > 0) {
            let h = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>#</th><th>Trade Ref</th><th>Security</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th></tr></thead><tbody>';
            d.forEach((t,i) => { h += '<tr><td>'+(i+1)+'</td><td>'+t.trade_reference+'</td><td>'+t.security_id+'</td><td class="text-end">'+parseFloat(t.quantity).toLocaleString()+'</td><td class="text-end">'+parseFloat(t.price).toFixed(2)+'</td><td class="text-end">TZS '+parseFloat(t.consideration).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+'</td></tr>'; });
            const tq = d.reduce((s,t)=>s+parseFloat(t.quantity),0), ta = d.reduce((s,t)=>s+parseFloat(t.consideration),0);
            h += '</tbody><tfoot><tr class="fw-bold"><td colspan="3" class="text-end">TOTAL:</td><td class="text-end">'+tq.toLocaleString()+'</td><td></td><td class="text-end">TZS '+ta.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+'</td></tr></tfoot></table></div>';
            c.innerHTML = h;
        } else { c.innerHTML = '<div class="alert alert-warning">No details found.</div>'; }
    }).catch(() => { c.innerHTML = '<div class="alert alert-danger">Error loading details.</div>'; });
}

function filterLinkTrades(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('#linked_trade_id option').forEach(o => {
        if (!o.value) return;
        o.style.display = (!q || (o.getAttribute('data-search')||o.textContent).toLowerCase().includes(q)) ? '' : 'none';
    });
}

function showLinkedDetails(tradeId, linkedTradeId, linkedRef, linkedClient, linkedSecurity, linkedAmount) {
    document.getElementById('linkedSaleDetails').innerHTML = '<strong>Trade ID:</strong> '+tradeId+'<br><strong>Status:</strong> Linked<br><strong>Linked To:</strong> Buy Trade #'+linkedTradeId;
    document.getElementById('linkedBuyDetails').innerHTML = '<strong>Trade Ref:</strong> '+(linkedRef||'N/A')+'<br><strong>Client:</strong> '+(linkedClient||'N/A')+'<br><strong>Security:</strong> '+(linkedSecurity||'N/A')+'<br><strong>Amount:</strong> TZS '+parseFloat(linkedAmount).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+'<br><strong>Trade ID:</strong> '+linkedTradeId;
    new bootstrap.Modal(document.getElementById('linkedDetailsModal')).show();
}

function markAsUnpaid(tradeId) {
    if (confirm('Undo this payment?')) { document.getElementById('unpaidTradeId').value = tradeId; document.getElementById('unpaidForm').submit(); }
}

function markAsFailed(tradeId) {
    currentTradeId = tradeId;
    document.getElementById('failureTradeId').value = tradeId;
    const ff = document.getElementById('failureForm'); if (ff) ff.reset();
    new bootstrap.Modal(document.getElementById('failureModal')).show();
}

function retryFailed(tradeId) {
    if (confirm('Reset for retry?')) { document.getElementById('retryTradeId').value = tradeId; document.getElementById('retryFailedForm').submit(); }
}

function showFailureDetails(tradeId, reason, action) {
    document.getElementById('detailsFailureReason').textContent = reason || 'No reason provided';
    document.getElementById('detailsActionNeeded').textContent = action || 'No action specified';
    new bootstrap.Modal(document.getElementById('failureDetailsModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap !== 'undefined') {
        [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]')).map(el => new bootstrap.Tooltip(el));
    }
    document.querySelectorAll('.trade-checkbox').forEach(cb => cb.addEventListener('change', updateBulkActions));
    updateBulkActions();
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'D') { e.preventDefault(); const d = document.getElementById('debugInfo'); if (d) d.style.display = d.style.display === 'none' ? 'block' : 'none'; }
    });
    const cr = document.getElementById('exportTypeContract'), cl = document.getElementById('exportTypeClient'), ht = document.getElementById('exportTypeHelp');
    if (cr && cl && ht) {
        function u() { ht.textContent = cr.checked ? 'Combined contract notes for each client.' : 'PDF list of all clients.'; }
        cr.addEventListener('change', u); cl.addEventListener('change', u);
    }
});
