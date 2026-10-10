<?php

declare(strict_types=1);

class SpotifyKachel extends IPSModuleStrict
{
    private const SPOTIFY_MODULE_ID = '{DCC40FC6-4447-AA1A-E3E5-B5F32DF81806}';
    private const URI_PATTERN = '/^spotify:(track|album|playlist|artist|show|episode):[A-Za-z0-9]+$/';
    private const SEARCH_LIMIT = 10; // Spotify erlaubt für Apps im Development Mode max. 10
    private const META_CACHE_SIZE = 200;
    private const HISTORY_SIZE = 50;
    private const ACTION_PLAY = 2;
    // Variablen der Spotify-Instanz, deren Änderung die Kachel sofort aktualisiert
    private const WATCHED_IDENTS = ['CurrentTrack', 'CurrentArtist', 'CurrentDuration', 'Action', 'Volume', 'Device'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SpotifyInstance', 0);
        $this->RegisterPropertyInteger('RefreshInterval', 15);
        $this->RegisterPropertyInteger('MaxRecent', 12);
        $this->RegisterPropertyInteger('MaxRecommended', 12);
        $this->RegisterPropertyBoolean('IncludeAlbums', false);
        $this->RegisterPropertyString('CustomStations', '[]');

        $this->RegisterAttributeString('Recent', '[]');
        $this->RegisterAttributeString('Recommended', '[]');
        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeString('MetaCache', '{}');
        $this->RegisterAttributeInteger('LastRefresh', 0);

        $this->RegisterTimer('Refresh', 0, 'SPK_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VariableSync', 0, 'SPK_SyncFromVariables($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ApiSync', 0, 'SPK_SyncFromApi($_IPS[\'TARGET\']);');

        // Darstellung als normale Kachel und im Vollbild
        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN);

        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $spotifyID = $this->ReadPropertyInteger('SpotifyInstance');
        if (!$this->SpotifyAvailable()) {
            $this->SetStatus(104);
            $this->SetTimerInterval('Refresh', 0);
            return;
        }
        $this->RegisterReference($spotifyID);
        foreach (self::WATCHED_IDENTS as $ident) {
            $variableID = @IPS_GetObjectIDByIdent($ident, $spotifyID);
            if ($variableID !== false) {
                $this->RegisterMessage($variableID, VM_UPDATE);
            }
        }
        $this->SetStatus(IS_ACTIVE);

