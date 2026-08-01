<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <style>
        /* dompdf needs a font that actually has Bengali glyphs — DejaVu Sans
           is the one it ships with that covers Bengali script; the default
           Helvetica/Arial substitution renders every বাংলা character as a
           blank box. */
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #222; }
        .header { text-align: center; margin-bottom: 14px; }
        .header img { max-height: 50px; margin-bottom: 4px; }
        .header h1 { font-size: 16px; margin: 0; }
        .header .sub { font-size: 10px; color: #666; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
        th { background: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) td { background: #fafafa; }
        .footer { margin-top: 14px; font-size: 9px; color: #888; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoUrl)
            <img src="{{ $logoUrl }}">
        @endif
        <h1>{{ $shopName }}</h1>
        <div class="sub">{{ $title }} &middot; {{ $generatedAt }}</div>
    </div>

    <table>
        @foreach ($rows as $i => $row)
            <tr>
                @foreach ($row as $cell)
                    @if ($i === 0)
                        <th>{{ $cell }}</th>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </table>

    <div class="footer">A Zaylotix product &middot; zaylotix.com</div>
</body>
</html>
