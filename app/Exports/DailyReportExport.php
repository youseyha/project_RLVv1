<?php

namespace App\Exports;

use App\Models\DailyReport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DailyReportExport implements
    FromCollection, 
    WithHeadings, 
    WithMapping, 
    WithTitle, 
    WithStyles,
    ShouldAutoSize
{
    protected DailyReport $report;

    public function __construct(DailyReport $report)
    {
        $this->report = $report;
    }

    /**
     * COLLECTION - ទិន្នន័យ
     */
    public function collection()
    {
        // Return collection with single report
        return collect([$this->report]);
    }

    /**
     * HEADINGS - ចំណងជើងជួរឈរ
     */
    public function headings(): array
    {
        return [
            'Report Date',
            'Branch',
            'Total Sales',
            'Transaction Count',
            'Average Transaction',
            'Total Tax',
            'Total Discount',
            'Customer Count',
            'Net Sales',
            'Generated At',
        ];
    }

    /**
     * MAPPING - ផ្គូផ្គងទិន្នន័យ
     */
    public function map($report): array
    {
        return [
            $report->report_date->format('Y-m-d'),
            $report->branch?->branch_name ?? 'All Branches',
            number_format($report->total_sales, 2),
            $report->transaction_count,
            number_format($report->average_transaction, 2),
            number_format($report->total_tax, 2),
            number_format($report->total_discount, 2),
            $report->customer_count,
            number_format($report->net_sales, 2),
            $report->generated_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * TITLE - ចំណងជើង Sheet
     */
    public function title(): string
    {
        return 'Daily Report ' . $this->report->report_date->format('Y-m-d');
    }

    /**
     * STYLES - រចនាប័ទ្ម
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Style first row (headings)
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E0E0E0']
                ],
            ],
        ];
    }
}