        // Listen kurz nach dem Übernehmen neu aufbauen (Einstellungen könnten sich geändert haben)
        $this->SetTimerInterval('Refresh', 2000);
    }

    public function GetVisualizationTile(): string
    {
        $state = [
            'recent'      => json_decode($this->ReadAttributeString('Recent'), true),
            'recommended' => json_decode($this->ReadAttributeString('Recommended'), true),
            'configured'  => $this->SpotifyAvailable()
        ];
        $json = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        $html = file_get_contents(__DIR__ . '/module.html');
        return str_replace('/*INITIAL_STATE*/null', $json, $html);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        // $Data[1]: Wert hat sich geändert
        if ($Message !== VM_UPDATE || !($Data[1] ?? false)) {
            return;
        }
        if (IPS_GetObject($SenderID)['ObjectIdent'] === 'Device') {
            // Gerätename und Lautstärke kommen nur aus der API
            $this->SetTimerInterval('ApiSync', 300);
            return;
        }
        // Mehrere Variablen ändern sich meist kurz hintereinander – gesammelt übertragen
        $this->SetTimerInterval('VariableSync', 150);
    }

    /**
     * Aktuelle Wiedergabe aus den Variablen der Spotify-Instanz übernehmen (ohne API-Aufruf).
     */
    public function SyncFromVariables(): void
    {
        $this->SetTimerInterval('VariableSync', 0);
        if (!$this->SpotifyAvailable()) {
            return;
        }
        $track = (string) $this->SpotifyValue('CurrentTrack', '-');
        if ($track === '' || $track === '-') {
            $this->SetBuffer('NowPlaying', '');
            $this->Push(['type' => 'nowPlaying', 'item' => null]);
            return;
        }

        $last = json_decode($this->GetBuffer('NowPlaying'), true);
        $artist = (string) $this->SpotifyValue('CurrentArtist', '');
        $trackChanged = !is_array($last) || $last['name'] !== $track || $last['sub'] !== $artist;
        $item = array_merge(is_array($last) ? $last : ['device' => '', 'deviceType' => '', 'canVolume' => true], [
            'name'     => $track,
            'sub'      => $artist,
            'playing'  => (int) $this->SpotifyValue('Action', 0) === self::ACTION_PLAY,
            'volume'   => (int) $this->SpotifyValue('Volume', 0),
            'progress' => $this->DurationToMs((string) $this->SpotifyValue('CurrentPosition', '0:00')),
            'duration' => $this->DurationToMs((string) $this->SpotifyValue('CurrentDuration', '0:00'))
        ]);
        if ($trackChanged) {
            // Cover, Kontext und Gerät des neuen Titels kommen gleich per API
            $item['uri'] = '';
            $item['image'] = '';
            $item['contextUri'] = '';
            $this->SetTimerInterval('ApiSync', 300);
        }
        $this->SetBuffer('NowPlaying', json_encode($item));
        $this->Push(['type' => 'nowPlaying', 'item' => $item]);
    }

    /**
     * Aktuelle Wiedergabe per API abrufen; nach eigenen Befehlen auch die Spotify-Instanz nachziehen.
     */
    public function SyncFromApi(): void
    {
        $this->SetTimerInterval('ApiSync', 0);
        $this->PushNowPlaying();
        if ($this->GetBuffer('UpdateSpotify') === '1') {
            $this->SetBuffer('UpdateSpotify', '');
            $error = '';
            $this->CallSpotify(fn (int $id) => SPO_UpdateVariables($id), $error);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'NowPlaying') {
            $this->SendDebug('RequestAction', $Ident . ' = ' . json_encode($Value), 0);
        }
        switch ($Ident) {
            case 'Init':
                // Veraltete Listen im Hintergrund nachladen, damit die Kachel sofort erscheint
                $interval = max(1, $this->ReadPropertyInteger('RefreshInterval')) * 60;
                if ((time() - $this->ReadAttributeInteger('LastRefresh')) > $interval) {
                    $this->SetTimerInterval('Refresh', 200);
                } else {
                    $this->PushState();
                }
                $this->PushNowPlaying();
                break;

            case 'NowPlaying':
                $this->PushNowPlaying();
                break;

            case 'Search':
                $request = json_decode((string) $Value, true);
                if (!is_array($request)) {
                    return;
                }
                $this->PushSearch(trim((string) ($request['q'] ?? '')), (string) ($request['cid'] ?? ''));
                break;

            case 'Play':
                $this->PlayUri((string) $Value);
                break;

            case 'Control':
                $this->Control((string) $Value);
                break;

            case 'Devices':
                $this->PushDevices((string) $Value);
                break;

            case 'SetDevice':
                $this->SetDevice((string) $Value);
                break;

            case 'Volume':
                $this->SetVolume((int) $Value);
                break;

            case 'Refresh':
                $this->Refresh();
                break;

            default:
                throw new Exception('Invalid ident');
        }
    }

    public function Refresh(): void
    {
        $this->ScheduleRefresh();
        if (!$this->SpotifyAvailable()) {
            return;
        }

        $recentlyPlayed = $this->Api('GET', '/me/player/recently-played?limit=50');
        $this->WriteAttributeString('Recent', json_encode($this->BuildRecent($recentlyPlayed)));
        $this->WriteAttributeString('Recommended', json_encode($this->BuildRecommended($recentlyPlayed)));
        $this->WriteAttributeInteger('LastRefresh', time());
        $this->PushState();
    }

    public function ClearCache(): void
    {
        $this->WriteAttributeString('History', '[]');
        $this->WriteAttributeString('MetaCache', '{}');
        $this->WriteAttributeString('Recent', '[]');
        $this->WriteAttributeString('Recommended', '[]');
        $this->WriteAttributeInteger('LastRefresh', 0);
        $this->Refresh();
    }

    // ---------------------------------------------------------------------
    // Listen
    // ---------------------------------------------------------------------

    private function BuildRecent(?array $recentlyPlayed): array
    {
        $allowed = $this->ReadPropertyBoolean('IncludeAlbums') ? ['playlist', 'album', 'artist'] : ['playlist'];

        // Kandidaten: Spotify-Verlauf + eigener Verlauf (Wiedergabe über die Kachel / erkannter Kontext)
        $candidates = [];
        foreach ($recentlyPlayed['items'] ?? [] as $item) {
            $uri = $item['context']['uri'] ?? '';
            $type = $item['context']['type'] ?? '';
            if ($uri !== '' && in_array($type, $allowed, true)) {
                $time = strtotime((string) ($item['played_at'] ?? '')) ?: 0;
                $candidates[$uri] = max($candidates[$uri] ?? 0, $time);
            }
        }
        foreach (json_decode($this->ReadAttributeString('History'), true) as $entry) {
            if (in_array($this->UriType($entry['uri']), $allowed, true)) {
                $candidates[$entry['uri']] = max($candidates[$entry['uri']] ?? 0, (int) $entry['time']);
            }
        }
        arsort($candidates);

        $max = $this->ReadPropertyInteger('MaxRecent');
        $result = [];
        foreach (array_keys($candidates) as $uri) {
            $meta = $this->ResolveMeta($uri);
            if ($meta !== null) {
                $result[] = $meta;
            }
            if (count($result) >= $max) {
                break;
            }
        }

        // Fallback ohne Verlauf (z. B. fehlende Berechtigung): eigene Playlists
        if (count($result) === 0) {
            $playlists = $this->Api('GET', '/me/playlists?limit=' . min(50, $max));
            foreach ($playlists['items'] ?? [] as $playlist) {
                if (is_array($playlist)) {
                    $result[] = $this->NormalizeItem($playlist, 'playlist');
                }
            }
        }
        return $result;
    }

    private function BuildRecommended(?array $recentlyPlayed): array
    {
        $max = $this->ReadPropertyInteger('MaxRecommended');
        $result = [];
        $add = function (?array $item) use (&$result, $max): void {
            if ($item === null || count($result) >= $max) {
                return;
            }
            foreach ($result as $existing) {
                if ($existing['uri'] === $item['uri']) {
                    return;
                }
            }
            $result[] = $item;
        };

        // 1. Eigene Sender aus der Konfiguration
        foreach (json_decode($this->ReadPropertyString('CustomStations'), true) as $station) {
            $uri = $this->NormalizeUri((string) ($station['URI'] ?? ''));
            if ($uri === null) {
                continue;
            }
            $meta = $this->ResolveMeta($uri) ?? ['uri' => $uri, 'name' => $uri, 'sub' => '', 'image' => '', 'kind' => $this->UriType($uri)];
            if (trim((string) ($station['Name'] ?? '')) !== '') {
                $meta['name'] = trim((string) $station['Name']);
            }
            $add($meta);
        }

        // 2. Empfehlungen von Spotify (steht nicht jeder App zur Verfügung)
        if (count($result) < $max) {
            $featured = $this->Api('GET', '/browse/featured-playlists?limit=' . min(50, $max));
            foreach ($featured['playlists']['items'] ?? [] as $playlist) {
                if (is_array($playlist)) {
                    $add($this->NormalizeItem($playlist, 'playlist'));
                }
            }
        }

        // 3. Radio-Sender passend zu den meistgehörten / zuletzt gehörten Künstlern
        if (count($result) < $max) {
            $artists = [];
            $top = $this->Api('GET', '/me/top/artists?limit=6&time_range=short_term');
            foreach ($top['items'] ?? [] as $artist) {
                $artists[] = $artist['name'];
            }
            foreach ($recentlyPlayed['items'] ?? [] as $item) {
                foreach ($item['track']['artists'] ?? [] as $artist) {
                    $artists[] = $artist['name'];
                }
            }
            $artists = array_slice(array_values(array_unique(array_filter($artists))), 0, 6);

            foreach ($artists as $artist) {
                if (count($result) >= $max) {
                    break;
                }
                $found = $this->Api('GET', '/search?type=playlist&limit=5&q=' . rawurlencode($artist . ' Radio'));
                $taken = 0;
                foreach ($found['playlists']['items'] ?? [] as $playlist) {
                    if (is_array($playlist) && stripos((string) $playlist['name'], 'radio') !== false) {
                        $add($this->NormalizeItem($playlist, 'playlist'));
                        if (++$taken >= 2) {
                            break;
                        }
                    }
                }
            }
        }

        // 4. Letzter Ausweg: allgemeine Radio-Playlists
        if (count($result) < $max) {
            $found = $this->Api('GET', '/search?type=playlist&limit=' . self::SEARCH_LIMIT . '&q=' . rawurlencode('Radio Hits'));
            foreach ($found['playlists']['items'] ?? [] as $playlist) {
                if (is_array($playlist)) {
                    $add($this->NormalizeItem($playlist, 'playlist'));
                }
            }
        }

        return $result;
    }

    private function ResolveMeta(string $uri): ?array
    {
        $cache = json_decode($this->ReadAttributeString('MetaCache'), true);
        if (isset($cache[$uri])) {
            return $cache[$uri];
        }

        $type = $this->UriType($uri);
        $id = substr($uri, strrpos($uri, ':') + 1);
        $meta = null;
        switch ($type) {
            case 'playlist':
                $data = $this->Api('GET', '/playlists/' . $id . '?fields=' . rawurlencode('uri,name,images,owner(display_name)'));
                break;
            case 'album':
            case 'artist':
            case 'show':
                $data = $this->Api('GET', '/' . $type . 's/' . $id);
                break;
            default:
                $data = null;
        }
        if (is_array($data) && isset($data['name'])) {
            $data['uri'] = $data['uri'] ?? $uri;
            $meta = $this->NormalizeItem($data, $type);
        } else {
            // Spotify-eigene Mixe (Daily Mix, Radio, …) liefert die Web API nicht mehr aus – oEmbed schon
            $meta = $this->OEmbed($uri, $type);
        }
        if ($meta === null) {
            return null;
        }

        $cache[$uri] = $meta;
        if (count($cache) > self::META_CACHE_SIZE) {
            $cache = array_slice($cache, -self::META_CACHE_SIZE, null, true);
        }
        $this->WriteAttributeString('MetaCache', json_encode($cache));
        return $meta;
    }

    private function OEmbed(string $uri, string $type): ?array
    {
        $id = substr($uri, strrpos($uri, ':') + 1);
        $url = 'https://open.spotify.com/oembed?url=' . rawurlencode('https://open.spotify.com/' . $type . '/' . $id);
        $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        $data = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($data) || !isset($data['title'])) {
            return null;
        }
        return [
            'uri'   => $uri,
            'name'  => (string) $data['title'],
            'sub'   => $this->KindLabel($type),
            'image' => (string) ($data['thumbnail_url'] ?? ''),
            'kind'  => $type
        ];
    }

    private function NormalizeItem(array $item, string $kind): array
    {
        $sub = '';
        switch ($kind) {
            case 'track':
            case 'album':
                $sub = implode(', ', array_map(fn ($a) => $a['name'], $item['artists'] ?? []));
                break;
            case 'playlist':
                $owner = (string) ($item['owner']['display_name'] ?? '');
                $sub = $owner !== '' ? 'Playlist · ' . $owner : 'Playlist';
                break;
            case 'artist':
                $sub = 'Künstler';
                break;
            case 'show':
                $sub = (string) ($item['publisher'] ?? 'Podcast');
                break;
        }
        $images = $kind === 'track' ? ($item['album']['images'] ?? []) : ($item['images'] ?? []);
        return [
            'uri'   => (string) $item['uri'],
            'name'  => (string) $item['name'],
            'sub'   => $sub,
            'image' => $this->PickImage($images),
            'kind'  => $kind
        ];
    }

    private function PickImage(?array $images): string
    {
        if (empty($images)) {
            return '';
        }
        // Kleinstes Bild, das noch mind. 200px breit ist (Liste ist absteigend sortiert)
        $chosen = $images[0]['url'] ?? '';
        foreach ($images as $image) {
            if (isset($image['width'], $image['url']) && $image['width'] >= 200) {
                $chosen = $image['url'];
            }
        }
        return (string) $chosen;
    }

    // ---------------------------------------------------------------------
    // Suche, Wiedergabe, Geräte
    // ---------------------------------------------------------------------

    private function PushSearch(string $query, string $cid): void
    {
        if ($query === '' || !$this->SpotifyAvailable()) {
            return;
        }
        $data = $this->Api('GET', '/search?type=track,playlist,album,artist&limit=' . self::SEARCH_LIMIT . '&q=' . rawurlencode($query));
        if ($data === null) {
            $this->Push(['type' => 'error', 'cid' => $cid, 'text' => 'Die Suche ist fehlgeschlagen.']);
            return;
        }
        $results = [];
        foreach (['tracks' => 'track', 'playlists' => 'playlist', 'albums' => 'album', 'artists' => 'artist'] as $group => $kind) {
            $results[$group] = [];
            foreach ($data[$group]['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $results[$group][] = $this->NormalizeItem($item, $kind);
                }
            }
        }
        $this->Push(['type' => 'search', 'cid' => $cid, 'query' => $query, 'results' => $results]);
    }

    private function PlayUri(string $uri): void
    {
        $uri = $this->NormalizeUri($uri);
        if ($uri === null || !$this->SpotifyAvailable()) {
            return;
        }
        $error = '';
        $this->CallSpotify(fn (int $id) => SPO_PlayURI($id, $uri), $error);
        if ($error !== '') {
            $this->Push(['type' => 'error', 'text' => 'Wiedergabe nicht möglich. Ist ein Spotify-Gerät aktiv und ausgewählt?']);
            return;
        }
        if ($this->UriType($uri) !== 'track') {
            $this->AddToHistory($uri);
        }
        $this->AfterPlaybackChange();
    }

    private function Control(string $command): void
    {
        if (!$this->SpotifyAvailable()) {
            return;
        }
        $error = '';
        switch ($command) {
            case 'play':
                $this->CallSpotify(fn (int $id) => SPO_Play($id), $error);
                break;
            case 'pause':
                $this->CallSpotify(fn (int $id) => SPO_Pause($id), $error);
                break;
            case 'next':
                $this->CallSpotify(fn (int $id) => SPO_NextTrack($id), $error);
                break;
            case 'previous':
                $this->CallSpotify(fn (int $id) => SPO_PreviousTrack($id), $error);
                break;
            default:
                return;
        }
        if ($error !== '') {
            $this->Push(['type' => 'error', 'text' => 'Spotify hat den Befehl abgelehnt. Ist ein Gerät aktiv?']);
        }
        $this->AfterPlaybackChange();
    }

    private function AfterPlaybackChange(int $delay = 700): void
    {
        // Spotify braucht einen Moment, bis der neue Zustand abrufbar ist – im Hintergrund nachladen,
        // statt die Kachel warten zu lassen. Play/Pause meldet die Spotify-Instanz sofort über "Action".
        $this->SetBuffer('UpdateSpotify', '1');
        $this->SetTimerInterval('ApiSync', $delay);
    }

    private function PushNowPlaying(): void
    {
        if (!$this->SpotifyAvailable()) {
            return;
        }
        $player = $this->Api('GET', '/me/player?additional_types=episode');
        if ($player === null) {
            return; // API-Fehler: letzten Stand behalten
        }
        $item = $player['item'] ?? null;
        if (!is_array($item)) {
            $this->SetBuffer('NowPlaying', '');
            $this->Push(['type' => 'nowPlaying', 'item' => null]);
            return;
        }

        $isEpisode = ($item['type'] ?? '') === 'episode';
        $contextUri = (string) ($player['context']['uri'] ?? '');
        if ($contextUri !== '' && in_array($this->UriType($contextUri), ['playlist', 'album', 'artist'], true)) {
            $this->AddToHistory($contextUri);
        }

        $nowPlaying = [
            'uri'        => (string) $item['uri'],
            'name'       => (string) $item['name'],
            // Wie die Spotify-Instanz, damit der Abgleich über die Variablen keinen Titelwechsel vermutet
            'sub'        => $isEpisode
                ? (string) ($item['show']['publisher'] ?? '')
                : implode(', ', array_map(fn ($a) => $a['name'], $item['artists'] ?? [])),
            'image'      => $this->PickImage($isEpisode ? ($item['images'] ?? []) : ($item['album']['images'] ?? [])),
            'contextUri' => $contextUri,
            'playing'    => (bool) ($player['is_playing'] ?? false),
            'progress'   => (int) ($player['progress_ms'] ?? 0),
            'duration'   => (int) ($item['duration_ms'] ?? 0),
            'device'     => (string) ($player['device']['name'] ?? ''),
            'deviceType' => (string) ($player['device']['type'] ?? ''),
            'volume'     => isset($player['device']['volume_percent']) ? (int) $player['device']['volume_percent'] : null,
            'canVolume'  => (bool) ($player['device']['supports_volume'] ?? true)
        ];
        $this->SetBuffer('NowPlaying', json_encode($nowPlaying));
        $this->Push(['type' => 'nowPlaying', 'item' => $nowPlaying]);
    }

    private function PushDevices(string $cid): void
    {
        $data = $this->Api('GET', '/me/player/devices');
        $devices = [];
        foreach ($data['devices'] ?? [] as $device) {
            $devices[] = [
                'id'     => (string) $device['id'],
                'name'   => (string) $device['name'],
                'type'   => (string) ($device['type'] ?? ''),
                'active' => (bool) ($device['is_active'] ?? false)
            ];
        }
        $this->Push(['type' => 'devices', 'cid' => $cid, 'devices' => $devices]);
    }

    private function SetDevice(string $deviceID): void
    {
        // Geräte-IDs sind je nach Gerät Hex-Strings oder UUIDs (mit Bindestrichen)
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $deviceID) || !$this->SpotifyAvailable()) {
            $this->SendDebug('SetDevice', 'Ungültige Geräte-ID: ' . $deviceID, 0);
            return;
        }

        // Gerät in der Spotify-Instanz setzen, damit SPO_PlayURI künftig dieses Gerät nutzt
        $variableID = @IPS_GetObjectIDByIdent('Device', $this->ReadPropertyInteger('SpotifyInstance'));
        if ($variableID !== false) {
            $error = '';
            $this->CallSpotify(fn (int $id) => RequestAction($variableID, $deviceID), $error);
        }

        // Wiedergabe übertragen, falls die Spotify-Instanz das nicht schon getan hat
        $player = $this->Api('GET', '/me/player');
        if (($player['device']['id'] ?? '') !== $deviceID) {
            $result = $this->Api('PUT', '/me/player', json_encode([
                'device_ids' => [$deviceID],
                'play'       => (bool) ($player['is_playing'] ?? false)
            ]));
            if ($result === null) {
                $this->Push(['type' => 'error', 'text' => 'Gerät konnte nicht gewechselt werden.']);
            }
        }
        $this->AfterPlaybackChange(800);
    }

    private function SetVolume(int $volume): void
    {
        if (!$this->SpotifyAvailable()) {
            return;
        }
        $volume = max(0, min(100, $volume));
        if ($this->Api('PUT', '/me/player/volume?volume_percent=' . $volume) === null) {
            $this->Push(['type' => 'error', 'text' => 'Die Lautstärke kann auf diesem Gerät nicht geändert werden.']);
            $this->PushNowPlaying();
            return;
        }
        // Lautstärke-Variable der Spotify-Instanz mitziehen
        $variableID = @IPS_GetObjectIDByIdent('Volume', $this->ReadPropertyInteger('SpotifyInstance'));
        if ($variableID !== false) {
            SetValue($variableID, $volume);
        }
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    private function AddToHistory(string $uri): void
    {
        $history = json_decode($this->ReadAttributeString('History'), true);
        if (($history[0]['uri'] ?? '') === $uri) {
            return;
        }
        $history = array_values(array_filter(
            $history,
            fn ($entry) => $entry['uri'] !== $uri
        ));
        array_unshift($history, ['uri' => $uri, 'time' => time()]);
        $this->WriteAttributeString('History', json_encode(array_slice($history, 0, self::HISTORY_SIZE)));

        // "Zuletzt gespielt" sofort nachziehen, wenn sich die Spitze ändert
        $recent = json_decode($this->ReadAttributeString('Recent'), true);
        if (($recent[0]['uri'] ?? '') === $uri) {
            return;
        }
        $allowed = $this->ReadPropertyBoolean('IncludeAlbums') ? ['playlist', 'album', 'artist'] : ['playlist'];
        if (!in_array($this->UriType($uri), $allowed, true)) {
            return;
        }
        $meta = $this->ResolveMeta($uri);
        if ($meta === null) {
            return;
        }
        $recent = array_values(array_filter($recent, fn ($entry) => $entry['uri'] !== $uri));
        array_unshift($recent, $meta);
        $this->WriteAttributeString('Recent', json_encode(array_slice($recent, 0, $this->ReadPropertyInteger('MaxRecent'))));
        $this->PushState();
    }

    private function NormalizeUri(string $value): ?string
    {
        $value = trim($value);
        // https://open.spotify.com/intl-de/playlist/ID?si=… → spotify:playlist:ID
        if (preg_match('#open\.spotify\.com/(?:intl-[a-z]+/)?(track|album|playlist|artist|show|episode)/([A-Za-z0-9]+)#', $value, $m)) {
            $value = 'spotify:' . $m[1] . ':' . $m[2];
        }
        return preg_match(self::URI_PATTERN, $value) ? $value : null;
    }

    private function SpotifyValue(string $ident, mixed $default): mixed
    {
        $variableID = @IPS_GetObjectIDByIdent($ident, $this->ReadPropertyInteger('SpotifyInstance'));
        return $variableID === false ? $default : GetValue($variableID);
    }

    private function DurationToMs(string $duration): int
    {
        $ms = 0;
        foreach (explode(':', $duration) as $part) {
            $ms = $ms * 60 + (int) $part;
        }
        return $ms * 1000;
    }

    private function UriType(string $uri): string
    {
        $parts = explode(':', $uri);
        return $parts[1] ?? '';
    }

    private function KindLabel(string $kind): string
    {
        return ['playlist' => 'Playlist', 'album' => 'Album', 'artist' => 'Künstler', 'track' => 'Titel', 'show' => 'Podcast'][$kind] ?? '';
    }

    private function PushState(): void
    {
        $this->Push([
            'type'        => 'state',
            'recent'      => json_decode($this->ReadAttributeString('Recent'), true),
            'recommended' => json_decode($this->ReadAttributeString('Recommended'), true)
        ]);
    }

    private function Push(array $message): void
    {
        $this->UpdateVisualizationValue(json_encode($message, JSON_UNESCAPED_UNICODE));
    }

    private function ScheduleRefresh(): void
    {
        $minutes = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('Refresh', $this->SpotifyAvailable() && $minutes > 0 ? $minutes * 60 * 1000 : 0);
    }

    private function SpotifyAvailable(): bool
    {
        $id = $this->ReadPropertyInteger('SpotifyInstance');
        return $id > 0 && IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::SPOTIFY_MODULE_ID;
    }

    /**
     * Ruft eine Funktion der Spotify-Instanz auf. Das Spotify-Modul meldet HTTP-Fehler als PHP-Warnung
     * (file_get_contents), diese werden hier abgefangen und in $error zurückgegeben.
     */
    private function CallSpotify(callable $call, string &$error): mixed
    {
        $error = '';
        set_error_handler(function (int $errno, string $errstr) use (&$error): bool {
            $error = $errstr;
            return true;
        });
        try {
            $result = $call($this->ReadPropertyInteger('SpotifyInstance'));
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $result = false;
        } finally {
            restore_error_handler();
        }
        if ($error !== '') {
            $this->SendDebug('Spotify', $error, 0);
        }
        return $result;
    }

    /**
     * Spotify Web API über die Spotify-Instanz. Liefert null bei Fehlern, [] bei leerer Antwort (204).
     */
    private function Api(string $method, string $path, string $body = ''): ?array
    {
        $error = '';
        $response = $this->CallSpotify(fn (int $id) => SPO_MakeAPIRequest($id, $method, $path, $body), $error);
        $this->SendDebug('API ' . $method, $path . ($error !== '' ? ' → ' . $error : ''), 0);
        if (!is_string($response) || $error !== '') {
            return null;
        }
        if ($response === '') {
            return [];
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
