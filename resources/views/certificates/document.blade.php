<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_number }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #1f262b; }
        .sheet { position: relative; width: 100%; height: 560px; padding: 46px 54px; box-sizing: border-box; }
        .rule { height: 6px; background: #16505f; }
        .kicker { font-size: 9px; letter-spacing: 3px; text-transform: uppercase; color: #6f797f; }
        h1 { margin: 6px 0 0; font-size: 27px; letter-spacing: -.5px; }
        .holder { margin-top: 26px; font-size: 34px; font-weight: bold; }
        .course { margin-top: 6px; font-size: 17px; color: #3d464c; }
        .meta { margin-top: 26px; width: 62%; font-size: 11px; color: #5f6a71; }
        .meta td { padding: 3px 0; }
        .meta .label { color: #8a9298; width: 42%; }
        .qr { position: absolute; right: 54px; bottom: 46px; text-align: center; }
        .qr img { width: 116px; height: 116px; }
        .qr span { display: block; margin-top: 6px; font-size: 8px; color: #8a9298; }
        .revoked { position: absolute; top: 210px; left: 54px; font-size: 44px; color: #c64242; opacity: .35; letter-spacing: 6px; }
        .footer { position: absolute; left: 54px; bottom: 46px; font-size: 9px; color: #8a9298; width: 60%; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="rule"></div>
    <div class="sheet">
        <p class="kicker">Oceanix · {{ __('ui.control_center') }}</p>
        <h1>{{ __('ui.certificate_document_title') }}</h1>

        <p class="holder">{{ $certificate->user->name }}</p>
        <p class="course">{{ $certificate->course->title }}</p>

        <table class="meta">
            <tr>
                <td class="label">{{ __('Certificate') }}</td>
                <td>{{ $certificate->certificate_number }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Content version') }}</td>
                <td>{{ __('Version :number', ['number' => $certificate->courseVersion->version_number]) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Issued') }}</td>
                <td>{{ $certificate->issued_at->locale(app()->getLocale())->translatedFormat('j F Y') }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Valid until') }}</td>
                <td>{{ $certificate->expires_at?->locale(app()->getLocale())->translatedFormat('j F Y') ?? __('No expiry') }}</td>
            </tr>
            @if ($certificate->score !== null)
                <tr>
                    <td class="label">{{ __('ui.score') }}</td>
                    <td>{{ $certificate->score }}%</td>
                </tr>
            @endif
        </table>

        @if ($certificate->isRevoked())
            <p class="revoked">{{ __('Revoked') }}</p>
        @endif

        <div class="qr">
            <img src="{{ $qr }}" alt="">
            <span>{{ __('ui.certificate_scan_to_verify') }}</span>
        </div>

        <p class="footer">
            {{ __('ui.certificate_document_footer', ['url' => $verificationUrl]) }}
        </p>
    </div>
</body>
</html>
