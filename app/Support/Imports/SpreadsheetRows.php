<?php

namespace App\Support\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class SpreadsheetRows
{
    /**
     * @param  list<string>  $requiredHeaders
     * @return list<array{row: int, values: array<string, string>}>
     */
    public function read(UploadedFile $file, array $requiredHeaders): array
    {
        $path = $file->getRealPath();

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'Upload a valid XLSX, XLS, or CSV file.',
            ]);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();

        if ($highestRow > 10001) {
            $spreadsheet->disconnectWorksheets();

            throw ValidationException::withMessages([
                'file' => 'A spreadsheet may contain no more than 10,000 data rows.',
            ]);
        }

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $headers = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $header = $this->normalizeHeader((string) $sheet->getCell(Coordinate::stringFromColumnIndex($column).'1')->getFormattedValue());

            if ($header !== '') {
                $headers[$header] = $column;
            }
        }

        $missingHeaders = array_values(array_diff($requiredHeaders, array_keys($headers)));

        if ($missingHeaders !== []) {
            $spreadsheet->disconnectWorksheets();

            throw ValidationException::withMessages([
                'file' => 'Missing required column headers: '.implode(', ', $missingHeaders).'.',
            ]);
        }

        $rows = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = [];

            foreach ($requiredHeaders as $header) {
                $column = $headers[$header];
                $coordinate = Coordinate::stringFromColumnIndex($column).$rowNumber;
                $values[$header] = trim((string) $sheet->getCell($coordinate)->getFormattedValue());
            }

            if (count(array_filter($values, fn (string $value) => $value !== '')) === 0) {
                continue;
            }

            $rows[] = [
                'row' => $rowNumber,
                'values' => $values,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }
}
