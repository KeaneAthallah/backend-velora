<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Response;

/**
 * Report downloads in the three formats the workspace promises. Everything is
 * generated in-process: CSV for spreadsheets and APIs, SpreadsheetML (`.xls`,
 * which Excel opens natively) for formatted workbooks, and a small PDF writer
 * for printable exports. No optional extensions or composer packages are
 * required, so a stock install can produce every format.
 */
class ReportExportService
{
    public const FORMATS = ['csv', 'excel', 'pdf'];

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function download(string $name, array $headers, array $rows, string $format = 'csv'): Response
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'csv';
        $stamp = now()->format('Ymd-His');
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) ?: 'report';

        return match ($format) {
            'excel' => response($this->excel($name, $headers, $rows), 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$safe.'-'.$stamp.'.xls"',
                'Cache-Control' => 'no-store, no-cache',
            ]),
            'pdf' => response($this->pdf($name, $headers, $rows), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$safe.'-'.$stamp.'.pdf"',
                'Cache-Control' => 'no-store, no-cache',
            ]),
            default => response($this->csv($headers, $rows), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$safe.'-'.$stamp.'.csv"',
                'Cache-Control' => 'no-store, no-cache',
            ]),
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function csv(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($value) => $value ?? '', $row));
        }
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return (string) $content;
    }

    /**
     * SpreadsheetML 2003: a single XML file Excel opens as a workbook with no
     * zip or spreadsheet extension needed.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function excel(string $title, array $headers, array $rows): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n"
            .'<Worksheet ss:Name="'.mb_substr($escape($title), 0, 28).'"><Table>'."\n";

        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell><Data ss:Type="String">'.$escape($header).'</Data></Cell>';
        }
        $xml .= '</Row>'."\n";

        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($row as $value) {
                if ($value === null || $value === '') {
                    $xml .= '<Cell/>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<Cell><Data ss:Type="Number">'.$escape($value).'</Data></Cell>';
                } else {
                    $xml .= '<Cell><Data ss:Type="String">'.$escape($value).'</Data></Cell>';
                }
            }
            $xml .= '</Row>'."\n";
        }

        return $xml.'</Table></Worksheet></Workbook>';
    }

    /**
     * Minimal A4 portrait PDF: title, generated timestamp, then a paginated
     * table in Helvetica. Enough for a printable report without a renderer.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function pdf(string $title, array $headers, array $rows): string
    {
        $margin = 36;
        $pageWidth = 595;
        $pageHeight = 842;
        $fontSize = 9;
        $rowHeight = 14;
        $rowsPerPage = (int) floor(($pageHeight - 2 * $margin - 70) / $rowHeight);
        $columns = max(1, count($headers));
        $columnWidth = ($pageWidth - 2 * $margin) / $columns;
        $maxChars = max(4, (int) floor($columnWidth / 4.6));

        $pages = [];

        foreach (array_chunk($rows, max(1, $rowsPerPage)) as $pageIndex => $chunk) {
            $y = $pageHeight - $margin - 24;
            $content = '';

            if ($pageIndex === 0) {
                $content .= $this->pdfText($title, $margin, $y, 14);
                $y -= 18;
                $content .= $this->pdfText('Generated '.now()->format('Y-m-d H:i'), $margin, $y, 8);
                $y -= 22;
            }

            $content .= $this->pdfText(implode('  |  ', array_map(
                fn ($header) => $this->pdfClip($header, $maxChars),
                $headers
            )), $margin, $y, $fontSize);
            $y -= $rowHeight;
            $content .= $this->pdfLine($margin, $y + 10, $pageWidth - $margin, $y + 10);

            foreach ($chunk as $row) {
                $cells = [];
                foreach (array_values($row) as $value) {
                    $cells[] = $this->pdfClip($value === null ? '' : (string) $value, $maxChars);
                }
                $content .= $this->pdfText(implode('  |  ', $cells), $margin, $y, $fontSize);
                $y -= $rowHeight;
            }

            $pages[] = $content;
        }

        if ($pages === []) {
            $pages[] = $this->pdfText($title, $margin, $pageHeight - $margin, 14)
                .$this->pdfText('No rows in this period.', $margin, $pageHeight - $margin - 24, 9);
        }

        return $this->assemblePdf($pages, $pageWidth, $pageHeight);
    }

    /* ------------------------------------------------------------------ */

    private function pdfText(string $text, float $x, float $y, float $size): string
    {
        return sprintf(
            "BT /F1 %s Tf %.2F %.2F Td (%s) Tj ET\n",
            $size,
            $x,
            $y,
            $this->pdfEscape($text)
        );
    }

    private function pdfLine(float $x1, float $y1, float $x2, float $y2): string
    {
        return sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    private function pdfClip(string $value, int $maxChars): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_strlen($value) > $maxChars ? mb_substr($value, 0, $maxChars - 1).'…' : $value;
    }

    private function pdfEscape(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
    }

    /**
     * @param  array<int, string>  $pages
     */
    private function assemblePdf(array $pages, int $pageWidth, int $pageHeight): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pageIds = [];
        $contentIds = [];
        $next = 4;
        foreach (array_keys($pages) as $index) {
            $contentIds[$index] = $next++;
            $pageIds[$index] = $next++;
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($id) => "$id 0 R", $pageIds)).'] /Count '.count($pages).' >>';

        foreach ($pages as $index => $content) {
            $objects[$contentIds[$index]] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
            $objects[$pageIds[$index]] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageWidth.' '.$pageHeight.'] '
                .'/Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentIds[$index].' 0 R >>';
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id.' 0 obj'."\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= 'xref'."\n".'0 '.$count."\n".'0000000000 65535 f '."\n";
        foreach ($objects as $id => $body) {
            $pdf .= str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT).' 00000 n '."\n";
        }
        $pdf .= 'trailer'."\n".'<< /Size '.$count.' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF';

        return $pdf;
    }
}
