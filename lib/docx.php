<?php
declare(strict_types=1);

if (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class)) {
    throw new \RuntimeException('PhpOffice\\PhpWord not loaded. Run: composer install');
}

use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;

final class DocxService {
    private string $templatePath;
    public function __construct(string $templatePath) { $this->templatePath = $templatePath; }

    public function build(array $vars, string $preferredPdfPath): array {
        $tmpDocx = tempnam(sys_get_temp_dir(), 'docx_');
        if (file_exists($tmpDocx)) unlink($tmpDocx);
        $tmpDocx .= '.docx';

        $tp = new TemplateProcessor($this->templatePath);
        foreach ($vars as $k=>$v) { $tp->setValue($k, (string)$v); }
        $tp->saveAs($tmpDocx);

        // LibreOffice → PDF (preferred)
        $soffice = $this->findSoffice();
        if ($soffice) {
            $cmd = sprintf(
                '%s --headless --nologo --nofirststartwizard --convert-to pdf --outdir %s %s',
                escapeshellarg($soffice), escapeshellarg(dirname($preferredPdfPath)), escapeshellarg($tmpDocx)
            );
            $out=[]; $code=0; exec($cmd.' 2>&1', $out, $code);
            $pdf = preg_replace('/\\.docx$/i','.pdf',$tmpDocx);
            if ($code===0 && is_file($pdf)) {
                @rename($pdf,$preferredPdfPath);
                return ['type'=>'pdf','path'=>$preferredPdfPath,'tmpdocx'=>$tmpDocx];
            }
        }

        // PhpWord PDF fallback (lower fidelity). Optional: comment out if you don’t want it.
        try {
            Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF);
            Settings::setPdfRendererPath(dirname(__DIR__).'/vendor/dompdf/dompdf');
            $phpWord = IOFactory::load($tmpDocx);
            $pdfWriter = IOFactory::createWriter($phpWord, 'PDF');
            $pdfWriter->save($preferredPdfPath);
            return ['type'=>'pdf','path'=>$preferredPdfPath,'tmpdocx'=>$tmpDocx];
        } catch (\Throwable $e) {
            // Return DOCX
            return ['type'=>'docx','path'=>$tmpDocx];
        }
    }

    private function findSoffice(): ?string {
        $mac = '/Applications/LibreOffice.app/Contents/MacOS/soffice';
        if (is_file($mac)) return $mac;
        $which = trim((string)@shell_exec('which soffice'));
        return $which !== '' ? $which : null;
    }
}
