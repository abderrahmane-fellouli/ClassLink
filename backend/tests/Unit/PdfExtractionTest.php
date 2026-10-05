<?php

namespace Tests\Unit;

use App\Services\SmalotPdfTextExtractor;
use Tests\TestCase;

class PdfExtractionTest extends TestCase
{
    public function test_real_pdf_parser_extracts_text_and_page_count(): void
    {
        $stream = 'BT /F1 12 Tf 72 720 Td (ClassLink course text) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        $path = tempnam(sys_get_temp_dir(), 'classlink-pdf-');
        try {
            file_put_contents($path, $pdf);
            $result = (new SmalotPdfTextExtractor)->extract($path);
            $this->assertSame(1, $result['page_count']);
            $this->assertStringContainsString('ClassLink course text', $result['text']);
        } finally {
            unlink($path);
        }
    }
}
