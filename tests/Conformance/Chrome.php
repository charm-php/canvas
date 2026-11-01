<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance;

/**
 * The oracle: runs op programs in a real Chrome canvas and returns the pixels
 * Chrome produced, so library behaviour can be compared against the browser
 * that defines the API.
 *
 * Requires google-chrome (or chromium) with headless support.  Results are
 * cached on disk keyed by case contents + browser build, because the oracle is
 * deterministic and Chrome startup is the dominant cost of a suite run.
 */
final class Chrome
{
    private string $binary;
    private string $cacheFile;
    private Fixture $fx;

    public function __construct(?string $binary = null)
    {
        $this->binary = $binary ?? self::findBinary();
        $this->fx = new Fixture();
        $this->cacheFile = __DIR__ . '/.oracle-cache.json';
    }

    public static function isAvailable(): bool
    {
        try {
            self::findBinary();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public static function findBinary(): string
    {
        foreach ([
            getenv('CHROME_BIN') ?: '',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ] as $b) {
            if ($b !== '' && is_executable($b)) {
                return $b;
            }
        }
        throw new \RuntimeException('no Chrome/Chromium binary found (set CHROME_BIN)');
    }

    public function version(): string
    {
        return trim((string) shell_exec('CHROME_VERSION_EXTRA=none ' . escapeshellarg($this->binary) . ' --version 2>/dev/null'));
    }

    /**
     * Render every case in Chrome.
     *
     * @param array<int,array{id:string,w:int,h:int,ops:array}> $cases
     * @return array<string,array{w:int,h:int,rgba:string}> keyed by case id
     */
    public function render(array $cases): array
    {
        $cases = array_values($cases);
        $key = hash('sha256', json_encode([
            array_map(fn($c) => [$c['id'], $c['w'], $c['h'], $c['ops']], $cases),
            $this->version(),
            'v1',
        ]));

        $cache = $this->readCache();
        if (getenv('CONFORMANCE_NOCACHE') !== '1' && isset($cache[$key])) {
            return $this->decode($cache[$key]['results']);
        }

        $html = $this->buildHtml($cases);
        $tmp = sys_get_temp_dir() . '/phasync-conf-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700, true);
        $page = $tmp . '/page.html';
        file_put_contents($page, $html);

        $cmd = implode(' ', [
            escapeshellarg($this->binary),
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            '--disable-lcd-text',
            '--disable-skia-runtime-opts',
            '--disable-font-subpixel-positioning',
            '--disable-gpu-rasterization',
            '--lang=en-US',
            '--allow-file-access-from-files',
            escapeshellarg('--user-data-dir=' . $tmp . '/profile'),
            '--virtual-time-budget=20000',
            '--dump-dom',
            escapeshellarg('file://' . $page),
        ]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException("could not launch Chrome: $cmd");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->cleanup($tmp);

        $start = strpos((string) $stdout, '@PHCONFORM_START@');
        $end = $start === false ? false : strpos((string) $stdout, '@PHCONFORM_END@', $start);
        if ($start === false || $end === false) {
            throw new \RuntimeException(
                "Chrome produced no conformance output (exit $code).\n"
                . "cmd: $cmd\nstderr: " . substr($stderr, 0, 2000)
                . "\nstdout head: " . substr((string) $stdout, 0, 1500)
            );
        }

        $json = base64_decode(substr((string) $stdout, $start + 17, $end - $start - 17), true);
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Chrome returned malformed JSON');
        }
        if (!empty($decoded['error'])) {
            throw new \RuntimeException('Chrome error: ' . $decoded['error']);
        }

        $results = $decoded['cases'] ?? [];
        $this->writeCache($cache, $key, $results);

        return $this->decode($results);
    }

    /** @param array<int,array> $cases */
    private function buildHtml(array $cases): string
    {
        $needsFont = false;
        $needsImages = false;
        foreach ($cases as $c) {
            foreach ($c['ops'] as $op) {
                if ($op[0] === 'drawImage' || $op[0] === 'copyRect' || $op[0] === 'copyRectShift') {
                    $needsImages = true;
                }
                if (($op[0] === 'setFont' || $op[0] === 'fillText' || $op[0] === 'strokeText' || $op[0] === 'measureRect')
                    && str_contains(json_encode($op), 'ConfSans')) {
                    $needsFont = true;
                }
            }
        }

        $fontFace = '';
        if ($needsFont) {
            $url = $this->fx->fontDataUrl();
            if ($url !== null) {
                $fontFace = '@font-face{font-family:ConfSans;src:url(' . $url . ');}';
            }
        }

        $payload = json_encode([
            'cases' => array_map(fn($c) => ['id' => $c['id'], 'w' => $c['w'], 'h' => $c['h'], 'ops' => $c['ops']], $cases),
            'tile' => $needsImages ? $this->fx->tileDataUrl() : null,
            'tileAlpha' => $needsImages ? $this->fx->tileAlphaDataUrl() : null,
        ]);

        $interp = Ops::JS_INTERPRETER;

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>*{margin:0;padding:0}html,body{background:#000;overflow:hidden}$fontFace</style>
<script type="application/json" id="payload">$payload</script>
</head><body><pre id="out"></pre>
<script>
$interp
(async () => {
  const payload = JSON.parse(document.getElementById('payload').textContent);
  const sources = {};
  const load = (src) => new Promise((res, rej) => {
    const im = new Image();
    im.onload = () => res(im);
    im.onerror = () => rej(new Error('image load failed'));
    im.src = src;
  });
  try {
    if (payload.tile) sources.tile = await load(payload.tile);
    if (payload.tileAlpha) sources.tileAlpha = await load(payload.tileAlpha);
    if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) {} }
    const out = {};
    for (const c of payload.cases) {
      const cv = document.createElement('canvas');
      cv.width = c.w; cv.height = c.h;
      const x = cv.getContext('2d');
      for (const op of c.ops) phStep(x, op, sources, {});
      const d = x.getImageData(0, 0, c.w, c.h);
      let s = '';
      const u8 = new Uint8Array(d.data.buffer, d.data.byteOffset, d.data.byteLength);
      for (let i = 0; i < u8.length; i += 8192) {
        s += String.fromCharCode.apply(null, u8.subarray(i, Math.min(i + 8192, u8.length)));
      }
      out[c.id] = { w: c.w, h: c.h, b64: btoa(s) };
    }
    document.getElementById('out').textContent = '@PHCONFORM_START@'
      + btoa(JSON.stringify({ cases: out })) + '@PHCONFORM_END@';
  } catch (err) {
    document.getElementById('out').textContent = '@PHCONFORM_START@'
      + btoa(JSON.stringify({ error: String(err && err.stack || err) })) + '@PHCONFORM_END@';
  }
})();
</script>
</body></html>
HTML;
    }

    /** @return array<string,array{w:int,h:int,rgba:string}> */
    private function decode(array $results): array
    {
        $out = [];
        foreach ($results as $id => $r) {
            $out[$id] = [
                'w' => (int) $r['w'],
                'h' => (int) $r['h'],
                'rgba' => base64_decode((string) $r['b64'], true) ?: '',
            ];
        }
        return $out;
    }

    private function readCache(): array
    {
        if (!is_file($this->cacheFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->cacheFile), true);
        return is_array($data) ? $data : [];
    }

    private function writeCache(array $cache, string $key, array $results): void
    {
        $cache[$key] = ['results' => $results, 'at' => time()];
        // Keep the cache bounded; oldest entries first.
        if (count($cache) > 24) {
            uasort($cache, fn($a, $b) => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));
            array_shift($cache);
        }
        file_put_contents($this->cacheFile, json_encode($cache));
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
