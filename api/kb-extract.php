<?php
/**
 * Text extraction for uploaded source documents.
 * .txt  — native
 * .docx — native (ZipArchive + strip XML)
 * .pdf  — smalot/pdfparser if installed (composer require smalot/pdfparser),
 *         else the pdftotext binary if on PATH, else empty (caller reports a clear message).
 */
function kofc_extract_text(string $path, string $ext): string
{
    $ext = strtolower($ext);
    if ($ext === 'txt')  return (string) file_get_contents($path);
    if ($ext === 'docx') return kofc_extract_docx($path);
    if ($ext === 'pdf')  return kofc_extract_pdf($path);
    return '';
}

function kofc_extract_docx(string $path): string
{
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return '';
    $xml = preg_replace('/<\/w:p>/', "\n", $xml);
    $text = strip_tags($xml);
    return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function kofc_extract_pdf(string $path): string
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists('\\Smalot\\PdfParser\\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                return trim($parser->parseFile($path)->getText());
            } catch (Throwable $e) {
                error_log('pdf parse: ' . $e->getMessage());
            }
        }
    }
    $out = @shell_exec('pdftotext ' . escapeshellarg($path) . ' -');
    return ($out !== null) ? trim($out) : '';
}
