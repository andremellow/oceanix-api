<?php

namespace App\Services\Certificates;

use App\Models\Certificate;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the certificate document.
 *
 * The QR code resolves to the public verification page, so a printed certificate can be
 * checked by someone who has only the paper. The document states the exact course version,
 * because that — not the course name — is what the holder was assessed on.
 * See docs/product-spec.md §17.
 */
class CertificateRenderer
{
    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['user:id,name,employee_id', 'course', 'courseVersion']);

        $path = sprintf('certificates/%s.pdf', $certificate->certificate_number);

        $pdf = Pdf::loadView('certificates.document', [
            'certificate' => $certificate,
            'verificationUrl' => route('certificates.verify', $certificate),
            'qr' => $this->qrCode(route('certificates.verify', $certificate)),
        ])->setPaper('a4', 'landscape');

        Storage::disk($this->disk())->put($path, $pdf->output());

        $certificate->update(['file_path' => $path]);

        return $path;
    }

    public function exists(Certificate $certificate): bool
    {
        return $certificate->file_path !== null
            && Storage::disk($this->disk())->exists($certificate->file_path);
    }

    public function contents(Certificate $certificate): string
    {
        if (! $this->exists($certificate)) {
            $this->render($certificate);
            $certificate->refresh();
        }

        return (string) Storage::disk($this->disk())->get($certificate->file_path);
    }

    public function disk(): string
    {
        return (string) config('oceanix.certificates.disk', 'local');
    }

    /** Inline SVG, so the document needs no image files and no imagick extension. */
    private function qrCode(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url));
    }
}
