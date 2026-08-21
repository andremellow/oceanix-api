<?php

namespace App\Services\People;

use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class PeopleSpreadsheetParser
{
    /**
     * The import contract is positional. Row one is always a header and is ignored:
     * A = name, B = email, C = job function, D = department.
     *
     * @return list<array{row: int, name: string, email: string, job_function: string, department: string}>
     */
    public function parse(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('The spreadsheet could not be opened.'));
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $worksheet = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($worksheet === false) {
                throw new RuntimeException(__('The spreadsheet does not contain a readable first worksheet.'));
            }

            return $this->rows(new SimpleXMLElement($worksheet), $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');

        if ($contents === false) {
            return [];
        }

        $xml = new SimpleXMLElement($contents);
        $strings = [];

        foreach ($xml->si as $item) {
            $strings[] = isset($item->t)
                ? (string) $item->t
                : collect($item->r)->map(fn (SimpleXMLElement $run): string => (string) $run->t)->join('');
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<array{row: int, name: string, email: string, job_function: string, department: string}>
     */
    private function rows(SimpleXMLElement $worksheet, array $sharedStrings): array
    {
        $worksheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];

        foreach ($worksheet->xpath('//x:sheetData/x:row') ?: [] as $row) {
            $number = (int) $row['r'];

            if ($number <= 1) {
                continue;
            }

            $values = ['', '', '', ''];

            foreach ($row->c as $cell) {
                $column = $this->columnIndex((string) $cell['r']);

                if ($column > 3) {
                    continue;
                }

                $values[$column] = $this->cellValue($cell, $sharedStrings);
            }

            [$name, $email, $jobFunction, $department] = array_map($this->clean(...), $values);

            if ($name === '' && $email === '' && $jobFunction === '' && $department === '') {
                continue;
            }

            $rows[] = [
                'row' => $number,
                'name' => $name,
                'email' => Str::lower($email),
                'job_function' => $jobFunction,
                'department' => $department,
            ];
        }

        return $rows;
    }

    /** @param list<string> $sharedStrings */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            return (string) $cell->is->t;
        }

        $value = (string) $cell->v;

        return $type === 's' ? ($sharedStrings[(int) $value] ?? '') : $value;
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/', strtoupper($reference), $matches);
        $letters = $matches[0] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
