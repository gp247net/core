<?php

namespace GP247\Core\Library;

class Export
{
    /**
     * Export data to CSV or XLS format
     * 
     * @param array $data Data array with structure:
     *                    - name: Display name
     *                    - name_export: File name without extension
     *                    - sheetname: Sheet name for XLS
     *                    - data: Array of data (each row is associative array with column keys)
     * @param string $type Export type: 'csv' or 'xls', default 'csv'
     * @return void
     */
    public static function export($data, $type = 'csv')
    {
        $type = strtolower($type);
        
        if (!isset($data['data']) || empty($data['data'])) {
            throw new \Exception('Data is required and cannot be empty');
        }
        
        // Convert associative data to array format for export
        $exportData = self::prepareDataForExport($data['data']);
        
        $exportConfig = [
            'name' => $data['name'] ?? '',
            'name_export' => $data['name_export'] ?? 'export',
            'sheetname' => $data['sheetname'] ?? 'Sheet1',
            'data' => $exportData
        ];
        
        if ($type === 'xls') {
            self::exportToXls($exportConfig);
        } else {
            self::exportToCsv($exportConfig);
        }
    }
    
    /**
     * Prepare data for export by converting associative arrays to indexed arrays
     * 
     * @param array $data Array of associative arrays where keys are column names
     * @return array Array with headers as first row and data rows following
     */
    private static function prepareDataForExport($data)
    {
        if (empty($data)) {
            return [];
        }
        
        // Get headers from first row
        $headers = array_keys($data[0]);
        
        // Prepare export data with headers as first row
        $exportData = [$headers];
        
        // Add data rows
        foreach ($data as $row) {
            $dataRow = [];
            foreach ($headers as $header) {
                $dataRow[] = $row[$header] ?? '';
            }
            $exportData[] = $dataRow;
        }
        
        return $exportData;
    }
    
    /**
     * Export data to CSV format
     * 
     * @param array $data
     * @return void
     */
    private static function exportToCsv($data)
    {
        $filename = isset($data['name_export']) ? $data['name_export'] : 'export';
        $filename = $filename . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write data
        foreach ($data['data'] as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Export data to XLS format
     * 
     * @param array $data
     * @return void
     */
    private static function exportToXls($data)
    {
        $filename = isset($data['name_export']) ? $data['name_export'] : 'export';
        $filename = $filename . '.xls';
        $sheetname = isset($data['sheetname']) ? $data['sheetname'] : 'Sheet1';
        
        // Set headers for XLS download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Start HTML table (Excel can read HTML tables)
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<style>';
        echo 'table { border-collapse: collapse; }';
        echo 'td, th { border: 1px solid #000; padding: 5px; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        
        // Create table
        echo '<table>';
        
        // Write data
        foreach ($data['data'] as $rowIndex => $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                // Escape HTML entities
                $cell = htmlspecialchars($cell, ENT_QUOTES, 'UTF-8');
                echo '<td>' . $cell . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</body>';
        echo '</html>';
        
        exit;
    }
    
    /**
     * Export data to XLSX format using PhpSpreadsheet (if available)
     * 
     * @param array $data
     * @return void
     */
    public static function exportToXlsx($data)
    {
        // Check if PhpSpreadsheet is available
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new \Exception('PhpSpreadsheet library is required for XLSX export. Please install it via composer: composer require phpoffice/phpspreadsheet');
        }
        
        if (!isset($data['data']) || empty($data['data'])) {
            throw new \Exception('Data is required and cannot be empty');
        }
        
        // Convert associative data to array format for export
        $exportData = self::prepareDataForExport($data['data']);
        
        $filename = isset($data['name_export']) ? $data['name_export'] : 'export';
        $filename = $filename . '.xlsx';
        $sheetname = isset($data['sheetname']) ? $data['sheetname'] : 'Sheet1';
        
        // Create new Spreadsheet object
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetname);
        
        // Write data
        foreach ($exportData as $rowIndex => $row) {
            foreach ($row as $colIndex => $cell) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->setCellValue($columnLetter . ($rowIndex + 1), $cell);
            }
        }
        
        // Auto-size columns
        foreach (range(1, count($exportData[0])) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        
        // Create Excel writer
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // Set headers
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output file
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Validate data structure
     * 
     * @param array $data
     * @return bool
     */
    public static function validateData($data)
    {
        if (!is_array($data)) {
            return false;
        }
        
        if (!isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
            return false;
        }
        
        // Check if all rows are associative arrays
        $firstRow = $data['data'][0];
        if (!is_array($firstRow) || empty($firstRow)) {
            return false;
        }
        
        $headers = array_keys($firstRow);
        
        // Check if all rows have the same keys
        foreach ($data['data'] as $row) {
            if (!is_array($row)) {
                return false;
            }
            
            $rowKeys = array_keys($row);
            if (count($rowKeys) !== count($headers) || array_diff($headers, $rowKeys)) {
                return false;
            }
        }
        
        return true;
    }
} 