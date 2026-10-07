<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use ZipArchive;

/** Minimal OpenXML/CSV interchange: no formulas, macros, external XML or evaluation. */
final class GradeSpreadsheet
{
    public const HEADERS = ['format_version', 'assessment_id', 'classroom_id', 'offering_id', 'assessment_version', 'roster_version', 'student_id', 'display_name', 'score', 'status', 'feedback'];

    public function matrix(object $assessment): array
    {
        $offering = DB::table('module_offerings')->find($assessment->offering_id);
        $entries = DB::table('grade_entries')->where('revision_id', $assessment->draft_revision_id ?: $assessment->published_revision_id)->get()->keyBy('student_id');
        $rows = [self::HEADERS];
        foreach (DB::table('assessment_candidates')->where('assessment_id', $assessment->id)->orderBy('student_id')->get() as $candidate) {
            $entry = $entries->get($candidate->student_id);
            $rows[] = ['1', $assessment->id, $offering->classroom_id, $assessment->offering_id, $assessment->version, $assessment->roster_version,
                $candidate->student_id, $candidate->display_name, $entry?->score ?? '', $entry?->status ?? 'ungraded', $entry?->feedback ?? ''];
        }

        return $rows;
    }

    public function export(array $matrix, string $format, string $instructions): string
    {
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            foreach ($matrix as $row) {
                fputcsv($stream, array_map($this->safeText(...), $row), ';', '"', '');
            }
            rewind($stream);
            $result = stream_get_contents($stream);
            fclose($stream);

            return "\xEF\xBB\xBF".$result;
        }
        abort_unless($format === 'xlsx', 422);
        $file = tempnam(storage_path('framework'), 'grade-template-');
        chmod($file, 0600);
        try {
            $zip = new ZipArchive;
            abort_unless($zip->open($file, ZipArchive::OVERWRITE) === true, 500);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Notes" sheetId="1" r:id="rId1"/><sheet name="Instructions" sheetId="2" r:id="rId2"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($matrix));
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet([[$instructions], ['Synthetic example only: score 16; status graded. Names never identify students.']]));
            $zip->close();

            return file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    private function safeText(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\s]*[=+@\-]/u', $text) ? "'".$text : $text;
    }

    private function sheet(array $matrix): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($matrix as $index => $row) {
            $xml .= '<row r="'.($index + 1).'">';
            foreach ($row as $column => $value) {
                $label = '';
                $number = $column + 1;
                while ($number > 0) {
                    $label = chr(65 + (($number - 1) % 26)).$label;
                    $number = intdiv($number - 1, 26);
                }
                $text = htmlspecialchars($this->safeText($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="'.$label.($index + 1).'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    public function parse(string $path, string $extension, object $assessment): array
    {
        $matrix = $extension === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path);
        abort_if(count($matrix) < 2 || count($matrix) > 5001, 422, __('api.school.invalid_import'));
        $headers = array_map(fn ($h) => trim((string) $h), array_shift($matrix));
        abort_unless(count($headers) === count(self::HEADERS) && count(array_unique($headers)) === count($headers) && array_diff(self::HEADERS, $headers) === [], 422, __('api.school.invalid_import'));
        $offering = DB::table('module_offerings')->find($assessment->offering_id);
        $rows = $errors = [];
        foreach ($matrix as $index => $values) {
            $number = $index + 2;
            if (count($values) !== count($headers)) {
                $errors[] = ['row' => $number, 'reason' => 'wrong_column_count'];

                continue;
            }
            $row = array_combine($headers, $values);
            $context = ['format_version' => 1, 'assessment_id' => $assessment->id, 'classroom_id' => $offering->classroom_id,
                'offering_id' => $assessment->offering_id, 'assessment_version' => $assessment->version, 'roster_version' => $assessment->roster_version];
            foreach ($context as $key => $expected) {
                if ((string) $row[$key] !== (string) $expected) {
                    $errors[] = ['row' => $number, 'reason' => 'wrong_template_context'];
                    break;
                }
            }
            $rows[] = ['_row' => $number, 'student_id' => $row['student_id'], 'score' => $row['score'], 'status' => $row['status'], 'feedback' => $row['feedback']];
        }

        return [$rows, $errors];
    }

    private function readCsv(string $path): array
    {
        $data = file_get_contents($path);
        abort_if(strlen($data) > 2097152, 422);
        if (str_starts_with($data, "\xFF\xFE")) {
            $data = mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($data, "\xFE\xFF")) {
            $data = mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE');
        } elseif (! mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
        }
        $data = preg_replace('/^\xEF\xBB\xBF/', '', $data);
        $header = strtok($data, "\r\n");
        $delimiters = array_filter([';', ',', "\t"], fn ($d) => count(str_getcsv($header ?: '', $d, '"', '')) === count(self::HEADERS));
        abort_unless(count($delimiters) === 1, 422, __('api.school.invalid_import'));
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $data);
        rewind($stream);
        $matrix = [];
        while (($row = fgetcsv($stream, null, reset($delimiters), '"', '')) !== false) {
            if ($row !== [null]) {
                $matrix[] = $row;
            }
            abort_if(count($matrix) > 5001, 422);
        }
        fclose($stream);

        return $matrix;
    }

    private function xml(string $text): \SimpleXMLElement
    {
        abort_if(preg_match('/<!DOCTYPE|<!ENTITY/i', $text), 422, __('api.school.invalid_import'));
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        abort_unless($xml !== false, 422, __('api.school.invalid_import'));
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xml;
    }

    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, __('api.school.invalid_import'));
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                abort_if($zip->numFiles > 100 || $size > 20971520 || $stat['size'] > 5242880 || str_ends_with(strtolower($stat['name']), '.bin'), 422, __('api.school.invalid_import'));
            }
            $strings = [];
            if (($shared = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach ($this->xml($shared)->xpath('//s:si') as $si) {
                    $si->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $strings[] = implode('', array_map(fn ($t) => (string) $t, $si->xpath('.//s:t')));
                }
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            abort_unless(is_string($sheet), 422);
            $xml = $this->xml($sheet);
            abort_if($xml->xpath('//s:f') !== [], 422, __('api.school.formulas_forbidden'));
            $matrix = [];
            foreach ($xml->xpath('//s:row') as $row) {
                $values = array_fill(0, count(self::HEADERS), '');
                foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                    preg_match('/^([A-Z]+)[0-9]+$/D', (string) $cell->attributes()->r, $match);
                    abort_unless(isset($match[1]), 422);
                    $col = 0;
                    foreach (str_split($match[1]) as $char) {
                        $col = $col * 26 + ord($char) - 64;
                    }
                    abort_if($col < 1 || $col > count(self::HEADERS), 422);
                    $c = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $type = (string) $cell->attributes()->t;
                    if ($type === 's') {
                        abort_unless(isset($strings[(int) $c->v]), 422);
                        $value = $strings[(int) $c->v];
                    } elseif ($type === 'inlineStr') {
                        $value = (string) $c->is->t;
                    } else {
                        $value = (string) $c->v;
                    }
                    $values[$col - 1] = $value;
                }
                $matrix[] = $values;
                abort_if(count($matrix) > 5001, 422);
            }

            return $matrix;
        } finally {
            $zip->close();
        }
    }
}
