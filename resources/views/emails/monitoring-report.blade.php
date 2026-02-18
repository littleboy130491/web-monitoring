<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $report->subject }}</title>
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; color: #333; }
  .wrapper { max-width: 680px; margin: 32px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
  .header { background: #1e293b; color: #fff; padding: 28px 32px; }
  .header h1 { margin: 0; font-size: 20px; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #94a3b8; }
  .body { padding: 28px 32px; }
  .section { margin-bottom: 28px; }
  .section-title { font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; padding: 6px 10px; border-radius: 4px; }
  .section-title.danger  { background: #fef2f2; color: #dc2626; }
  .section-title.warning { background: #fffbeb; color: #d97706; }
  .section-title.info    { background: #eff6ff; color: #2563eb; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; padding: 8px 10px; background: #f8fafc; color: #64748b; border-bottom: 1px solid #e2e8f0; }
  td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  td a { color: #2563eb; text-decoration: none; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: bold; }
  .badge-red    { background: #fef2f2; color: #dc2626; }
  .badge-yellow { background: #fffbeb; color: #d97706; }
  .badge-blue   { background: #eff6ff; color: #2563eb; }
  .sub-list { margin: 4px 0 0; padding-left: 14px; color: #64748b; font-size: 12px; }
  .footer { background: #f8fafc; padding: 16px 32px; font-size: 12px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; }
  .no-issues { text-align: center; padding: 32px; color: #64748b; }
</style>
</head>
<body>
<div class="wrapper">

  {{-- Header --}}
  <div class="header">
    <h1>🔍 Website Monitoring Report</h1>
    <p>Generated {{ now()->format('D, d M Y H:i:s T') }}</p>
  </div>

  <div class="body">

    @php
      $summary = $report->summary;
      $down           = $summary['down'] ?? [];
      $expiring       = $summary['expiring'] ?? [];
      $contentChanged = $summary['content_changed'] ?? [];
      $brokenAssets   = $summary['broken_assets'] ?? [];
      $total = count($down) + count($expiring) + count($contentChanged) + count($brokenAssets);
    @endphp

    @if($total === 0)
      <div class="no-issues">
        <p style="font-size:32px">✅</p>
        <p>All monitored websites are healthy. No issues detected.</p>
      </div>
    @else

      {{-- Sites Down --}}
      @if(count($down))
      <div class="section">
        <div class="section-title danger">🔴 Sites Down ({{ count($down) }})</div>
        <table>
          <tr><th>URL</th><th>HTTP</th><th>Error</th></tr>
          @foreach($down as $item)
          <tr>
            <td><a href="{{ $item['url'] }}">{{ $item['url'] }}</a></td>
            <td>{{ $item['status_code'] ?? '—' }}</td>
            <td>{{ $item['error'] ?? '—' }}</td>
          </tr>
          @endforeach
        </table>
      </div>
      @endif

      {{-- Domain Expiring --}}
      @if(count($expiring))
      <div class="section">
        <div class="section-title danger">⏰ Domain Expiring Soon ({{ count($expiring) }})</div>
        <table>
          <tr><th>URL</th><th>Expires</th><th>Days Left</th></tr>
          @foreach($expiring as $item)
          <tr>
            <td><a href="{{ $item['url'] }}">{{ $item['url'] }}</a></td>
            <td>{{ $item['expires_at'] }}</td>
            <td>
              <span class="badge {{ $item['days'] <= 0 ? 'badge-red' : 'badge-yellow' }}">
                {{ $item['days'] <= 0 ? 'EXPIRED' : $item['days'].' days' }}
              </span>
            </td>
          </tr>
          @endforeach
        </table>
      </div>
      @endif

      {{-- Content Changed --}}
      @if(count($contentChanged))
      <div class="section">
        <div class="section-title warning">📄 Significant Content Changes ({{ count($contentChanged) }})</div>
        <table>
          <tr><th>URL</th><th>Pages Changed</th></tr>
          @foreach($contentChanged as $item)
          <tr>
            <td><a href="{{ $item['url'] }}">{{ $item['url'] }}</a></td>
            <td>
              @foreach($item['pages'] as $page)
                <div>{{ $page['slug'] }}: <strong>{{ $page['change_percent'] }}%</strong></div>
              @endforeach
            </td>
          </tr>
          @endforeach
        </table>
      </div>
      @endif

      {{-- Broken Assets --}}
      @if(count($brokenAssets))
      <div class="section">
        <div class="section-title warning">🔗 Broken Assets / 404 ({{ count($brokenAssets) }})</div>
        <table>
          <tr><th>URL</th><th>Broken Files</th></tr>
          @foreach($brokenAssets as $item)
          <tr>
            <td><a href="{{ $item['url'] }}">{{ $item['url'] }}</a></td>
            <td>
              @foreach($item['assets'] as $asset)
                <div class="badge badge-red">{{ strtoupper($asset['type']) }}</div>
                <span style="font-size:11px;color:#64748b;word-break:break-all"> {{ $asset['url'] }}</span><br>
              @endforeach
            </td>
          </tr>
          @endforeach
        </table>
      </div>
      @endif

    @endif
  </div>

  <div class="footer">
    Sent by Web Monitoring &bull; {{ config('app.url') }}
  </div>
</div>
</body>
</html>
