<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Email preview — {{ $result->subject }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, sans-serif; background: #f4f4f4; }
        .wb-shell { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .wb-cols { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
        .wb-col { flex: 1 1 480px; background: #fff; border: 1px solid #e4e4e4; border-radius: 8px; overflow: hidden; }
        .wb-col h2 { margin: 0; padding: 12px 16px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; background: #fafafa; border-bottom: 1px solid #e4e4e4; }
        .wb-col iframe { width: 100%; height: 640px; border: 0; display: block; }
        .wb-text { white-space: pre-wrap; padding: 16px; font-size: 14px; line-height: 1.6; margin: 0; }
        .wb-meta { color: #6b6b6b; font-size: 13px; margin: 0 0 16px; }
    </style>
</head>
<body>
    <div class="wb-shell">
        <h1 style="font-size:22px;">Subject: {{ $result->subject }}</h1>
        <p class="wb-meta">
            Rendered via <code>app(EmailRenderer::class)-&gt;render($email, 'en', preview: true)</code>
            — {{ $email->title('en') }} — {{ count($result->embeds) }} embed(s), {{ $result->sizeBytes }} bytes.
            Literal <code>@verbatim{{ variable }}@endverbatim</code> tokens are preserved verbatim; the host's
            send pipeline substitutes them, not Heisenberg.
        </p>
        <div class="wb-cols">
            <div class="wb-col">
                <h2>HTML</h2>
                <iframe title="Email HTML preview" srcdoc="{{ $result->html }}"></iframe>
            </div>
            <div class="wb-col">
                <h2>Plain text</h2>
                <pre class="wb-text">{{ $result->text }}</pre>
            </div>
        </div>
    </div>
</body>
</html>
