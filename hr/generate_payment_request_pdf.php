<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../tcpdf/tcpdf.php';

require_hr();
$db = getDBConnection();

if (!isset($_GET['request_id'])) {
    die('No request ID provided');
}

$request_id = (int)$_GET['request_id'];

try {
    $stmt = $db->prepare("
        SELECT pp.*, 
               lt.description as pay_to_desc,
               u1.full_name as requested_by_fullname,
               u2.full_name as ceo_approved_by_fullname,
               u3.full_name as finance_approved_by_fullname,
               u4.full_name as paid_by_fullname
        FROM pending_pay pp
        LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
        LEFT JOIN users u1 ON pp.requested_by = u1.id
        LEFT JOIN users u2 ON pp.ceo_approved_by = u2.id
        LEFT JOIN users u3 ON pp.finance_approved_by = u3.id
        LEFT JOIN users u4 ON pp.paid_by = u4.id
        WHERE pp.id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();

    if (!$request) {
        die('Request not found');
    }

    class PaymentRequestPDF extends TCPDF {
        public function Header() {
            $logo_file = __DIR__ . '/../assets/HeaderLogoVfsl.jpg';
            if (!file_exists($logo_file)) $logo_file = __DIR__ . '/../reports/assets/HeaderLogoVfsl.jpg';
            if (!file_exists($logo_file)) $logo_file = '../assets/HeaderLogoVfsl.jpg';

            $margins = $this->getMargins();
            $lm = $margins['left'];
            $pw = $this->getPageWidth();
            $lineRight = $pw - $margins['right'];

            if (file_exists($logo_file)) {
                $this->Image($logo_file, $lm + 2, 5, 18, 0, '', '', 'T', false, 300);
            }
            $this->SetFont('helvetica', 'B', 12);
            $this->SetTextColor(4, 45, 146);
            $this->SetXY($lm, 5);
            $this->Cell(0, 5, 'VICTORY FINANCIAL SERVICES LIMITED', 0, 1, 'C');
            $this->SetFont('helvetica', 'BI', 9);
            $this->SetTextColor(255, 0, 0);
            $this->SetX($lm);
            $this->Cell(0, 4, 'Stockbroker/Dealer, Fund Manager & Investment Advisor', 0, 1, 'C');
            $this->SetFont('helvetica', 'B', 8);
            $this->SetTextColor(4, 45, 146);
            $this->SetX($lm);
            $this->Cell(0, 4, 'Members of the Dar Es Salaam Stock Exchange', 0, 1, 'C');
            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(4, 45, 146);
            $this->SetX($lm);
            $this->Cell(0, 3, 'House No. 11, Ursino Street, Mikocheni A, P.O Box 8706 - Dar es Salaam', 0, 1, 'C');
            $this->SetFont('helvetica', 'B', 7);
            $this->SetX($lm);
            $this->Cell(0, 3, 'Mob: +255 752 824 977 | Tel: +255 22 211 2691 | Email: info@vfsl.co.tz', 0, 1, 'C');
            $lineY = $this->GetY() + 2;
            $this->SetLineWidth(0.5);
            $this->SetDrawColor(4, 45, 146);
            $this->Line($lm, $lineY, $lineRight, $lineY);
            $this->SetLineWidth(0.3);
            $this->SetDrawColor(255, 0, 0);
            $this->Line($lm, $lineY + 0.8, $lineRight, $lineY + 0.8);
            $this->SetTextColor(0, 0, 0);
            $this->SetY($lineY + 2);
        }

        public function Footer() {
            $this->SetY(-18);
            $this->SetFont('helvetica', 'I', 7);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 4, 'This is a computer generated document. Confidential - For Internal Use Only.', 0, 1, 'C');
            $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }

    $pdf = new PaymentRequestPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Stockex Pro');
    $pdf->SetTitle('Payment Request - ' . $request['request_no']);
    $pdf->SetMargins(25, 30, 15);
    $pdf->SetAutoPageBreak(true, 22);
    $pdf->AddPage();

    $pw = $pdf->getPageWidth();
    $lm = 15;
    $rm = 15;
    $cw = $pw - $lm - $rm;

    $navy = [4, 45, 146];
    $red = [200, 0, 0];
    $gray = [80, 80, 80];
    $black = [0, 0, 0];
    $green = [0, 100, 0];
    $amber = [200, 150, 0];

    $status_labels = [
        'pending' => 'PENDING',
        'approved_ceo' => 'CEO APPROVED',
        'approved_finance' => 'FINANCE APPROVED',
        'rejected' => 'REJECTED',
        'paid' => 'PAID',
    ];


    $y = $pdf->GetY();

    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetXY($lm, $y);
    $pdf->Cell($cw, 10, 'PAYMENT REQUEST', 0, 1, 'C');
    $y += 10;

    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetLineWidth(0.4);
    $pdf->Line($lm, $y, $pw - $rm, $y);
    $y += 4;

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor($black[0], $black[1], $black[2]);
    $pdf->SetXY($lm, $y);
    $pdf->Cell(30, 6, 'Request No:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(60, 6, $request['request_no'], 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(30, 6, 'Date:', 0, 0, 'R');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(30, 6, date('d M Y', strtotime($request['requested_at'])), 0, 1, 'R');
    $y += 8;

    $status = $request['status'];
    $sc = $status_colors[$status] ?? [108, 117, 125];
    $sl = $status_labels[$status] ?? strtoupper($status);


    $pdf->SetTextColor($black[0], $black[1], $black[2]);

    function drawSectionHeader($pdf, $y, $title, $lm, $cw) {
        $navy = [4, 45, 146];
        $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($lm, $y, $lm + $cw, $y);
        $y += 4;
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
        $pdf->SetXY($lm, $y);
        $pdf->Cell($cw, 6, $title, 0, 1, 'L');
        $y += 5;
        $pdf->SetTextColor(0, 0, 0);
        return $y;
    }

    function drawField($pdf, $y, $label, $value, $lx, $rx, $lw = 42, $maxRW = 0) {
        $gray = [80, 80, 80];
        $black = [0, 0, 0];
        $cw = $maxRW > 0 ? $maxRW : 0;
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
        $pdf->SetXY($lx, $y);
        $pdf->Cell($lw, 5.5, $label, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor($black[0], $black[1], $black[2]);
        $pdf->Cell(0, 5.5, $value, 0, 1, 'L');
        return $y + 5.5;
    }

    function drawFieldRow($pdf, $y, $label1, $value1, $label2, $value2, $lm, $cw, $colW = 0) {
        if ($colW == 0) $colW = $cw / 2;
        $gray = [80, 80, 80];
        $black = [0, 0, 0];
        $navy = [4, 45, 146];

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
        $pdf->SetXY($lm, $y);
        $pdf->Cell(35, 5.5, $label1, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor($black[0], $black[1], $black[2]);
        $pdf->Cell($colW - 35, 5.5, $value1, 0, 0, 'L');

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
        $pdf->Cell(35, 5.5, $label2, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor($black[0], $black[1], $black[2]);
        $pdf->Cell(0, 5.5, $value2, 0, 1, 'L');
        return $y + 5.5;
    }

    $y = drawSectionHeader($pdf, $y, 'REQUEST INFORMATION', $lm, $cw);

    $y = drawFieldRow($pdf, $y, 'Requested By:', $request['requested_by_fullname'] ?? 'N/A', 'Date:', date('d M Y', strtotime($request['requested_at'])), $lm, $cw);
    $y = drawField($pdf, $y, 'Subject:', $request['subject'], $lm, $lm, 42, $cw);
    $y += 3;

    $y = drawSectionHeader($pdf, $y, 'PAYMENT DETAILS', $lm, $cw);

    $y = drawFieldRow($pdf, $y, 'Pay To Type:', $request['pay_to_desc'] ?? $request['pay_to_type'], 'Cheque No:', $request['cheque_no'] ?: 'N/A', $lm, $cw);
    $y = drawField($pdf, $y, 'Payee Name:', $request['payee_name'], $lm, $lm, 42, $cw);

    $y += 1;
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->SetLineWidth(0.15);
    $pdf->Line($lm, $y, $pw - $rm, $y);
    $y += 2;

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
    $pdf->SetXY($lm, $y);
    $pdf->Cell(42, 6, 'Amount:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor($green[0], $green[1], $green[2]);
    $amount_str = number_format((float)$request['amount_paid'], 2) . ' ' . $request['currency'];
    $pdf->Cell(0, 6, $amount_str, 0, 1, 'L');
    $y += 9;

    if (!empty($request['payment_description'])) {
        $y = drawField($pdf, $y, 'Description:', $request['payment_description'], $lm, $lm, 42, $cw);
        $y += 1;
    }

    $y += 1;

    $y = drawSectionHeader($pdf, $y, 'BANK DETAILS', $lm, $cw);

    $y = drawFieldRow($pdf, $y, 'Bank Name:', $request['payee_bank_name'] ?: 'N/A', 'Branch:', $request['payee_branch'] ?: 'N/A', $lm, $cw);
    $y = drawFieldRow($pdf, $y, 'Account Name:', $request['payee_account_name'] ?: 'N/A', 'Account No:', $request['payee_account_no'] ?: 'N/A', $lm, $cw);
    $y += 3;

    $y = drawSectionHeader($pdf, $y, 'APPROVAL STATUS', $lm, $cw);

    $col1 = $cw / 2;
    $col2 = $cw / 2;

    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY($lm, $y);
    $pdf->Cell($col1, 7, 'Approval Level', 1, 0, 'C', true);
    $pdf->Cell($col2, 7, 'Status', 1, 1, 'C', true);
    $y += 7;

    $rows = [
        ['CEO Approval', $request['ceo_approved_at'] ? 'Approved ' . date('d M Y', strtotime($request['ceo_approved_at'])) : 'PENDING', $request['ceo_approved_at'] ? $green : $amber, $request['ceo_approved_at'] ? ($request['ceo_approved_by_fullname'] ?? '') : ''],
        ['Finance Approval', $request['finance_approved_at'] ? 'Approved ' . date('d M Y', strtotime($request['finance_approved_at'])) : 'PENDING', $request['finance_approved_at'] ? $green : $amber, $request['finance_approved_at'] ? ($request['finance_approved_by_fullname'] ?? '') : ''],
        ['Payment', $request['paid_at'] ? 'Paid ' . date('d M Y', strtotime($request['paid_at'])) : 'NOT PAID', $request['paid_at'] ? $green : $amber, ''],
    ];

    foreach ($rows as $ri => $row) {
        $bg = $ri % 2 == 0 ? [245, 245, 250] : [255, 255, 255];
        $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.1);
        $pdf->SetXY($lm, $y);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
        $pdf->Cell($col1, 7, '  ' . $row[0], 1, 0, 'L', true);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor($row[2][0], $row[2][1], $row[2][2]);
        $label = $row[1];
        if (!empty($row[3])) {
            $label .= ' by ' . $row[3];
        }
        $pdf->Cell($col2, 7, $label, 1, 1, 'C', true);
        $y += 7;
    }
    $y += 3;

    if (!empty($request['attachment_name'])) {
        $y = drawSectionHeader($pdf, $y, 'ATTACHMENT', $lm, $cw);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor($black[0], $black[1], $black[2]);
        $pdf->SetXY($lm, $y);
        $pdf->Cell($cw, 5.5, $request['attachment_name'] . ' (' . $request['attachment_mime'] . ')', 0, 1, 'L');
        $y += 8;
    }

    if ($y > 200) {
        $pdf->AddPage();
        $y = $pdf->GetY() + 5;
    }

    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetLineWidth(0.3);
    $pdf->Line($lm, $y, $pw - $rm, $y);
    $y += 6;

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetXY($lm, $y);
    $pdf->Cell($cw, 5, 'SIGNATORIES', 0, 1, 'L');
    $y += 4;

    $sigPadW = 55;
    $sigGap = 7;
    $sigCount = 3;
    $totalSigW = ($sigPadW * $sigCount) + ($sigGap * ($sigCount - 1));
    $sigStartX = $lm + ($cw - $totalSigW) / 2;

    $sigs = [
        ['Prepared By', $request['requested_by_fullname'] ?? '', $request['requested_at']],
        ['CEO Approval', $request['ceo_approved_by_fullname'] ?? '', $request['ceo_approved_at'] ?? ''],
        ['Finance Approval', $request['finance_approved_by_fullname'] ?? '', $request['finance_approved_at'] ?? ''],
    ];

    foreach ($sigs as $i => $sig) {
        $sx = $sigStartX + ($i * ($sigPadW + $sigGap));

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($sx, $y + 22, $sx + $sigPadW, $y + 22);

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
        $pdf->SetXY($sx, $y + 24);
        $pdf->Cell($sigPadW, 5, $sig[0], 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor($black[0], $black[1], $black[2]);
        $pdf->SetXY($sx, $y + 29);
        $pdf->Cell($sigPadW, 5, $sig[1] ?: '________________________', 0, 1, 'C');

        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetXY($sx, $y + 34);
        $dateStr = $sig[2] ? date('d M Y', strtotime($sig[2])) : '____/____/________';
        $pdf->Cell($sigPadW, 5, 'Date: ' . $dateStr, 0, 1, 'C');
    }

    if (!empty($request['attachment_name']) && !empty($request['attachment_data'])) {
        $mime = $request['attachment_mime'];
        if ($mime === 'application/pdf') {
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
            $pdf->Cell($cw, 6, 'ATTACHED DOCUMENT', 0, 1, 'C');
            $pdf->Ln(3);
            $pdf->Image('@' . $request['attachment_data'], '', '', $cw * 0.7, 0, '', '', '', false, 300);
        } elseif (in_array($mime, ['image/jpeg', 'image/png', 'image/jpg'])) {
            $pdf->Ln(5);
            $pdf->Image('@' . $request['attachment_data'], '', '', $cw * 0.6, 0, '', '', '', false, 300);
        }
    }

    $pdf->Output('payment_request_' . $request['request_no'] . '.pdf', 'I');

} catch (Exception $e) {
    die('Error generating PDF: ' . $e->getMessage());
}
