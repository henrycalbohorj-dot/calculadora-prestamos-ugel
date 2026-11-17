<?php
declare(strict_types=1);

/**
 * Cliente mínimo de Google Sheets API v4 con cuenta de servicio (JWT RS256).
 * No necesita Composer: solo las extensiones curl y openssl de PHP.
 */
class GoogleSheets
{
    private array $cred;
    private string $tokenUrl;
    private string $apiBase;
    private ?string $caBundle;
    private string $cacheFile;

    public function __construct(?array $opts = null)
    {
        $opts = $opts ?? cfg('google');
        $ruta = $opts['credenciales'];
        if (!is_file($ruta)) {
            throw new RuntimeException('No se encontró el archivo de credenciales de Google: ' . $ruta);
        }
        $this->cred = json_decode((string)file_get_contents($ruta), true) ?: [];
        if (empty($this->cred['client_email']) || empty($this->cred['private_key'])) {
            throw new RuntimeException('El JSON de credenciales no es de una cuenta de servicio válida.');
        }
        $this->tokenUrl  = $opts['token_url'] ?? 'https://oauth2.googleapis.com/token';
        $this->apiBase   = rtrim($opts['api_base'] ?? 'https://sheets.googleapis.com', '/');
        $this->caBundle  = (!empty($opts['ca_bundle']) && is_file($opts['ca_bundle'])) ? $opts['ca_bundle'] : null;
        $this->cacheFile = dirname(__DIR__) . '/cache/google_token_' . md5($this->cred['client_email']) . '.json';
    }

    public static function emailCuenta(): ?string
    {
        $ruta = cfg('google.credenciales');
        if (!is_file($ruta)) return null;
        $j = json_decode((string)file_get_contents($ruta), true);
        return $j['client_email'] ?? null;
    }

    /* ------------------------------ API pública ------------------------------ */

    public function metadatos(string $sheetId): array
    {
        return $this->request('GET', "/v4/spreadsheets/{$sheetId}?fields=properties.title,sheets.properties.title");
    }

    /** Devuelve una matriz de filas (valores sin formato). */
    public function leer(string $sheetId, string $rango): array
    {
        $q = http_build_query([
            'valueRenderOption'    => 'UNFORMATTED_VALUE',
            'dateTimeRenderOption' => 'FORMATTED_STRING',
            'majorDimension'       => 'ROWS',
        ]);
        $r = $this->request('GET', "/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($rango) . '?' . $q);
        return $r['values'] ?? [];
    }

    /** $datos = [ ['range' => "HOJA!N7:P7", 'values' => [[...]]], ... ] */
    public function escribirLote(string $sheetId, array $datos): int
    {
        if (!$datos) return 0;
        $total = 0;
        foreach (array_chunk($datos, 400) as $chunk) {
            $r = $this->request('POST', "/v4/spreadsheets/{$sheetId}/values:batchUpdate", [
                'valueInputOption' => 'RAW',
                'data'             => $chunk,
            ]);
            $total += (int)($r['totalUpdatedRows'] ?? count($chunk));
        }
        return $total;
    }

    /** Borra los valores de un rango (mantiene el formato). */
    public function limpiar(string $sheetId, string $rango): void
    {
        $this->request('POST', "/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($rango) . ':clear', new stdClass());
    }

    /** Crea la pestaña si no existe. */
    public function asegurarHoja(string $sheetId, string $titulo): void
    {
        foreach ($this->metadatos($sheetId)['sheets'] ?? [] as $h) {
            if (($h['properties']['title'] ?? '') === $titulo) return;
        }
        $this->request('POST', "/v4/spreadsheets/{$sheetId}:batchUpdate", ['requests' => [['addSheet' => ['properties' => ['title' => $titulo]]]]]);
    }

    /** Amplía la pestaña hasta $filas x $columnas si es más pequeña (una hoja nueva trae 1000 filas). */
    public function asegurarFilas(string $sheetId, string $titulo, int $filas, int $columnas = 0): void
    {
        $m = $this->request('GET', "/v4/spreadsheets/{$sheetId}?fields=sheets.properties(sheetId,title,gridProperties)");
        foreach ($m['sheets'] ?? [] as $h) {
            $p = $h['properties'] ?? [];
            if (($p['title'] ?? '') !== $titulo) continue;
            $g = $p['gridProperties'] ?? [];
            $rf = max($filas, (int)($g['rowCount'] ?? 0)); $rc = max($columnas, (int)($g['columnCount'] ?? 0));
            if ($rf === (int)($g['rowCount'] ?? 0) && $rc === (int)($g['columnCount'] ?? 0)) return;
            $this->request('POST', "/v4/spreadsheets/{$sheetId}:batchUpdate", ['requests' => [['updateSheetProperties' => [
                'properties' => ['sheetId' => $p['sheetId'], 'gridProperties' => ['rowCount' => $rf, 'columnCount' => $rc]],
                'fields' => 'gridProperties.rowCount,gridProperties.columnCount']]]]);
            return;
        }
    }

    /* ------------------------------- Internos ------------------------------- */

    private function token(): string
    {
        if (is_file($this->cacheFile)) {
            $c = json_decode((string)file_get_contents($this->cacheFile), true);
            if (!empty($c['token']) && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
        }
        $ahora = time();
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $cab = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $pay = $b64(json_encode([
            'iss'   => $this->cred['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => $this->tokenUrl,
            'iat'   => $ahora,
            'exp'   => $ahora + 3600,
        ]));
        $firma = '';
        if (!openssl_sign("$cab.$pay", $firma, $this->cred['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT (revisa private_key).');
        }
        $jwt = "$cab.$pay." . $b64($firma);

        $r = $this->http('POST', $this->tokenUrl, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        if (empty($r['access_token'])) throw new RuntimeException('Google no entregó token de acceso.');
        @file_put_contents($this->cacheFile, json_encode([
            'token' => $r['access_token'],
            'exp'   => $ahora + (int)($r['expires_in'] ?? 3600),
        ]));
        return $r['access_token'];
    }

    private function request(string $metodo, string $ruta, $cuerpo = null): array
    {
        return $this->http(
            $metodo,
            $this->apiBase . $ruta,
            $cuerpo === null ? null : json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json']
        );
    }

    private function http(string $metodo, string $url, ?string $cuerpo, array $cabeceras, int $intento = 0): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        if ($this->caBundle) curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Sin conexión con Google: ' . $err .
                (stripos($err, 'SSL') !== false ? ' — revisa google.ca_bundle en config.php' : ''));
        }
        $j = json_decode((string)$resp, true);
        if (($code === 429 || $code === 503) && $intento < 3) {   // cupo por minuto: espera y reintenta (5 s, 15 s, 30 s)
            sleep([5, 15, 30][$intento]);
            return $this->http($metodo, $url, $cuerpo, $cabeceras, $intento + 1);
        }
        if ($code >= 400) {
            $msg = $j['error']['message'] ?? $j['error_description'] ?? substr((string)$resp, 0, 200);
            if ($code === 403 || $code === 404) {
                $msg .= ' — ¿Compartiste el Sheet con ' . $this->cred['client_email'] . ' como Editor?';
            }
            throw new RuntimeException("Google respondió $code: $msg");
        }
        return is_array($j) ? $j : [];
    }
}
