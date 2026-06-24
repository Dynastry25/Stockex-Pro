<?php

/**
 * Render the VFSL report header on a TCPDF instance (procedural helper)
 */
function renderVfslPdfHeader($pdf, $company_name = 'VICTORY FINANCIAL SERVICES LIMITED', $company_exchange = 'Dar Es Salaam Stock Exchange', $company_address = 'House No. 11|Ursino Street|Mikocheni A| P.O Box 8706 - Dar es Salaam', $company_mobile = '+255 752 824 977', $company_phone = '+255 22 211 2691', $company_email = 'info@vfsl.co.tz') {
    $logo_file = __DIR__ . '/../../assets/HeaderLogoVfsl.jpg';
    if (!file_exists($logo_file)) {
        $logo_file = __DIR__ . '/../assets/HeaderLogoVfsl.jpg';
    }
    if (!file_exists($logo_file)) {
        $logo_file = '../assets/HeaderLogoVfsl.jpg';
    }

    $margins = $pdf->getMargins();
    $lm = $margins['left'];
    $pw = $pdf->getPageWidth();
    $lineRight = $pw - $margins['right'];

    if (file_exists($logo_file)) {
        $pdf->Image($logo_file, $lm + 2, 5, 18, 0, '', '', 'T', false, 300);
    }
    $pdf->SetFont('times', 'B', 12);
    $pdf->SetTextColor(4, 45, 146);
    $pdf->SetXY($lm, 5);
    $pdf->Cell(0, 5, strtoupper($company_name), 0, 1, 'C');
    $pdf->SetFont('times', 'BI', 9);
    $pdf->SetTextColor(255, 0, 0);
    $pdf->SetX($lm);
    $pdf->Cell(0, 4, 'Stockbroker/Dealer, Fund Manager & Investment Advisor', 0, 1, 'C');
    $pdf->SetFont('times', 'B', 8);
    $pdf->SetTextColor(4, 45, 146);
    $pdf->SetX($lm);
    $pdf->Cell(0, 4, 'Members of the ' . $company_exchange, 0, 1, 'C');
    $pdf->SetFont('times', '', 7);
    $pdf->SetTextColor(4, 45, 146);
    $pdf->SetX($lm);
    $pdf->Cell(0, 3, $company_address, 0, 1, 'C');
    $contact_info = '';
    if (!empty($company_mobile)) $contact_info .= 'Mob: ' . $company_mobile;
    if (!empty($company_phone)) $contact_info .= '| Tel: ' . $company_phone;
    if (!empty($company_email)) $contact_info .= '| Email: ' . $company_email;
    $pdf->SetFont('times', 'B', 7);
    $pdf->SetTextColor(4, 45, 146);
    $pdf->SetX($lm);
    $pdf->Cell(0, 3, $contact_info, 0, 1, 'C');
    $lineY = $pdf->GetY() + 2;
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(4, 45, 146);
    $pdf->Line($lm, $lineY, $lineRight, $lineY);
    $pdf->SetLineWidth(0.3);
    $pdf->SetDrawColor(255, 0, 0);
    $pdf->Line($lm, $lineY + 0.8, $lineRight, $lineY + 0.8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($lineY + 2);
}

trait ReportHeaderTrait {
    protected $header_logo_path = '';
    protected $header_company_name = '';
    protected $header_business_type = '';
    protected $header_exchange = '';
    protected $header_address = '';
    protected $header_mobile = '';
    protected $header_phone = '';
    protected $header_email = '';

    protected function initHeaderDefaults() {
        if (empty($this->header_company_name)) {
            if (property_exists($this, 'company_data') && !empty($this->company_data)) {
                $d = $this->company_data;
                $this->header_company_name = $d['company_name'] ?? 'VICTORY FINANCIAL SERVICES LIMITED';
                $this->header_business_type = $d['business_type'] ?? 'Stockbroker/Dealer, Fund Manager & Investment Advisor';
                $this->header_exchange = $d['exchange'] ?? 'Members of the Dar Es Salaam Stock Exchange';
                $this->header_address = $d['address'] ?? 'House No. 11|Ursino Street|Mikocheni A| P.O Box 8706 - Dar es Salaam';
                $this->header_phone = $d['phone'] ?? '+255 22 211 2691';
                $this->header_mobile = $d['mobile'] ?? '+255 752 824 977';
                $this->header_email = $d['email'] ?? 'info@vfsl.co.tz';
            } elseif (property_exists($this, 'company_name') && !empty($this->company_name)) {
                $this->header_company_name = $this->company_name;
                $this->header_business_type = 'Stockbroker/Dealer, Fund Manager & Investment Advisor';
                $this->header_exchange = 'Members of the Dar Es Salaam Stock Exchange';
                $this->header_address = 'House No. 11|Ursino Street|Mikocheni A| P.O Box 8706 - Dar es Salaam';
                $this->header_phone = '+255 22 211 2691';
                $this->header_mobile = '+255 752 824 977';
                $this->header_email = 'info@vfsl.co.tz';
            } else {
                $this->header_company_name = 'VICTORY FINANCIAL SERVICES LIMITED';
                $this->header_business_type = 'Stockbroker/Dealer, Fund Manager & Investment Advisor';
                $this->header_exchange = 'Members of the Dar Es Salaam Stock Exchange';
                $this->header_address = 'House No. 11|Ursino Street|Mikocheni A| P.O Box 8706 - Dar es Salaam';
                $this->header_phone = '+255 22 211 2691';
                $this->header_mobile = '+255 752 824 977';
                $this->header_email = 'info@vfsl.co.tz';
            }
        }

        if (empty($this->header_logo_path)) {
            $candidates = [
                __DIR__ . '/../../assets/HeaderLogoVfsl.jpg',
                __DIR__ . '/../assets/HeaderLogoVfsl.jpg',
                '../assets/HeaderLogoVfsl.jpg',
                'assets/HeaderLogoVfsl.jpg',
                __DIR__ . '/../../assets/NewHeaderLogo.png',
            ];
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $this->header_logo_path = $candidate;
                    break;
                }
            }
        }
    }

    protected function renderReportHeader() {
        $this->initHeaderDefaults();

        $logoPath = $this->header_logo_path;
        $companyName = strtoupper($this->header_company_name);
        $businessType = $this->header_business_type;
        $exchange = $this->header_exchange;
        $address = $this->header_address;
        $mobile = $this->header_mobile;
        $phone = $this->header_phone;
        $email = $this->header_email;

        $margins = $this->getMargins();
        $lm = $margins['left'];
        $pw = $this->getPageWidth();
        $lineRight = $pw - $margins['right'];

        // Logo on left
        if ($logoPath && file_exists($logoPath)) {
            $this->Image($logoPath, $lm + 2, 5, 18, 0, '', '', 'T', false, 300);
        }

        $y = 5;

        $this->SetFont('times', 'B', 12);
        $this->SetTextColor(4, 45, 146);
        $this->SetXY($lm, $y);
        $this->Cell(0, 5, $companyName, 0, 1, 'C');
        $y += 5;

        $this->SetFont('times', 'BI', 9);
        $this->SetTextColor(255, 0, 0);
        $this->SetXY($lm, $y);
        $this->Cell(0, 4, $businessType, 0, 1, 'C');
        $y += 4;

        $this->SetFont('times', 'B', 8);
        $this->SetTextColor(4, 45, 146);
        $this->SetXY($lm, $y);
        $this->Cell(0, 4, $exchange, 0, 1, 'C');
        $y += 4;

        $this->SetFont('times', '', 7);
        $this->SetTextColor(4, 45, 146);
        $this->SetXY($lm, $y);
        $this->Cell(0, 3, $address, 0, 1, 'C');
        $y += 3;

        $this->SetFont('times', 'B', 7);
        $this->SetTextColor(4, 45, 146);
        $this->SetXY($lm, $y);
        $this->Cell(0, 3, 'Mob: ' . $mobile . '| Tel: ' . $phone . '| Email: ' . $email, 0, 1, 'C');
        $y += 4;

        $this->SetLineWidth(0.5);
        $this->SetDrawColor(4, 45, 146);
        $this->Line($lm, $y, $lineRight, $y);

        $this->SetLineWidth(0.3);
        $this->SetDrawColor(255, 0, 0);
        $this->Line($lm, $y + 0.8, $lineRight, $y + 0.8);

        $this->SetTextColor(0, 0, 0);
        $this->SetY($y + 2);
    }
}
