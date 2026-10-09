<?php
// includes/export.php - CSV download helper

/**
 * Stream a CSV file. Cells that start with a spreadsheet formula character are
 * prefixed with an apostrophe so opening the file cannot run formulas.
 */
function csv_download($filename, array $headers, array $rows) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
    $clean = function ($v) {
        $v = (string)($v ?? '');
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) ? "'" . $v : $v;
    };
    fputcsv($out, array_map($clean, $headers));
    foreach ($rows as $row) {
        fputcsv($out, array_map($clean, array_values($row)));
    }
    fclose($out);
    exit;
}
