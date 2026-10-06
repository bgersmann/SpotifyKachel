# SpotifyKachel

Kachel für die Symcon-Kachelvisualisierung (HTML-SDK, normale Kachel + Vollbild) zur bestehenden **Spotify**-Instanz von Symcon.

## Funktionen
- **Suche** nach Titeln, Playlists, Alben und Künstlern. Ein Klick startet die Wiedergabe.
- **Empfohlene Sender**: zuerst die eigenen Sender aus der Konfiguration, dann Spotify-Empfehlungen (falls die API sie liefert), sonst „Radio“-Playlists zu den meistgehörten bzw. zuletzt gehörten Künstlern
- **Zuletzt gespielt**: Playlists aus dem Spotify-Verlauf (`/me/player/recently-played`), ergänzt um einen eigenen Verlauf. Wahlweise auch mit Alben und Künstlern.
- Leiste „Aktuelle Wiedergabe“ mit Zurück, Play/Pause, Weiter und Geräteauswahl

## Einrichtung
1. Instanz „SpotifyKachel“ anlegen.
2. Die vorhandene Spotify-Instanz auswählen.
3. Optional eigene Sender eintragen, entweder als `spotify:playlist:…` oder als `https://open.spotify.com/…`-Link.
4. Die Instanz in der Kachelvisualisierung einfügen.

Alle API-Aufrufe laufen über `SPO_MakeAPIRequest` der Spotify-Instanz. Ein eigener Login ist deshalb nicht nötig.

## Hinweise
- Spotify-eigene Mixe (Daily Mix, Radio …) liefert die Web API nicht mehr aus. Name und Cover kommen dann über den oEmbed-Dienst von Spotify.
- Fehlt der Spotify-Verbindung die Berechtigung für den Verlauf, zeigt „Zuletzt gespielt“ den eigenen Verlauf bzw. die eigenen Playlists.
- Zur Fehlersuche: Debug-Fenster der Instanz (zeigt jeden API-Aufruf)
