<?php
declare(strict_types=1);

use PhpOffice\PhpWord\TemplateProcessor;

final class DocxService {
    private string $templatePath;

    public function __construct(string $templatePath) {
        $this->templatePath = $templatePath;
    }

    public function build(array $vars, string $preferredPdfPath): array {
        // 1) Fill DOCX
        $tmpDocx = tempnam(sys_get_temp_dir(), 'docx_');
        if (file_exists($tmpDocx)) { unlink($tmpDocx); }
        $tmpDocx .= '.docx';

        $tp = new TemplateProcessor($this->templatePath);
        foreach ($vars as $k => $v) {
            $tp->setValue($k, (string)$v);
        }
        $tp->saveAs($tmpDocx);

        // 2) Convert via LibreOffice (preserves design)
        $soffice = $this->findSoffice();
        if ($soffice) {
            $outDir = dirname($preferredPdfPath);
            @mkdir($outDir, 0777, true);
            $cmd = sprintf(
                '%s --headless --nologo --nofirststartwizard --convert-to pdf --outdir %s %s',
                escapeshellarg($soffice),
                escapeshellarg($outDir),
                escapeshellarg($tmpDocx)
            );
            $out = [];
            $code = 0;
            exec($cmd . ' 2>&1', $out, $code);
            $pdfPath = preg_replace('/\\.docx$/i', '.pdf', $tmpDocx);
            if ($code === 0 && is_file($pdfPath)) {
                // move to requested path
                @rename($pdfPath, $preferredPdfPath);
                return ['type' => 'pdf', 'path' => $preferredPdfPath, 'tmpdocx' => $tmpDocx];
            }
        }

        // 3) Fallback: return DOCX
        return ['type' => 'docx', 'path' => $tmpDocx];
    }

    private function findSoffice(): ?string {
        // macOS default install
        $mac = '/Applications/LibreOffice.app/Contents/MacOS/soffice';
        if (is_file($mac)) return $mac;
        // PATH
        $which = trim((string)@shell_exec('which soffice'));
        return $which !== '' ? $which : null;
    }
}
