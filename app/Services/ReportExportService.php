<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Exports\DailyReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;

class ReportExportService
{
    /**
     * EXPORT DAILY REPORT - នាំចេញរបាយការណ៍ប្រចាំថ្ងៃ
     * 
     * Formats: PDF, Excel (xlsx), CSV
     * 
     * @param DailyReport $report
     * @param string $format (pdf, xlsx, csv)
     * @return string File path
     */
    public function exportDailyReport(DailyReport $report, string $format): string
    {
        return match($format) {
            'pdf' => $this->exportToPdf($report),
            'xlsx' => $this->exportToExcel($report),
            'csv' => $this->exportToCsv($report),
            default => throw new \InvalidArgumentException('Invalid format'),
        };
    }

    /**
     * EXPORT TO PDF
     */
    protected function exportToPdf(DailyReport $report): string
    {
        // Prepare data
        $data = [
            'report' => $report,
            'branch_name' => $report->branch?->branch_name ?? 'All Branches',
            'company_name' => $report->tenant->company_name,
            'date' => $report->report_date->format('d M Y'),
            'report_id' => $report->report_id,
        ];

        // Generate PDF
        $pdf = Pdf::loadView('reports.pdf.daily', $data);
        
        // Set paper size
        $pdf->setPaper('A4', 'portrait');

        // Generate filename
        $filename = $this->generateFilename($report, 'pdf');
        $path = "reports/daily/{$filename}";

        // Save to storage
       Storage::disk('public')->put($path, $pdf->output());

        return $path;
    }

    /**
     * EXPORT TO EXCEL
     */
    protected function exportToExcel(DailyReport $report): string
    {
        $filename = $this->generateFilename($report, 'xlsx');
        $path = "reports/daily/{$filename}";

        // Use Excel export class
        Excel::store(new DailyReportExport($report), $path,'public');

        return $path;
    }

    /**
     * EXPORT TO CSV
     */
    protected function exportToCsv(DailyReport $report): string
    {
        $filename = $this->generateFilename($report, 'csv');
        $path = "reports/daily/{$filename}";

        // Use Excel export class with CSV format
        Excel::store(
            new DailyReportExport($report), $path, 'public', \Maatwebsite\Excel\Excel::CSV
        );

        return $path;
    }

    /**
     * GENERATE FILENAME
     */
    protected function generateFilename(DailyReport $report, string $extension): string
    {
        $date = $report->report_date->format('Ymd');
        $branch = $report->branch?->branch_code ?? 'ALL';
        $timestamp = now()->format('His');
        
        return "daily-report-{$branch}-{$date}-{$timestamp}.{$extension}";
    }
}