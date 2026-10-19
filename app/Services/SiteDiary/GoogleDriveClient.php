<?php

namespace App\Services\SiteDiary;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Minimal Google Drive v3 client over Guzzle (no extra composer package, so it ships in a plain zip deploy).
 * Two credential styles: a service account (use a folder inside a Shared Drive — service accounts have no storage of
 * their own) or an OAuth refresh token of a normal Google account. Files are never made public; they are read back
 * through WIMS with the stored credential.
 */
class GoogleDriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private ?Client $http = null;

    public function __construct(private SiteDiarySettings $settings)
    {
    }

    public function withHttp(Client $http): self
    {
        $clone = clone $this;
        $clone->http = $http;

        return $clone;
    }

    private function client(): Client
    {
        return $this->http ??= new Client(['timeout' => 120, 'connect_timeout' => 15, 'http_errors' => false]);
    }

    public function enabled(): bool
    {
        return $this->settings->flag('drive_enabled');
    }

    public function configured(): bool
    {
        $c = $this->settings->credentials();
        $usable = $c && (($c['type'] ?? '') === 'service_account' || (($c['type'] ?? '') === 'oauth' && ! empty($c['refresh_token'])));

        return $usable && (string) $this->settings->get('drive_root_folder_id') !== '';
    }

    public function ready(): bool
    {
        return $this->enabled() && $this->configured();
    }

    // ---- auth ----------------------------------------------------------------------------------------------------

    private function accessToken(): string
    {
        $cred = $this->settings->credentials();
        if (! $cred) {
            throw new RuntimeException('Google Drive credentials have not been saved.');
        }
        $cacheKey = 'site_diary_drive_token_'.md5(json_encode([$cred['type'] ?? '', $cred['client_email'] ?? $cred['client_id'] ?? '']));
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        if (($cred['type'] ?? '') === 'oauth') {
            $res = $this->client()->post(self::TOKEN_URL, ['form_params' => [
                'client_id' => $cred['client_id'], 'client_secret' => $cred['client_secret'],
                'refresh_token' => $cred['refresh_token'], 'grant_type' => 'refresh_token',
            ]]);
        } else {
            $res = $this->client()->post(self::TOKEN_URL, ['form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt($cred),
            ]]);
        }
        $body = json_decode((string) $res->getBody(), true) ?: [];
        if ($res->getStatusCode() >= 400 || empty($body['access_token'])) {
            throw new RuntimeException('Google rejected the Drive credentials ('.($body['error_description'] ?? $body['error'] ?? $res->getStatusCode()).').');
        }
        Cache::put($cacheKey, $body['access_token'], max(60, (int) ($body['expires_in'] ?? 3600) - 120));

        return $body['access_token'];
    }

    private function signedJwt(array $cred): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
            'iss' => $cred['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600,
        ]));
        if (! openssl_sign($unsigned, $signature, $cred['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account private key could not be used to sign.');
        }

        return $unsigned.'.'.$b64($signature);
    }

    private function auth(array $extra = []): array
    {
        return ['headers' => ['Authorization' => 'Bearer '.$this->accessToken()] + ($extra['headers'] ?? [])] + array_diff_key($extra, ['headers' => 1]);
    }

    private function fail(ResponseInterface $res, string $what)
    {
        $body = json_decode((string) $res->getBody(), true);
        $msg = $body['error']['message'] ?? ('HTTP '.$res->getStatusCode());
        throw new RuntimeException("Google Drive: {$what} failed ({$msg}).");
    }

    // ---- one-click "Connect Google account" (OAuth authorisation-code flow) -------------------------------------

    /** The Google consent-screen URL. Only files WIMS itself creates are reachable (drive.file scope). */
    public static function authUrl(string $clientId, string $redirect, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'state' => $state,
            'scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    /** Trades the one-time code for a refresh token. Returns [refresh_token, access_token, expires_in]. */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirect): array
    {
        $res = $this->client()->post(self::TOKEN_URL, ['form_params' => [
            'code' => $code, 'client_id' => $clientId, 'client_secret' => $clientSecret, 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ]]);
        $body = json_decode((string) $res->getBody(), true) ?: [];
        if ($res->getStatusCode() >= 400 || empty($body['access_token'])) {
            throw new RuntimeException('Google would not accept the sign-in ('.($body['error_description'] ?? $body['error'] ?? $res->getStatusCode()).').');
        }
        if (empty($body['refresh_token'])) {
            throw new RuntimeException('Google did not return a long-lived token. Remove the app from your Google account permissions and connect again.');
        }

        return $body;
    }

    /** Email of the Google account behind an access token (shown on the settings page). */
    public function accountEmail(string $accessToken): ?string
    {
        $res = $this->client()->get(self::API.'/about', ['headers' => ['Authorization' => 'Bearer '.$accessToken], 'query' => ['fields' => 'user(emailAddress)']]);

        return $res->getStatusCode() < 400 ? (json_decode((string) $res->getBody(), true)['user']['emailAddress'] ?? null) : null;
    }

    /** Creates the folder that will hold everything (in the connected account's My Drive) and returns its id. */
    public function createRootFolder(string $name): string
    {
        $res = $this->client()->post(self::API.'/files?supportsAllDrives=true&fields=id', $this->auth(['json' => [
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder',
        ]]));
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'creating the storage folder');
        }

        return json_decode((string) $res->getBody(), true)['id'];
    }

    // ---- operations ----------------------------------------------------------------------------------------------

    /** Confirms the credential works and the root folder can be written to. */
    public function test(): array
    {
        $root = (string) $this->settings->get('drive_root_folder_id');
        $res = $this->client()->get(self::API.'/files/'.rawurlencode($root), $this->auth(['query' => ['fields' => 'id,name,mimeType,capabilities(canAddChildren)', 'supportsAllDrives' => 'true']]));
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'opening the root folder');
        }
        $f = json_decode((string) $res->getBody(), true);
        if (($f['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
            throw new RuntimeException('The Drive ID given is not a folder.');
        }
        if (isset($f['capabilities']['canAddChildren']) && ! $f['capabilities']['canAddChildren']) {
            throw new RuntimeException('This credential can see the folder but cannot add files to it.');
        }

        return ['name' => $f['name'] ?? '', 'id' => $f['id'] ?? $root];
    }

    /** Finds or creates the folder chain under the root and remembers every folder id (created once, reused after). */
    public function ensureFolder(array $segments): string
    {
        $parent = (string) $this->settings->get('drive_root_folder_id');
        $key = $parent;
        foreach ($segments as $segment) {
            $segment = $this->cleanName($segment);
            $key .= '/'.$segment;
            $pathKey = strlen($key) > 191 ? md5($key) : $key;
            $known = DB::table('site_diary_drive_folders')->where('path_key', $pathKey)->value('drive_folder_id');
            if ($known) {
                $parent = $known;

                continue;
            }
            $id = $this->findFolder($segment, $parent) ?? $this->createFolder($segment, $parent);
            DB::table('site_diary_drive_folders')->updateOrInsert(['path_key' => $pathKey], ['drive_folder_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $parent = $id;
        }

        return $parent;
    }

    private function findFolder(string $name, string $parent): ?string
    {
        $q = "name = '".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."' and '".$parent."' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $res = $this->client()->get(self::API.'/files', $this->auth(['query' => ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']]));
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'looking for a folder');
        }

        return (json_decode((string) $res->getBody(), true)['files'][0]['id'] ?? null) ?: null;
    }

    private function createFolder(string $name, string $parent): string
    {
        $res = $this->client()->post(self::API.'/files?supportsAllDrives=true&fields=id', $this->auth(['json' => [
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent],
        ]]));
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'creating a folder');
        }

        return json_decode((string) $res->getBody(), true)['id'];
    }

    /** Streams a local file to Drive with a resumable session (never loads it into memory). Returns the Drive file id. */
    public function upload(string $path, string $name, string $mime, string $folderId): string
    {
        $size = filesize($path);
        $init = $this->client()->post(self::UPLOAD.'/files?uploadType=resumable&supportsAllDrives=true&fields=id', $this->auth([
            'headers' => ['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $size],
            'json' => ['name' => $name, 'parents' => [$folderId]],
        ]));
        if ($init->getStatusCode() >= 400 || ! $init->getHeaderLine('Location')) {
            $this->fail($init, 'starting the upload');
        }

        $stream = fopen($path, 'rb');
        try {
            $res = $this->client()->put($init->getHeaderLine('Location'), [
                'headers' => ['Content-Type' => $mime, 'Content-Length' => (string) $size],
                'body' => $stream,
                'timeout' => 0,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'uploading the file');
        }
        $id = json_decode((string) $res->getBody(), true)['id'] ?? null;
        if (! $id) {
            throw new RuntimeException('Google Drive did not return a file id.');
        }

        return $id;
    }

    /** Moves a file to the Drive Trash (recoverable there for 30 days). A file that is already gone counts as done. */
    public function trash(string $fileId): void
    {
        $res = $this->client()->patch(self::API.'/files/'.rawurlencode($fileId).'?supportsAllDrives=true&fields=id', $this->auth(['json' => ['trashed' => true]]));
        if ($res->getStatusCode() === 404) {
            return;
        }
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'deleting the file');
        }
    }

    /** Permanently erases a file (it does not go to the Trash, so the space is freed at once). Already gone counts as done. */
    public function delete(string $fileId): void
    {
        $res = $this->client()->delete(self::API.'/files/'.rawurlencode($fileId).'?supportsAllDrives=true', $this->auth());
        if ($res->getStatusCode() === 404) {
            return;
        }
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'erasing the file');
        }
    }

    /** Storage of the connected Google account: ['limit' => ?int bytes (null = unlimited), 'used' => int, 'left' => ?int]. */
    public function quota(): array
    {
        $res = $this->client()->get(self::API.'/about', $this->auth(['query' => ['fields' => 'storageQuota(limit,usage,usageInDrive,usageInDriveTrash)']]));
        if ($res->getStatusCode() >= 400) {
            $this->fail($res, 'reading the storage space');
        }
        $q = json_decode((string) $res->getBody(), true)['storageQuota'] ?? [];
        $limit = isset($q['limit']) ? (int) $q['limit'] : null;
        $used = (int) ($q['usage'] ?? 0);

        return ['limit' => $limit, 'used' => $used, 'left' => $limit === null ? null : max(0, $limit - $used), 'drive' => (int) ($q['usageInDrive'] ?? 0), 'trash' => (int) ($q['usageInDriveTrash'] ?? 0)];
    }

    /**
     * Erases everything WIMS can see inside the storage folder (the folder itself stays). Files go first, then the
     * folders deepest-first. Returns ['files' => n, 'folders' => n, 'skipped' => n].
     */
    public function clearRoot(): array
    {
        $root = (string) $this->settings->get('drive_root_folder_id');
        $files = [];
        $folders = [];
        $queue = [$root];
        $seen = [$root => true];
        while ($queue) {
            $parent = array_shift($queue);
            $page = null;
            do {
                $res = $this->client()->get(self::API.'/files', $this->auth(['query' => array_filter([
                    'q' => "'".$parent."' in parents", 'fields' => 'nextPageToken,files(id,mimeType)', 'pageSize' => 200,
                    'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'pageToken' => $page,
                ])]));
                if ($res->getStatusCode() >= 400) {
                    $this->fail($res, 'listing the storage folder');
                }
                $body = json_decode((string) $res->getBody(), true) ?: [];
                foreach ($body['files'] ?? [] as $f) {
                    if (isset($seen[$f['id']])) {
                        continue;
                    }
                    $seen[$f['id']] = true;
                    if (($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                        array_unshift($folders, $f['id']); // later-found (deeper) folders come first
                        $queue[] = $f['id'];
                    } else {
                        $files[] = $f['id'];
                    }
                }
                $page = $body['nextPageToken'] ?? null;
            } while ($page);
        }

        $out = ['files' => 0, 'folders' => 0, 'skipped' => 0];
        foreach ($files as $id) {
            try {
                $this->delete($id);
                $out['files']++;
            } catch (\Throwable $e) {
                $out['skipped']++;
            }
        }
        foreach ($folders as $id) {
            try {
                $this->delete($id);
                $out['folders']++;
            } catch (\Throwable $e) {
                $out['skipped']++;
            }
        }
        DB::table('site_diary_drive_folders')->delete();

        return $out;
    }

    /** Opens a file for reading through WIMS (optionally a byte range, so video seeking works). */
    public function open(string $fileId, ?string $range = null): ResponseInterface
    {
        $headers = $range ? ['Range' => $range] : [];

        return $this->client()->get(self::API.'/files/'.rawurlencode($fileId), $this->auth([
            'headers' => $headers, 'query' => ['alt' => 'media', 'supportsAllDrives' => 'true'], 'stream' => true, 'timeout' => 0,
        ]));
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\\\\\/\x00-\x1F]+/', ' ', $name));

        return mb_substr($name === '' ? 'Untitled' : $name, 0, 80);
    }
}
