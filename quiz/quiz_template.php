<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Tạo file Excel mẫu cho câu hỏi một đáp án
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Đặt tiêu đề cho các cột
$sheet->setCellValue('A1', 'Câu hỏi');
$sheet->setCellValue('B1', 'Phương án A');
$sheet->setCellValue('C1', 'Phương án B');
$sheet->setCellValue('D1', 'Phương án C');
$sheet->setCellValue('E1', 'Phương án D');
$sheet->setCellValue('F1', 'Đáp án đúng (1-4)');
$sheet->setCellValue('G1', 'Giải thích');

// Thêm dữ liệu mẫu
$sheet->setCellValue('A2', 'Đâu là thủ đô của Việt Nam?');
$sheet->setCellValue('B2', 'Hà Nội');
$sheet->setCellValue('C2', 'Hồ Chí Minh');
$sheet->setCellValue('D2', 'Đà Nẵng');
$sheet->setCellValue('E2', 'Huế');
$sheet->setCellValue('F2', '1');
$sheet->setCellValue('G2', 'Hà Nội là thủ đô của Việt Nam từ năm 1945');

// Đặt độ rộng cột tự động
foreach(range('A','G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Tạo file Excel
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="quiz_template_single.xlsx"');
header('Cache-Control: max-age=0');
$writer->save('php://output');

// Tạo file Excel mẫu cho câu hỏi nhiều đáp án
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Đặt tiêu đề cho các cột
$sheet->setCellValue('A1', 'Câu hỏi');
$sheet->setCellValue('B1', 'Phương án A');
$sheet->setCellValue('C1', 'Phương án B');
$sheet->setCellValue('D1', 'Phương án C');
$sheet->setCellValue('E1', 'Phương án D');
$sheet->setCellValue('F1', 'Đáp án A');
$sheet->setCellValue('G1', 'Đáp án B');
$sheet->setCellValue('H1', 'Đáp án C');
$sheet->setCellValue('I1', 'Đáp án D');
$sheet->setCellValue('J1', 'Giải thích');

// Thêm dữ liệu mẫu
$sheet->setCellValue('A2', 'Những thành phố nào sau đây là thành phố trực thuộc trung ương?');
$sheet->setCellValue('B2', 'Hà Nội');
$sheet->setCellValue('C2', 'Hồ Chí Minh');
$sheet->setCellValue('D2', 'Đà Nẵng');
$sheet->setCellValue('E2', 'Huế');
$sheet->setCellValue('F2', 'X');
$sheet->setCellValue('G2', 'X');
$sheet->setCellValue('H2', 'X');
$sheet->setCellValue('I2', '');
$sheet->setCellValue('J2', 'Hà Nội, Hồ Chí Minh và Đà Nẵng là 3 thành phố trực thuộc trung ương');

// Đặt độ rộng cột tự động
foreach(range('A','J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Tạo file Excel
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="quiz_template_multiple.xlsx"');
header('Cache-Control: max-age=0');
$writer->save('php://output'); 